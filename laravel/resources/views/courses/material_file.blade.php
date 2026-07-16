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
            <h6 class="mb-0">File Details</h6>
            <div class="d-flex align-items-center flex-wrap gap-2">
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
                <!-- TODO: Remove this form section, it's for testing only -->
                <form method="POST"
                      action="{{ route('course.material.files.refresh', [$course_id, $material_id, $file->course_material_file_id]) }}"
                      class="d-inline">
                    @csrf
                    <button type="submit"
                            class="btn btn-sm btn-outline-primary ms-2"
                            @disabled($file->status === 'INDEXING')
                            onclick="return confirm('Re-run text and topic extraction for this file using the saved settings?');">
                            <i class="bi bi-arrow-clockwise"></i> Refresh topics
                    </button>
                </form>
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
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Topics</h6>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" id="add-topic-btn" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-lg"></i> Add
                    </button>
                    <button type="button" id="edit-topics-btn" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil-square"></i> Edit
                    </button>
                    <button type="button" id="save-topics-btn" class="btn btn-sm btn-success d-none">
                        <i class="bi bi-check-lg"></i> Save
                    </button>
                    <button type="button" id="cancel-topics-btn" class="btn btn-sm btn-outline-secondary d-none">
                        <i class="bi bi-x-lg"></i> Cancel
                    </button>
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="topics-table">
                    <thead>
                        <tr>
                            <th>Topic</th>
                            <th class="topic-actions-col d-none">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="topics-tbody">
                        @forelse ($file->topics as $topic)
                            <tr class="topic-row" data-topic-id="{{ $topic->course_topic_id }}">
                                <td>
                                    <span class="topic-display">{{ $topic->topic }}</span>
                                    <input type="text" class="form-control form-control-sm topic-input d-none" value="{{ $topic->topic }}">
                                </td>
                                <td class="topic-actions-col d-none">
                                    <button type="button" class="btn btn-sm btn-outline-danger topic-delete-btn">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <div></div>
                        @endforelse
                    </tbody>
                </table>
            </div>
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
                    <p class="text-muted mb-0">No text was extracted. Try increasing the OCR threshold or making the scan clearer. If OCR is not required, please try re-uploading with OCR disabled.</p>
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
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

    const tbody = document.getElementById('topics-tbody');
    const editBtn = document.getElementById('edit-topics-btn');
    const saveBtn = document.getElementById('save-topics-btn');
    const cancelBtn = document.getElementById('cancel-topics-btn');
    const addBtn = document.getElementById('add-topic-btn');

    const updateUrl = '{{ route("course.material.files.topics.update", [$course_id, $material_id, $file->course_material_file_id]) }}';
    const csrfToken = '{{ csrf_token() }}';

    let editMode = false;

    let topics = [
        @foreach ($file->topics as $topic)
            { id: {{ $topic->course_topic_id }}, text: @json($topic->topic) },
        @endforeach
    ];

    function renderNoTopics() {
        return `
            <tr id="empty-topics-row">
                <td colspan="2" class="text-muted small">
                    No topics were extracted from this file.
                </td>
            </tr>
        `;
    }


    function renderTopics() {
        tbody.innerHTML = '';

        if (topics.length === 0) {
            tbody.innerHTML = renderNoTopics();
            return;
        }

        topics.forEach((t, index) => {
            const row = document.createElement('tr');
            row.className = 'topic-row';
            row.dataset.index = index;

            row.innerHTML = `
                <td>
                    <span class="topic-display ${editMode ? 'd-none' : ''}">${t.text}</span>
                    <input type="text" class="form-control form-control-sm topic-input ${editMode ? '' : 'd-none'}"
                           value="${t.text}">
                </td>
                <td class="topic-actions-col ${editMode ? '' : 'd-none'}">
                    <button type="button" class="btn btn-sm btn-outline-danger topic-delete-btn">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;

            tbody.appendChild(row);
        });
    }

    function setEditMode(enabled) {
        editMode = enabled;

        editBtn.classList.toggle('d-none', enabled);
        addBtn.classList.toggle('d-none', !enabled);
        saveBtn.classList.toggle('d-none', !enabled);
        cancelBtn.classList.toggle('d-none', !enabled);

        renderTopics();
    }

    tbody.addEventListener('click', e => {
        const btn = e.target.closest('.topic-delete-btn');
        if (!btn) return;

        const row = btn.closest('.topic-row');
        const index = parseInt(row.dataset.index, 10);

        topics.splice(index, 1);
        renderTopics();
    });
    editBtn.addEventListener('click', () => {
        setEditMode(true);
    });

    addBtn.addEventListener('click', () => {
        topics.unshift({ id: null, text: '' });
        setEditMode(true);

        renderTopics();

        const firstInput = tbody.querySelector('.topic-input');
        if (firstInput) firstInput.focus();
    });

    tbody.addEventListener('input', e => {
        const input = e.target.closest('.topic-input');
        if (!input) return;

        const row = input.closest('.topic-row');
        const index = parseInt(row.dataset.index, 10);

        topics[index].text = input.value;
    });

    saveBtn.addEventListener('click', () => {
        const cleaned = topics
            .map(t => ({ id: t.id, text: t.text.trim() }))
            .filter(t => t.text !== '');

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = updateUrl;

        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_token';
        csrf.value = csrfToken;
        form.appendChild(csrf);

        cleaned.forEach((topic, index) => {
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = `topics[${index}][id]`;
            idInput.value = topic.id || '';
            form.appendChild(idInput);

            const textInput = document.createElement('input');
            textInput.type = 'hidden';
            textInput.name = `topics[${index}][text]`;
            textInput.value = topic.text;
            form.appendChild(textInput);
        });

        document.body.appendChild(form);
        form.submit();
    });

    cancelBtn.addEventListener('click', () => {
        location.reload();
    });

    // Initial render
    renderTopics();
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
