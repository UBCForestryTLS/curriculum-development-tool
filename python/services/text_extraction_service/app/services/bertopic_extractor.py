import re

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

CUSTOM_STOP_WORDS = [
    "et al", "et. al.", "plot", "chart", "diagram", "graph"
    # Add more stop words as needed
]
# TODO: Even though it's supposedly better to pass these into the vectorizer,
#       I seem to have gotten better results by just filtering them out in postprocessing.
#       Should these just be moved back there then?

def extract(pages: list[str]) -> list[Topic]:
    """Extract topics from text using BERTopic.

    BERTopic clusters a set of documents, so the text is split into sentences and
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


def _dedupe_plurals(pages: list[str]) -> list[str]:
    """Remove plurals with NLP before performing BERTopic extraction"""
    # TODO: Should get a list of technical Forestry words perhaps to avoid filtering them out
    PAGE_BREAK = "<<<PAGE_BREAK>>>" # ! If this exact string appears in the text, it may cause extra splitting and suboptimal results
    print("Using NLP to filter out stop words and word variants")
    nlp_model = spacy.load("en_core_web_sm")
    text = PAGE_BREAK.join(pages)
    doc = nlp_model(text)
    filtered_text = " ".join([token.lemma_ for token in doc if not token.is_stop])
    # filtered_text = " ".join([token.lemma_ for token in doc])
    pages = filtered_text.split(PAGE_BREAK)
    return pages
    

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
