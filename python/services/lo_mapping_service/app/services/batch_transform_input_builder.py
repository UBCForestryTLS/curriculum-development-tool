import json

from datetime import datetime

import boto3

from app.core.logging_config import logger
from app.core.config import Settings

from app.schemas import (
    CourseLearningOutcome,
    MappingScaleOption,
    OutcomeMappingRequest,
    ProgramLearningOutcome,
)

class BatchTransformInputBuilder:
    """Builds batch transform input JSONL files for CLO-PLO/Standard mapping."""

    def __init__(self, request: OutcomeMappingRequest) -> None:
        #load_dotenv()
        self.settings = Settings() 
        self.request = request
        self.s3_bucket = self.settings.BATCH_TRANSFORM_INPUT_S3_BUCKET

    def build_batch_prompt_records(self) -> str:
        
        # Use course_id and program_id from OutcomeMappingRequest
        course_id = getattr(self.request, 'course_id', None)
        program_id = getattr(self.request, 'program_id', None)

        # If Course Id or Program Id is not present
        if course_id is None:
            course_id = 'unknowncourse'
        if program_id is None:
            program_id = 'unknownprogram'

        course_id_str = self._sanitize(course_id)
        program_id_str = self._sanitize(program_id)

        # Create a unique filename using timestamp, course and program ids
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
        filename = f"batch_transform_input__courseid-{course_id_str}__programid-{program_id_str}__{timestamp}.jsonl"
        s3_key = f"batch_inputs/{filename}"

        # Build JSONL content
        lines = []
        for clo in self.request.course_learning_outcomes:
            for plo in self.request.program_learning_outcomes:
                pair_id = self._build_pair_id(clo, plo)
                prompt = self._build_prompt(clo, plo, pair_id)
                payload = self._build_jsonl_payload(pair_id, prompt)
                lines.append(json.dumps(payload, ensure_ascii=False))

        jsonl_content = "\n".join(lines)

        # Upload to S3
        try:
            input_s3_path = self.__upload_to_s3(jsonl_content, s3_key)
        
            return input_s3_path
        except Exception as e:  
            raise RuntimeError(f"Failed to build and upload batch transform input: {str(e)}")

    def __upload_to_s3(self, content: str, s3_key: str) -> str:
        
        try:
            if not content:
                raise ValueError("Content is empty")
            if not s3_key:
                raise ValueError("s3_key is required")
            if not self.s3_bucket:
                raise ValueError("S3 bucket is not set")
            if not self.settings.ACCESS_KEY or not self.settings.SECRET_KEY or not self.settings.AWS_REGION:
                raise ValueError("AWS credentials or region are not set in environment variables")

            session_kwargs = {
                "aws_access_key_id": self.settings.ACCESS_KEY,
                "aws_secret_access_key": self.settings.SECRET_KEY,
                "region_name": self.settings.AWS_REGION
            }

            if self.settings.SESSION_TOKEN is not None:
                session_kwargs["aws_session_token"] = self.settings.SESSION_TOKEN

            boto_session = boto3.Session(**session_kwargs)

            s3_client = boto_session.client("s3")

            logger.info(f"Uploading data to S3 bucket \"{self.s3_bucket}\"")

            s3_client.put_object(
                Bucket=self.s3_bucket,
                Key=s3_key,
                Body=content.encode("utf-8"),
                ContentType="application/x-ndjson",
            )
            
            return f"s3://{self.s3_bucket}/{s3_key}"
        except Exception as e:
            raise RuntimeError(f"Failed to upload batch transform input to S3: {str(e)}")
    
    def _build_pair_id(self, clo: CourseLearningOutcome, plo: ProgramLearningOutcome,) -> str:
        return f"clo-{clo.l_outcome_id}__plo-{plo.pl_outcome_id}"

    def _build_prompt(self, clo: CourseLearningOutcome, plo: ProgramLearningOutcome, pair_id: str,) -> str:
        mapping_scales_text = self._format_mapping_scales_for_prompt(
            self.request.mapping_scales
        )
        
        map_labels_json = self._build_mapping_scale_labels_list(self.request.mapping_scales)

        return "\n".join(
            [
                "<|im_start|>system",
                "You are an experienced course designer who aligns Course Learning Outcomes (CLOs) with accreditation standards.",
                "You follow instructions precisely and always return responses in JSON format for given CLO and accreditation standard pair, the JSON must be the following object (not wrapped in any other key):",
                "{ ",
                '"id": <given id for CLO and accreditation standard pair>, ',
                '"CLO": "<given CLO>", ',
                '"Accreditation_Standard": "<given accreditation standard>", ',
                '"is_mapped": true or false, ',
                '"mapLabels": ' + map_labels_json + ', ',
                '"explanation": "<explanation phrase>", ',
                "} ",
                "Note: A CLO may span multiple mapping scale levels. If so, classify it under all relevant levels. ",
                "<|im_end|>",
                "<|im_start|>user",
                "An instructor has developed Course Learning Outcomes (CLOs) for a course syllabus and seeks assistance in aligning each CLO with each accreditation standard and categorizing its cognitive level according to the given mapping scale. ",
                "As an experienced course designer:",
                "  1. Compare the given CLO with the given accreditation standard to determine if there is alignment.",
                "  2. If they align, give an explanation for why they align. ",
                "  3. If they align, classify the CLO into one or more levels of the given mapping scale:",
                "     Mapping Scale Levels: ",
                mapping_scales_text,
                "Please review the following CLO and accreditation standard and complete the alignment, explanation, and mapping level determination:",
                "CLO: " + clo.l_outcome,
                "Accreditation Standard: " + plo.pl_outcome,
                "id: " + pair_id,
                "<|im_end|>",
                "<|im_start|>assistant",
                "/no_think"
            ]
        )
        
    def _build_mapping_scale_labels_list(self, scales: list[MappingScaleOption]) -> list[str]:
        map_labels = []
        for scale in self.request.mapping_scales:
            if not scale.abbreviation:
                raise ValueError(f"Mapping scale with id {scale.map_scale_id} is missing an abbreviation.")
            else:
                map_labels.append(scale.abbreviation)   
        map_labels_json = json.dumps(map_labels)
        return map_labels_json

    def _build_jsonl_payload(self, pair_id: str, prompt: str,) -> dict[str, object]:
        return {
            "id": pair_id,
            "inputs": prompt,
            "parameters": {
                "max_new_tokens": 1500, 
                "return_full_text": False, 
                "stop": ["<|im_start|>", "<|im_end|>"], 
                "enable_thinking": False
                }
        }

    def _format_mapping_scales_for_prompt(self, scales: list[MappingScaleOption]) -> str:
        if not scales:
            return "- No mapping scales were provided."

        formatted_scales: list[str] = []
        for scale in scales:
            formatted_scales.append(f"       {scale.abbreviation} - {scale.title}")

        return "\n".join(formatted_scales)
    
    # Sanitize for filename (remove spaces, special chars)
    def _sanitize(self, val):
        return str(val).replace(' ', '_').replace('/', '_').replace('\\', '_')
