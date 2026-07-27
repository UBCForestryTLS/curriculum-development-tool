# Search Feature

## Overview

The search feature is a Laravel feature that lets authenticated users search across course and program content in the Curriculum Mapping Tool.

The feature's role is to accept a search query, apply optional filters, search PostgreSQL full-text indexes, and return either course-based or program-based results.

This feature is implemented primarily under:

- [`laravel/app/Http/Controllers/SearchController.php`](../laravel/app/Http/Controllers/SearchController.php)
- [`laravel/app/Http/Controllers/SavedSearchFilterController.php`](../laravel/app/Http/Controllers/SavedSearchFilterController.php)
- [`laravel/app/Helpers/SearchCourseAccess.php`](../laravel/app/Helpers/SearchCourseAccess.php)
- [`laravel/app/Helpers/SearchFilterOptions.php`](../laravel/app/Helpers/SearchFilterOptions.php)
- [`laravel/resources/views/search/index.blade.php`](../laravel/resources/views/search/index.blade.php)
- [`laravel/resources/views/search/partials/highlighted-snippet.blade.php`](../laravel/resources/views/search/partials/highlighted-snippet.blade.php)
- [`laravel/tests/Feature/SearchTest.php`](../laravel/tests/Feature/SearchTest.php)

The related routes are defined in [`laravel/routes/web.php`](../laravel/routes/web.php).

## Primary Responsibility

The feature is responsible for:

- searching course identity fields such as code, number, and title
- searching course topics
- searching course learning outcomes
- searching course assessment methods
- searching course descriptions
- searching course materials
- searching program names
- filtering results by course code, course level, program, and searchable property
- showing results in either Course view or Program view
- saving, applying, and deleting user-specific search filter presets

## Routes

The main search page is:

```php
GET /search
```

The saved filter preset routes are:

```php
POST /search/filters
DELETE /search/filters/{savedFilterId}
GET /search/filters/{savedFilterId}/apply
```

All search routes require `auth` and `verified` middleware because search results can include course and program content.

## Searchable Content

Search uses PostgreSQL full-text search across these fields:

| Filter Value | UI Label | Source |
| --- | --- | --- |
| `course` | Course Identity | `courses.course_code`, `courses.course_num`, `courses.course_title` |
| `topics` | Topics | `course_topics.topic` |
| `learning_outcomes` | Learning Objectives | `learning_outcomes.l_outcome` |
| `assessments` | Assessments | `assessment_methods.a_method` |
| `descriptions` | Descriptions | `course_description.description` |
| `materials` | Materials | `course_materials.name`, `course_materials.type`, `course_materials.description` |

Program names are searched directly from `programs.program`.

The filter values and UI labels are defined in `SearchFilterOptions`, which is used by both search controllers and the Blade view. This keeps property validation, defaults, and display labels in one place.

## Search Access

Search results are scoped to the logged-in user's course access. The access rule is applied in the database query before results, stats, filter options, and Program view groups are returned.

Current supported access:

- administrators can search all courses
- regular users can search courses they have direct access to through `course_users`
- program directors can search courses attached to programs they direct through `program_user_role` and `course_programs`

The search access logic lives in `SearchCourseAccess`. It uses `whereExists` subqueries so inaccessible courses are filtered out before the controller combines matches or paginates results.

Department Head access is not implemented in search yet. That still needs a separate decision on whether to use existing role access rows or calculate access from department/course-code ownership tables.

## Request Flow

The processing flow is:

1. A logged-in user opens `/search` or submits a search query.
2. `SearchController@index` validates the query, selected view, selected properties, course filters, and program filters.
3. The controller normalizes input, including trimming query whitespace and uppercasing course codes.
4. If a query exists, the controller searches the selected properties.
5. Raw matches are grouped by course and ranked.
6. Course results are enriched with their related programs.
7. Program view groups matching courses under their programs and also includes direct program-name matches.
8. Results, stats, filters, and saved presets are passed to the Blade view.

If the query is empty, the request is valid, but no search results are currently shown.

## Search Strategy

The search feature uses generated PostgreSQL `tsvector` columns and GIN indexes. These are added by the search vector migrations.

The main PostgreSQL functions used are:

```sql
websearch_to_tsquery('english', ?)
ts_headline(...)
```

`websearch_to_tsquery` makes the search input behave closer to normal web search syntax. `ts_headline` creates result snippets with `<mark>` tags around matching words.

The feature currently gathers all matching rows, combines them in PHP, and then paginates the final collection. This is acceptable for the current project size, but may need to be revisited if the dataset becomes much larger.

## Database Migrations

The search feature depends on these migrations:

- `2026_07_10_000001_add_search_vectors_to_course_search_tables.php`
  - adds generated `search_vector` columns and GIN indexes for courses, topics, learning outcomes, assessments, descriptions, and materials
- `2026_07_10_000002_add_search_vector_to_programs_table.php`
  - adds the generated `search_vector` column and GIN index for program names
- `2026_07_13_000001_create_saved_search_filters_table.php`
  - creates `saved_search_filters` with `user_id`, `name`, and JSONB `filters`

The generated vectors are stored columns, so PostgreSQL updates them when the source fields change.

## Ranking

Raw matches are grouped by course in `combineMatchesByCourse()`.

Results are ranked using priority weights - Current weights:

Course identity: 70 
Topic: 50 
Learning outcome: 40 
Assessment: 30 
Description: 20 
Material: 10

Direct course matches are intentionally favoured. For example, a search for `CONS123` should show the actual course before another course that only mentions `CONS123` in a topic or description.

Multiple lower-weight matches can still outrank one higher-weight match. This is expected and covered by tests.

## Filters And Views

Current filters include:

- result view: Courses or Programs
- searchable properties
- course codes
- course levels
- programs

Course code filter options are loaded from distinct course codes already in the database.

Course levels are treated as ranges:

- `100` means `100-199`
- `200` means `200-299`
- `300` means `300-399`
- `400` means `400-499`
- `500` means `500-599`
- `600` means `600+`

Course view is the default view. It shows matching courses, related programs, match stats, and snippets.

Program view groups matching courses under their programs. Program results can come from direct program-name matches or from matching courses that belong to a program.

## Saved Filter Presets

Authenticated users can save named filter presets.

Saved presets are stored in `saved_search_filters` with:

- `user_id`
- `name`
- `filters` as JSONB

The filters JSON stores the selected view, properties, course codes, course levels, and program IDs. Preset names are unique per user.

Applying a preset redirects to `search.index` with the saved preset converted into normal search query parameters. Deleting a preset is scoped through the current user's saved filters relation, so users cannot delete another user's preset by guessing an ID.

## Snippet Safety

PostgreSQL returns snippets with `<mark>` tags around matching terms.

Stored course content could contain unsafe HTML, so the snippet partial:

1. Escapes the full snippet.
2. Restores only escaped `<mark>` and `</mark>` tags.

This keeps highlighting while preventing stored HTML from rendering.

## Important Defaults

- Course view is selected by default.
- All searchable properties are selected by default unless property filters are applied.
- No selected course codes means all course codes are searched.
- No selected course levels means all course levels are searched.
- No selected programs means all programs are searched.
- Empty query is valid, but it does not currently list all courses or programs.

## Test Coverage

The main tests live in:

- [`laravel/tests/Feature/SearchTest.php`](../laravel/tests/Feature/SearchTest.php)

The tests cover:

- search page authentication
- query validation and whitespace normalization
- searching each course property
- direct course and direct program matches
- direct course access and Program Director access
- ranking behavior
- safe highlighted snippets
- search stats
- course and program pagination
- course code, course level, and program filters
- saved filter saving, applying, deleting, and current preset display

Run the search tests with:

```bash
cd laravel
php artisan test tests/Feature/SearchTest.php
```

## Operational Notes

Few constraints that might be important to consider for this feature:

- search depends on PostgreSQL generated `tsvector` columns and GIN indexes
- the search page requires a verified logged-in user
- search result links go to existing course and program wizard routes
- PHP-side grouping and pagination is fine for a small dataset, but may need refactoring if the database grows extremely large, which is unlikely
- empty-query listing is not currently implemented
- if new searchable fields are added, update `SearchFilterOptions`, migrations, controller search methods, tests, and this doc
