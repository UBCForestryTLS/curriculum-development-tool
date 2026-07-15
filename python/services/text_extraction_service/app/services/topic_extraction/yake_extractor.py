import yake
from app.schemas import Topic

# Note: this TOPIC_COUNT only controls how many topics are extracted by YAKE
# The postprocessor may further filter topics when returning to the user
TOPICS_COUNT = 30

def extract(text: str) -> list[Topic]:
    """Extract topics from text using YAKE, most relevant to least"""

    extractor = yake.KeywordExtractor(
        lan="en",
        n=3,
        lemmatize=True,
        dedupLim=0.9, 
        dedupFunc="jaro", 
        top=TOPICS_COUNT
    )
    keywords = extractor.extract_keywords(text)  # [(phrase, score)], best first
    return [
        Topic(topic = phrase, score = round(float(score), 4))
        for phrase, score in keywords
    ]
