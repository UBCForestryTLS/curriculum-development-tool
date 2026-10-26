from fastapi import FastAPI


app = FastAPI(title="Learning Outcome Mapping API")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}
