from datetime import date

import app.services.syllabus_parser as sp
import pytest
from pathlib import Path

TESTS_DIR = Path(__file__).parent

BAD_FILE_PATH = TESTS_DIR / 'data' / 'badFileExample.pdf'

EXPECTED_TEST_TOPICS = [
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

EXPECTED_TEST_MATERIALS = [
    {
        "name": "Textbook 1",
        "type": "textbook",
        "description": "Testing. This id the test textbook for the test syllabus",
    }
]

def test_topic_section_stops_on_start_page():
    doc = [
        "Schedule of Topics\nClimate Change\nAssessment Methods",
        "Unrelated content",
    ]

    assert sp.find_topic_section_page_indices(doc) == [0]

def test_parser_bad_file_raises_error():
    with open(BAD_FILE_PATH, 'rb') as bad_file:
        with pytest.raises(Exception, match="Error opening file"):
            sp.get_course_from_file_contents(bad_file.read(), BAD_FILE_PATH.name)

def test_parser_no_contents_raises_error():
    with pytest.raises(Exception, match="Error opening file"):
        sp.get_course_from_file_contents(bytes(), "no-contents.pdf")

""" def test_syllabi_word_template_with_tabular_assessments():
    file_path = TESTS_DIR /"data"/"syllabi"/"TEST101_2025W1_Word_Tabular_Assessments.docx"
    file_name = "TEST101_2025W1_Word_Tabular_Assessments.docx"
    course_instance = sp.get_course_from_text_file(file_path, file_name)
    assert course_instance['code'] == 'TEST'
    assert course_instance['number'] == 101
    assert course_instance['term'] == 'W1'
    assert course_instance['year'] == 2025
    assert course_instance['title'] == 'Acknowledgement' # Tables are not detected in .docx files, 
                                                         # no other title pattern is folled in the file
    assert course_instance['level'] == 'Undergraduate'
    assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject \narea], focusing on the key concepts, issues, and practices that shape \nthe field. Students will explore the historical background, current \ntrends, and future directions of [subject area], engaging with a \nvariety of perspectives and resources. The course blends lectures, \ndiscussions, and applied activities to help students understand how \nideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational \ntheories as well as contemporary approaches, gaining insight into \nthe ways [subject area] influences academic research, industry \npractice, and everyday life. The course also offers opportunities to \nwork with real-world examples and case studies, encouraging \nstudents to make connections between abstract concepts and \npractical applications. \nThis description outlines the scope and nature of the course, \nproviding students with a clear sense of the themes and topics that \nwill be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the \nsyllabus.'
    assert course_instance['goals'] == ['Explain the core concepts, theories, and terminology related to [subject area].',
                                        'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].',
                                        'Evaluate and critique information or arguments using evidence- based reasoning.',
                                        'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.',
                                        'Explore the connections between theoretical knowledge and real- world practice.',
                                        'Integrate knowledge gained in class with real-world or interdisciplinary contexts.']
    assert course_instance['assessments'] == [('Test Assignments', 27), 
                                              ('Test participation', 3), 
                                              ('Test Midterm', 40), 
                                              ('Test Individual final Exam', 30)] # Tests the clean_assessment_and_weights function to remove multiple "\n" in assessment names
    assert course_instance['topics'] == []
    assert course_instance['materials'] == [] """

""" def test_syllabi_word_template_with_assessments_with_brackets():
    file_path = TESTS_DIR /"data"/"syllabi"/"TEST101_2025W1_Word_Assessments_with_brackets.docx"
    file_name = "TEST101_2025W1_Word_Assessments_with_brackets.docx"
    course_instance = sp.get_course_from_text_file(file_path, file_name)
    assert course_instance['code'] == 'TEST'
    assert course_instance['number'] == 101
    assert course_instance['term'] == 'W1'
    assert course_instance['year'] == 2025
    assert course_instance['title'] == 'Acknowledgement' # Tables are not detected in .docx files, 
                                                         # no other title pattern is folled in the file
    assert course_instance['level'] == 'Undergraduate'
    assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject \narea], focusing on the key concepts, issues, and practices that shape \nthe field. Students will explore the historical background, current \ntrends, and future directions of [subject area], engaging with a \nvariety of perspectives and resources. The course blends lectures, \ndiscussions, and applied activities to help students understand how \nideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational \ntheories as well as contemporary approaches, gaining insight into \nthe ways [subject area] influences academic research, industry \npractice, and everyday life. The course also offers opportunities to \nwork with real-world examples and case studies, encouraging \nstudents to make connections between abstract concepts and \npractical applications. \nThis description outlines the scope and nature of the course, \nproviding students with a clear sense of the themes and topics that \nwill be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the \nsyllabus.'
    assert course_instance['goals'] == ['Explain the core concepts, theories, and terminology related to [subject area].',
                                        'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].',
                                        'Evaluate and critique information or arguments using evidence- based reasoning.',
                                        'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.',
                                        'Explore the connections between theoretical knowledge and real- world practice.',
                                        'Integrate knowledge gained in class with real-world or interdisciplinary contexts.']
    assert course_instance['assessments'] == [('Test Assignments', 27), 
                                              ('Test participation', 3), 
                                              ('Test Midterm', 40), 
                                              ('Test Individual final Exam', 30)]
    assert course_instance['topics'] == []
    assert course_instance['materials'] == [] """

def test_parser_pdf_template_with_tabular_assessments():
    file_path = TESTS_DIR / "data" / "syllabi" / "TEST101_2025W1_PDF_Tabular_Assessments.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W1'
        assert course_instance['year'] == 2025
        assert course_instance['title'] == 'TEST Course Syllabus' 
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert course_instance['goals'] == [
            'Explain the core concepts, theories, and terminology related to [subject area].', 
            'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'Evaluate and critique information or arguments using evidence-based reasoning.', 
            'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'Explore the connections between theoretical knowledge and real-world practice.', 
            'Integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27),                           
            ('Test participation', 3),                           
            ('Test Midterm', 40),                             
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == EXPECTED_TEST_MATERIALS


def test_parser_pdf_template_CLOs_without_bullets():
    file_path = TESTS_DIR / "data"/ "syllabi"/ "TEST101_2025W1_PDF_Without_Bullet_CLOs.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W1'
        assert course_instance['year'] == 2025
        assert course_instance['title'] == 'TEST Course Syllabus' 
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert course_instance['goals'] == [
            'Explain the core concepts, theories, and terminology related to [subject area].',                  
            'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].',                        
            'Evaluate and critique information or arguments using evidence-based reasoning.',                        
            'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.',                        
            'Explore the connections between theoretical knowledge and real-world practice.',                         
            'Integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27), 
            ('Test participation', 3), 
            ('Test Midterm', 40), 
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == EXPECTED_TEST_MATERIALS
    

def test_parser_pdf_template_without_course_title_table():
    file_path = TESTS_DIR / "data" / "syllabi" / "TEST101_2025W1_Without_Course_Title_table.pdf"
    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W1'
        assert course_instance['year'] == 2025
        assert course_instance['title'] == 'COURSE SYLLABUS' #Course code is removed from the title
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert course_instance['goals'] == [
            'Explain the core concepts, theories, and terminology related to [subject area].', 
            'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'Evaluate and critique information or arguments using evidence-based reasoning.', 
            'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'Explore the connections between theoretical knowledge and real-world practice.', 
            'Integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27), 
            ('Test participation', 3), 
            ('Test Midterm', 40), 
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == EXPECTED_TEST_MATERIALS
    
def test_parser_file_name_without_course_code_number_and_term():
    file_path = TESTS_DIR / "data" / "syllabi" / "FileNameWithoutCode&Term.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W2'
        assert course_instance['year'] == date.today().year
        assert course_instance['title'] == 'COURSE SYLLABUS' #Course code is removed from the title
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert course_instance['goals'] == [
            'Explain the core concepts, theories, and terminology related to [subject area].', 
            'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'Evaluate and critique information or arguments using evidence-based reasoning.', 
            'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'Explore the connections between theoretical knowledge and real-world practice.', 
            'Integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27), 
            ('Test participation', 3), 
            ('Test Midterm', 40), 
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == EXPECTED_TEST_MATERIALS


def test_parser_file_name_without_course_code_number_term_and_multiple_codes_in_file():
    file_path = TESTS_DIR / "data" / "syllabi" / "FilenameWithoutCode&MultipleCodeInFile.pdf"
    
    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W2'
        assert course_instance['year'] == date.today().year
        assert course_instance['title'] == 'TEST Course Syllabus'
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert course_instance['goals'] == [
            'Explain the core concepts, theories, and terminology related to [subject area].', 
            'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'Evaluate and critique information or arguments using evidence-based reasoning.', 
            'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'Explore the connections between theoretical knowledge and real-world practice.', 
            'Integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27), 
            ('Test participation', 3), 
            ('Test Midterm', 40), 
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == EXPECTED_TEST_MATERIALS
    
    
def test_parser_different_layout():
    file_path = TESTS_DIR / "data" / "syllabi" / "DifferentLayoutSyllabus.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W2'
        assert course_instance['year'] == 2025
        assert course_instance['title'] == 'TEST 101 2025W2: Test Course Syllabus'
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'
        
        assert course_instance['goals'] == [
            'To explain the core concepts, theories, and terminology related to [subject area].', 
            'To apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'To evaluate and critique information or arguments using evidence-based reasoning.', 
            'To communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'To explore the connections between theoretical knowledge and real-world practice.', 
            'To integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27), 
            ('Test participation', 3), 
            ('Test Midterm', 40), 
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == []
    
    
def test_parser_different_layout_assessments_percentage_seperated():
    file_path = TESTS_DIR / "data" / "syllabi" / "DifferentLayoutSyllabusWithComponentsPrecentageSeperated.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W2'
        assert course_instance['year'] == 2025
        assert course_instance['title'] == 'TEST 101 2025W2: Test Course Syllabus'
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert course_instance['goals'] == [
            'To explain the core concepts, theories, and terminology related to [subject area].', 
            'To apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'To evaluate and critique information or arguments using evidence-based reasoning.', 
            'To communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'To explore the connections between theoretical knowledge and real-world practice.', 
            'To integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('test assignments', 27), 
            ('test participation', 3), 
            ('test midterm', 40), 
            ('test individual final exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == []
    

def test_parser_description_with_additional_section():
    file_path = TESTS_DIR / "data" / "syllabi" / "TEST101_Syllabus_Description_With_Additional_Section.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W2'
        assert course_instance['year'] == date.today().year
        assert course_instance['title'] == 'TEST Course Syllabus'
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus. \nTarget Audience \nThis course is intended for undergraduates who are interested in gaining knowledge and skills in \n[subject area]. It is especially suitable for students enrolled in forestry programs, as well as \nthose from related fields seeking to broaden their understanding of the topic. No prior experience \nin [subject area] is required unless otherwise noted in the prerequisites.'

        assert course_instance['goals'] == [
            'Explain the core concepts, theories, and terminology related to [subject area].', 
            'Apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].', 
            'Evaluate and critique information or arguments using evidence-based reasoning.', 
            'Communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.', 
            'Explore the connections between theoretical knowledge and real-world practice.', 
            'Integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27), 
            ('Test participation', 3), 
            ('Test Midterm', 40), 
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == EXPECTED_TEST_MATERIALS
    


def test_parser_CLOs_with_prefixed():
    file_path = TESTS_DIR / "data" / "syllabi" / "TEST101_2025W1_CLOs_with_prefixes.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['code'] == 'TEST'
        assert course_instance['number'] == 101
        assert course_instance['term'] == 'W1'
        assert course_instance['year'] == 2025
        assert course_instance['title'] == 'TEST Course Syllabus'
        assert course_instance['level'] == 'Undergraduate'
        assert course_instance['description'] == 'This course provides a comprehensive introduction to [subject area], focusing on the key \nconcepts, issues, and practices that shape the field. Students will explore the historical \nbackground, current trends, and future directions of [subject area], engaging with a variety of \nperspectives and resources. The course blends lectures, discussions, and applied activities to help \nstudents understand how ideas in this domain are developed, debated, and implemented. \nThroughout the term, students will gain exposure to foundational theories as well as \ncontemporary approaches, gaining insight into the ways [subject area] influences academic \nresearch, industry practice, and everyday life. The course also offers opportunities to work with \nreal-world examples and case studies, encouraging students to make connections between \nabstract concepts and practical applications. \nThis description outlines the scope and nature of the course, providing students with a clear sense \nof the themes and topics that will be covered. Specific learning objectives, assessment criteria, \nand expected outcomes are detailed in separate sections of the syllabus.'

        assert course_instance['goals'] == [
            'Be able to explain the core concepts, theories, and terminology related to [subject area].',
            'Be able to apply appropriate methods, tools, or frameworks to analyze problems and develop solutions within [subject area].',
            'Be able to evaluate and critique information or arguments using evidence-based reasoning.',
            'Be able to  communicate ideas and findings effectively in written, oral, or visual formats appropriate to the field.',
            'Be able to explore the connections between theoretical knowledge and real-world practice.',
            'Be able to integrate knowledge gained in class with real-world or interdisciplinary contexts.'
        ]

        assert course_instance['assessments'] == [
            ('Test Assignments', 27), 
            ('Test participation', 3), 
            ('Test Midterm', 40), 
            ('Test Individual final Exam', 30)
        ]

        assert course_instance['topics'] == EXPECTED_TEST_TOPICS
        assert course_instance['materials'] == EXPECTED_TEST_MATERIALS

# ===========================
# TOPIC & MATERIAL EXTRACTION
# ===========================
def test_parser_no_topics_and_material():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_Syllabus_no_topics_and_materials.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [] #concrete topics not included, either leave blank or use fallback 
        assert course_instance['materials'] == [] #no material

def test_parser_syllabus_with_topic_num_and_title():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_Syllabus_with_topic#_name_and_module.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [
            "Urban ecology",
            "Introduction to urban ecosystems",
            "Cities, biodiversity and ecological systems",
            "Urban forests and green corridors",
            "Climate resilience in cities",
            "Sustainable infrastructure",
            "Environmental planning",
            "Communication & engagement",
            "Professional development",
            "Stormwater systems and water management",
            "Green roofs and sustainable design",
            "Transportation systems and sustainability",
            "Land-use planning and environmental policy",
            "Environmental monitoring and indicators",
            "Community engagement and urban planning",
            "Environmental communication strategies",
            "Public outreach and sustainability campaigns",
            "Communicating scientific uncertainty",
            "Research and scholarly communication",
            "Ethics and professionalism",
            "Collaborative problem solving",
        ]

        assert course_instance['materials'] == [
            {
                "name": "Foster, R. & Nguyen, T. (2022). Urban Ecology and Sustainable Landscapes: Environmental Systems in Modern Cities. West Coast Academic Press.",
                "type": "textbook",
                "description": "The course textbook",
            }
        ]

def test_parser_syllabus_with_course_and_lab_topics():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_Syllabus_with_course_and_lab_topics.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [
            "Introduction to urban ecology",
            "Cities, biodiversity & ecosystems",
            "Urban Vegetation I - Trees & shrubs",
            "Environmental monitoring methods",
            "Urban Vegetation II - Ground cover",
            "Climate resilience and adaptation",
            "Urban biodiversity assessment",
            "Green infrastructure systems",
            "Field activity week",
            "Urban water systems",
            "Landscape planning & sustainability",
            "Urban planning workshop",
            "Environmental policy and governance",
            "Community engagement activity",
            "Ecological restoration",
            "Urban ecology field observations",
            "Environmental communication",
            "Research project support",
            "Future cities and sustainability",
        ]

        assert course_instance['materials'] == [
            {
                "name": "Sanders, P. & Hill, J. 2021. Urban Ecology and Sustainable Systems. Northern Academic Press.",
                "type": "text",
                "description": "Optional text for lectures",
            },
            {
                "name": "Carter, L. 2018. Field Guide to Urban Ecosystems. Pacific Environmental Publications.",
                "type": "text",
                "description": "Optional text for labs",
            },
            {
                "name": "UBC Library environmental databases",
                "type": "digital resource",
                "description": "Digital resources",
            },
            {
                "name": "Urban Ecology Research Network resources",
                "type": "digital resource",
                "description": "Digital resources",
            },
            {
                "name": "E-Flora BC and environmental mapping portals",
                "type": "digital resource",
                "description": "Digital resources",
            },
        ]


def test_parser_table_of_schedule_and_messy_material():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_Syllabus_table_of_sched_and_messy_written_material.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [
            "Course introduction and syllabus overview",
            "Understanding sustainability and environmental",
            "Public perceptions of environmental change",
            "Guest lecture & discussion: Environmental jour",
            "Media narratives and environmental storytelling",
            "Workshop: Communicating scientific uncertaint",
            "Social media and environmental campaigns",
            "Visual communication and infographic design",
            "Climate misinformation and public trust",
            "Workshop: Designing communication materials",
            "Community engagement and participatory outre",
            "Guest lecture: Environmental policy communica",
            "Urban resilience and future planning",
            "Workshop: Scenario planning activities",
            "Environmental ethics and emerging technologie",
            "Course wrap-up and future directions",
        ]

        assert course_instance['materials'] == [
            {
                "name": "Morrison, L. & Patel, R. (2021). Communicating Environmental Futures: Media, Sustainability and Public Action. Pacific Northwest Academic Press.",
                "type": "textbook",
                "description": "The course textbook",
            }
        ]


def test_parser_syllabus_no_topics_table():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_Syllabus_no_topics_table.pdf"


    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [
            "Introduction to urban ecosystems",
            "Climate adaptation and sustainability planning",
            "Urban biodiversity and ecological connectivity",
            "Green infrastructure systems",
            "Indigenous stewardship perspectives",
            "Environmental monitoring and resilience indicators",
            "Urban water systems and watershed management",
            "Environmental governance and policy",
            "Ecological restoration strategies",
            "Community resilience and adaptation",
            "Environmental communication and engagement",
            "Urban forestry and climate mitigation",
            "Nature-based climate solutions",
            "Sustainable transportation systems",
            "Global environmental change and adaptation",
            "Environmental justice and equity",
            "Urban sustainability planning",
            "Case studies in ecosystem resilience",
            "Future cities and environmental innovation",
        ]

        assert course_instance['materials'] == [
            {
                "name": "Foster, H. & Kim, R. 2022. Urban Ecology and Environmental Resilience. Pacific Academic Press.",
                "type": "textbook",
                "description": "Recommended literature sources",
            },
            {
                "name": "Martin, S. 2020. Climate Adaptation in Cities. Greenleaf Publishing.",
                "type": "textbook",
                "description": "Recommended literature sources",
            },
        ]

def test_parser_syllabus_no_topic_keyword():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_Syllabus_no_topic_keyword_and_bulletList_of_material.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [
            "Environmental Foundations",
            "Introduction to Environmental Systems",
            "Ecosystem Components and Interactions",
            "Climate Systems and Feedback Loops",
            "Human Impacts on Landscapes",
            "Spatial Analysis",
            "Introduction to GIS",
            "Map Projections and Spatial Scale",
            "Remote Sensing Fundamentals",
            "Spectral Data and Image Interpretation",
            "Spatial Data Visualization",
            "Environmental Applications",
            "Forest Disturbance Mapping",
            "Urban Heat Island Analysis",
            "Watershed Monitoring",
            "Wildlife Habitat Analysis",
            "Environmental Risk Assessment",
            "Climate Adaptation Strategies",
            "Environmental Policy and Data Ethics",
            "Future of Environmental Monitoring",
        ]

        assert course_instance['materials'] == [
            {
                "name": "Introduction to Environmental Systems, 3rd Edition.",
                "type": "textbook",
                "description": "Required Textbook",
            },
            {
                "name": "Access to ArcGIS Online and QGIS software.",
                "type": "software",
                "description": "Course Materials",
            },
            {
                "name": "Scientific calculator recommended for lab activities.",
                "type": "equipment",
                "description": "Course Materials",
            },
            {
                "name": "Selected journal articles and case studies posted throughout the term.",
                "type": "article",
                "description": "Course Materials",
            },
        ]

def test_parser_syllabus_multiple_material_types():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_Syllabus_multuple_material_types.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [
            "Introduction to environmental communication",
            "Climate storytelling and sustainability media",
            "Environmental journalism and public engagement",
            "Digital campaigns and environmental advocacy",
        ]

        assert course_instance['materials'] == [
            {
                "name": "Lawson, M. & Rivera, T. (2023). Environmental Communication in a Changing World. Pacific Coast Academic Press.",
                "type": "textbook",
                "description": "Required Textbook",
            },
            {
                "name": "Climate Futures: Cities Under Pressure (documentary series)",
                "type": "video",
                "description": "Required Documentary / Video Content",
            },
            {
                "name": "Weekly lecture videos posted on Canvas",
                "type": "video",
                "description": "Required Documentary / Video Content",
            },
            {
                "name": "The Sustainability Exchange podcast, selected episodes",
                "type": "podcast",
                "description": "Podcast and Audio Media",
            },
            {
                "name": "Government of Canada climate data portal",
                "type": "website",
                "description": "Websites and Online Articles",
            },
            {
                "name": "Selected articles from National Geographic and The Narwhal",
                "type": "article",
                "description": "Websites and Online Articles",
            },
        ]

def test_parser_table_spanning_multiple_pages():
    file_path = TESTS_DIR / "data" / "syllabi" / "Test_syllabus_table_spanning_multiple_pages.pdf"

    with open(file_path, 'rb') as syllabus_file:
        course_instance = sp.get_course_from_file_contents(syllabus_file.read(), file_path.name)

        assert course_instance['topics'] == [
            "Introduction and course overview",
            "Design goals for distributed systems",
            "Layering and abstractions",
            "Socket programming basics",
            "Client-server applications",
            "Application protocols and APIs",
            "Naming and discovery",
            "Caching and content delivery",
            "Reliable message delivery",
            "Transport layer concepts",
            "Timeouts and retransmission",
            "Windowing protocols",
            "Congestion control",
            "Network layer introduction",
            "Addressing and forwarding",
            "Routing algorithms",
            "Distance vector routing",
            "Link state routing",
            "Network security introduction",
            "Authentication protocols",
            "Encryption and TLS",
            "Cloud networking",
            "Distributed storage systems",
            "Consensus and replication",
            "Fault tolerance",
            "Monitoring and observability",
        ]

        assert course_instance['materials'] == [
            {
                "name": "Chen, R. and Wallace, P. Distributed Systems and Network Applications, 2nd Edition. Additional readings and tutorial resources will be posted on Canvas.",
                "type": "textbook",
                "description": "Required textbook",
            },
        ]
