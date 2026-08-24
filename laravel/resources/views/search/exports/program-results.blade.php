<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <style>
            body {
                color: #002145;
                font-family: DejaVu Sans, sans-serif;
                font-size: 10px;
                line-height: 1.4;
            }

            h1 {
                font-size: 20px;
                margin: 0 0 4px;
            }

            h2 {
                font-size: 14px;
                margin: 0 0 4px;
            }

            h3 {
                font-size: 12px;
                margin: 8px 0 3px;
            }

            .generated {
                color: #555;
                margin-bottom: 14px;
            }

            .summary {
                border-collapse: collapse;
                margin-bottom: 18px;
                width: 100%;
            }

            .summary th,
            .summary td {
                border: 1px solid #c8d0d9;
                padding: 5px 7px;
                text-align: left;
                vertical-align: top;
            }

            .summary th {
                background: #e9ecef;
                width: 20%;
            }

            .program {
                border-bottom: 1px solid #c8d0d9;
                margin-bottom: 14px;
                padding-bottom: 10px;
            }

            .course {
                margin-left: 12px;
                page-break-inside: avoid;
            }

            .program-match,
            .match-counts {
                color: #555;
                margin: 3px 0;
            }

            .match {
                margin: 5px 0 0 12px;
            }

            mark {
                background: #fff3a6;
            }
        </style>
    </head>
    <body>
        <h1>Program Search Results</h1>
        <div class="generated">Generated {{ now()->format('Y-m-d H:i') }}</div>

        <table class="summary">
            <tr>
                <th>Search Query</th>
                <td>{{ $searchTerm }}</td>
            </tr>
            @foreach($filterSummary as $label => $value)
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ $value }}</td>
                </tr>
            @endforeach
            <tr>
                <th>Programs</th>
                <td>{{ $programResults->count() }}</td>
            </tr>
            <tr>
                <th>Courses</th>
                <td>{{ $programResults->flatMap(fn ($program) => $program->courses)->unique('course_id')->count() }}</td>
            </tr>
        </table>

        @forelse($programResults as $programResult)
            <div class="program">
                <h2>{{ $programResult->program }}</h2>

                @if($programResult->program_match_snippet)
                    <div class="program-match">
                        <strong>Program name match:</strong>
                        @include('search.partials.highlighted-snippet', ['snippet' => $programResult->program_match_snippet])
                    </div>
                @endif

                <div class="program-match">
                    <strong>Matching courses:</strong> {{ $programResult->courses->count() }}
                </div>

                @forelse($programResult->courses as $course)
                    <div class="course">
                        <h3>{{ $course->course_code }} {{ $course->course_num }}: {{ $course->course_title }}</h3>

                        <div class="match-counts">
                            <strong>Found in:</strong>
                            @if($course->is_course_match) Course Identity: 1; @endif
                            @foreach([
                                'topics' => 'Topics',
                                'learning_outcomes' => 'Learning Objectives',
                                'assessments' => 'Assessments',
                                'descriptions' => 'Descriptions',
                                'materials' => 'Materials',
                                'material_content' => 'Material Content',
                            ] as $key => $label)
                                @if($course->match_stats[$key] > 0)
                                    {{ $label }}: {{ $course->match_stats[$key] }};
                                @endif
                            @endforeach
                        </div>

                        @foreach($course->matches as $match)
                            <div class="match">
                                <strong>{{ $match->property === 'learning outcome' ? 'Learning Objective' : ucwords($match->property) }}:</strong>
                                @if($match->property === 'material content')
                                    {{ $match->file_name }}, Page {{ $match->page_number }}<br>
                                @endif
                                @include('search.partials.highlighted-snippet', ['snippet' => $match->snippet])
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p>No course content matches were found in this program.</p>
                @endforelse
            </div>
        @empty
            <p>No matching programs were found.</p>
        @endforelse
    </body>
</html>
