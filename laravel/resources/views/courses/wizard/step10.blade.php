@extends('layouts.app')

@section('content')

    <div>
        @include('courses.wizard.header')
        <div id="app">
            <div class="home">

                <div class="card" style="position:static">
                    <div class="card-header wizard">
                        <h3>
                            Course Materials
                            <div style="float: right;">
                                <button id="courseMaterialsHelp" style="border: none; background: none; outline: none;"
                                    data-bs-toggle="modal" href="#guideModal">
                                    <i class="bi bi-question-circle" style="color:#002145;"></i>
                                </button>
                            </div>
                            <div class="text-left">
                                @include('layouts.guide')
                            </div>
                        </h3>
                    </div>

                    <!-- Start of add materials modal -->
                    <div id="addCourseMaterialsModal" class="modal fade" data-bs-backdrop="static" data-bs-keyboard="false"
                        tabindex="-1" role="dialog" aria-labelledby="addCourseMaterialsModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="addCourseMaterialsModalLabel"><i
                                            class="bi bi-pencil-fill btn-icon mr-2"></i> Course Materials</h5>
                                </div>

                                <div class="modal-body">
                                    <form id="addCourseMaterialsForm" class="needs-validation" novalidate>
                                        <!-- Materials have multiple fields, so the add form collects one complete material row. -->
                                        <div class="row justify-content-between align-items-end m-2">
                                            <div class="col-4">
                                                <label for="courseMaterialName" class="form-label fs-6"><b>Name</b></label>
                                                <input id="courseMaterialName" class="form-control"
                                                    oninput="validateMaxlength(event)" onpaste="validateMaxlength(event)"
                                                    maxlength="191" placeholder="Material name" required>
                                                <div class="invalid-tooltip">
                                                    Please provide a material name.
                                                </div>
                                            </div>
                                            <div class="col-2">
                                                <label for="courseMaterialType" class="form-label fs-6"><b>Type</b></label>
                                                <input id="courseMaterialType" class="form-control"
                                                    oninput="validateMaxlength(event)" onpaste="validateMaxlength(event)"
                                                    maxlength="191" placeholder="Textbook, video, article...">
                                            </div>
                                            <div class="col-2">
                                                <label for="courseMaterialUrl" class="form-label fs-6"><b>URL</b></label>
                                                <input id="courseMaterialUrl" class="form-control"
                                                    placeholder="https://...">
                                            </div>
                                            <div class="col-2 text-center">
                                                <label for="courseMaterialRequired"
                                                    class="form-label fs-6"><b>Required</b></label>
                                                <div class="form-check d-flex justify-content-center">
                                                    <input id="courseMaterialRequired" type="checkbox"
                                                        class="form-check-input" value="1">
                                                </div>
                                            </div>
                                            <div class="col-2">
                                                <button id="addCourseMaterialBtn" type="submit"
                                                    class="btn btn-primary col">Add</button>
                                            </div>
                                        </div>
                                        <div class="row m-2">
                                            <div class="col">
                                                <label for="courseMaterialDescription"
                                                    class="form-label fs-6"><b>Description</b></label>
                                                <textarea id="courseMaterialDescription" class="form-control" rows="2"
                                                    placeholder="Brief description"></textarea>
                                            </div>
                                        </div>
                                    </form>
                                    <div class="row justify-content-center">
                                        <div class="col-8">
                                            <hr>
                                        </div>
                                    </div>
                                    <div class="row m-1">
                                        <table id="addCourseMaterialsTbl" class="table table-light table-borderless">
                                            <thead>
                                                <tr class="table-primary">
                                                    <th>Name</th>
                                                    <th>Type</th>
                                                    <th>Description</th>
                                                    <th>URL</th>
                                                    <th class="text-center">Required</th>
                                                    <th class="text-center">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($courseMaterials as $index => $courseMaterial)
                                                    <tr>
                                                        <td>
                                                            <input
                                                                id="courseMaterialName{{$courseMaterial->course_material_id}}"
                                                                type="text" class="form-control"
                                                                oninput="validateMaxlength(event)"
                                                                onpaste="validateMaxlength(event)" maxlength="191"
                                                                name="current_material[{{$courseMaterial->course_material_id}}][name]"
                                                                value="{{$courseMaterial->name}}" placeholder="Material name"
                                                                form="saveCourseMaterialChanges" required spellcheck="true"
                                                                style="white-space: pre">
                                                        </td>
                                                        <td>
                                                            <input
                                                                id="courseMaterialType{{$courseMaterial->course_material_id}}"
                                                                type="text" class="form-control"
                                                                oninput="validateMaxlength(event)"
                                                                onpaste="validateMaxlength(event)" maxlength="191"
                                                                name="current_material[{{$courseMaterial->course_material_id}}][type]"
                                                                value="{{$courseMaterial->type}}" placeholder="Type"
                                                                form="saveCourseMaterialChanges" spellcheck="true"
                                                                style="white-space: pre">
                                                        </td>
                                                        <td>
                                                            <textarea
                                                                id="courseMaterialDescription{{$courseMaterial->course_material_id}}"
                                                                class="form-control" rows="1"
                                                                name="current_material[{{$courseMaterial->course_material_id}}][description]"
                                                                placeholder="Description" form="saveCourseMaterialChanges"
                                                                spellcheck="true">{{$courseMaterial->description}}</textarea>
                                                        </td>
                                                        <td>
                                                            <input id="courseMaterialUrl{{$courseMaterial->course_material_id}}"
                                                                type="text" class="form-control"
                                                                name="current_material[{{$courseMaterial->course_material_id}}][url]"
                                                                value="{{$courseMaterial->url}}" placeholder="URL"
                                                                form="saveCourseMaterialChanges" spellcheck="true">
                                                        </td>
                                                        <td class="text-center">
                                                            <input type="checkbox" class="form-check-input"
                                                                name="current_material[{{$courseMaterial->course_material_id}}][is_required]"
                                                                value="1" form="saveCourseMaterialChanges"
                                                                @checked($courseMaterial->is_required)>
                                                        </td>
                                                        <td class="text-center">
                                                            <i class="bi bi-x-circle-fill text-danger fs-4 btn"
                                                                onclick="deleteCourseMaterial(this)"></i>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <!-- This form sends all current_material/new_material arrays to CourseMaterialController@store. -->
                                <form method="POST" id="saveCourseMaterialChanges"
                                    action="{{ action([\App\Http\Controllers\CourseMaterialController::class, 'store']) }}">
                                    @csrf
                                    <div class="modal-footer">
                                        <input type="hidden" name="course_id" value="{{$course->course_id}}"
                                            form="saveCourseMaterialChanges">
                                        <button id="cancel" type="button" class="btn btn-secondary col-3"
                                            data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-success btn col-3">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- End of add course materials modal -->

                    <!-- Start of add file modal -->
                    <div id="uploadFileModal" class="modal fade" tabindex="-1" role="dialog"
                        aria-labelledby="uploadFileModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered" role="document">
                            <div class="modal-content">
                                <form method="POST" action="" enctype="multipart/form-data" id="uploadFileForm"
                                    class="needs-validation" novalidate>
                                    @csrf
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="uploadFileModalLabel"><i
                                                class="bi bi-file-earmark-pdf btn-icon mr-2"></i> Add File</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                            aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="text-muted mb-3">Uploading file to for <strong
                                                id="uploadFileMaterialName"></strong></p>
                                        <div class="form-check mt-2">
                                            <input type="hidden" name="ocr_enabled" value="0">
                                            <input class="form-check-input" type="checkbox" id="ocrEnabled"
                                                name="ocr_enabled" value="1" onchange="toggleMatOcr(this)">
                                            <label class="form-check-label" for="ocrEnabled">
                                                Turn scanned pages into searchable text (OCR)
                                            </label>
                                        </div>
                                        <div id="ocrAdv" class="mt-2 ms-4 d-none">
                                            <div class="accordion accordion-flush" id="ocrAdvAccordion">
                                                <div class="accordion-item">
                                                    <h2 class="accordion-header">
                                                        <button class="accordion-button collapsed p-2" type="button"
                                                            data-bs-toggle="collapse" data-bs-target="#ocrAdvBody"
                                                            aria-expanded="false" aria-controls="ocrAdvBody">
                                                            Advanced settings
                                                        </button>
                                                    </h2>
                                                    <div id="ocrAdvBody" class="accordion-collapse collapse"
                                                        data-bs-parent="#ocrAdvAccordion">
                                                        <div class="accordion-body p-2 row">
                                                            <div class="col-md-6">
                                                                <label class="form-label small mb-0">Extraction Engine</label>
                                                                <select name="extraction_engine"
                                                                    class="form-select form-select-sm"
                                                                    onchange="toggleOcrThresholdSetting(this)">
                                                                    <option value="tesseract">Tesseract</option>
                                                                    <option value="textract">AWS Textract (cloud)</option>
                                                                </select>
                                                            </div>
                                                            <div id="ocrThresholdSetting" class="col-md-6">
                                                                <label class="form-label small mb-0">OCR threshold (characters)</label>
                                                                <input type="number" name="ocr_threshold" min="0"
                                                                    max="100000" value="0"
                                                                    class="form-control form-control-sm">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label mb-0">PDF (max 50MB)</label>
                                            <input type="file" name="file" id="uploadFileInput"
                                                class="form-control form-control-sm" accept="application/pdf" required
                                                onchange="validateFileSize(this)">
                                            <small id="fileSizeFeedback" class="form-text text-danger d-none"></small>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary col-3"
                                            data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary col-3">Upload</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- End of add file modal -->

                    <div class="card-body">
                        <div class="alert alert-primary d-flex align-items-center" role="alert" style="text-align:justify">
                            <i class="bi bi-info-circle-fill pr-2 fs-3"></i>
                            <div class="col mb-6">
                                Input the main course materials individually. These may include textbooks, articles, videos,
                                websites, datasets, or other required and recommended resources.
                            </div>
                        </div>
                        <div class="row">
                            <div class="col mb-1">
                                <button type="button" class="btn btn-primary col-3 float-right bg-primary text-white fs-5"
                                    data-bs-toggle="modal" data-bs-target="#addCourseMaterialsModal">
                                    Edit Course Materials
                                </button>
                            </div>
                        </div>


                        <div id="admins">
                            <table class="table table-light" id="course_material_table">
                                <tr class="table-primary">
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th class="text-center">Required</th>
                                    <th class="text-center w-25">Actions</th>
                                </tr>

                                @if(count($courseMaterials) < 1)
                                    <tr>
                                        <td colspan="5">
                                            <div class="alert alert-warning wizard">
                                                <i class="bi bi-exclamation-circle-fill"></i>There are no course materials set
                                                for this course.
                                            </div>
                                        </td>
                                    </tr>
                                @else
                                    @foreach($courseMaterials as $index => $courseMaterial)
                                        <tr class="material-row">
                                            <td>
                                                {{$courseMaterial->name}}
                                                @if($courseMaterial->url)
                                                    <br><a href="{{$courseMaterial->url}}" target="_blank"
                                                        rel="noopener noreferrer">{{$courseMaterial->url}}</a>
                                                @endif
                                            </td>
                                            <td>
                                                {{$courseMaterial->type}}
                                            </td>
                                            <td>
                                                {{$courseMaterial->description}}
                                            </td>
                                            <td class="text-center">
                                                @if($courseMaterial->is_required)
                                                    Required
                                                @else
                                                    Optional
                                                @endif
                                            </td>
                                            <td class="text-center align-middle">
                                                <!-- We don't really need the per-row edit button, as -->
                                                <!-- each open the same menu as the tablewide edit button at the top -->
                                                <!-- <button type="button" style="width:60px;" class="btn btn-secondary btn-sm m-1" data-bs-toggle="modal" data-bs-target="#addCourseMaterialsModal">
                                                        Edit
                                                    </button> -->
                                                <button type="button"
                                                    class="btn btn-outline-primary btn-sm m-1 files-toggle collapsed"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#filesRow-{{$courseMaterial->course_material_id}}"
                                                    aria-expanded="false">
                                                    <span class="label-show">Show Files</span>
                                                    <span class="label-hide">Hide Files</span>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="5" class="p-0 border-top-0">
                                                <div class="collapse" id="filesRow-{{$courseMaterial->course_material_id}}">
                                                    <div class="p-3 bg-light">
                                                        @if($courseMaterial->files->isEmpty())
                                                            <p class="text-muted mb-2">No files uploaded yet.</p>
                                                        @else
                                                            <table class="table table-sm table-borderless mb-3">
                                                                <thead>
                                                                    <tr class="table-light">
                                                                        <th>File</th>
                                                                        <th class="text-center">Status</th>
                                                                        <th class="text-center">Pages</th>
                                                                        <th class="text-center">Actions</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    @foreach($courseMaterial->files as $file)
                                                                                                                    <tr>
                                                                                                                        <td class="align-middle">
                                                                                                                            <a
                                                                                                                                href="{{route('course.material.files.show', [$course->course_id, $courseMaterial->course_material_id, $file->course_material_file_id])}}">
                                                                                                                                <i
                                                                                                                                    class="bi bi-file-earmark-pdf me-1"></i>{{$file->file_name}}
                                                                                                                            </a>
                                                                                                                        </td>
                                                                                                                        <td class="text-center align-middle">
                                                                                                                            @php
                                                                                                                                $statusClass = match ($file->status) {
                                                                                                                                    'INDEXED' => 'material-status--indexed',
                                                                                                                                    'INDEXING' => 'material-status--indexing',
                                                                                                                                    'FAILED' => 'material-status--failed',
                                                                                                                                    default => 'material-status--pending',
                                                                                                                                };
                                                                                                                            @endphp
                                                                         <span
                                                                                                                                class="material-status {{$statusClass}}">{{$file->status}}</span>
                                                                                                                            @if($file->status === 'FAILED' && $file->error_message)
                                                                                                                                <i class="bi bi-exclamation-circle text-danger ms-1"
                                                                                                                                    title="{{$file->error_message}}"></i>
                                                                                                                            @endif
                                                                                                                        </td>
                                                                                                                        <td class="text-center align-middle">
                                                                                                                            {{$file->page_count ?? '-'}}</td>
                                                                                                                        <td class="text-center align-middle">
                                                                                                                            <a href="{{route('course.material.files.download', [$course->course_id, $courseMaterial->course_material_id, $file->course_material_file_id])}}"
                                                                                                                                class="btn btn-outline-secondary btn-sm">
                                                                                                                                <i class="bi bi-download"></i>
                                                                                                                            </a>
                                                                                                                            <form method="POST"
                                                                                                                                action="{{route('course.material.files.destroy', [$course->course_id, $courseMaterial->course_material_id, $file->course_material_file_id])}}"
                                                                                                                                class="d-inline"
                                                                                                                                onsubmit="return confirm('Delete this file?')">
                                                                                                                                @csrf
                                                                                                                                @method('DELETE')
                                                                                                                                <button type="submit"
                                                                                                                                    class="btn btn-outline-danger btn-sm">
                                                                                                                                    <i class="bi bi-trash"></i>
                                                                                                                                </button>
                                                                                                                            </form>
                                                                                                                        </td>
                                                                                                                    </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        @endif

                                                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal"
                                                            data-bs-target="#uploadFileModal"
                                                            data-action="{{route('course.material.files.store', [$course->course_id, $courseMaterial->course_material_id])}}"
                                                            data-material-name="{{$courseMaterial->name}}">
                                                            <i class="bi bi-plus mr-1"></i>Add File
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            </table>
                        </div>
                    </div>


                    <!-- card footer -->
                    <div class="card-footer">
                        <div class="card-body mb-4">
                            <a href="{{route('courseWizard.step9', $course->course_id)}}">
                                <button class="btn btn-sm btn-primary col-3 float-left"><i
                                        class="bi bi-arrow-left mr-2"></i> Course Topics</button>
                            </a>
                            <a href="{{route('courseWizard.step1', $course->course_id)}}">
                                <button class="btn btn-sm btn-primary col-3 float-right">Course Learning Outcomes <i
                                        class="bi bi-arrow-right ml-2"></i></button>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {

            //   $("form").submit(function () {
            //     // prevent duplicate form submissions
            //     $(this).find(":submit").attr('disabled', 'disabled');
            //     $(this).find(":submit").html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>');

            //   });

            $('#addCourseMaterialsForm').submit(function (event) {
                // prevent default form submission handling
                event.preventDefault();
                event.stopPropagation();
                // check if input fields contain data
                if ($('#courseMaterialName').val().length != 0) {
                    addCourseMaterial();
                    // reset form
                    $(this).trigger('reset');
                    $(this).removeClass('was-validated');
                } else {
                    // mark form as validated
                    $(this).addClass('was-validated');
                }
                // readjust modal's position
                $('#addCourseMaterialsModal').modal('handleUpdate');

            });

            $('#cancel').click(function (event) {
                $('#addCourseMaterialsTbl tbody').html(`
                    @foreach($courseMaterials as $index => $courseMaterial)
                        <tr>
                            <td>
                                <input id="courseMaterialName{{$courseMaterial->course_material_id}}" type="text" class="form-control" name="current_material[{{$courseMaterial->course_material_id}}][name]" value="{{$courseMaterial->name}}" placeholder="Material name" form="saveCourseMaterialChanges" required spellcheck="true" style="white-space: pre">
                            </td>
                            <td>
                                <input id="courseMaterialType{{$courseMaterial->course_material_id}}" type="text" class="form-control" name="current_material[{{$courseMaterial->course_material_id}}][type]" value="{{$courseMaterial->type}}" placeholder="Type" form="saveCourseMaterialChanges" spellcheck="true" style="white-space: pre">
                            </td>
                            <td>
                                <textarea id="courseMaterialDescription{{$courseMaterial->course_material_id}}" class="form-control" rows="1" name="current_material[{{$courseMaterial->course_material_id}}][description]" placeholder="Description" form="saveCourseMaterialChanges" spellcheck="true">{{$courseMaterial->description}}</textarea>
                            </td>
                            <td>
                                <input id="courseMaterialUrl{{$courseMaterial->course_material_id}}" type="text" class="form-control" name="current_material[{{$courseMaterial->course_material_id}}][url]" value="{{$courseMaterial->url}}" placeholder="URL" form="saveCourseMaterialChanges" spellcheck="true">
                            </td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="current_material[{{$courseMaterial->course_material_id}}][is_required]" value="1" form="saveCourseMaterialChanges" @checked($courseMaterial->is_required)>
                            </td>
                            <td class="text-center">
                                <i class="bi bi-x-circle-fill text-danger fs-4 btn" onclick="deleteCourseMaterial(this)"></i>
                            </td>
                        </tr>
                    @endforeach
                `);
            });
        });

        function deleteCourseMaterial(submitter) {
            $(submitter).parents('tr').remove();
        }

        const uploadFileModal = document.getElementById('uploadFileModal');
        if (uploadFileModal) {
            uploadFileModal.addEventListener('show.bs.modal', function (event) {
                const trigger = event.relatedTarget;
                if (!trigger) return;
                const form = document.getElementById('uploadFileForm');
                form.action = trigger.getAttribute('data-action');
                document.getElementById('uploadFileMaterialName').textContent = trigger.getAttribute('data-material-name') || '';
                // reset form to a clean state each time it opens
                form.reset();
                document.getElementById('ocrAdv').classList.add('d-none');
                const feedback = document.getElementById('fileSizeFeedback');
                feedback.textContent = '';
                feedback.classList.add('d-none');
                const advBody = document.getElementById('ocrAdvBody');
                if (advBody && advBody.classList.contains('show')) {
                    bootstrap.Collapse.getOrCreateInstance(advBody).hide();
                }
            });
        }

        function toggleMatOcr(checkbox) {
            const panel = document.getElementById('ocrAdv');
            if (!panel) return;
            if (checkbox.checked) {
                panel.classList.remove('d-none');
            } else {
                panel.classList.add('d-none');
                const body = document.getElementById('ocrAdvBody');
                if (body && body.classList.contains('show')) {
                    bootstrap.Collapse.getOrCreateInstance(body).hide();
                }
            }
        }

        function toggleOcrThresholdSetting(select) {
            const row = document.getElementById('ocrThresholdSetting');
            if (row) row.classList.toggle('d-none', select.value !== 'tesseract');
        }

        function validateFileSize(input) {
            const maxBytes = 50 * 1024 * 1024;
            const feedback = document.getElementById('fileSizeFeedback');
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

        function addCourseMaterial() {
            // Build grouped request keys because each material has several fields.
            const materialIndex = $('#addCourseMaterialsTbl tbody tr').length;
            const requiredInput = $('#courseMaterialRequired').is(':checked')
                ? `<input type="checkbox" class="form-check-input" name="new_material[${materialIndex}][is_required]" value="1" form="saveCourseMaterialChanges" checked>`
                : `<input type="checkbox" class="form-check-input" name="new_material[${materialIndex}][is_required]" value="1" form="saveCourseMaterialChanges">`;

            $('#addCourseMaterialsTbl tbody').append(`
                <tr>
                    <td>
                        <input type="text" class="form-control" name="new_material[${materialIndex}][name]" value="${$('#courseMaterialName').val()}" placeholder="Material name" form="saveCourseMaterialChanges" required spellcheck="true" style="white-space: pre">
                    </td>
                    <td>
                        <input type="text" class="form-control" name="new_material[${materialIndex}][type]" value="${$('#courseMaterialType').val()}" placeholder="Type" form="saveCourseMaterialChanges" spellcheck="true" style="white-space: pre">
                    </td>
                    <td>
                        <textarea class="form-control" rows="1" name="new_material[${materialIndex}][description]" placeholder="Description" form="saveCourseMaterialChanges" spellcheck="true">${$('#courseMaterialDescription').val()}</textarea>
                    </td>
                    <td>
                        <input type="text" class="form-control" name="new_material[${materialIndex}][url]" value="${$('#courseMaterialUrl').val()}" placeholder="URL" form="saveCourseMaterialChanges" spellcheck="true">
                    </td>
                    <td class="text-center">
                        ${requiredInput}
                    </td>
                    <td class="text-center">
                        <i class="bi bi-x-circle-fill text-danger fs-4 btn" onclick="deleteCourseMaterial(this)"></i>
                    </td>
                </tr>
            `);
        }


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

        .material-status--indexed {
            background-color: #198754;
        }

        .material-status--indexing {
            background-color: #6EC4E8;
            color: #212529;
        }

        .material-status--pending {
            background-color: #6c757d;
        }

        .material-status--failed {
            background-color: #dc3545;
        }

        .files-toggle .label-hide {
            display: none;
        }

        .files-toggle:not(.collapsed) .label-show {
            display: none;
        }

        .files-toggle:not(.collapsed) .label-hide {
            display: inline;
        }

        #course_material_table tr.material-row>td {
            border-bottom: 0;
        }
    </style>
@endsection
