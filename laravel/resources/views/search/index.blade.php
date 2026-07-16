@extends('layouts.app')

@section('content')
    <style>
        .search-page-header {
            max-width: 780px;
            margin: 0 auto 1.5rem;
        }

        .search-input,
        .search-action-button {
            height: 48px !important;
            font-size: 1.05rem;
        }

        .search-action-button {
            padding-top: 0;
            padding-bottom: 0;
        }

        .search-filter-button {
            width: 48px;
            min-width: 48px;
        }

        .search-filter-menu {
            min-width: 540px;
            padding: 0.85rem 1rem;
            border-radius: 6px;
        }

        .search-filter-select {
            min-height: 92px;
        }

        .search-property-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            column-gap: 0.8rem;
            row-gap: 0.2rem;
        }

        .search-property-grid .form-check {
            margin-bottom: 0;
        }

        .search-property-grid .form-check-label {
            white-space: nowrap;
            font-size: 0.9rem;
        }

        .search-filter-menu .form-check-input:checked {
            background-color: #40B4E5;
            border-color: #40B4E5;
        }

        .search-filter-menu .form-check-input:focus {
            border-color: #40B4E5;
            box-shadow: 0 0 0 0.15rem rgba(64, 180, 229, 0.25);
        }

        .search-level-toggle {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.35rem;
        }

        .search-level-toggle .btn {
            color: #0055b7;
            border-color: #40B4E5;
            font-size: 0.82rem;
        }

        .search-level-toggle .btn:hover,
        .search-level-toggle .btn-check:checked + .btn {
            color: #fff;
            background-color: #40B4E5;
            border-color: #40B4E5;
        }

        .search-level-toggle .btn-check:focus + .btn {
            box-shadow: none;
        }

        .search-level-toggle .btn-check:focus-visible + .btn {
            outline: 2px solid #0055b7;
            outline-offset: 2px;
        }

        .search-chip-selector {
            position: relative;
        }

        .search-chip-options {
            display: none;
            position: absolute;
            z-index: 1056;
            width: 100%;
            max-height: 160px;
            overflow-y: auto;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.12);
        }

        .search-chip-option {
            display: block;
            width: 100%;
            padding: 0.35rem 0.5rem;
            color: #002145;
            text-align: left;
            background: none;
            border: 0;
        }

        .search-chip-option:hover {
            background-color: #e9f6fc;
        }

        .search-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin: 0.35rem 0.25rem 0 0;
            padding: 0.2rem 0.45rem;
            color: #002145;
            background-color: #e9f6fc;
            border: 1px solid #40B4E5;
            border-radius: 999px;
            font-size: 0.82rem;
        }

        .search-filter-chip button {
            padding: 0;
            color: #0055b7;
            background: none;
            border: 0;
            line-height: 1;
        }

        .search-clear-filters {
            font-size: 0.85rem;
        }

        .search-filter-heading {
            margin-bottom: 0.6rem;
            color: #6c757d;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .search-view-toggle .btn {
            color: #0055b7;
            border-color: #40B4E5;
            font-size: 0.9rem;
        }

        .search-view-toggle .btn:hover,
        .search-view-toggle .btn-check:checked + .btn {
            color: #fff;
            background-color: #40B4E5;
            border-color: #40B4E5;
        }

        .search-view-toggle .btn-check:focus + .btn {
            box-shadow: none;
        }

        .search-view-toggle .btn-check:focus-visible + .btn {
            outline: 2px solid #0055b7;
            outline-offset: 2px;
        }

        .search-stats,
        .course-match-stats {
            color: #495057;
        }

        .search-summary-chip {
            display: inline-flex;
            align-items: center;
            margin: 0.2rem 0.25rem;
            padding: 0.25rem 0.55rem;
            color: #002145;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 999px;
            font-size: 0.86rem;
        }

        .search-stats-divider {
            display: inline-block;
            height: 1.15rem;
            margin: 0 0.85rem;
            border-left: 2px solid #adb5bd;
            vertical-align: middle;
        }

        .search-stats-modal-list {
            max-height: 360px;
            overflow-y: auto;
        }

        .course-match-stats {
            font-size: 0.9rem;
        }

        .search-result-match {
            margin-bottom: 0.65rem;
        }

        .search-result-match p {
            margin-bottom: 0.2rem;
        }

        .search-extra-matches summary {
            cursor: pointer;
            color: #0055b7;
            font-size: 0.9rem;
        }

        .program-course-result {
            margin-left: 1rem;
            padding: 0.75rem 0;
            border-top: 1px solid #dee2e6;
        }

        mark {
            padding: 0.1rem 0.2rem;
        }
    </style>

    <div class="search-page-header text-center">
        <h1 class="mb-3">Course Search</h1>

        <form method="GET" action="{{ route('search.index') }}" id="courseSearchForm">
            <input type="hidden" name="property_filters_applied" value="1">
            <input type="hidden" name="course_filters_applied" value="1">
            <input type="hidden" name="program_filters_applied" value="1">

            <div class="input-group">
                <input
                    type="search"
                    name="query"
                    value="{{ $searchTerm }}"
                    placeholder="Search courses"
                    class="form-control search-input"
                >

                <button
                    type="button"
                    class="btn btn-outline-secondary search-action-button search-filter-button"
                    id="searchFiltersButton"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="false"
                    aria-expanded="false"
                    aria-label="Search settings"
                    title="Search settings"
                >
                    <i class="bi bi-gear"></i>
                </button>

                <div class="dropdown-menu dropdown-menu-end search-filter-menu" aria-labelledby="searchFiltersButton">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="search-filter-heading mb-0">View</div>
                        <button
                            type="button"
                            class="btn-close"
                            id="closeSearchFilters"
                            aria-label="Close search settings"
                        ></button>
                    </div>

                    <div class="btn-group w-100 search-view-toggle" role="group" aria-label="Search result view">
                        <input type="radio" class="btn-check" name="view" id="courseView" value="courses" @checked($selectedView === 'courses') autocomplete="off">
                        <label class="btn btn-outline-primary" for="courseView">
                            <i class="bi bi-journal-text me-1"></i> Courses
                        </label>

                        <input type="radio" class="btn-check" name="view" id="programView" value="programs" @checked($selectedView === 'programs') autocomplete="off">
                        <label class="btn btn-outline-primary" for="programView">
                            <i class="bi bi-diagram-3 me-1"></i> Programs
                        </label>
                    </div>

                    <div class="search-filter-heading mt-2">Properties</div>

                    @php
                        $propertyOptions = [
                            'course' => 'Course Identity',
                            'topics' => 'Topics',
                            'learning_outcomes' => 'Learning Objectives',
                            'assessments' => 'Assessments',
                            'descriptions' => 'Descriptions',
                            'materials' => 'Materials',
                        ];
                    @endphp

                    <div class="search-property-grid">
                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="allProperties"
                                @checked(count($selectedProperties) === count($propertyOptions))
                            >
                            <label class="form-check-label fw-semibold" for="allProperties">
                                All Properties
                            </label>
                        </div>

                        @foreach($propertyOptions as $value => $label)
                            <div class="form-check">
                                <input
                                    class="form-check-input property-filter-option"
                                    type="checkbox"
                                    name="properties[]"
                                    value="{{ $value }}"
                                    id="property-{{ $value }}"
                                    @checked(in_array($value, $selectedProperties))
                                >
                                <label class="form-check-label" for="property-{{ $value }}">
                                    {{ $label }}
                                </label>
                            </div>
                        @endforeach
                    </div>

                    <div class="search-filter-heading mt-2">Course Filters</div>

                    <label class="form-label small mb-1" for="courseCodeSearch">Course Code</label>
                    <div class="search-chip-selector" id="courseCodeChipSelector">
                        <input
                            type="text"
                            class="form-control form-control-sm"
                            id="courseCodeSearch"
                            placeholder="Search course codes"
                            autocomplete="off"
                        >
                        <div class="search-chip-options" id="courseCodeOptions"></div>
                        <div id="selectedCourseCodeChips"></div>
                        <div id="selectedCourseCodeInputs"></div>
                    </div>
                    <div class="form-text small">No selection searches all course codes.</div>

                    <label class="form-label small mt-2 mb-1">Course Level</label>
                    <div class="search-level-toggle" role="group" aria-label="Course level filters">
                        @foreach(['100', '200', '300', '400', '500', '600'] as $courseLevel)
                            <input
                                type="checkbox"
                                class="btn-check"
                                name="course_levels[]"
                                id="courseLevel-{{ $courseLevel }}"
                                value="{{ $courseLevel }}"
                                @checked(in_array($courseLevel, $selectedCourseLevels))
                                autocomplete="off"
                            >
                            <label class="btn btn-outline-primary btn-sm" for="courseLevel-{{ $courseLevel }}">
                                {{ $courseLevel }}
                            </label>
                        @endforeach
                    </div>
                    <div class="form-text small">No selection searches all course levels.</div>

                    <label class="form-label small mt-2 mb-1" for="programSearch">Program</label>
                    <div class="search-chip-selector" id="programChipSelector">
                        <input
                            type="text"
                            class="form-control form-control-sm"
                            id="programSearch"
                            placeholder="Search programs"
                            autocomplete="off"
                        >
                        <div class="search-chip-options" id="programOptions"></div>
                        <div id="selectedProgramChips"></div>
                        <div id="selectedProgramInputs"></div>
                    </div>
                    <div class="form-text small">No selection searches all programs.</div>

                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <button type="button" class="btn btn-link search-clear-filters px-0" id="clearSearchFilters">
                            Clear filters
                        </button>

                        @auth
                            <div class="d-flex gap-2">
                                <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm"
                                    id="openSavedFiltersModal"
                                    data-bs-toggle="modal"
                                    data-bs-target="#savedFiltersModal"
                                >
                                    View Saved Filters
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-primary btn-sm"
                                    id="openSaveFilterModal"
                                    data-bs-toggle="modal"
                                    data-bs-target="#saveFilterModal"
                                >
                                    Save Filter
                                </button>
                            </div>
                        @endauth
                    </div>

                </div>

                <button type="submit" class="btn btn-primary search-action-button">Search</button>
            </div>

            @error('query')
                <div class='text-danger mt-2'>
                    {{$message}}
                </div>
            @enderror

        </form>
        @auth
            <div
                id="saveFilterModal"
                class="modal fade text-start"
                tabindex="-1"
                aria-labelledby="saveFilterModalLabel"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false"
            >
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="{{ route('search.filters.store') }}" id="saveFilterForm">
                            @csrf

                            <div class="modal-header">
                                <h5 class="modal-title" id="saveFilterModalLabel">
                                    Save Filter Selection
                                </h5>
                                <button
                                    type="button"
                                    class="btn-close"
                                    data-bs-dismiss="modal"
                                    aria-label="Close"
                                ></button>
                            </div>

                            <div class="modal-body">
                                <label for="savedFilterName" class="form-label">
                                    Filter Name
                                </label>

                                <input
                                    type="text"
                                    id="savedFilterName"
                                    name="name"
                                    value="{{ old('name') }}"
                                    class="form-control @error('name') is-invalid @enderror"
                                    maxlength="100"
                                    required
                                >

                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror

                                <div id="savedFilterValues">
                                    @if($errors->has('name'))
                                        <input type="hidden" name="view" value="{{ old('view', 'courses') }}">
                                        <input type="hidden" name="property_filters_applied" value="1">

                                        @foreach((array) old('properties', []) as $property)
                                            <input type="hidden" name="properties[]" value="{{ $property }}">
                                        @endforeach

                                        @foreach((array) old('course_codes', []) as $courseCode)
                                            <input type="hidden" name="course_codes[]" value="{{ $courseCode }}">
                                        @endforeach

                                        @foreach((array) old('course_levels', []) as $courseLevel)
                                            <input type="hidden" name="course_levels[]" value="{{ $courseLevel }}">
                                        @endforeach

                                        @foreach((array) old('program_ids', []) as $programId)
                                            <input type="hidden" name="program_ids[]" value="{{ $programId }}">
                                        @endforeach
                                    @endif
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    Save Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div
                id="savedFiltersModal"
                class="modal fade text-start"
                tabindex="-1"
                aria-labelledby="savedFiltersModalLabel"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false"
            >
                <div class="modal-dialog modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="savedFiltersModalLabel">
                                Saved Filters
                            </h5>
                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"
                                aria-label="Close"
                            ></button>
                        </div>

                        <div class="modal-body">
                            @forelse($savedSearchFilters as $savedFilter)
                                <div class="d-flex justify-content-between align-items-center gap-3 border-bottom py-2">
                                    <span>{{ $savedFilter->name }}</span>

                                    <div class="d-flex gap-2">
                                        <a
                                            href="{{ route('search.filters.apply', [
                                                'savedFilterId' => $savedFilter->id,
                                                'query' => $searchTerm,
                                            ]) }}"
                                            class="btn btn-primary btn-sm"
                                        >
                                            Apply
                                        </a>

                                        <form
                                            method="POST"
                                            action="{{ route('search.filters.destroy', $savedFilter->id) }}"
                                            onsubmit="return confirm('Delete this saved filter?')"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <p class="mb-0 text-muted">You do not have any saved filters.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endauth

    </div>
    
    @php
        $hasSelectedResults = $selectedView === 'courses'
            ? $stats['courses'] > 0
            : $stats['programs'] > 0;

        $visibleStats = collect([
            'Courses' => $stats['courses'],
            'Programs' => $stats['programs'],
            'Topics' => $stats['topics'],
            'Learning Objectives' => $stats['learning_outcomes'],
            'Assessments' => $stats['assessments'],
            'Descriptions' => $stats['descriptions'],
            'Materials' => $stats['materials'],
        ])->filter(fn ($count) => $count > 0);
        $contentStats = $visibleStats->except(['Courses', 'Programs']);

        $allPropertiesSelected = count($selectedProperties) === count($propertyOptions);
        $selectedPropertyLabels = collect($selectedProperties)
            ->map(fn ($property) => $propertyOptions[$property] ?? $property)
            ->values()
            ->all();
    @endphp

    <div class="text-center mb-3">
        <span class="search-summary-chip">
            View: {{ $selectedView === 'programs' ? 'Programs' : 'Courses' }}
        </span>

        <span class="search-summary-chip">
            Properties:
            {{ $allPropertiesSelected ? 'All' : (empty($selectedPropertyLabels) ? 'None' : implode(', ', $selectedPropertyLabels)) }}
        </span>

        @if(!empty($selectedCourseCodes))
            <span class="search-summary-chip">
                Course Codes: {{ implode(', ', $selectedCourseCodes) }}
            </span>
        @endif

        @if(!empty($selectedCourseLevels))
            <span class="search-summary-chip">
                Levels: {{ implode(', ', $selectedCourseLevels) }}
            </span>
        @endif

        @if(!empty($selectedProgramNames))
            <span class="search-summary-chip">
                Programs: {{ implode(', ', $selectedProgramNames) }}
            </span>
        @endif

        @if($searchTerm !== '' || !$allPropertiesSelected || !empty($selectedCourseCodes) || !empty($selectedCourseLevels) || !empty($selectedProgramNames) || $selectedView !== 'courses')
            <a href="{{ route('search.index') }}" class="search-clear-filters ms-2">
                Clear search
            </a>
        @endif
    </div>

    @if($searchTerm !== '' && $hasSelectedResults)
        <div class="search-stats text-center mb-4">
            <span class="search-filter-heading me-2">Found in</span>

            @if($stats['courses'] > 0)
                <a href="#" data-bs-toggle="modal" data-bs-target="#matchedCoursesModal">
                    Courses: {{ $stats['courses'] }}
                </a>
            @endif

            @if($stats['programs'] > 0)
                @if($stats['courses'] > 0)<span class="mx-2">|</span>@endif
                <a href="#" data-bs-toggle="modal" data-bs-target="#matchedProgramsModal">
                    Programs: {{ $stats['programs'] }}
                </a>
            @endif

            @if(($stats['courses'] > 0 || $stats['programs'] > 0) && $contentStats->isNotEmpty())
                <span class="search-stats-divider"></span>
            @endif

            @foreach($contentStats as $label => $count)
                @if(!$loop->first)<span class="mx-2">|</span>@endif
                <span>{{ $label }}: {{ $count }}</span>
            @endforeach
        </div>

        <div class="modal fade" id="matchedCoursesModal" tabindex="-1" aria-labelledby="matchedCoursesModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="matchedCoursesModalLabel">Matched Courses</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body search-stats-modal-list">
                        @forelse($courseQuickLinks as $course)
                            <div class="mb-2">
                                <a href="{{ route('courseWizard.step8', $course->course_id) }}">
                                    {{ $course->course_code }} {{ $course->course_num }}: {{ $course->course_title }}
                                </a>
                            </div>
                        @empty
                            <p class="mb-0 text-muted">No matching courses found.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="matchedProgramsModal" tabindex="-1" aria-labelledby="matchedProgramsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="matchedProgramsModalLabel">Matched Programs</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body search-stats-modal-list">
                        @forelse($programQuickLinks as $program)
                            <div class="mb-2">
                                <a href="{{ route('programWizard.step1', $program->program_id) }}">
                                    {{ $program->program }}
                                </a>
                            </div>
                        @empty
                            <p class="mb-0 text-muted">No matching programs found.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @elseif($searchTerm !== '')
        <p class="text-center">No matches found.</p>
    @endif

    @if($selectedView === 'courses')
        @foreach($results as $result)
            <div class="border-bottom py-3">
                <h3 class="mb-1">
                    <a href="{{ route('courseWizard.step8', $result->course_id) }}">
                        @if($result->course_match_snippet)
                            {!! $result->course_match_snippet !!}
                        @else
                            {{ $result->course_code }} {{ $result->course_num }}: {{ $result->course_title }}
                        @endif
                    </a>
                </h3>

                @if($result->programs->isNotEmpty())
                    <div class="small mb-2">
                        <span class="text-muted">Programs:</span>
                        @foreach($result->programs as $program)
                            <a href="{{ route('programWizard.step1', $program->program_id) }}">{{ $program->program }}</a>@if(!$loop->last), @endif
                        @endforeach
                    </div>
                @endif

                @if(array_sum($result->match_stats) > 0)
                    <div class="course-match-stats mb-2">
                        <span>Found in:</span>

                        @if($result->match_stats['topics'] > 0)
                            <span class="ms-2">Topics: {{ $result->match_stats['topics'] }}</span>
                        @endif

                        @if($result->match_stats['learning_outcomes'] > 0)
                            <span class="ms-2">Learning Objectives: {{ $result->match_stats['learning_outcomes'] }}</span>
                        @endif

                        @if($result->match_stats['assessments'] > 0)
                            <span class="ms-2">Assessments: {{ $result->match_stats['assessments'] }}</span>
                        @endif

                        @if($result->match_stats['descriptions'] > 0)
                            <span class="ms-2">Descriptions: {{ $result->match_stats['descriptions'] }}</span>
                        @endif

                        @if($result->match_stats['materials'] > 0)
                            <span class="ms-2">Materials: {{ $result->match_stats['materials'] }}</span>
                        @endif
                    </div>
                @endif

                @foreach($result->matches->take(3) as $match)
                    <div class="search-result-match">
                        <p>
                            <strong>{{ $match->property === 'learning outcome' ? 'Learning Objective' : ucfirst($match->property) }}:</strong>
                            {!! $match->snippet !!}
                        </p>
                    </div>
                @endforeach

                @if($result->matches->count() > 3)
                    <details class="search-extra-matches">
                        <summary>Show {{ $result->matches->count() - 3 }} more matches...</summary>

                        <div class="mt-2">
                            @foreach($result->matches->slice(3) as $match)
                                <div class="search-result-match">
                                    <p>
                                        <strong>{{ $match->property === 'learning outcome' ? 'Learning Objective' : ucfirst($match->property) }}:</strong>
                                        {!! $match->snippet !!}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        @endforeach

        @if($results->hasPages())
            <div class="mt-4 d-flex justify-content-center">
                {{ $results->links() }}
            </div>
        @endif
    @else
        @foreach($programResults as $programResult)
            <div class="border-bottom py-3">
                <h3 class="mb-1">
                    <a href="{{ route('programWizard.step1', $programResult->program_id) }}">
                        @if($programResult->program_match_snippet)
                            {!! $programResult->program_match_snippet !!}
                        @else
                            {{ $programResult->program }}
                        @endif
                    </a>
                </h3>

                <div class="small text-muted mb-2">
                    Matching courses: {{ $programResult->courses->count() }}
                </div>

                @foreach($programResult->courses as $course)
                    <div class="program-course-result">
                        <h5 class="mb-2">
                            <a href="{{ route('courseWizard.step8', $course->course_id) }}">
                                @if($course->course_match_snippet)
                                    {!! $course->course_match_snippet !!}
                                @else
                                    {{ $course->course_code }} {{ $course->course_num }}: {{ $course->course_title }}
                                @endif
                            </a>
                        </h5>

                        @foreach($course->matches->take(3) as $match)
                            <div class="search-result-match">
                                <p>
                                    <strong>{{ $match->property === 'learning outcome' ? 'Learning Objective' : ucfirst($match->property) }}:</strong>
                                    {!! $match->snippet !!}
                                </p>
                            </div>
                        @endforeach

                        @if($course->matches->count() > 3)
                            <details class="search-extra-matches">
                                <summary>Show {{ $course->matches->count() - 3 }} more matches...</summary>

                                <div class="mt-2">
                                    @foreach($course->matches->slice(3) as $match)
                                        <div class="search-result-match">
                                            <p>
                                                <strong>{{ $match->property === 'learning outcome' ? 'Learning Objective' : ucfirst($match->property) }}:</strong>
                                                {!! $match->snippet !!}
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        @if($programResults->hasPages())
            <div class="mt-4 d-flex justify-content-center">
                {{ $programResults->links() }}
            </div>
        @endif
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchForm = document.getElementById('courseSearchForm');
            const allProperties = document.getElementById('allProperties');
            const propertyOptions = Array.from(document.querySelectorAll('.property-filter-option'));

            function updatePropertyControls() {
                if (allProperties.checked) {
                    propertyOptions.forEach(function (option) {
                        option.checked = true;
                        option.disabled = true;
                    });
                    return;
                }

                propertyOptions.forEach(function (option) {
                    option.disabled = false;
                });
            }

            allProperties.addEventListener('change', updatePropertyControls);

            propertyOptions.forEach(function (option) {
                option.addEventListener('change', function () {
                    allProperties.checked = propertyOptions.every(function (propertyOption) {
                        return propertyOption.checked;
                    });

                    updatePropertyControls();
                });
            });

            searchForm.addEventListener('submit', function () {
                propertyOptions.forEach(function (option) {
                    option.disabled = false;
                });
            });

            updatePropertyControls();

            const courseCodeSearch = document.getElementById('courseCodeSearch');
            const courseCodeOptionsContainer = document.getElementById('courseCodeOptions');
            const selectedCourseCodeChips = document.getElementById('selectedCourseCodeChips');
            const selectedCourseCodeInputs = document.getElementById('selectedCourseCodeInputs');
            const courseCodeChipSelector = document.getElementById('courseCodeChipSelector');
            const courseCodeOptions = @json($availableCourseCodes);
            const selectedCourseCodes = new Set(@json($selectedCourseCodes).map(function (courseCode) {
                return String(courseCode);
            }));
            const programSearch = document.getElementById('programSearch');
            const programOptionsContainer = document.getElementById('programOptions');
            const selectedProgramChips = document.getElementById('selectedProgramChips');
            const selectedProgramInputs = document.getElementById('selectedProgramInputs');
            const programChipSelector = document.getElementById('programChipSelector');
            const programOptions = @json($availablePrograms);
            const selectedPrograms = new Set(@json($selectedProgramIds).map(function (programId) {
                return String(programId);
            }));
            const clearSearchFilters = document.getElementById('clearSearchFilters');
            const searchFiltersButton = document.getElementById('searchFiltersButton');
            const closeSearchFilters = document.getElementById('closeSearchFilters');
            const openSaveFilterModal = document.getElementById('openSaveFilterModal');
            const openSavedFiltersModal = document.getElementById('openSavedFiltersModal');
            const savedFilterValues = document.getElementById('savedFilterValues');

            function closeSearchFilterMenu() {
                window.bootstrap.Dropdown.getOrCreateInstance(searchFiltersButton).hide();
            }

            closeSearchFilters.addEventListener('click', closeSearchFilterMenu);

            //Adds one selected filter as a hidden field in the save-filter form
            function addSavedFilterValue(name, value) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                savedFilterValues.appendChild(input);
            }

            //Copies the current filter controls into the separate save-filter form
            function prepareSavedFilterValues() {
                savedFilterValues.innerHTML = '';

                const selectedView = document.querySelector('input[name="view"]:checked');
                addSavedFilterValue('view', selectedView ? selectedView.value : 'courses');
                addSavedFilterValue('property_filters_applied', '1');

                propertyOptions.forEach(function (option) {
                    if (option.checked) {
                        addSavedFilterValue('properties[]', option.value);
                    }
                });

                selectedCourseCodes.forEach(function (courseCode) {
                    addSavedFilterValue('course_codes[]', courseCode);
                });

                document.querySelectorAll('input[name="course_levels[]"]:checked').forEach(function (level) {
                    addSavedFilterValue('course_levels[]', level.value);
                });

                selectedPrograms.forEach(function (programId) {
                    addSavedFilterValue('program_ids[]', programId);
                });
            }

            if (openSaveFilterModal && savedFilterValues) {
                openSaveFilterModal.addEventListener('click', function () {
                    prepareSavedFilterValues();
                    closeSearchFilterMenu();
                });
            }

            if (openSavedFiltersModal) {
                openSavedFiltersModal.addEventListener('click', closeSearchFilterMenu);
            }

            //Rebuilds the selected course code chips and the hidden form inputs
            function renderSelectedCourseCodes() {
                selectedCourseCodeChips.innerHTML = '';
                selectedCourseCodeInputs.innerHTML = '';

                selectedCourseCodes.forEach(function (courseCode) {
                    //The visible chip lets users see and remove selected course codes
                    const chip = document.createElement('span');
                    chip.className = 'search-filter-chip';
                    chip.textContent = courseCode;

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.setAttribute('aria-label', 'Remove ' + courseCode);
                    removeButton.textContent = 'x';
                    removeButton.addEventListener('click', function () {
                        selectedCourseCodes.delete(courseCode);
                        renderSelectedCourseCodes();
                        renderCourseCodeOptions();
                    });

                    //backend still expects course_codes[] so each chip needs a matching hidden input
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'course_codes[]';
                    hiddenInput.value = courseCode;

                    chip.appendChild(removeButton);
                    selectedCourseCodeChips.appendChild(chip);
                    selectedCourseCodeInputs.appendChild(hiddenInput);
                });
            }

            //Shows matching course-code options based on what the user has typed
            function renderCourseCodeOptions() {
                const searchText = courseCodeSearch.value.trim().toLowerCase();
                courseCodeOptionsContainer.innerHTML = '';

                //do not show already selected course codes and keep the dropdown short
                const matchingCourseCodes = courseCodeOptions
                    .filter(function (courseCode) {
                        return !selectedCourseCodes.has(courseCode)
                            && (!searchText || courseCode.toLowerCase().includes(searchText));
                    })
                    .slice(0, 8);

                if (matchingCourseCodes.length === 0) {
                    courseCodeOptionsContainer.style.display = 'none';
                    return;
                }

                matchingCourseCodes.forEach(function (courseCode) {
                    //selecting an option turns it into a chip instead of submitting right away
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'search-chip-option';
                    option.textContent = courseCode;
                    option.addEventListener('click', function () {
                        selectedCourseCodes.add(courseCode);
                        courseCodeSearch.value = '';
                        renderSelectedCourseCodes();
                        renderCourseCodeOptions();
                        courseCodeSearch.focus();
                    });

                    courseCodeOptionsContainer.appendChild(option);
                });

                courseCodeOptionsContainer.style.display = 'block';
            }

            courseCodeSearch.addEventListener('focus', renderCourseCodeOptions);
            courseCodeSearch.addEventListener('input', renderCourseCodeOptions);

            //Rebuilds the selected program chips and the hidden inputs submitted to Laravel
            function renderSelectedPrograms() {
                selectedProgramChips.innerHTML = '';
                selectedProgramInputs.innerHTML = '';

                selectedPrograms.forEach(function (programId) {
                    const program = programOptions.find(function (programOption) {
                        return String(programOption.program_id) === programId;
                    });

                    if (!program) {
                        return;
                    }

                    //The chip is what the user sees, while the hidden input keeps normal form submission working
                    const chip = document.createElement('span');
                    chip.className = 'search-filter-chip';
                    chip.textContent = program.program;

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.setAttribute('aria-label', 'Remove ' + program.program);
                    removeButton.textContent = 'x';
                    removeButton.addEventListener('click', function () {
                        selectedPrograms.delete(programId);
                        renderSelectedPrograms();
                        renderProgramOptions();
                    });

                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'program_ids[]';
                    hiddenInput.value = programId;

                    chip.appendChild(removeButton);
                    selectedProgramChips.appendChild(chip);
                    selectedProgramInputs.appendChild(hiddenInput);
                });
            }

            //Shows program options that match the typed text and are not already selected
            function renderProgramOptions() {
                const searchText = programSearch.value.trim().toLowerCase();
                programOptionsContainer.innerHTML = '';

                const matchingPrograms = programOptions
                    .filter(function (program) {
                        const programId = String(program.program_id);

                        return !selectedPrograms.has(programId)
                            && (!searchText || program.program.toLowerCase().includes(searchText));
                    })
                    .slice(0, 8);

                if (matchingPrograms.length === 0) {
                    programOptionsContainer.style.display = 'none';
                    return;
                }

                matchingPrograms.forEach(function (program) {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'search-chip-option';
                    option.textContent = program.program;
                    option.addEventListener('click', function () {
                        selectedPrograms.add(String(program.program_id));
                        programSearch.value = '';
                        renderSelectedPrograms();
                        renderProgramOptions();
                        programSearch.focus();
                    });

                    programOptionsContainer.appendChild(option);
                });

                programOptionsContainer.style.display = 'block';
            }

            programSearch.addEventListener('focus', renderProgramOptions);
            programSearch.addEventListener('input', renderProgramOptions);

            clearSearchFilters.addEventListener('click', function () {
                allProperties.checked = true;
                propertyOptions.forEach(function (option) {
                    option.checked = true;
                });
                selectedCourseCodes.clear();
                selectedPrograms.clear();
                document.querySelectorAll('input[name="course_levels[]"]').forEach(function (levelOption) {
                    levelOption.checked = false;
                });

                updatePropertyControls();
                renderSelectedCourseCodes();
                renderSelectedPrograms();
                propertyOptions.forEach(function (option) {
                    option.disabled = false;
                });
                searchForm.submit();
            });

            document.addEventListener('click', function (event) {
                if (!courseCodeChipSelector.contains(event.target)) {
                    courseCodeOptionsContainer.style.display = 'none';
                }

                if (!programChipSelector.contains(event.target)) {
                    programOptionsContainer.style.display = 'none';
                }
            });

            renderSelectedCourseCodes();
            renderSelectedPrograms();

            @if($errors->has('name'))
                const saveFilterModal = document.getElementById('saveFilterModal');
                if (saveFilterModal) {
                    window.bootstrap.Modal.getOrCreateInstance(saveFilterModal).show();
                }
            @endif
        });
    </script>
@endsection
