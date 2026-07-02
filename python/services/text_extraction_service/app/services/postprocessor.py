from app.schemas import Topic

def process(topics: list[Topic]) -> list[Topic]:
    """Postprocess topics extracted by YAKE, most relevant to least"""

    # Remove topics that are empty or too long
    trimmed_topics = [t for t in topics if 1 <= len(t.topic.split()) <= 8]
    
    word_filtered_topics = []
    for t in trimmed_topics:
        words = t.topic.split()
        if len(words) == 1:
            word = words[0]
            if (word.istitle() or word.isupper()) and len(word) >= 5:
                print("Adding...\n")
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
            
    # Remove topics already contained in other topics
    unique_topics = []
    for t in deduped_topics:
        if not any(t.topic.lower() in other.topic.lower() and t.topic.lower() != other.topic.lower() for other in deduped_topics):
            unique_topics.append(t)
    
    return unique_topics


def union(topics_1: list[Topic], topics_2: list[Topic]) -> list[Topic]:
    seen: set[str] = set()
    merged: list[Topic] = []
    for t in [*topics_1, *topics_2]:
        key = t.topic.lower().strip()
        if key and key not in seen:
            seen.add(key)
            merged.append(t)
    return merged