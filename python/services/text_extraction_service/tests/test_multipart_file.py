import json
from pathlib import Path
from fastapi.testclient import TestClient

from app.api.routes import app

PDF_PATH = Path(__file__).parent / "fixtures" / "sample_document.pdf"

client = TestClient(app)

def test_extract_endpoint_multipart():
    pdf_bytes = PDF_PATH.read_bytes()
    metadata = json.dumps({
        "ocr_enabled": False,
        "extraction_engine": "tesseract",
        "ocr_threshold": 0,
        "material_type": "slides",
        "existing_topics": ["Forest Ecology", "Conservation"]
    })

    files = {
        "file": ("sample_document.pdf", pdf_bytes, "application/pdf")
    }
    data = {
        "metadata": metadata
    }

    response = client.post("/extract", files=files, data=data)
    assert response.status_code == 200
    res_data = response.json()

    assert "pages" in res_data
    assert "page_count" in res_data
    assert "topics" in res_data
    assert res_data["page_count"] == 3

    topic_names = [t["topic"] for t in res_data["topics"]]
    assert "Forest Ecology" in topic_names and "Conservation" in topic_names
