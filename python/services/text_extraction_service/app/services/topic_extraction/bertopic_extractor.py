import re
import math

from app.schemas import Topic

from bertopic import BERTopic
from bertopic.representation import KeyBERTInspired
from sklearn.feature_extraction.text import CountVectorizer, ENGLISH_STOP_WORDS
import spacy

from sentence_transformers import SentenceTransformer

TOPICS_COUNT = 100          # max topics returned overall
TOPICS_PER_CLUSTER = 10     # top words taken from each cluster
MIN_SENTENCE_WORDS = 5     # drop sentence fragments shorter than this
MIN_TOPIC_SIZE = 5         # min sentences to form a cluster (small docs need a low value)

WORDS_TO_JUMP_RATIO = 137 # Optimized with trial and error so far

CUSTOM_STOP_WORDS = [
    "et al", "et. al.", "plot", "chart", "diagram", "graph"
    # Add more stop words as needed
]
# TODO: Even though it's supposedly better to pass these into the vectorizer,
#       I seem to have gotten better results by just filtering them out in postprocessing.
#       Should these just be moved back there then?

def extract(pages: list[str]) -> list[Topic]:
    """Extract topics from text using BERTopic.

    BERTopic clusters a set of documents, so the text is split into pages/chunks and
    treated as the document set.
    """       
    
    print("BERTopic extraction starting...")

    deduped_pages = _dedupe_plurals(pages)
    docs = _to_documents(deduped_pages)
    print(f"Split text into {len(docs)} documents for topic extraction")
    # if len(docs) < MIN_TOPIC_SIZE:
    #     # TODO: Re-evaluate this
    #     return []
    print("Extracting topics from text...")

    # This prevents stop words like "etc" and "the" from being counted as topics
    # vectorizer_model = CountVectorizer(stop_words="english", ngram_range=(1, 5), main_df=2)
    vectorizer_model = CountVectorizer(stop_words=list(ENGLISH_STOP_WORDS.union(CUSTOM_STOP_WORDS)), ngram_range=(1, 5), min_df=1)
    # TODO: Add a local copy of the model in case the HF repo is taken down
    embedding_model = SentenceTransformer("sentence-transformers/all-mpnet-base-v2")
    # embedding_model = SentenceTransformer("sentence-transformers/all-MiniLM-L6-v2")
    # embedding_model = SentenceTransformer("ViktorDo/EcoBERT-Pretrained")
    representation_model = KeyBERTInspired(top_n_words=30)
    
    print("Set up embedding and representation models for BERTopic")

    topic_model = BERTopic(
        embedding_model=embedding_model,
        vectorizer_model=vectorizer_model,
        representation_model=representation_model,
        min_topic_size= max(MIN_TOPIC_SIZE, 2),
        # min_topic_size= max(len(docs), 2) if len(docs) < MIN_TOPIC_SIZE else MIN_TOPIC_SIZE,
        calculate_probabilities=False,
        verbose=True,
    )
    
    print("Configured BERTopic model, fitting to documents...")
    
    try:
        topic_model.fit_transform(docs)
    except Exception as e:
        pass
    finally:
        print("Points:", topic_model.get_document_info(docs).shape[0], len(docs))

    topics: list[Topic] = []
    seen: set[str] = set()
    
    print("Extracting topics from fitted model...")
    
    for topic_id in topic_model.get_topic_info()["Topic"]:
        print("")
        # print(f"Topic ID: {topic_id}, Name: {topic_model.get_topic(topic_id)}")
        for word, score in topic_model.get_topic(topic_id)[:TOPICS_PER_CLUSTER]:
            print(f"Word: {word}, Score: {score}")
            key = word.strip().lower()
            if key and key not in seen:
                seen.add(key)
                topics.append(Topic(topic = word.strip(), score = 1 - round(float(score), 4)))
    return topics[:TOPICS_COUNT]


def _dedupe_plurals(pages: list[str]) -> list[str]:
    """Remove plurals with NLP before performing BERTopic extraction"""
    # TODO: Should get a list of technical Forestry words perhaps to avoid filtering them out
    PAGE_BREAK = "<<<PAGE_BREAK>>>" # ! If this exact string appears in the text, it may cause extra splitting and suboptimal results
    print("Using NLP to filter out stop words and word variants")
    nlp_model = spacy.load("en_core_web_sm")
    nlp_model.tokenizer.add_special_case(PAGE_BREAK, [{"ORTH": PAGE_BREAK}])
    print(pages)
    text = PAGE_BREAK.join(pages)
    doc = nlp_model(text)
    # print("Non filtered text:", (text))
    filtered_text = " ".join([token.lemma_ for token in doc if not token.is_stop])
    # print("Filtered text:", (filtered_text))
    # filtered_text = " ".join([token.lemma_ for token in doc])
    pages = filtered_text.split(PAGE_BREAK)
    return pages
    

def _to_documents(pages: list[str]) -> list[str]:
    """Split text into paragraph-sized 'documents'"""
    
    # Strategy 1
        
    # Strategy 3 (somewhat mix of 1 & 2)
    word_count = sum(len(page.split()) for page in pages)
    # jump_size = round(word_count / WORDS_TO_JUMP_RATIO)
    jump_size = round(math.sqrt(word_count))
    
    paragraphs = []
    for page in pages:
        page_words = page.split()
        if len(page_words) < 20:
            paragraphs.append(page)
        else:
            # jump_size = 100
            for i in range(0, len(page_words), jump_size):
                paragraph = " ".join(page_words[i:i+jump_size])
                paragraphs.append(paragraph)
    
    return paragraphs 
