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
                font-size: 13px;
                margin: 0 0 4px;
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

            .truncation-notice {
                background: #fff3cd;
                border: 1px solid #e4cf87;
                margin-bottom: 18px;
                padding: 7px;
            }

            .result {
                border-bottom: 1px solid #c8d0d9;
                margin-bottom: 12px;
                padding-bottom: 10px;
                page-break-inside: avoid;
            }

            .programs,
            .match-counts {
                color: #555;
                margin: 3px 0;
            }

            .match {
                margin: 6px 0 0 12px;
            }

            mark {
                background: #fff3a6;
            }
        </style>
    </head>
    <body>
        <h1>Course Search Results</h1>
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
            @foreach([
                'courses' => 'Courses',
                'programs' => 'Programs',
                'topics' => 'Topics',
                'learning_outcomes' => 'Learning Objectives',
                'assessments' => 'Assessments',
                'descriptions' => 'Descriptions',
                'materials' => 'Materials',
                'material_content' => 'Material Content',
            ] as $key => $label)
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ $stats[$key] }}</td>
                </tr>
            @endforeach
        </table>

        @if($resultLimit['truncated'])
            <div class="truncation-notice">
                Showing detailed results for the first {{ $resultLimit['rendered'] }} of
                {{ $resultLimit['total'] }} matching courses. Search statistics reflect the complete result set.
            </div>
        @endif

        @forelse($results as $result)
            <div class="result">
                <h2>{{ $result->course_code }} {{ $result->course_num }}: {{ $result->course_title }}</h2>

                <div class="programs">
                    <strong>Programs:</strong>
                    {{ $result->programs->pluck('program')->implode(', ') ?: 'None' }}
                </div>

                <div class="match-counts">
                    <strong>Found in:</strong>
                    @if($result->is_course_match) Course Identity: 1; @endif
                    @foreach([
                        'topics' => 'Topics',
                        'learning_outcomes' => 'Learning Objectives',
                        'assessments' => 'Assessments',
                        'descriptions' => 'Descriptions',
                        'materials' => 'Materials',
                        'material_content' => 'Material Content',
                    ] as $key => $label)
                        @if($result->match_stats[$key] > 0)
                            {{ $label }}: {{ $result->match_stats[$key] }};
                        @endif
                    @endforeach
                </div>

                @foreach($result->matches as $match)
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
            <p>No matching courses were found.</p>
        @endforelse
    </body>
</html>
