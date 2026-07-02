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
            <h3>Course: {{ $course->year }} {{ $course->semester }} {{ $course->course_code }} {{ $course->course_num }} {{ $course->section }}</h3>
            <h5 class="text-muted">{{ $course->course_title }}</h5>
        </div>
        <div class="col-auto">
            <a href="{{ route('courseWizard.step1', $course->course_id) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back to Course Wizard
            </a>
        </div>
    </div>

    @if (request('upload_error') === 'too_large')
        <div class="alert alert-danger mt-3">
            The uploaded file is too large. The current server limit is {{ request('limit', '?') }}B.
            Please try a smaller file.
        </div>
    @endif

    <div class="card mt-4">
        <div class="card-header"><h5 class="mb-0">Coverage Analysis (WIP)</h5></div>
        <div class="card-body">
            <form method="GET" action="{{ route('course.materials.search', $course->course_id) }}" class="d-flex gap-2">
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
                <span class="text-muted fw-normal">({{ session('search_results')->count() }} results)</span>
            </h6></div>
            <div class="card-body">
                @if (session('search_results')->isEmpty())
                    <p class="text-muted mb-0">No results found.</p>
                @else
                    @foreach (session('search_results') as $result)
                        <a href="{{ route('course.materials.view', ['course' => $course->course_id, 'materialId' => $result->material_id]) }}#page={{ $result->page_number }}"
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
                                        <img src="{{ route('course.materials.thumbnail', ['course' => $course->course_id, 'materialId' => $result->material_id, 'page' => $result->page_number]) }}"
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

    <div class="card mt-3">
        <div class="card-header"><h6 class="mb-0">Upload Material</h6></div>
        <div class="card-body">
            @if ($canEdit)
                <form id="uploadForm" action="{{ route('course.materials.store', $course->course_id) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label for="materialFile" class="form-label">PDF (max 50MB)</label>
                            <input type="file" id="materialFile" name="file" accept="application/pdf" class="form-control" required
                                onchange="validateMaterialSize(this)">
                            <small id="materialSizeFeedback" class="form-text text-danger d-none"></small>
                        </div>
                        <div class="col-md-4">
                            <button id="uploadBtn" type="submit" class="btn btn-primary w-100">Upload</button>
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input type="hidden" name="ocr_enabled" value="0">
                        <input class="form-check-input" type="checkbox" id="ocrEnabled" name="ocr_enabled" value="1" onchange="toggleOcrAdvanced(this)">
                        <label class="form-check-label" for="ocrEnabled">
                            Perform OCR on image-based pages
                        </label>
                        <i class="bi bi-question-circle ms-1 text-primary"
                            style="cursor: help;"
                            data-bs-toggle="tooltip"
                            data-bs-placement="right"
                            data-bs-html="true"
                            title="Enable this for scanned PDFs or slide decks where text is rendered as images (i.e. you can't select the text in a PDF viewer)."></i>
                    </div>

                    <div id="ocrAdvanced" class="mt-2 ms-4 d-none">
                        <a class="small text-decoration-none" data-bs-toggle="collapse" href="#ocrAdvancedBody" role="button" aria-expanded="false" aria-controls="ocrAdvancedBody">
                            <i class="bi bi-caret-right-fill"></i> Advanced settings
                        </a>
                        <div class="collapse" id="ocrAdvancedBody">
                            <div class="row g-2 align-items-end mt-2">
                                <div class="col-md-4">
                                    <label for="extractionEngine" class="form-label small mb-0">
                                        Extraction Engine
                                        <i class="bi bi-question-circle ms-1 text-primary"
                                            style="cursor: help;"
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="right"
                                            title="Tesseract OCR runs in the extraction service. AWS Textract is a cloud OCR service (~$0.15 per 1000 pages)."></i>
                                    </label>
                                    <select id="extractionEngine" name="extraction_engine" class="form-select form-select-sm" onchange="toggleThresholdByEngine(this)">
                                        <option value="tesseract">Tesseract</option>
                                        <option value="textract">AWS Textract (cloud)</option>
                                    </select>
                                </div>
                            </div>

                            <div id="thresholdRow" class="row g-2 align-items-end mt-2">
                                <div class="col-md-4">
                                    <label for="ocrThreshold" class="form-label small mb-0">
                                        OCR threshold (characters)
                                        <i class="bi bi-question-circle ms-1 text-primary"
                                            style="cursor: help;"
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="right"
                                            title="If the number of words of selectable text on a page is less than or equal to the OCR threshold, then perform OCR on the page. Otherwise, just extract selectable text."></i>
                                    </label>
                                    <input type="number" id="ocrThreshold" name="ocr_threshold" min="0" max="100000" value="0" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            @else
                <p class="text-muted mb-0">You have view-only access; uploads are disabled.</p>
            @endif
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Extracted Materials</h6>
            <small class="text-muted">{{ $materials->count() }} file(s)</small>
        </div>
        <div class="card-body">
            @if ($materials->isEmpty())
                <p class="text-muted mb-0">No materials uploaded yet.</p>
            @else
                @if ($materials->contains(fn ($m) => in_array($m->status, ['PENDING', 'INDEXING'])))
                    <div class="alert alert-info py-2">
                        Some materials are still indexing. Refresh in a moment.
                    </div>
                @endif

                <div class="accordion" id="materialsAccordion">
                    @foreach ($materials as $material)
                        @php
                            $heading = 'materialHeading' . $material->id;
                            $body = 'materialBody' . $material->id;
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
                                                {{ number_format($material->file_size / 1024, 1) }} KB,
                                                uploaded {{ $material->created_at->format('Y-m-d H:i') }}
                                                @if ($material->uploader) by {{ $material->uploader->name }} @endif
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
                                aria-labelledby="{{ $heading }}" data-bs-parent="#materialsAccordion">
                                <div class="accordion-body">
                                    <div class="d-flex gap-2 mb-3">
                                        <a class="btn btn-sm btn-outline-primary"
                                            href="{{ route('course.materials.download', ['course' => $course->course_id, 'materialId' => $material->id]) }}">
                                            <i class="bi bi-download"></i> Download original
                                        </a>
                                        @if ($canEdit)
                                            <form action="{{ route('course.materials.destroy', ['course' => $course->course_id, 'materialId' => $material->id]) }}"
                                                method="POST" onsubmit="return confirm('Delete this material and its extracted content?');" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        @endif
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
                                            {{ $material->chunks->count() }} page(s) extracted. Showing raw text per page.
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
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

        const uploadForm = document.getElementById('uploadForm');
        const uploadBtn = document.getElementById('uploadBtn');
        if (uploadForm && uploadBtn) {
            uploadForm.addEventListener('submit', function () {
                uploadBtn.textContent = 'Uploading...';
                uploadBtn.disabled = true;
            });
        }
    });

    function toggleOcrAdvanced(checkbox) {
        const panel = document.getElementById('ocrAdvanced');
        if (!panel) return;
        if (checkbox.checked) {
            panel.classList.remove('d-none');
        } else {
            panel.classList.add('d-none');
            const body = document.getElementById('ocrAdvancedBody');
            if (body && body.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(body).hide();
            }
        }
    }

    function toggleThresholdByEngine(select) {
        const thresholdRow = document.getElementById('thresholdRow');
        if (select.value === 'tesseract') {
            thresholdRow.classList.remove('d-none');
        } else {
            thresholdRow.classList.add('d-none');
        }
    }

    function validateMaterialSize(input) {
        const maxBytes = 50 * 1024 * 1024;
        const feedback = document.getElementById('materialSizeFeedback');
        if (input.files && input.files[0] && input.files[0].size > maxBytes) {
            const sizeMb = (input.files[0].size / 1024 / 1024).toFixed(1);
            feedback.textContent = `That file is ${sizeMb} MB; the maximum is 50 MB. Please choose a smaller file.`;
            feedback.classList.remove('d-none');
            input.value = '';
        } else {
            feedback.textContent = '';
            feedback.classList.add('d-none');
        }
    }
</script>
@endsection
