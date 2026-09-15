from pathlib import Path

from app.services.text_readers.document_extractor import extract


FIXTURES_DIR = Path(__file__).parent / "fixtures"
PDF_PATH = FIXTURES_DIR / "sample_document.pdf"


def _get_all_text(pages) -> str:
    return "\n".join(line.text for page in pages for line in page.lines)


def _find_line(lines, substring):
    for line in lines:
        if substring in line.text:
            return line
    return None


class TestTextExtractionNormal:
    def test_extract_text_and_font_properties(self):
        pages, page_count = extract(
            file_bytes=PDF_PATH.read_bytes(),
            ocr_enabled=False,
            extraction_engine="tesseract",
            ocr_threshold=0,
        )

        assert page_count == 3
        assert len(pages) == 3
        for page in pages:
            assert page.page_number in (1, 2, 3)

        full_text = _get_all_text(pages)
        assert "Forest Ecology" in full_text
        assert "Forestry is the science" in full_text
        assert "Forest management" in full_text
        assert "Clear-cutting" in full_text
        assert "Selective cutting" in full_text
        assert "Additional Techniques" in full_text
        assert "Conservation" in full_text
        assert "Sustainable forestry" in full_text
        assert "Old-growth forests" in full_text

        page1 = pages[0]
        p1_lines = page1.lines

        title = _find_line(p1_lines, "Forest Ecology")
        assert title is not None
        assert title.size is not None
        assert title.size > 16.0
        assert title.bold is False

        intro_heading = _find_line(p1_lines, "Introduction")
        assert intro_heading is not None
        assert intro_heading.size is not None
        assert intro_heading.size > 12.0
        assert intro_heading.bold is False

        body_line = _find_line(p1_lines, "Forestry is the science")
        assert body_line is not None
        assert body_line.size is not None
        assert body_line.size < intro_heading.size
        assert body_line.bold is False

        bold_line = _find_line(p1_lines, "Forest management involves")
        assert bold_line is not None
        assert bold_line.bold is True
        assert bold_line.size is not None

        page3 = pages[2]
        p3_lines = page3.lines

        conservation_heading = _find_line(p3_lines, "Conservation")
        assert conservation_heading is not None
        assert conservation_heading.size is not None
        assert conservation_heading.size > 12.0
        assert conservation_heading.bold is False

        sustainable_line = _find_line(p3_lines, "Sustainable forestry practices aim")
        assert sustainable_line is not None
        assert sustainable_line.bold is True
        assert sustainable_line.size is not None

        oldgrowth_line = _find_line(p3_lines, "Old-growth forests are particularly")
        assert oldgrowth_line is not None
        assert oldgrowth_line.bold is False
        assert oldgrowth_line.size is not None


class TestTextExtractionOCR:
    def test_ocr_extract_text_and_font_size(self):
        pages, page_count = extract(
            file_bytes=PDF_PATH.read_bytes(),
            ocr_enabled=True,
            extraction_engine="tesseract",
            ocr_threshold=9999,
        )

        assert page_count == 3
        assert len(pages) == 3

        full_text = _get_all_text(pages)
        assert "Forest Ecology" in full_text
        assert "Forestry is the science and craft" in full_text
        assert "Forest management involves the planning" in full_text
        assert "Clear-cutting is a logging practice" in full_text
        assert "Selective cutting removes only selected" in full_text
        assert "Sustainable forestry practices aim" in full_text
        assert "Old-growth forests are particularly" in full_text

        for page in pages:
            for line in page.lines:
                assert line.size is not None
                assert line.size > 0
                assert line.bold is None

        all_lines = [line for page in pages for line in page.lines]

        title_candidates = [line for line in all_lines if "Forest Ecology" in line.text]
        assert len(title_candidates) >= 1
        title_line = title_candidates[0]
        assert title_line.size > 16.0

        body_candidates = [line for line in all_lines if "science" in line.text.lower()]
        assert len(body_candidates) == 1
        assert body_candidates[0].size < title_line.size
