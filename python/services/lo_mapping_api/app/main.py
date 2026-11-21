from fastapi import FastAPI

from app.job_routes import router as job_router


app = FastAPI(title="Learning Outcome Mapping API")
app.include_router(job_router)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}
