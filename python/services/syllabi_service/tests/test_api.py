from pathlib import Path

from fastapi.testclient import TestClient
from app.api.routes import app

client = TestClient(app)

TESTS_DIR = Path(__file__).parent

BAD_FILE_PATH = TESTS_DIR / 'data' / 'badFileExample.pdf'
VALID_FILE_PATH = TESTS_DIR / 'data' / 'syllabi' / 'TEST101_2025W1_PDF_Tabular_Assessments.pdf'

def test_api_non_post_request_create_course_from_syllabi():
    response = client.get("/create_course_from_syllabi")
    assert response.status_code == 405 
    
def test_api_missing_post_body_create_course_from_syllabi():
    response = client.post("/create_course_from_syllabi")
    assert response.status_code == 422
    
def test_api_incomplete_post_body_create_course_from_syllabi():
    response = client.post("/create_course_from_syllabi", files={})
    assert response.status_code == 422

def test_api_incorrect_request_file_name_create_course_from_syllabi():
    with open(VALID_FILE_PATH, 'rb') as valid_file:
        response = client.post("/create_course_from_syllabi", files={ 'bad-name': valid_file })
        assert response.status_code == 422

def test_api_invalid_request_file_create_course_from_syllabi():
    response = client.post("/create_course_from_syllabi", files={ 'file': 'foobar' })

    assert response.status_code == 200

    json_response = response.json()

    assert json_response["status"] == "error"
    assert "An error occurred while processing the request." == json_response["message"]
        
def test_invalid_file_type_create_course_from_syllabi():
    with open(BAD_FILE_PATH, "rb") as bad_file:
        response = client.post("/create_course_from_syllabi", files={ 'file': bad_file })

        assert response.status_code == 200

        json_response = response.json()

        print(json_response)

        assert json_response["status"] == "error"
        assert "An error occurred while processing the request." == json_response["message"]
    

def test_valid_file_create_course_from_syllabi():
    with open(VALID_FILE_PATH, 'rb') as valid_file:
        response = client.post("/create_course_from_syllabi", files={ 'file': valid_file })

        assert response.status_code == 200

        json_response = response.json()

        assert json_response["status"] == 'success'
        assert json_response["message"] == 'File processed successfully'

        data = json_response["data"]

        assert data['code'] == 'TEST'
        assert data['number'] == 101
        assert data['term'] == 'W1'
        assert data['year'] == 2025
        assert data['title'] == 'TEST Course Syllabus' 
        assert data['level'] == 'Undergraduate'
        assert data['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert data['goals'] == [
            'Explain the core concepts, theories, and terminology related to [subject area].', 
            'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'Evaluate and critique information or arguments using evidence-based reasoning.', 
            'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'Explore the connections between theoretical knowledge and real-world practice.', 
            'Integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert data['assessments'] == [
            ['Test Assignments', 27],
            ['Test participation', 3],
            ['Test Midterm', 40],
            ['Test Individual final Exam', 30]
        ]

        assert data["topics"] == [
            "Topics 1",
            "Topic 2",
            "Topic 3",
            "Topic 4",
            "Topic 5",
            "Topic 6",
            "Topic 7",
            "Topic 8",
            "Topic 9",
            "Topic 10",
            "Topic 11",
            "Topic 12",
            "Topic 13",
        ]

        assert data["materials"] == [
            {
                "name": "Textbook 1",
                "type": "textbook",
                "description": "Testing. This id the test textbook for the test syllabus",
            }
        ]
