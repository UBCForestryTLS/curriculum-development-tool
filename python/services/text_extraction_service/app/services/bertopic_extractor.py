import re

from app.schemas import Topic

from bertopic import BERTopic
from bertopic.representation import KeyBERTInspired
from sklearn.feature_extraction.text import CountVectorizer


TOPICS_COUNT = 30          # max topics returned overall
TOPICS_PER_CLUSTER = 5     # top words taken from each cluster
MIN_SENTENCE_WORDS = 4     # drop sentence fragments shorter than this
MIN_TOPIC_SIZE = 3         # min sentences to form a cluster (small docs need a low value)


def extract(text: str) -> list[Topic]:
    """Extract topics from text using BERTopic.

    BERTopic clusters a set of documents, so the text is split into sentences and
    treated as the document set.
    """

    docs = _to_documents(text)
    if len(docs) < MIN_TOPIC_SIZE:
        return []

    # This prevents stop words like "etc" and "the" from being counted as topics
    vectorizer_model = CountVectorizer(stop_words="english", ngram_range=(1, 5))
    representation_model = KeyBERTInspired()

    topic_model = BERTopic(
        vectorizer_model=vectorizer_model,
        representation_model=representation_model,
        min_topic_size=MIN_TOPIC_SIZE,
        calculate_probabilities=False,
        verbose=False,
    )
    topic_model.fit_transform(docs)

    topics: list[Topic] = []
    seen: set[str] = set()
    for topic_id in topic_model.get_topic_info()["Topic"]:
        if topic_id == -1:  # outlier cluster
            continue
        for word, score in topic_model.get_topic(topic_id)[:TOPICS_PER_CLUSTER]:
            key = word.strip().lower()
            if key and key not in seen:
                seen.add(key)
                topics.append(Topic(topic=word.strip(), score=round(float(score), 4)))
    return topics[:TOPICS_COUNT]


def _to_documents(text: str) -> list[str]:
    """Split text into sentence-sized documents, dropping short fragments."""
    sentences = re.split(r"(?<=[.!?])\s+", text)
    return [s.strip() for s in sentences if len(s.split()) >= MIN_SENTENCE_WORDS]
