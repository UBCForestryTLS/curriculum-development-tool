from pathlib import Path

import pytest
import warnings

from app.schemas import Topic, TopicSource
from app.services.topic_extraction.type_specific_handlers import (
    ArticleHandler,
    MaterialTypeHandler,
    SlidesHandler,
    get_handler,
)
from app.schemas import ExtractedLine, ExtractedPage


class TestMatchTopics:
    def test_matching_and_case_and_substring_rules(self):
        handler = MaterialTypeHandler()
        r1 = handler.match_topics("Forest ecology is fascinating", ["Forest"])
        assert len(r1) == 1 and r1[0].topic == "Forest"
        r2 = handler.match_topics("CLIMATE change in British Columbia", ["climate change"])
        assert len(r2) == 1
        r3 = handler.match_topics("The forester went to the jungle", ["forest"])
        assert len(r3) == 0  # forester ignored because substring of word
        r4 = handler.match_topics("I love forestry and the forest and forests", ["Forest"])
        assert len(r4) == 1  # substrings of words ignored, but "forest" counted

    def test_min_count(self):
        handler = MaterialTypeHandler()
        r1 = handler.match_topics("Forest appears once", ["Forest"], min_count=2)
        assert r1 == []
        r2 = handler.match_topics("Forest is great. I love Forest.", ["Forest"], min_count=2)
        assert len(r2) == 1 and r2[0].topic == "Forest"


class TestDefaultHandler:
    def test_extract_and_refresh(self):
        handler = MaterialTypeHandler()

        page = _make_page(["Climate change affects forests"])
        topics = handler.extract_topics([page], existing_topics=["Climate change"])
        assert len(topics) == 1 and topics[0].topic == "Climate change"

        page2 = _make_page(["Forest ecology"])
        topics2 = handler.refresh_topics([page2], existing_topics=["Forest"])
        assert len(topics2) == 1 and topics2[0].source == TopicSource.MATCH



class TestSlidesHandler:
    def test_title_topics_empty(self):
        handler = SlidesHandler()
        empty = ExtractedPage(page_number=1, lines=[])
        assert handler._title_topics([empty]) == []

    def test_title_topics_largest_font(self):
        handler = SlidesHandler()
        page = _make_page(["Small", "BIG TITLE", "Medium"], sizes=[10, 24, 14])
        t1 = handler._title_topics([page])
        assert len(t1) == 1 and t1[0].topic == "BIG TITLE"

    def test_title_topics_skips_long_titles(self):
        handler = SlidesHandler()
        # MAX_TITLE_WORD_COUNT = 15
        # Change test based on value in type_specific_handlers.py.
        long_title = " ".join(["word"] * 16)
        page2 = _make_page([long_title], sizes=[30])
        assert handler._title_topics([page2]) == []
        

    def test_refresh_vs_extract(self):
        handler = SlidesHandler()
        body_lines = _read_sample_file_lines()
        page = _make_page(
            ["Title slide", "forestry and ecology"] + body_lines,
            sizes=[30, 15] + [12.0] * len(body_lines),
        )
        topics_refreshed = handler.refresh_topics([page], existing_topics=["forestry"])
        print("Refreshed topics:")
        print([t.topic for t in topics_refreshed])
        assert any(t.topic == "forestry" for t in topics_refreshed)
        assert not any(t.source == TopicSource.FONT for t in topics_refreshed)
        topics_extracted = handler.extract_topics([page], existing_topics=["forestry"])
        print("Extracted topics:")
        print([t.topic for t in topics_extracted])
        assert any(t.topic == "Title slide" for t in topics_extracted)
        assert any(t.topic == "forestry" for t in topics_extracted)


class TestArticleHandler:
    def test_is_heading(self):
        handler = ArticleHandler()
        assert handler._is_heading(ExtractedLine(text="Heading", size=14, bold=True))
        assert handler._is_heading(ExtractedLine(text="Heading", size=14, bold=None))
        assert not handler._is_heading(ExtractedLine(text="Small", size=8, bold=True))
        assert not handler._is_heading(ExtractedLine(text="Small", size=8, bold=False))

    def test_heading_topics(self):
        handler = ArticleHandler()
        page = _make_page(["Regular", "Section Title", "More"], sizes=[10, 14, 10], bolds=[False, True, False])
        topics = handler._heading_topics([page])
        assert len(topics) == 1 and topics[0].topic == "Section Title"

    def test_refresh_skips_heading_topics(self):
        handler = ArticleHandler()
        body_lines = _read_sample_file_lines()
        page = _make_page(
            ["Heading"] + body_lines,
            sizes=[14] + [12.0] * len(body_lines),
            bolds=[True] + [None] * len(body_lines),
        )
        topics = handler.refresh_topics([page], existing_topics=[])
        for t in topics:
            print(f"Topic: {t.topic}, Source: {t.source}")
        assert not any(t.source == TopicSource.FONT for t in topics)

    def test_bertopic_basic(self):
        # May be flaky
        handler = ArticleHandler()
        sample_path = Path(__file__).parent / "fixtures" / "sample_file.txt"
        lines = [line for line in sample_path.read_text().splitlines() if line.strip()]
        page = _make_page(lines)
        topics = handler.extract_topics([page])
        print(f"Identified topics ({len(topics)}):")
        for t in topics:
            print(f"Topic: {t.topic}, Score: {t.score}, Source: {t.source}")
        print([t.topic.lower() for t in topics])
        if not any("maple" in t.topic.lower() for t in topics):
            warnings.warn("Warning: 'maple' not among extracted topics. This could be due to randomness, or the algorithm should be improved.")
        assert any(t.source == TopicSource.KEYWORD for t in topics)

    def test_preprocess_truncates_at_final_references(self):
        handler = ArticleHandler()
        text = "Some text References: [1] Article"
        assert handler.preprocess(text) == "Some text "
        text = "Some text Citations: [1] Article"
        assert handler.preprocess(text) == "Some text "
        text = "This figure references that chart"
        assert handler.preprocess(text) == "This figure references that chart"
        text = "We surveyed the forest. References were used to plot points. References: [1] Article"
        assert handler.preprocess(text) == "We surveyed the forest. References were used to plot points. "
        


class TestGetHandler:
    def test_default_handler_for_unknown(self):
        assert isinstance(get_handler("textbook"), MaterialTypeHandler)
        assert isinstance(get_handler("unknown"), MaterialTypeHandler)

    def test_case_insensitive(self):
        assert isinstance(get_handler("Slides"), SlidesHandler)
        assert isinstance(get_handler("slides"), SlidesHandler)
        assert isinstance(get_handler("SlIdEs"), SlidesHandler)
        assert isinstance(get_handler("ARTICLE"), ArticleHandler)
        assert isinstance(get_handler("article"), ArticleHandler)

def _make_page(lines: list[str], sizes: list[float] | None = None, bolds: list[bool | None] | None = None) -> ExtractedPage:
    """Helper to create an ExtractedPage from line texts."""
    if sizes is None:
        sizes = [12.0] * len(lines)
    if bolds is None:
        bolds = [None] * len(lines)
    return ExtractedPage(
        page_number=1,
        lines=[ExtractedLine(text=t, size=s, bold=b) for t, s, b in zip(lines, sizes, bolds)],
    )


def _read_sample_file_lines() -> list[str]:
    sample_file_path = Path(__file__).parent / "fixtures" / "sample_file.txt"
    return [line for line in sample_file_path.read_text().splitlines() if line.strip()]