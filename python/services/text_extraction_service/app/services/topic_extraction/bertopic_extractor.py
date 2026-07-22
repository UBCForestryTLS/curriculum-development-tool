import math

from app.schemas import Topic

from bertopic import BERTopic
from bertopic.representation import MaximalMarginalRelevance
from sklearn.feature_extraction.text import CountVectorizer, ENGLISH_STOP_WORDS
import spacy

from sentence_transformers import SentenceTransformer

TOPICS_COUNT = 100          # max topics returned overall
TOPICS_PER_CLUSTER = 10     # top words taken from each cluster
MIN_TOPIC_SIZE = 5         # min sentences to form a cluster (small docs need a low value)

WORDS_TO_JUMP_RATIO = 137 # Optimized with trial and error so far
OVERLAP_PROPORTION = 0.25

PAGE_BREAK = "PAGE_BREAK" # ! If this exact string appears in the text, it may cause extra splitting and suboptimal results
CUSTOM_STOP_WORDS = [
    PAGE_BREAK,
    # Citations
    "et", "al", "et.", "al.",
    # Diagrams
    "plot", "chart", "diagram", "graph", "data", "datum", "figure"
    # Units
    "cm", "mm", "m", "km", "ha", "m3", "m2", "m³", "m²", "g", "kg", "t", "tonne", "tonnes", "°C", "°F", "K",
]

def extract(text: str, min_topic_size = MIN_TOPIC_SIZE) -> list[Topic]:
    """Extract topics from text using BERTopic.

    BERTopic clusters a set of documents, so the text is split into overlapping
    windows and treated as the document set.
    """       
    
    print("BERTopic extraction starting...")

    deduped_text = _dedupe_plurals(text)
    docs = _to_documents(deduped_text, OVERLAP_PROPORTION)
    doc_count = len(docs)
    print(f"Split text into {doc_count} documents for topic extraction")
    print("Extracting topics from text...")

    min_df_pct = 0.01  # Word must appear in at least 1% of the windows
    max_df_pct = 0.5  # Word must not appear in more than 50% of the windows

    min_df = max(2, round(doc_count * min_df_pct)) 

    max_df = max_df_pct if doc_count > 10 else 1.0 

    vectorizer_model = CountVectorizer(
                            stop_words=list(ENGLISH_STOP_WORDS.union(list(map(str.lower, CUSTOM_STOP_WORDS)))),
                            ngram_range=(1, 3),
                            min_df=min_df,
                            max_df=max_df,
                            lowercase=False, # This is useful to keep abbreviations in UPPERCASE, but can cause duplication
                        )
    # TODO: Add a local copy of the model in case the HF repo is taken down
    embedding_model = SentenceTransformer("sentence-transformers/all-mpnet-base-v2")
    representation_model = MaximalMarginalRelevance(diversity=0.8)
    
    print("Set up embedding and representation models for BERTopic")

    topic_model = BERTopic(
        embedding_model=embedding_model,
        vectorizer_model=vectorizer_model,
        representation_model=representation_model,
        min_topic_size=max(min_topic_size, 2),
        calculate_probabilities=False,
        verbose=True,
    )
    
    print("Configured BERTopic model, fitting to documents...")
    
    try:
        topic_model.fit_transform(docs)
    except Exception as e:
        print(f"BERTopic extraction failed: {e}")
    finally:
        print("Points:", topic_model.get_document_info(docs).shape[0], len(docs))

    topics: list[Topic] = []
    seen: set[str] = set()
    
    print("Extracting topics from fitted model...")
    
    for topic_id in topic_model.get_topic_info()["Topic"]:
        print("")
        for word, score in topic_model.get_topic(topic_id)[:TOPICS_PER_CLUSTER]:
            key = word.strip().lower()
            if key and key not in seen:
                seen.add(key)
                topics.append(Topic(topic = word.strip(), score = round(float(score), 4)))
    return topics[:TOPICS_COUNT]

def _dedupe_plurals(text: str) -> str:
    """Lemmatize tokens to group plurals and other variants of the same word together"""
    print("Lemmatizing text to deduplicate plurals...")
    nlp_model = spacy.load("en_core_web_sm")
    nlp_model.tokenizer.add_special_case(PAGE_BREAK, [{"ORTH": PAGE_BREAK}])
    doc = nlp_model(text)
    return " ".join([token.lemma_ for token in doc])
    

def _to_documents(text: str, overlap_proportion: float) -> list[str]:
    word_count = len(text.split())
    window_size = max(round(math.sqrt(word_count)), 20)
    step = max(round(window_size * (1 - overlap_proportion)), 1)

    paragraphs = []
    words = text.split()
    for i in range(0, len(words), step):
        paragraph = " ".join(words[i:i + window_size])
        paragraphs.append(paragraph)

    return paragraphs
