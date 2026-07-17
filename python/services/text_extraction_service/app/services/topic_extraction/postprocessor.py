from itertools import chain

from app.schemas import Topic
import spacy

def process(topics: list[Topic], filterLowerCaseSingleWords = False, minTopicCharCount = 5, scoreThreshold = 0.5) -> list[Topic]:
    """Postprocess extracted topics"""

    # Remove topics that are empty or too long
    trimmed_topics = [t for t in topics if 1 <= len(t.topic.split()) <= 8]
    
    # For 1-word topics, check their length and optionally their case
    word_filtered_topics = []
    for t in trimmed_topics:
        words = t.topic.split()
        if len(words) == 1:
            word = words[0]
            if ((not filterLowerCaseSingleWords) or (word.istitle() or word.isupper())) and len(word) >= minTopicCharCount:
                word_filtered_topics.append(t)
        else:
            word_filtered_topics.append(t)

    # Remove duplicates (case-insensitive)
    seen = set()
    deduped_topics = []
    for t in word_filtered_topics:
        topic_lower = t.topic.lower()
        if topic_lower not in seen:
            seen.add(topic_lower)
            deduped_topics.append(t)
            
    # Remove topics with numbers in them
    number_filtered_topics = []
    for t in deduped_topics:
        # TODO: Should this be not all instead of not any? Can any topics actually have numbers?
        if not any(char.isdigit() for char in t.topic):
            number_filtered_topics.append(t)
            
    # Remove topics containing file or web URL elements
    url_filtered_topics = []
    for t in number_filtered_topics:
        if any(word in t.topic.lower().split() for word in ["com", "org"]):
            continue
        if any(word in t.topic.lower() for word in ["http", "jpg", "png", "www"]):
            continue
        url_filtered_topics.append(t)
        
    # Remove topics that have adjectives in word final position
    adjective_filtered_topics = []
    nlp = spacy.load("en_core_web_sm") # Shouldn't slow down here, since it'll be cached from the BERTopic extractor
    for t in url_filtered_topics:
        doc = nlp(t.topic)
        if doc[-1].pos_ != "ADJ":
            adjective_filtered_topics.append(t)
            
    # Remove topics already contained in other topics
    unique_topics = []
    for t in adjective_filtered_topics:
        if not any(t.topic.lower() in other.topic.lower() and t.topic.lower() != other.topic.lower() for other in deduped_topics):
            unique_topics.append(t)
    
    # Remove less relevant topics
    relevant_topics = [t for t in unique_topics if t.score < scoreThreshold]
    
    return relevant_topics


def union(*topic_lists: list[Topic]) -> list[Topic]:
    seen: set[str] = set()
    merged: list[Topic] = []
    for t in chain.from_iterable(topic_lists):
        key = t.topic.lower().strip()
        if key and key not in seen:
            seen.add(key)
            merged.append(t)
    return merged