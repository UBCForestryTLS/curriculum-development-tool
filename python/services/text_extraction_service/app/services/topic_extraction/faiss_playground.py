import httpx
import faiss
import numpy as np
from sentence_transformers import SentenceTransformer


# ------------------------------------------------------------
# Config
# ------------------------------------------------------------
LARAVEL_TOPIC_URL = "http://127.0.0.1:8000/api/microservices/text-extraction/course/4/topics"  # replace with your real endpoint
EMBED_MODEL = "all-mpnet-base-v2"
SIM_THRESHOLD = 0.45


# ------------------------------------------------------------
# Fetch topics from Laravel
# ------------------------------------------------------------
def fetch_topics():
    # resp = httpx.get(LARAVEL_TOPIC_URL, timeout=10)
    # resp.raise_for_status()
    # topics = resp.json()

    # if not isinstance(topics, list):
    #     raise ValueError("Laravel /topics endpoint must return a JSON list of strings.")
    topics = [
        "basal area",
        "biomass: amount of organic (Carbon) matter in a given ecosystem, linked to Carbon Storage",
        "stand density: number of trees per unit area, used to measure richness of forest",
        "trees"
    ]
    return topics
    


# ------------------------------------------------------------
# Build FAISS index
# ------------------------------------------------------------
class TopicMatcher:
    def __init__(self, topics):
        self.model = SentenceTransformer(EMBED_MODEL)
        self.topics = topics

        # Embed topics
        self.topic_embs = self.model.encode(topics, normalize_embeddings=True)
        dim = self.topic_embs.shape[1]

        # Build FAISS index (cosine similarity via normalized embeddings)
        self.index = faiss.IndexFlatIP(dim)
        self.index.add(self.topic_embs)

    def match(self, text):
        """Return (score, topic) for the best match."""
        emb = self.model.encode([text], normalize_embeddings=True)
        scores, idxs = self.index.search(emb, k=1)

        score = float(scores[0][0])
        topic = self.topics[idxs[0][0]]
        return score, topic


# ------------------------------------------------------------
# Demo
# ------------------------------------------------------------
def main():
    print("Fetching topics from Laravel…")
    topics = fetch_topics()
    print(f"Loaded {len(topics)} topics.")

    matcher = TopicMatcher(topics)

    # Try some sample text
    samples = [
        "The forest stand density increased after thinning operations.",
        "Carbon storage potential rises with improved soil management.",
        "Wildfire risk assessment requires updated fuel models."
    ]

    print("\nSemantic matches:")
    for text in samples:
        score, topic = matcher.match(text)
        label = topic if score >= SIM_THRESHOLD else "NO MATCH"
        print(f"- Text: {text}")
        print(f"  Score: {score:.3f}")
        print(f"  Topic: {label}\n")


if __name__ == "__main__":
    main()
