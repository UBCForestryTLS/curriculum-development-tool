from typing import Annotated, Self
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, model_validator


PositiveId = Annotated[int, Field(strict=True, gt=0)]
# Reject blank text without changing the text sent to the model.
NonBlankText = Annotated[str, StringConstraints(strict=True, pattern=r"\S")]


class _JobInput(BaseModel):
    model_config = ConfigDict(extra="forbid")


class CourseLearningOutcome(_JobInput):
    l_outcome_id: PositiveId
    l_outcome: NonBlankText


class ProgramLearningOutcome(_JobInput):
    pl_outcome_id: PositiveId
    pl_outcome: NonBlankText


class MappingScaleInput(_JobInput):
    map_scale_id: PositiveId
    title: NonBlankText
    abbreviation: NonBlankText
    description: NonBlankText


class MappingJobRequest(_JobInput):
    """Inputs for a new job. The service supplies status, counts and timestamps."""

    # Laravel supplies this ID so retries can refer to the same job.
    job_id: UUID
    course_id: PositiveId
    program_id: PositiveId
    # Send each outcome once; the service will pair every CLO with every PLO.
    # For example, 2 CLOs and 3 PLOs produce 6 pairs without repeating text in the request.
    course_learning_outcomes: list[CourseLearningOutcome] = Field(min_length=1)
    program_learning_outcomes: list[ProgramLearningOutcome] = Field(min_length=1)
    mapping_scales: list[MappingScaleInput] = Field(min_length=1)

    @model_validator(mode="after")
    def validate_unique_inputs(self) -> Self:
        # Repeated outcome IDs would produce duplicate pair records in the job.
        clo_ids = [clo.l_outcome_id for clo in self.course_learning_outcomes]
        if len(set(clo_ids)) != len(clo_ids):
            raise ValueError("CLO IDs must be unique within a job.")

        plo_ids = [plo.pl_outcome_id for plo in self.program_learning_outcomes]
        if len(set(plo_ids)) != len(plo_ids):
            raise ValueError("PLO IDs must be unique within a job.")

        scale_ids = [scale.map_scale_id for scale in self.mapping_scales]
        if len(set(scale_ids)) != len(scale_ids):
            raise ValueError("Mapping scale IDs must be unique within a job.")

        # Each label returned by the model must identify one mapping level.
        abbreviations = [scale.abbreviation for scale in self.mapping_scales]
        if len(set(abbreviations)) != len(abbreviations):
            raise ValueError("Mapping scale abbreviations must be unique within a job.")

        return self
