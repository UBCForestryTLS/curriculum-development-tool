# Database Schema

This document provides a reference for the project's database schema. It includes all tables, their columns, data types 
and constraints.


### `assessment_methods` table

| Column Name      | Type                     | Constraints             |
|------------------|--------------------------|-------------------------|
| a_method_id      | bigint                   | PK                      |
| a_method         | character varying(191)   | NOT NULL                |
| weight           | integer                  | NOT NULL                |
| course_id        | bigint                   | FK on courses, NOT NULL |
| pos_in_alignment | bigint                   | NOT NULL, Default: 0    |
| created_at       | timestamp with time zone |                         |
| updated_at       | timestamp with time zone |                         |

### `campuses` table

| Column Name | Type                     | Constraints |
|-------------|--------------------------|-------------|
| campus_id   | bigint                   | PK          |
| campus      | character varying(191)   | NOT NULL    |
| created_at  | timestamp with time zone |             |
| updated_at  | timestamp with time zone |             |


### `course_description` table

| Column Name | Type                     | Constraints   |
|-------------|--------------------------|---------------|
| id          | bigint                   | PK            |
| course_id   | bigint                   | FK on courses |
| description | text                     |               |
| created_at  | timestamp with time zone |               |
| updated_at  | timestamp with time zone |               |


### `course_material_chunks` table

| Column Name              | Type                     | Constraints                                                  |
|--------------------------|--------------------------|--------------------------------------------------------------|
| id                       | bigint                   | PK                                                           |
| course_material_file_id  | bigint                   | FK on course_material_files, NOT NULL                        |
| page_number              | integer                  | NOT NULL                                                     |
| chunk_index              | integer                  | NOT NULL, Default: 0                                         |
| content                  | text                     | NOT NULL                                                     |
| content_tsv              | tsvector                 | GENERATED ALWAYS AS (to_tsvector('english', content)) STORED |
| created_at               | timestamp with time zone |                                                              |
| updated_at               | timestamp with time zone |                                                              |

**Constraints**:
* UNIQUE (course_material_file_id, page_number, chunk_index)
* GIN index on content_tsv

### `course_material_file_topic` table

| Column Name             | Type                     | Constraints                           |
|-------------------------|--------------------------|---------------------------------------|
| course_material_file_id | bigint                   | FK on course_material_files, NOT NULL |
| course_topic_id         | bigint                   | FK on course_topics, NOT NULL         |


### `course_material_files` table

| Column Name               | Type                     | Constraints                              |
|---------------------------|--------------------------|------------------------------------------|
| course_material_file_id   | bigint                   | PK                                       |
| course_material_id        | bigint                   | FK on course_materials, NOT NULL         |
| uploaded_by               | bigint                   | FK on users                              |
| file_name                 | character varying(191)   | NOT NULL                                 |
| file_path                 | character varying(191)   | NOT NULL                                 |
| file_size                 | bigint                   | NOT NULL                                 |
| status                    | character varying(191)   | NOT NULL, Default: 'PENDING'             |
| error_message             | text                     |                                          |
| page_count                | integer                  |                                          |
| ocr_enabled               | boolean                  | NOT NULL, Default: false                 |
| ocr_threshold             | integer                  | NOT NULL, Default: 0                     |
| extraction_engine         | character varying(191)   | NOT NULL, Default: 'tesseract'           |
| processing_time_seconds   | integer                  |                                          |
| created_at                | timestamp with time zone |                                          |
| updated_at                | timestamp with time zone |                                          |

### `course_materials` table

| Column Name        | Type                     | Constraints              |
|--------------------|--------------------------|--------------------------|
| course_material_id | bigint                   | PK                       |
| course_id          | bigint                   | FK on courses, NOT NULL  |
| name               | text                     | NOT NULL                 |
| type               | character varying(191)   |                          |
| description        | text                     |                          |
| is_required        | boolean                  |                          |
| url                | character varying(191)   |                          |
| position           | integer                  | NOT NULL, Default: 0     |
| created_at         | timestamp with time zone |                          |
| updated_at         | timestamp with time zone |                          |

### `course_optional_priorities` table

| Column Name | Type                     | Constraints                   |
|-------------|--------------------------|-------------------------------|
| op_id       | bigint                   | PK, FK on optional_priorities |
| course_id   | bigint                   | PK, FK on courses             |
| created_at  | timestamp with time zone |                               |
| updated_at  | timestamp with time zone |                               |

### `course_programs` table

| Column Name         | Type                     | Constraints              |
|---------------------|--------------------------|--------------------------|
| id                  | bigint                   | PK, NOT NULL             |
| course_id           | bigint                   | FK on courses, NOT NULL  |
| program_id          | bigint                   | FK on programs, NOT NULL |
| course_required     | integer                  |                          |
| instructor_assigned | integer                  |                          |
| map_status          | integer                  | NOT NULL, Default: 0     |
| manual_map_status   | boolean                  | NOT NULL, Default: false |
| ai_suggestion_status| boolean                  | NOT NULL, Default: false |
| note                | character varying(191)   |                          |
| created_at          | timestamp with time zone |                          |
| updated_at          | timestamp with time zone |                          |

### `course_schedules` table

| Column Name | Type                     | Constraints             |
|-------------|--------------------------|-------------------------|
| id          | bigint                   | PK                      |
| syllabus_id | bigint                   | FK on syllabi, NOT NULL |
| "row"       | integer                  | NOT NULL                |
| col         | integer                  | NOT NULL                |
| val         | text                     |                         |
| created_at  | timestamp with time zone |                         |
| updated_at  | timestamp with time zone |                         |

### `course_syllabi_file` table

| Column Name | Type                     | Constraints   |
|-------------|--------------------------|---------------|
| id          | bigint                   | PK            |
| file_name   | text                     |               |
| file_path   | text                     |               |
| course_id   | bigint                   | FK on courses |
| created_at  | timestamp with time zone |               |
| updated_at  | timestamp with time zone |               |


### `course_topics` table

| Column Name     | Type                     | Constraints             |
|-----------------|--------------------------|-------------------------|
| course_topic_id | bigint                   | PK                      |
| course_id       | bigint                   | FK on courses, NOT NULL |
| topic           | text                     | NOT NULL                |
| description     | text                     |                         |
| position        | integer                  | NOT NULL, Default: 0    |
| created_at      | timestamp with time zone |                         |
| updated_at      | timestamp with time zone |                         |

### `course_user_role` table

| Column Name   | Type                     | Constraints              |
|---------------|--------------------------|--------------------------|
| id            | bigint                   | PK                       |
| course_id     | bigint                   | FK on courses,  NOT NULL |
| user_id       | bigint                   | NOT NULL                 |
| role_id       | bigint                   |                          |
| program_id    | bigint                   | FK on programs           |
| department_id | bigint                   | FK on departments        |
| created_at    | timestamp with time zone |                          |
| updated_at    | timestamp with time zone |                          |

**Constraints**:
* UNIQUE (course_id, user_id, role_id, program_id, department_id)
* FK (user_id, role_id) on role_user

### `course_users` table

| Column Name | Type                     | Constraints             |
|-------------|--------------------------|-------------------------|
| id          | bigint                   | PK                      |
| course_id   | bigint                   | FK on courses, NOT NULL |
| user_id     | bigint                   | FK on users, NOT NULL   |
| permission  | bigint                   | NOT NULL, Default: 0    |
| created_at  | timestamp with time zone |                         |
| updated_at  | timestamp with time zone |                         |

**Constraints**:
* UNIQUE (user_id, course_id)

### `courses` table

| Column Name          | Type                     | Constraints                                            |
|----------------------|--------------------------|--------------------------------------------------------|
| course_id            | bigint                   | PK                                                     |
| course_code          | character varying(191)   | NOT NULL                                               |
| course_num           | character varying(30)    |                                                        |
| delivery_modality    | character(1)             | NOT NULL                                               |
| year                 | integer                  | NOT NULL                                               |
| semester             | character(2)             | NOT NULL                                               |
| section              | character varying(20)    |                                                        |
| course_title         | character varying(191)   | NOT NULL                                               |
| program_id           | bigint                   | FK on programs                                         |
| status               | integer                  | NOT NULL, Default: -1                                  |
| assigned             | integer                  | NOT NULL                                               |
| required             | integer                  |                                                        |
| type                 | character varying(191)   | NOT NULL                                               |
| standard_category_id | bigint                   | FK on standard_categories, NOT NULL, Default: 1        |
| scale_category_id    | bigint                   | FK on standards_scale_categories, NOT NULL, Default: 1 |
| last_modified_user   | character varying(191)   |                                                        |
| campus               | character varying(191)   |                                                        |
| faculty              | character varying(191)   |                                                        |
| department           | character varying(191)   |                                                        |
| created_at           | timestamp with time zone |                                                        |
| updated_at           | timestamp with time zone |                                                        |


### `custom_assessment_methods` table

| Column Name      | Type                     | Constraints |
|------------------|--------------------------|-------------|
| custom_method_id | bigint                   | PK          |
| custom_methods   | character varying(191)   | NOT NULL    |
| created_at       | timestamp with time zone |             |
| updated_at       | timestamp with time zone |             |


### `custom_learning_activities` table

| Column Name        | Type                     | Constraints |
|--------------------|--------------------------|-------------|
| custom_activity_id | bigint                   | PK          |
| custom_activities  | character varying(191)   | NOT NULL    |
| created_at         | timestamp with time zone |             |
| updated_at         | timestamp with time zone |             |

### `custom_program_learning_outcomes` table

| Column Name         | Type                     | Constraints |
|---------------------|--------------------------|-------------|
| id                  | bigint                   | PK          |
| custom_plo          | character varying(191)   | NOT NULL    |
| custom_program_id   | integer                  | NOT NULL    |
| custom_program_name | character varying(191)   | NOT NULL    |
| created_at          | timestamp with time zone |             |
| updated_at          | timestamp with time zone |             |


### `department_head` table

| Column Name                          | Type                     | Constraints                 |
|--------------------------------------|--------------------------|-----------------------------|
| id                                   | bigint                   | PK                          |
| department_id                        | bigint                   | FK on departments, NOT NULL |
| user_id                              | bigint                   | FK on users, NOT NULL       |
| has_access_to_all_courses_in_faculty | boolean                  | Default: false              |
| created_at                           | timestamp with time zone |                             |
| updated_at                           | timestamp with time zone |                             |

**Constraints**:
* UNIQUE (user_id, department_id)

### `departments` table

| Column Name   | Type                     | Constraints               |
|---------------|--------------------------|---------------------------|
| department_id | bigint                   | PK                        |
| department    | character varying(191)   | NOT NULL                  |
| faculty_id    | bigint                   | FK on faculties, NOT NULL |
| created_at    | timestamp with time zone |                           |
| updated_at    | timestamp with time zone |                           |


### `faculties` table

| Column Name | Type                     | Constraints |
|-------------|--------------------------|-------------|
| faculty_id  | bigint                   | PK          |
| faculty     | character varying(191)   | NOT NULL    |
| campus_id   | bigint                   | NOT NULL    |
| created_at  | timestamp with time zone |             |
| updated_at  | timestamp with time zone |             |


### `faculty_course_codes` table

| Column Name | Type                     | Constraints     |
|-------------|--------------------------|-----------------|
| faculty_id  | bigint                   | FK on faculties |
| course_code | character varying(191)   | NOT NULL        |
| created_at  | timestamp with time zone |                 |
| updated_at  | timestamp with time zone |                 |

**Constraints**:
* PK (faculty_id, course_code)

### `failed_jobs` table

| Column Name | Type                     | Constraints                          |
|-------------|--------------------------|--------------------------------------|
| id          | bigint                   | PK                                   |
| uuid        | character varying(191)   | NOT NULL, UNIQUE                     |
| connection  | text                     | NOT NULL                             |
| queue       | text                     | NOT NULL                             |
| payload     | text                     | NOT NULL                             |
| exception   | text                     | NOT NULL                             |
| updated_at  | timestamp with time zone | NOT NULL, Default: CURRENT_TIMESTAMP |

### `invites` table

| Column Name      | Type                     | Constraints           |
|------------------|--------------------------|-----------------------|
| id               | bigint                   | PK                    |
| user_id          | bigint                   | FK on users, NOT NULL |
| email            | character varying(191)   | NOT NULL, UNIQUE      |
| invitation_token | character varying(191)   | UNIQUE                |
| accepted_at      | timestamp with time zone |                       |
| created_at       | timestamp with time zone |                       |
| updated_at       | timestamp with time zone |                       |

### `job_batches` table

| Column Name    | Type                   | Constraints |
|----------------|------------------------|-------------|
| id             | bigint                 | PK          |
| name           | character varying(191) | NOT NULL    |
| total_jobs     | integer                | NOT NULL    |
| pending_jobs   | integer                | NOT NULL    |
| failed_jobs    | integer                | NOT NULL    |
| failed_job_ids | text                   | NOT NULL    |
| options        | text                   |             |
| cancelled_at   | integer                |             |
| created_at     | integer                | NOT NULL    |
| finished_at    | integer                |             |

### `jobs` table

| Column Name  | Type                   | Constraints |
|--------------|------------------------|-------------|
| id           | bigint                 | PK          |
| queue        | character varying(191) | NOT NULL    |
| payload      | text                   | NOT NULL    |
| attempts     | tinyint                | NOT NULL    |
| reserved_at  | bigint                 |             |
| available_at | bigint                 | NOT NULL    |
| created_at   | bigint                 | NOT NULL    |


### `learning_activities` table

| Column Name      | Type                     | Constraints             |
|------------------|--------------------------|-------------------------|
| l_activity_id    | bigint                   | PK                      |
| l_activity       | character varying(191)   | NOT NULL                |
| course_id        | bigint                   | FK on courses, NOT NULL |
| l_activities_pos | bigint                   | NOT NULL, Default: 0    |
| created_at       | timestamp with time zone |                         |
| updated_at       | timestamp with time zone |                         |


### `learning_outcomes` table

| Column Name      | Type                     | Constraints             |
|------------------|--------------------------|-------------------------|
| l_outcome_id     | bigint                   | PK                      |
| clo_shortphrase  | character varying(191)   |                         |
| l_outcome        | text                     | NOT NULL                |
| course_id        | bigint                   | FK on courses, NOT NULL |
| pos_in_alignment | bigint                   | NOT NULL, Default: 0    |
| created_at       | timestamp with time zone |                         |
| updated_at       | timestamp with time zone |                         |

### `mapping_scale_categories` table

| Column Name                 | Type                     | Constraints |
|-----------------------------|--------------------------|-------------|
| mapping_scale_categories_id | bigint                   | PK          |
| msc_title                   | character varying(191)   | NOT NULL    |
| description                 | text                     |             |
| created_at                  | timestamp with time zone |             |
| updated_at                  | timestamp with time zone |             |


### `mapping_scale_programs` table

| Column Name  | Type                     | Constraints                    |
|--------------|--------------------------|--------------------------------|
| map_scale_id | bigint                   | FK on mapping_scales, NOT NULL |
| program_id   | bigint                   | FK on programs, NOT NULL       |
| created_at   | timestamp with time zone |                                |
| updated_at   | timestamp with time zone |                                |

**Constraints**:
* PK (map_scale_id, program_id)

### `mapping_scales` table

| Column Name                 | Type                     | Constraints                    |
|-----------------------------|--------------------------|--------------------------------|
| map_scale_id                | bigint                   | PK                             |
| title                       | character varying(191)   | NOT NULL                       |
| abbreviation                | character varying(191)   | NOT NULL                       |
| description                 | text                     | NOT NULL                       |
| colour                      | character(7)             | NOT NULL                       |
| mapping_scale_categories_id | bigint                   | FK on mapping_scale_categories |
| created_at                  | timestamp with time zone |                                |
| updated_at                  | timestamp with time zone |                                |

### `okanagan_syllabi` table

| Column Name        | Type                     | Constraints             |
|--------------------|--------------------------|-------------------------|
| id                 | bigint                   | PK                      |
| syllabus_id        | bigint                   | FK on syllabi, NOT NULL |
| course_format      | text                     |                         |
| course_overview    | text                     |                         |
| course_description | text                     |                         |
| created_at         | timestamp with time zone |                         |
| updated_at         | timestamp with time zone |                         |

### `okanagan_syllabus_resources` table

| Column Name | Type                     | Constraints      |
|-------------|--------------------------|------------------|
| id          | bigint                   | PK               |
| title       | character varying(191)   | NOT NULL         |
| id_name     | character varying(191)   | NOT NULL, UNIQUE |
| created_at  | timestamp with time zone |                  |
| updated_at  | timestamp with time zone |                  |

### `optional_priorities` table

| Column Name       | Type                     | Constraints                                     |
|-------------------|--------------------------|-------------------------------------------------|
| op_id             | bigint                   | PK                                              |
| subcat_id         | bigint                   | FK on optional_priority_subcategories, NOT NULL |
| optional_priority | text                     | NOT NULL                                        |
| year              | integer                  |                                                 |
| op_subdesc        | bigint                   | FK on optional_priorities_subdescriptions       |
| created_at        | timestamp with time zone |                                                 |
| updated_at        | timestamp with time zone |                                                 |


### `optional_priorities_subdescriptions` table

| Column Name | Type                     | Constraints |
|-------------|--------------------------|-------------|
| op_subdesc  | bigint                   | PK          |
| description | text                     | NOT NULL    |
| created_at  | timestamp with time zone |             |
| updated_at  | timestamp with time zone |             |


### `optional_priority_categories` table

| Column Name | Type                     | Constraints |
|-------------|--------------------------|-------------|
| cat_id      | bigint                   | PK          |
| cat_name    | character varying(191)   | NOT NULL    |
| created_at  | timestamp with time zone |             |
| updated_at  | timestamp with time zone |             |


### `optional_priority_subcategories` table

| Column Name      | Type                     | Constraints                                  |
|------------------|--------------------------|----------------------------------------------|
| subcat_id        | bigint                   | PK                                           |
| subcat_name      | text                     | NOT NULL                                     |
| cat_id           | bigint                   | FK on optional_priority_categories, NOT NULL |
| subcat_desc      | text                     |                                              |
| subcat_postamble | text                     |                                              |
| created_at       | timestamp with time zone |                                              |
| updated_at       | timestamp with time zone |                                              |


### `outcome_activities` table

| Column Name   | Type                     | Constraints                         |
|---------------|--------------------------|-------------------------------------|
| l_outcome_id  | bigint                   | FK on learning_outcomes, NOT NULL   |
| l_activity_id | bigint                   | FK on learning_activities, NOT NULL |
| created_at    | timestamp with time zone |                                     |
| updated_at    | timestamp with time zone |                                     |

**Constraints**:
* PK (l_outcome_id, l_activity_id)

### `outcome_assessments` table

| Column Name  | Type                     | Constraints                        |
|--------------|--------------------------|------------------------------------|
| l_outcome_id | bigint                   | FK on learning_outcomes, NOT NULL  |
| a_method_id  | bigint                   | FK on assessment_methods, NOT NULL |
| created_at   | timestamp with time zone |                                    |
| updated_at   | timestamp with time zone |

**Constraints**:
* PK (l_outcome_id, a_method_id)

### `outcome_map_ai_suggestions` table

| Column Name   | Type                     | Constraints                               |
|---------------|--------------------------|-------------------------------------------|
| l_outcome_id  | bigint                   | FK on learning_outcomes, NOT NULL         |
| pl_outcome_id | bigint                   | FK on program_learning_outcomes, NOT NULL |
| map_scale_id  | bigint                   | FK on mapping_scales, NOT NULL, Default: 0 |
| created_at    | timestamp with time zone |                                           |
| updated_at    | timestamp with time zone |                                           |

**Constraints**:
* PK (l_outcome_id, pl_outcome_id, map_scale_id)

### `outcome_maps` table

| Column Name   | Type                     | Constraints                               |
|---------------|--------------------------|-------------------------------------------|
| l_outcome_id  | bigint                   | FK on learning_outcomes, NOT NULL         |
| pl_outcome_id | bigint                   | FK on program_learning_outcomes, NOT NULL |
| map_scale_id  | bigint                   | NOT NULL, Default: 0                      |
| created_at    | timestamp with time zone |                                           |
| updated_at    | timestamp with time zone |

**Constraints**:
* PK (l_outcome_id, pl_outcome_id, map_scale_id)

### `p_l_o_categories` table

| Column Name     | Type                     | Constraints              |
|-----------------|--------------------------|--------------------------|
| plo_category_id | bigint                   | PK                       |
| plo_category    | character varying(191)   | NOT NULL                 |
| program_id      | bigint                   | FK on programs, NOT NULL |
| created_at      | timestamp with time zone |                          |
| updated_at      | timestamp with time zone |

### `password_resets` table

| Column Name | Type                     | Constraints |
|-------------|--------------------------|-------------|
| email       | character varying(191)   | INDEX       |
| token       | character varying(191)   | NOT NULL    |
| created_at  | timestamp with time zone |             |

### `program_learning_outcomes` table

| Column Name     | Type                     | Constraints              |
|-----------------|--------------------------|--------------------------|
| pl_outcome_id   | bigint                   | PK                       |
| plo_shortphrase | character varying(191)   |                          |
| pl_outcome      | text                     | NOT NULL                 |
| program_id      | bigint                   | FK on programs, NOT NULL |
| plo_category_id | bigint                   | FK on p_l_o_categories   |
| created_at      | timestamp with time zone |                          |
| updated_at      | timestamp with time zone |


### `program_user_role` table

| Column Name                          | Type                     | Constraints       |
|--------------------------------------|--------------------------|-------------------|
| id                                   | bigint                   | PK                |
| program_id                           | bigint                   | FK on programs    |
| user_id                              | bigint                   | NOT NULL          |
| role_id                              | bigint                   |                   |
| department_id                        | bigint                   | FK on departments |
| has_access_to_all_courses_in_faculty | boolean                  | Default: false    |
| created_at                           | timestamp with time zone |                   |
| updated_at                           | timestamp with time zone |

**Constraints**:
* FK (user_id, role_id) on role_user
* UNIQUE (program_id, user_id, role_id, department_id)

### `program_users` table

| Column Name | Type                     | Constraints              |
|-------------|--------------------------|--------------------------|
| id          | bigint                   | PK                       |
| user_id     | bigint                   | FK on users, NOT NULL    |
| program_id  | bigint                   | FK on programs, NOT NULL |
| permission  | bigint                   | NOT NULL, Default: 0     |
| created_at  | timestamp with time zone |                          |
| updated_at  | timestamp with time zone |

**Constraints**:
* UNIQUE (user_id, program_id)

### `programs` table

| Column Name        | Type                     | Constraints    |
|--------------------|--------------------------|----------------|
| program_id         | bigint                   | PK             |
| program            | character varying(191)   | NOT NULL       |
| faculty            | character varying(191)   |                |
| department         | character varying(191)   |                |
| level              | character varying(191)   | NOT NULL       |
| status             | integer                  | NOT NULL       |
| last_modified_user | character varying(191)   |                |
| campus             | character varying(191)   | Default: Other |
| created_at         | timestamp with time zone |                |
| updated_at         | timestamp with time zone |                |

### `role_user` table

| Column Name | Type                     | Constraints |
|-------------|--------------------------|-------------|
| role_id     | bigint                   | FK on roles |
| user_id     | bigint                   | FK on users |
| created_at  | timestamp with time zone |             |
| updated_at  | timestamp with time zone |

**Constraints**:
* PK (user_id, role_id)

### `roles` table

| Column Name | Type                     | Constraints |
|-------------|--------------------------|-------------|
| id          | bigint                   | PK          |
| role        | character varying(191)   | NOT NULL    |
| created_at  | timestamp with time zone |             |
| updated_at  | timestamp with time zone |             |


### `standard_categories` table

| Column Name          | Type                     | Constraints |
|----------------------|--------------------------|-------------|
| standard_category_id | bigint                   | PK          |
| sc_name              | character varying(191)   | NOT NULL    |
| created_at           | timestamp with time zone |             |
| updated_at           | timestamp with time zone |             |


### `standard_scales` table

| Column Name       | Type                     | Constraints                      |
|-------------------|--------------------------|----------------------------------|
| standard_scale_id | bigint                   | PK                               |
| scale_category_id | bigint                   | FK on standards_scale_categories |
| title             | character varying(191)   | NOT NULL                         |
| abbreviation      | character varying(191)   | NOT NULL                         |
| description       | text                     | NOT NULL                         |
| colour            | character(7)             | NOT NULL                         |
| created_at        | timestamp with time zone |                                  |
| updated_at        | timestamp with time zone |                                  |


### `standards` table

| Column Name          | Type                     | Constraints                                     |
|----------------------|--------------------------|-------------------------------------------------|
| standard_id          | bigint                   | PK                                              |
| standard_category_id | bigint                   | FK on standard_categories, NOT NULL, Default: 1 |
| s_shortphrase        | character varying(191)   | NOT NULL                                        |
| s_outcome            | text                     | NOT NULL                                        |
| created_at           | timestamp with time zone |                                                 |
| updated_at           | timestamp with time zone |                                                 |


### `standards_outcome_maps` table

| Column Name       | Type                     | Constraints                                 |
|-------------------|--------------------------|---------------------------------------------|
| standard_id       | bigint                   | FK on standards, NOT NULL                   |
| course_id         | bigint                   | FK on courses, NOT NULL, Default: 1         |
| standard_scale_id | bigint                   | FK on standard_scales, NOT NULL, Default: 0 |
| created_at        | timestamp with time zone |                                             |
| updated_at        | timestamp with time zone |                                             |


**Constraints**:
* PK (standard_id, course_id)

### `standards_scale_categories` table

| Column Name       | Type                     | Constraints |
|-------------------|--------------------------|-------------|
| scale_category_id | bigint                   | PK          |
| name              | character varying(191)   | NOT NULL    |
| description       | text                     |             |
| created_at        | timestamp with time zone |             |
| updated_at  | timestamp with time zone |             |


### `suggested_topics` table

| Column Name             | Type                     | Constraints                          |
|-------------------------|--------------------------|--------------------------------------|
| suggested_topic_id      | bigint                   | PK                                   |
| course_material_file_id | bigint                   | FK on course_material_files, NOT NULL |
| topic                   | text                     | NOT NULL                             |
| score                   | double precision         |                                      |
| source                  | character varying(191)   | NOT NULL, Default: 'keyword'         |
| status                  | smallint                 | NOT NULL, Default: 0                 |
| created_at              | timestamp with time zone |                                      |
| updated_at              | timestamp with time zone |                                      |

**Constraints**:
* UNIQUE (course_material_file_id, topic)

### `syllabi` table

| Column Name               | Type                     | Constraints              |
|---------------------------|--------------------------|--------------------------|
| id                        | bigint                   | PK                       |
| course_title              | character varying(191)   |                          |
| course_code               | character varying(191)   |                          |
| course_num                | bigint                   |                          |
| delivery_modality         | character(1)             | NOT NULL, Default: 0     |
| course_term               | character(2)             |                          |
| course_year               | integer                  |                          |
| campus                    | character(1)             | NOT NULL                 |
| faculty                   | character varying(191)   |                          |
| department                | character varying(191)   |                          |
| course_instructor         | character varying(191)   | NOT NULL                 |
| course_location           | character varying(191)   |                          |
| office_hours              | text                     |                          |
| class_meeting_days        | character(24)            |                          |
| other_instructional_staff | text                     |                          |
| class_start_time          | character varying(191)   |                          |
| class_end_time            | character varying(191)   |                          |
| learning_outcomes         | text                     |                          |
| learning_assessments      | text                     |                          |
| learning_activities       | text                     |                          |
| late_policy               | text                     |                          |
| missed_exam_policy        | text                     |                          |
| missed_activity_policy    | text                     |                          |
| learning_materials        | text                     |                          |
| learning_resources        | text                     |                          |
| last_modified_user        | character varying(191)   |                          |
| course_id                 | bigint                   |                          |
| include_alignment         | boolean                  | NOT NULL, Default: false |
| cc_license                | text                     |                          |
| copyright                 | boolean                  |                          |
| land_acknow               | boolean                  |                          |
| custom_resource           | text                     |                          |
| custom_resource_title     | text                     |                          |
| cross_listed_code         | text                     |                          |
| cross_listed_num          | text                     |                          |
| course_section            | text                     |                          |
| prerequisites             | text                     |                          |
| corequisites              | text                     |                          |
| created_at                | timestamp with time zone |                          |
| updated_at                | timestamp with time zone |                          |


### `syllabi_programs` table

| Column Name | Type                     | Constraints              |
|-------------|--------------------------|--------------------------|
| id          | bigint                   | PK                       |
| syllabus_id | bigint                   | FK on syllabi, NOT NULL  |
| program_id  | bigint                   | FK on programs, NOT NULL |
| created_at  | timestamp with time zone |                          |
| updated_at  | timestamp with time zone |                          |

### `syllabi_resources_okanagan` table

| Column Name            | Type                     | Constraints                                 |
|------------------------|--------------------------|---------------------------------------------|
| id                     | bigint                   | PK                                          |
| syllabus_id            | bigint                   | FK on syllabi, NOT NULL                     |
| o_syllabus_resource_id | bigint                   | FK on okanagan_syllabus_resources, NOT NULL |
| created_at             | timestamp with time zone |                                             |
| updated_at             | timestamp with time zone |                                             |

### `syllabi_resources_vancouver` table

| Column Name            | Type                     | Constraints                                  |
|------------------------|--------------------------|----------------------------------------------|
| id                     | bigint                   | PK                                           |
| syllabus_id            | bigint                   | FK on syllabi, NOT NULL                      |
| v_syllabus_resource_id | bigint                   | FK on vancouver_syllabus_resources, NOT NULL |
| created_at             | timestamp with time zone |                                              |
| updated_at             | timestamp with time zone |                                              |

### `syllabi_users` table

| Column Name | Type                     | Constraints             |
|-------------|--------------------------|-------------------------|
| id          | bigint                   | PK                      |
| syllabus_id | bigint                   | FK on syllabi, NOT NULL |
| user_id     | bigint                   | FK on users, NOT NULL   |
| permission  | bigint                   | NOT NULL, Default: 0    |
| created_at  | timestamp with time zone |                         |
| updated_at  | timestamp with time zone |                         |


### `syllabus_instructors` table

| Column Name | Type                     | Constraints             |
|-------------|--------------------------|-------------------------|
| id          | bigint                   | PK                      |
| syllabus_id | bigint                   | FK on syllabi, NOT NULL |
| name        | character varying(191)   | NOT NULL                |
| email       | character varying(191)   | NOT NULL                |
| created_at  | timestamp with time zone |                         |
| updated_at  | timestamp with time zone |                         |

### `users` table

| Column Name       | Type                     | Constraints              |
|-------------------|--------------------------|--------------------------|
| id                | bigint                   | PK                       |
| name              | character varying(191)   | NOT NULL                 |
| email             | character varying(191)   | NOT NULL                 |
| email_verified_at | timestamp with time zone |                          |
| password          | character varying(191)   | NOT NULL                 |
| remember_token    | character varying(100)   |                          |
| has_temp          | boolean                  | NOT NULL, Default: false |
| created_at        | timestamp with time zone |                          |
| updated_at        | timestamp with time zone |                          |

### `vancouver_syllabi` table

| Column Name        | Type                     | Constraints             |
|--------------------|--------------------------|-------------------------|
| id                 | bigint                   | PK                      |
| syllabus_id        | bigint                   | FK on syllabi, NOT NULL |
| course_credit      | bigint                   | NOT NULL                |
| office_location    | character varying(191)   |                         |
| course_description | text                     |                         |
| course_contacts    | text                     |                         |
| instructor_bio     | text                     |                         |
| course_prereqs     | text                     |                         |
| course_coreqs      | text                     |                         |
| course_structure   | text                     |                         |
| course_schedule    | text                     |                         |
| learning_analytics | text                     |                         |
| created_at         | timestamp with time zone |                         |
| updated_at         | timestamp with time zone |                         |


### `vancouver_syllabus_resources` table

| Column Name | Type                     | Constraints      |
|-------------|--------------------------|------------------|
| id          | bigint                   | PK               |
| title       | character varying(191)   | NOT NULL         |
| id_name     | character varying(191)   | NOT NULL, UNIQUE |
| created_at  | timestamp with time zone |                  |
| updated_at  | timestamp with time zone |                  |

