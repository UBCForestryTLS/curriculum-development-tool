import re

from app.schemas import Topic

from bertopic import BERTopic
from bertopic.representation import KeyBERTInspired
# from sklearn.feature_extraction.text import CountVectorizer

from sentence_transformers import SentenceTransformer

TOPICS_COUNT = 100          # max topics returned overall
TOPICS_PER_CLUSTER = 5     # top words taken from each cluster
MIN_SENTENCE_WORDS = 3     # drop sentence fragments shorter than this
MIN_TOPIC_SIZE = 4         # min sentences to form a cluster (small docs need a low value)


def extract(pages: list[str]) -> list[Topic]:
    """Extract topics from text using BERTopic.

    BERTopic clusters a set of documents, so the text is split into sentences and
    treated as the document set.
    """
    print("BERTopic extraction starting...")

    docs = _to_documents(pages)
    print(f"Split text into {len(docs)} documents for topic extraction")
    # if len(docs) < MIN_TOPIC_SIZE:
    #     # TODO: Re-evaluate this
    #     return []
    
    print("Extracting topics from text...")

    # This prevents stop words like "etc" and "the" from being counted as topics
    # vectorizer_model = CountVectorizer(stop_words="english", ngram_range=(1, 5))
    embedding_model = SentenceTransformer("sentence-transformers/all-MiniLM-L6-v2")
    representation_model = KeyBERTInspired()
    
    print("Set up embedding and representation models for BERTopic")

    topic_model = BERTopic(
        embedding_model=embedding_model,
        # vectorizer_model=vectorizer_model,
        representation_model=representation_model,
        min_topic_size=MIN_TOPIC_SIZE,
        calculate_probabilities=False,
        verbose=True,
    )
    
    print("Configured BERTopic model, fitting to documents...")
    
    topic_model.fit_transform(docs)

    topics: list[Topic] = []
    seen: set[str] = set()
    
    print("Extracting topics from fitted model...")
    
    for topic_id in topic_model.get_topic_info()["Topic"]:
        print("")
        print(f"Topic ID: {topic_id}, Name: {topic_model.get_topic(topic_id)}")
        # TODO: Find out why genuinely relevant topics are being labelled -1 (outlier)
        # if topic_id == -1:  # outlier cluster
            # continue
        for word, score in topic_model.get_topic(topic_id)[:TOPICS_PER_CLUSTER]:
            print(f"Word: {word}, Score: {score}")
            key = word.strip().lower()
            if key and key not in seen:
                seen.add(key)
                topics.append(Topic(topic = word.strip(), score = 1 - round(float(score), 4)))
    return topics[:TOPICS_COUNT]


def _to_documents(pages: list[str]) -> list[str]:
    """Split text into paragraph-sized 'documents'"""
    
    # Strategy 1
    # TODO: Will this splitting work well for various layouts? Eg: Multi col text layour
    #       Note: after trying this in Strategy 3 with a 100 word limit, results seem decent
    #             We should keep the word limit as large as possible but the number of chunks should also be large
    #             So maybe instead of 100, we use a proportion of the total word count?
    #             TODO: Try the above proportionate splitting after refining model parameters
    # print("Splitting text into paragraphs")
    # paragraphs = re.split(r"\n\s*\n", text)
    # paragraphs = [p.strip() for p in paragraphs if len(p.split()) >= 20]
    # return paragraphs 
    
    # Strategy 2 (includes refactor in other files)
    # print(pages[0])
    # for i, page in enumerate(pages):
    #     print("Page", i, "word count:", len(page.split()))
        
    # Strategy 3 (somewhat mix of 1 & 2)
    paragraphs = []
    for page in pages:
        page_words = page.split()
        if len(page_words) < 20:
            paragraphs.append(page)
        else:
            JUMP_SIZE = 100 # TODO Rename
            for i in range(0, len(page_words), JUMP_SIZE):
                paragraph = " ".join(page_words[i:i+JUMP_SIZE])
                paragraphs.append(paragraph)
    
    return paragraphs 