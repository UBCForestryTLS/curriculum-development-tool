from fastapi import FastAPI, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from app.core.config import settings
from app.core.logging_config import logger
from app.schema.courseSyllabi import ParseResponse
from app.services import syllabus_parser  

app = FastAPI(
    title="Curriculum Development Tool Python API",
    version="0.1.0",
    docs_url="/docs",
    redoc_url="/redoc"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.ALLOWED_ORIGINS,
    allow_credentials=False,
    allow_methods=["POST"],
    allow_headers=["*"]
)

@app.post("/create_course_from_syllabi")
async def create_course_from_syllabi(file: UploadFile) -> ParseResponse:
    try:
        course = syllabus_parser.get_course_from_file_contents(
            await file.read(),
            file.filename
        )

        return {
            "status": "success",
            "data": course,
            "message": "File processed successfully"
        }
    except Exception as e:
        logger.error(f"Error in create_course_from_syllabi: {e}")
        return {
            "status": "error", 
            "message": "An error occurred while processing the request."
        }
