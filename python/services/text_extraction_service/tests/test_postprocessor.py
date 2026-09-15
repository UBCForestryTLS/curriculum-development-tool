import pytest

from app.schemas import Topic, TopicSource
from app.services.topic_extraction.postprocessor import process, union

class TestProcess:
    def test_filters_by_word_count_and_char_length(self):
        # MIN_WORD_COUNT = 1
        # MAX_WORD_COUNT = 8
        # Modify test based on constants in postprocessor.py
        topic_9_words = Topic(topic="sap sap sap sap sap sap sap sap sap", score=0.9, source=TopicSource.KEYWORD)
        topic_8_words = Topic(topic="pot pot pot pot pot pot pot pot", score=0.9, source=TopicSource.KEYWORD)
        topics = [
            topic_9_words,
            topic_8_words,
            Topic(topic="Forestry", score=0.9, source=TopicSource.KEYWORD),
            Topic(topic="four", score=0.9, source=TopicSource.KEYWORD),
        ]
        result = process(topics, minTopicCharCount=5, scoreThreshold=0.0)
        assert set(result) == set([
                                    topic_8_words, 
                                    Topic(topic="Forestry", score=0.9, source=TopicSource.KEYWORD)
                                   ])

    def test_filters_lowercase_single_words_when_enabled(self):
        topics = [
            Topic(topic="forest", score=0.9, source=TopicSource.KEYWORD),
            Topic(topic="Forest", score=0.9, source=TopicSource.KEYWORD),
        ]
        result = process(topics, filterLowerCaseSingleWords=True, minTopicCharCount=3, scoreThreshold=0.0)
        assert set(result) == set([Topic(topic="Forest", score=0.9, source=TopicSource.KEYWORD)])

    def test_deduplicates_case_insensitive(self):
        topics = [
            Topic(topic="Forest", score=0.9, source=TopicSource.KEYWORD),
            Topic(topic="forest", score=0.8, source=TopicSource.MATCH),
        ]
        result = process(topics, scoreThreshold=0.0)
        assert set(result) == set([Topic(topic="Forest", score=0.9, source=TopicSource.KEYWORD)])

    def test_removes_numbers_and_url_fragments(self):
        topics = [
            Topic(topic="Chapter 1", score=0.9, source=TopicSource.KEYWORD),
            Topic(topic="www.example.com", score=0.9, source=TopicSource.KEYWORD),
            Topic(topic="image.jpg", score=0.9, source=TopicSource.KEYWORD),
            Topic(topic="Valid Topic", score=0.9, source=TopicSource.KEYWORD),
        ]
        result = process(topics, scoreThreshold=0.0)
        assert set(result) == set([Topic(topic="Valid Topic", score=0.9, source=TopicSource.KEYWORD)])

    def test_filters_by_score_threshold(self):
        topics = [
            Topic(topic="Economy", score=0.59, source=TopicSource.KEYWORD),
            Topic(topic="Ecology", score=0.6, source=TopicSource.KEYWORD),
            Topic(topic="Equality", score=0.61, source=TopicSource.KEYWORD),
        ]
        result = process(topics, scoreThreshold=0.6)
        assert set(result) == set([
                                    Topic(topic="Ecology", score=0.6, source=TopicSource.KEYWORD), 
                                    Topic(topic="Equality", score=0.61, source=TopicSource.KEYWORD)
                                   ])


class TestUnion:
    def test_merges_and_deduplicates(self):
        list1 = [Topic(topic="A", score=0.5, source=TopicSource.KEYWORD)]
        list2 = [Topic(topic="B", score=0.5, source=TopicSource.MATCH)]
        list3 = [Topic(topic="a", score=0.5, source=TopicSource.FONT)]
        result = union(list1, list2, list3)
        assert set(result) == set([
            Topic(topic="A", score=0.5, source=TopicSource.KEYWORD),
            Topic(topic="B", score=0.5, source=TopicSource.MATCH)
        ])
