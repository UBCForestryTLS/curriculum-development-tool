import regex as re

from app.schemas import Topic
from app.services.topic_extraction import postprocessor
from app.services.topic_extraction import bertopic_extractor as extractor


class MaterialTypeHandler:
    """Base handler - extracts keywords from text only"""

    def preprocess(self, text: str) -> str:
        # Remove links
        text = re.sub(r'http:.*?/.*?\. .*(?:jpg|jpeg|png|gif|bmp|svg|webp)', '', text)
        text = re.sub(r'\b(?:http|https|www)\S*\b', '', text)
        text = re.sub(r'\b(?:jpg|jpeg|png|gif|bmp|svg|webp)\b', '', text)
        return text

    def extract_topics(self, pages: list[dict]) -> list[Topic]:
        text = ". \f".join("\n".join(line["text"] for line in page["lines"]) for page in pages if page["lines"])
        preprocessed_text = self.preprocess(text)
        print("Extracting topics from preprocessed_text...")
        return postprocessor.process(extractor.extract(preprocessed_text))

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
        return text

    def extract_topics(self, pages: list[dict]) -> list[Topic]:
        keyword_topics = self._keyword_topics(pages)
        return (keyword_topics)
        # return postprocessor.process(self._title_topics(pages), filterLowerCaseSingleWords = True)
        # TODO
        # return postprocessor.union(self._title_topics(pages), keyword_topics, filterLowerCaseSingleWords = True)

    def _keyword_topics(self, pages: list[dict]) -> list[Topic]:
        text = ". \f".join(self.preprocess(" ".join(line["text"] for line in page["lines"])) for page in pages if page["lines"])
        return extractor.extract(text)

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
    def extract_topics(self, pages: list[dict]) -> list[Topic]:
        print("Page count:", len(pages))
        # TODO: Gotta make this a model of some sort to avoid the confusing dict operations
        preprocessed_pages = [" ".join([line["text"] for line in page["lines"]]) for page in pages]        # if len(preprocessed_pages) > 0:
        #     print(pages[0])
        print("Preprocessed pages count:", len(preprocessed_pages))
        print("Extracting topics from preprocessed_text...")
        topics = extractor.extract(preprocessed_pages)
        # keyword_topics = super().extract_topics(pages)
        # return postprocessor.process(keyword_topics)
        # TODO
        postprocessed_topics = postprocessor.process(
            topics, 
            minTopicCharCount = 4, 
            filterLowerCaseSingleWords = False, 
            scoreThreshold = 0.8 # BERTopic
            # TODO: Can we scale the score threshold inversely by text length?
            # Reasoning: Longer texts should produce more topics, and better selected topics too.
            #            The higher (worse) scored topics would then probably be less relevant
            # TODO: Another point. Do we even need a score check here? Maybe for articles we do,
            #                      but for slides, important terms may only appear once. We should still keep them.
            #                      Could apply to articles too, especially if it's divided by section.
        )
        # return postprocessor.union(self._heading_topics(pages), keyword_topics)
        return postprocessed_topics

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
        # bold is None for OCR (weight unknown), but require bold if non-OCR
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