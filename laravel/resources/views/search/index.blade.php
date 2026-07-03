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
            min-width: 240px;
            padding: 1rem;
            border-radius: 6px;
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

        <form method="GET" action="{{ route('search.index') }}">
            <input type="hidden" name="property_filters_applied" value="1">

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
                    data-bs-auto-close="outside"
                    aria-expanded="false"
                    aria-label="Search settings"
                    title="Search settings"
                >
                    <i class="bi bi-gear"></i>
                </button>

                <div class="dropdown-menu dropdown-menu-end search-filter-menu" aria-labelledby="searchFiltersButton">
                    <div class="search-filter-heading">View</div>

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

                    <div class="search-filter-heading mt-3">Properties</div>

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

                    @foreach($propertyOptions as $value => $label)
                        <div class="form-check">
                            <input
                                class="form-check-input"
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

                <button type="submit" class="btn btn-primary search-action-button">Search</button>
            </div>

            @error('query')
                <div class='text-danger mt-2'>
                    {{$message}}
                </div>
            @enderror
        </form>
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
    @endphp

    @if($searchTerm !== '' && $hasSelectedResults)
        <div class="search-stats text-center mb-4">
            @foreach($visibleStats as $label => $count)
                @if(!$loop->first)<span class="mx-2">|</span>@endif
                <span>{{ $label }}: {{ $count }}</span>
            @endforeach
        </div>
    @elseif($searchTerm !== '')
        <p class="text-center">No matches found.</p>
    @endif

    @if($selectedView === 'courses')
        @foreach($results as $result)
            <div class="border-bottom py-3">
                @if($result->course_match_snippet)
                    <h3 class="mb-1">{!! $result->course_match_snippet !!}</h3>
                @else
                    <h3 class="mb-1">{{ $result->course_code }} {{ $result->course_num }}: {{ $result->course_title }}</h3>
                @endif

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
                            <a href="{{ route('courseWizard.step1', $course->course_id) }}">
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
@endsection
