import re
import math

from app.schemas import Topic

from bertopic import BERTopic
from bertopic.representation import KeyBERTInspired, MaximalMarginalRelevance
from sklearn.feature_extraction.text import CountVectorizer, ENGLISH_STOP_WORDS
from umap import UMAP
import spacy

from sentence_transformers import SentenceTransformer

TOPICS_COUNT = 100          # max topics returned overall
TOPICS_PER_CLUSTER = 10     # top words taken from each cluster
MIN_TOPIC_SIZE = 5         # min sentences to form a cluster (small docs need a low value)

WORDS_TO_JUMP_RATIO = 137 # Optimized with trial and error so far

PAGE_BREAK = "PAGE_BREAK" # ! If this exact string appears in the text, it may cause extra splitting and suboptimal results
CUSTOM_STOP_WORDS = [
    PAGE_BREAK,
    # Citations
    "et al", "et. al.",
    # Diagrams
    "plot", "chart", "diagram", "graph",
    # Units
    "cm", "mm", "m", "km", "ha", "m3", "m2", "m³", "m²", "g", "kg", "t", "tonne", "tonnes", "°C", "°F", "K"
]

def extract(text: str, min_topic_size = MIN_TOPIC_SIZE) -> list[Topic]:
    """Extract topics from text using BERTopic.

    BERTopic clusters a set of documents, so the text is split into pages/chunks and
    treated as the document set.
    """       
    
    print("BERTopic extraction starting...")

    deduped_pages = _dedupe_plurals(text)
    docs = _to_documents(deduped_pages)
    print(f"Split text into {len(docs)} documents for topic extraction")
    print("Extracting topics from text...")

    # This prevents stop words like "etc" and "the" from being counted as topics
    # vectorizer_model = CountVectorizer(stop_words="english", ngram_range=(1, 5), main_df=2)
    vectorizer_model = CountVectorizer(
                            stop_words=list(ENGLISH_STOP_WORDS.union(list(map(str.lower, CUSTOM_STOP_WORDS)))), 
                            ngram_range=(1, 5), 
                            min_df=1
                        )
    # TODO: Add a local copy of the model in case the HF repo is taken down
    embedding_model = SentenceTransformer("sentence-transformers/all-mpnet-base-v2")
    # representation_model = KeyBERTInspired(top_n_words=30)
    representation_model = MaximalMarginalRelevance(diversity=0.8)
    
    umap_model = UMAP(
        n_neighbors=15, 
        n_components=5, 
        min_dist=0.0, 
        metric='cosine', 
        # All the above arguments are default arguments. Only declaring this model
        # to set the random_state seed so that results are consistent across runs/tests.
        random_state=108
    )
    
    print("Set up embedding and representation models for BERTopic")

    topic_model = BERTopic(
        umap_model=umap_model,
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
        # print(f"Topic ID: {topic_id}, Name: {topic_model.get_topic(topic_id)}")
        for word, score in topic_model.get_topic(topic_id)[:TOPICS_PER_CLUSTER]:
            # print(f"Word: {word}, Score: {score}")
            key = word.strip().lower()
            if key and key not in seen:
                seen.add(key)
                topics.append(Topic(topic = word.strip(), score = 1 - round(float(score), 4)))
    return topics[:TOPICS_COUNT]

def _dedupe_plurals(text: str) -> str:
    """Remove plurals with NLP before performing BERTopic extraction"""
    print("Using NLP to filter out stop words and word variants")
    nlp_model = spacy.load("en_core_web_sm")
    nlp_model.tokenizer.add_special_case(PAGE_BREAK, [{"ORTH": PAGE_BREAK}])
    doc = nlp_model(text)
    filtered_text = " ".join([token.lemma_ for token in doc if not token.is_stop])
    return filtered_text
    

def _to_documents(text: str) -> list[str]:
    """Split text into paragraph-sized 'documents'"""
    
    word_count = len(text.split())
    # jump_size = round(word_count / WORDS_TO_JUMP_RATIO)
    jump_size = round(math.sqrt(word_count))
    
    paragraphs = []
    words = text.split()
    for i in range(0, len(words), jump_size):
        paragraph = " ".join(words[i:i+jump_size])
        paragraphs.append(paragraph)
        
    # for page in pages:
    #     page_words = page.split()
    #     # if len(page_words) < 20:
    #     #     paragraphs.append(page)
    #     # else:
    #     # TODO: Go back to no page logic, just handling text?
    #     for i in range(0, len(page_words), jump_size):
    #         paragraph = " ".join(page_words[i:i+jump_size])
    #         paragraphs.append(paragraph)
    
    return paragraphs 
