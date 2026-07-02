@extends('layouts.app')

@section('content')

<div class="mt-4 mb-5">
    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                <i class="bi bi-file-earmark-pdf me-2"></i>{{ $file->file_name }}
            </h4>
            <p class="text-muted mb-0 small mt-1">
                {{ $file->courseMaterial->name }}
            </p>
        </div>
        <div class="col-auto">
            <a href="{{ route('courseWizard.step10', $course_id) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back to Course Materials
            </a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Extraction Details</h6>
            <div>
                @if ($file->ocr_enabled)
                    @php
                        if ($file->extraction_engine === 'textract') {
                            $ocrLabel = 'OCR (AWS)';
                            $ocrTip   = 'AWS Textract';
                        } else {
                            $ocrLabel = 'OCR';
                            $ocrTip   = 'Tesseract';
                        }
                        if ($file->processing_time_seconds !== null) {
                            $ocrTip .= ' (' . $file->processing_time_seconds . 's)';
                        }
                    @endphp
                    <span class="material-status material-status--ocr me-1"
                          data-bs-toggle="tooltip" data-bs-placement="left" title="{{ $ocrTip }}">
                        {{ $ocrLabel }}
                    </span>
                @endif
                @switch($file->status)
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
                        <span class="material-status material-status--failed">Failed</span>
                        @break
                @endswitch
            </div>
        </div>
        <div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-sm-3">Pages</dt>
                <dd class="col-sm-9">{{ $file->page_count ?? 'Unknown' }}</dd>

                <dt class="col-sm-3">File size</dt>
                <dd class="col-sm-9">{{ number_format($file->file_size / 1024, 1) }} KB</dd>

                <dt class="col-sm-3">Uploaded</dt>
                <dd class="col-sm-9">
                    {{ $file->created_at->format('Y-m-d H:i') }}
                    @if ($file->uploader) by {{ $file->uploader->name }} @endif
                </dd>

                <dt class="col-sm-3">Extraction engine</dt>
                <dd class="col-sm-9">
                    @if ($file->ocr_enabled)
                        {{ $file->extraction_engine === 'textract' ? 'AWS Textract' : 'Tesseract OCR' }}
                        @if ($file->extraction_engine !== 'textract')
                            <span class="text-muted">(threshold: {{ $file->ocr_threshold }} chars)</span>
                        @endif
                    @else
                        Text extraction only (no OCR)
                    @endif
                </dd>

                @if ($file->processing_time_seconds !== null)
                    <dt class="col-sm-3">Processing time</dt>
                    <dd class="col-sm-9">{{ $file->processing_time_seconds }}s</dd>
                @endif

                @if ($file->status === 'FAILED' && $file->error_message)
                    <dt class="col-sm-3">Error</dt>
                    <dd class="col-sm-9"><code class="text-danger">{{ $file->error_message }}</code></dd>
                @endif
            </dl>

            <div class="mt-3">
                <a href="{{ route('course.material.files.download', [$course_id, $material_id, $file->course_material_file_id]) }}"
                   class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-download"></i> Download original
                </a>
                <a href="{{ route('course.material.files.view', [$course_id, $material_id, $file->course_material_file_id]) }}"
                   class="btn btn-sm btn-outline-secondary ms-2" target="_blank">
                    <i class="bi bi-eye"></i> View PDF
                </a>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">Extracted Topics</h6>
        </div>
        <div class="card-body">
            @if ($file->topics->isEmpty())
                <p class="text-muted mb-0 small">
                    No topics were extracted from this file.
                </p>
            @else
                @foreach ($file->topics as $topic)
                    <span class="badge bg-light text-dark border me-1 mb-1 fw-normal">{{ $topic->topic }}</span>
                @endforeach
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Extracted Text</h6>
            <small class="text-muted">{{ $file->chunks->count() }} page(s)</small>
        </div>
        <div class="card-body">
            @if ($file->status === 'PENDING' || $file->status === 'INDEXING')
                <div class="alert alert-info py-2 mb-0">
                    This file is still being indexed. Refresh in a moment.
                </div>
            @elseif ($file->status === 'FAILED')
                <div class="alert alert-danger mb-0">
                    <strong>Indexing failed.</strong>
                    @if ($file->error_message)
                        <div><code>{{ $file->error_message }}</code></div>
                    @endif
                </div>
            @elseif ($file->chunks->isEmpty())
                @if ($file->ocr_enabled)
                    <p class="text-muted mb-0">No text was recovered. OCR did not find readable content in this PDF.</p>
                @else
                    <p class="text-muted mb-0">No text was extracted. Try re-uploading with the OCR option enabled.</p>
                @endif
            @else
                <p class="text-muted small mb-3">
                    Showing raw extracted text per page.
                </p>
                @foreach ($file->chunks as $chunk)
                    <details class="mb-2">
                        <summary>
                            <strong>Page {{ $chunk->page_number }}</strong>
                            <small class="text-muted ms-2">({{ str_word_count($chunk->content) }} words)</small>
                        </summary>
                        <pre class="bg-light border p-2 mt-2 mb-0" style="white-space: pre-wrap; max-height: 400px; overflow-y: auto;">{{ $chunk->content }}</pre>
                    </details>
                @endforeach
            @endif
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
    });
</script>

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

@endsection
