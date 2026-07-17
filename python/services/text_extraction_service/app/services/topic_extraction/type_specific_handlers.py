import regex as re

from app.schemas import Topic
from app.services.topic_extraction import postprocessor
from app.services.topic_extraction import bertopic_extractor as extractor


class MaterialTypeHandler:
    """Base handler - extracts keywords from text only"""

    def match_topics(self, text: str, topics: list[str], min_count: int = 1) -> list[Topic]:
        from app.services.topic_extraction.faiss_topic_matcher import FaissTopicMatcher  # noqa: F401
        # return FaissTopicMatcher().match(text, topics, min_count=min_count)

        matched: list[Topic] = []
        for topic in topics:
            pattern = re.compile(rf'(?<!\S){re.escape(topic)}(?!\S)', re.IGNORECASE | re.UNICODE)
            if len(pattern.findall(text)) >= min_count:
                matched.append(Topic(topic=topic, score=0.0))
        return matched

    def preprocess(self, text: str) -> str:
        # Remove links
        text = re.sub(r'(?:http|https|www):.*?/.*?\. .*(?:jpg|jpeg|png|gif|bmp|svg|webp)', '', text)
        text = re.sub(r'\b(?:http|https|www)\S*\b', '', text)
        text = re.sub(r'\b(?:jpg|jpeg|png|gif|bmp|svg|webp)\b', '', text)
        return text

    def extract_topics(self, pages: list[dict], existing_topics: list[str] = []) -> list[Topic]:
        text = ". \f".join("\n".join(line["text"] for line in page["lines"]) for page in pages if page["lines"])
        preprocessed_text = self.preprocess(text)
        print("Extracting topics from preprocessed_text...")
        
        matched_topics = self.match_topics(preprocessed_text, existing_topics)

        return matched_topics

def _to_topics(texts) -> list[Topic]:
    """De-duplicate (case-insensitive) and wrap heading texts as topics."""
    seen: set[str] = set()
    topics: list[Topic] = []
    for text in texts:
        text = text.strip()
        key = text.lower()
        if text and key not in seen:
            seen.add(key)
            topics.append(Topic(topic=text, score=0.0))
    return topics


class SlidesHandler(MaterialTypeHandler):
    MAX_TITLE_WORDS = 15

    def preprocess(self, text: str) -> str:
        text = super().preprocess(text)
        # Add a period after newlines so bullets without punctuation are treated
        # as separate sentences.
        text = re.sub(r'(?<![.!?])\n', '. \n', text)
        # remove bullet points • or - with a space after 
        text = re.sub(r'(?<=\n)[•-]\s+', '', text)
        return text

    def extract_topics(self, pages: list[dict], existing_topics: list[str] = []) -> list[Topic]:
        title_topics = self._title_topics(pages)
        keyword_topics = self._keyword_topics(pages)
        matched_topics = self._matched_topics(pages, existing_topics)
        return postprocessor.process(
                        postprocessor.union(title_topics, keyword_topics, matched_topics), 
                        filterLowerCaseSingleWords = False,
                        # TODO: Remove 1 - score to revert to higher is better everywhere
                        scoreThreshold = 0.8 # Lower score is better
                    )
        
    def _matched_topics(self, pages: list[dict], existing_topics: list[str]):
        text = ". \f".join([self.preprocess(". ".join([line["text"] for line in page["lines"]])) for page in pages])
        matched_topics = self.match_topics(text, existing_topics)
        return matched_topics

    def _keyword_topics(self, pages: list[dict]) -> list[Topic]:
        # text = ". \f".join(self.preprocess(" ".join(line["text"] for line in page["lines"])) for page in pages if page["lines"])
        text = ". \f".join([self.preprocess(". ".join([line["text"] for line in page["lines"]])) for page in pages])
        return extractor.extract(text, min_topic_size=3)

    def _title_topics(self, pages: list[dict]) -> list[Topic]:
        # A slide's title is simply the largest-font line on the slide.
        titles = []
        for page in pages:
            lines = [line for line in page.get("lines", []) if line.get("text", "").strip()]
            if not lines:
                continue
            biggest = max(lines, key=lambda line: line.get("size", 0.0))
            title = biggest["text"].strip()
            if title and len(title.split()) <= self.MAX_TITLE_WORDS:
                titles.append(title)
        return _to_topics(titles)


class ArticleHandler(MaterialTypeHandler):
    MIN_HEADING_SIZE = 10.0
    
    def preprocess(self, text: str) -> str:
        text = super().preprocess(text)
        
        # Find the final occurrence of 'Citations' or 'References'
        match = re.search(r'\b(?:Citations|References)\b', text, flags=re.IGNORECASE)
        if match:
            return text[:match.start()]
        else:
            return text
    
    def extract_topics(self, pages: list[dict], existing_topics: list[str] = []) -> list[Topic]:
        print("Page count:", len(pages))
        # TODO: Gotta make this a model of some sort to avoid the confusing dict operations
        text = ". \f".join([self.preprocess(". ".join([line["text"] for line in page["lines"]])) for page in pages])
        print("Preprocessed pages count:", len(text))
        print("Extracting topics from preprocessed_text...")
        keyword_topics = extractor.extract(text)
        heading_topics = self._heading_topics(pages)
        matched_topics = self._matched_topics(pages, existing_topics)
        topics = postprocessor.union(heading_topics, keyword_topics, matched_topics)
        postprocessed_topics = postprocessor.process(
            topics, 
            minTopicCharCount = 4, 
            filterLowerCaseSingleWords = False, 
            scoreThreshold = 1 # BERTopic
            # TODO: Can we scale the score threshold inversely by text length?
            # Reasoning: Longer texts should produce more topics, and better selected topics too.
            #            The higher (worse) scored topics would then probably be less relevant
            # TODO: Another point. Do we even need a score check here? Maybe for articles we do,
            #                      but for slides, important terms may only appear once. We should still keep them.
            #                      Could apply to articles too, especially if it's divided by section.
        )
        # return postprocessor.union(self._heading_topics(pages), keyword_topics)
        return postprocessed_topics
    
    def _matched_topics(self, pages: list[dict], existing_topics: list[str]):
        text = ". \f".join([self.preprocess(". ".join([line["text"] for line in page["lines"]])) for page in pages])
        # We can potentially scale min_count by the number of pages or text size
        matched_topics = self.match_topics(text, existing_topics, min_count=2)
        return matched_topics

    def _heading_topics(self, pages: list[dict]) -> list[Topic]:
        headings = [
            line["text"]
            for page in pages
            for line in page.get("lines", [])
            if line.get("text", "").strip() and self._is_heading(line)
        ]
        return _to_topics(headings)

    def _is_heading(self, line: dict) -> bool:
        bold = line.get("bold")
        # bold is None for OCR, but require bold if non-OCR
        # Note: Tesseract does have a way to estimate bold text with some math, but results are decent as-is already.
        if line.get("size", 0.0) >= self.MIN_HEADING_SIZE and (bold or bold is None):
            return True
        else:
            return False


handlers: dict[str, MaterialTypeHandler] = {
    "slides": SlidesHandler(),
    "article": ArticleHandler(),
}

DEFAULT_HANDLER = MaterialTypeHandler()


def get_handler(material_type: str | None) -> MaterialTypeHandler:
    # TODO: Make frontend dropdown to limit to known types 
    #       with an 'other' option for free text, for which we'll use the default handler
    print("getting handler for material_type: ", (material_type or "").lower())
    if (material_type or "").lower() not in handlers:
        print("No specific handler found for material_type: ", (material_type or "").lower())
    else:
        print("Found handlers: ", handlers.get((material_type or "").lower(), DEFAULT_HANDLER))
    return handlers.get((material_type or "").lower(), DEFAULT_HANDLER)