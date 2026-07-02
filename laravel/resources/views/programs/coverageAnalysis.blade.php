@extends('layouts.app')

@section('content')
<style>
    .material-status {
        display: inline-block;
        padding: 0.25em 0.6em;
        font-size: 0.75em;
        font-weight: 700;
        line-height: 1;
        color: #fff;
        text-align: center;
        white-space: nowrap;
        vertical-align: baseline;
        border-radius: 0.25rem;
    }
    .material-status--indexed { background-color: #198754; }
    .material-status--indexing { background-color: #6EC4E8; color: #212529; }
    .material-status--pending  { background-color: #6c757d; }
    .material-status--failed   { background-color: #dc3545; }
    .material-status--ocr      { background-color: #ffc107; color: #212529; }
</style>
<div class="mt-4 mb-5">
    <div class="row">
        <div class="col">
            <h3>Program: {{ $program->program }}</h3>
            <h5 class="text-muted">{{ $program->faculty }} &middot; {{ $program->department }}</h5>
        </div>
        <div class="col-auto">
            <a href="{{ route('programWizard.step1', $program->program_id) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back to Program Wizard
            </a>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><h5 class="mb-0">Coverage Analysis (WIP)</h5></div>
        <div class="card-body">
            <form method="GET" action="{{ route('program.materials.search', $program->program_id) }}" class="d-flex gap-2">
                <input type="text" name="query" class="form-control" placeholder="Search extracted text..."
                    value="{{ session('search_query', '') }}" required>
                <button type="submit" class="btn btn-primary text-nowrap">Search</button>
            </form>
        </div>
    </div>

    @if (session('search_results') !== null)
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">
                Search results for <em>{{ session('search_query') }}</em>
                <span class="text-muted fw-normal">({{ session('search_results')->count() }} result(s))</span>
            </h6></div>
            <div class="card-body">
                @if (session('search_results')->isEmpty())
                    <p class="text-muted mb-0">No results found.</p>
                @else
                    @foreach (session('search_results') as $result)
                        <a href="{{ route('course.materials.view', ['course' => $result->course_id, 'materialId' => $result->material_id]) }}#page={{ $result->page_number }}"
                           target="_blank" class="text-decoration-none text-reset">
                            <div class="border rounded p-3 mb-2">
                                <div class="row g-3 align-items-center">
                                    <div class="col-12 col-md">
                                        <div class="mb-1">
                                            <strong>{{ $result->file_name }}</strong>
                                            <span class="text-muted ms-2">Page {{ $result->page_number }}</span>
                                        </div>
                                        <div>{!! $result->snippet !!}</div>
                                    </div>
                                    <div class="col-12 col-md-4 text-md-end">
                                        <img src="{{ route('course.materials.thumbnail', ['course' => $result->course_id, 'materialId' => $result->material_id, 'page' => $result->page_number]) }}"
                                             alt="Page {{ $result->page_number }} thumbnail"
                                             class="rounded img-fluid" style="max-height:120px;width:auto;object-fit:contain;">
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                @endif
            </div>
        </div>
    @endif

    @if ($courses->isEmpty())
        <div class="card mt-3">
            <div class="card-body">
                <p class="text-muted mb-0">No courses are mapped to this program yet.</p>
            </div>
        </div>
    @else
        @foreach ($courses as $course)
            @php
                $courseMaterials = $materialsByCourse->get($course->course_id, collect());
                $indexedCount = $courseMaterials->where('status', 'INDEXED')->count();
            @endphp
            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>{{ $course->course_code }} {{ $course->course_num }}</strong>
                        <span class="text-muted">&middot; {{ $course->course_title }}</span>
                    </div>
                    <div>
                        <span class="badge bg-primary me-2">
                            {{ $indexedCount }} / {{ $courseMaterials->count() }} indexed
                        </span>
                        <a class="btn btn-sm btn-outline-primary"
                            href="{{ route('course.coverageAnalysis', $course->course_id) }}">
                            Open course page
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    @if ($courseMaterials->isEmpty())
                        <p class="text-muted mb-0">No materials uploaded for this course.</p>
                    @else
                        <div class="accordion" id="programAccordion{{ $course->course_id }}">
                            @foreach ($courseMaterials as $material)
                                @php
                                    $heading = 'progMaterialHeading' . $material->id;
                                    $body = 'progMaterialBody' . $material->id;
                                @endphp
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="{{ $heading }}">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                            data-bs-target="#{{ $body }}" aria-expanded="false" aria-controls="{{ $body }}">
                                            <div class="d-flex w-100 justify-content-between align-items-center pe-3">
                                                <div>
                                                    <strong>{{ $material->file_name }}</strong>
                                                    <small class="text-muted ms-2">
                                                        {{ $material->page_count ?? '?' }} pages,
                                                        {{ number_format($material->file_size / 1024, 1) }} KB
                                                    </small>
                                                </div>
                                                <div>
                                                    @if ($material->ocr_enabled)
                                                        @php
                                                            if ($material->extraction_engine === 'textract') {
                                                                $badgeLabel = 'OCR (AWS)';
                                                                $tooltipTitle = 'AWS Textract';
                                                            } else {
                                                                $badgeLabel = 'OCR';
                                                                $tooltipTitle = 'Tesseract';
                                                            }
                                                            if ($material->processing_time_seconds !== null) {
                                                                $tooltipTitle .= ' (' . $material->processing_time_seconds . 's)';
                                                            }
                                                        @endphp
                                                        <span class="material-status material-status--ocr me-1"
                                                            data-bs-toggle="tooltip"
                                                            data-bs-placement="left"
                                                            title="{{ $tooltipTitle }}">
                                                            {{ $badgeLabel }}
                                                        </span>
                                                    @endif
                                                    @switch($material->status)
                                                        @case('INDEXED')
                                                            <span class="material-status material-status--indexed">Indexed</span>
                                                            @break
                                                        @case('INDEXING')
                                                            <span class="material-status material-status--indexing">Indexing</span>
                                                            @break
                                                        @case('PENDING')
                                                            <span class="material-status material-status--pending">Pending</span>
                                                            @break
                                                        @case('FAILED')
                                                            <span class="material-status material-status--failed" title="{{ $material->error_message }}">Failed</span>
                                                            @break
                                                    @endswitch
                                                </div>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="{{ $body }}" class="accordion-collapse collapse"
                                        aria-labelledby="{{ $heading }}" data-bs-parent="#programAccordion{{ $course->course_id }}">
                                        <div class="accordion-body">

                                            <div class="d-flex gap-2 mb-3">
                                                <a class="btn btn-sm btn-outline-primary"
                                                    href="{{ route('course.materials.download', ['course' => $course->course_id, 'materialId' => $material->id]) }}">
                                                    <i class="bi bi-download"></i> Download original
                                                </a>
                                            </div>

                                            @if ($material->status === 'FAILED')
                                                <div class="alert alert-danger">
                                                    <strong>Indexing failed.</strong>
                                                    <div><code>{{ $material->error_message }}</code></div>
                                                </div>
                                            @elseif ($material->chunks->isEmpty())
                                                @if ($material->status === 'INDEXED')
                                                    @if ($material->ocr_enabled)
                                                        <p class="text-muted mb-0">No extracted content. OCR did not recover any readable text from this PDF either.</p>
                                                    @else
                                                        <p class="text-muted mb-0">No extracted content. Retry this file with the OCR option checked.</p>
                                                    @endif
                                                @else
                                                    <p class="text-muted mb-0">No extracted content yet.</p>
                                                @endif
                                            @else
                                                <p class="text-muted small">
                                                    {{ $material->chunks->count() }} page(s) extracted.
                                                </p>
                                                @foreach ($material->chunks as $chunk)
                                                    <details class="mb-2">
                                                        <summary>
                                                            <strong>Page {{ $chunk->page_number }}</strong>
                                                            <small class="text-muted">({{ str_word_count($chunk->content) }} words)</small>
                                                        </summary>
                                                        <pre class="bg-light border p-2 mt-2 mb-0" style="white-space: pre-wrap; max-height: 400px; overflow-y: auto;">{{ $chunk->content }}</pre>
                                                    </details>
                                                @endforeach
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
    });
</script>
@endsection
