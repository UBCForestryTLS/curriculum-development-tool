@extends('layouts.app')

@section('content')

<div>
    <div class="row justify-content-center">
        <div class="col-md-12">
            @include('programs.wizard.header')

            <div class="card">
                <h3 class="card-header wizard">
                    Courses

                    <div style="float: right;">
                        <button id="programCoursesHelp" style="border: none; background: none; outline: none;" data-bs-toggle="modal" href="#guideModal">
                            <i class="bi bi-question-circle" style="color:#002145;"></i>
                        </button>
                    </div>
                    <div class="text-start">
                        @include('layouts.guide')
                    </div>
                </h3>

                <div class="card-body">
                    <div class="alert alert-primary d-flex align-items-center ms-3 me-3" role="alert" style="text-align:justify">
                        <i class="bi bi-info-circle-fill pe-2 fs-3"></i>
                        <div class="ms-2">
                            <div class="mt-2 mb-2">
                                <li class="m-0 p-0">Add required and non-required courses to the program.</li>
                                <li class="m-0 p-0">After adding courses to the program, map or request to map each course to the program learning outcomes (PLOs) of this program.</li>
                                <li class="m-0 p-0">Once all courses have been mapped to this program, go to <a class="alert-link" href="{{route('programWizard.step4', $program->program_id)}}">step 4, Program Overview</a>, to see your completed program and its curriculum MAP.</li>
                            </div>
                        </div>
                    </div>
                    <h6 class="card-subtitle wizard text-primary fw-bold float-end me-3">
                        Note: Only course owners or editors can map the course to this program.
                    </h6>
                    <ul class="me-2">
                        <li class="my-2"><b>Button - Map Course:</b> You will see this button if you are the owner or editor of the course to complete the course to program mapping.</li>
                    </ul>

                    <div class="row mb-2">
                        <div class="col">
                            <button type="button" class="btn btn-primary btn-md col-2 mt-2 float-end" data-bs-toggle="modal" data-bs-target="#createCourseModal" style="background-color:#002145;color:white;"><i class="bi bi-plus pe-2"></i>New Course</button>
                            <button type="button" class="btn btn-primary btn-md col-3 mt-2 float-end" data-bs-toggle="modal" data-bs-target="#addCourseModal" style="margin-right: 10px; background-color:#002145;color:white;"><i class="bi bi-plus pe-2"></i> Course From My Dashboard</button>
                        </div>
                    </div>

                    <div id="courses">
                        <div class="row">
                            <div class="col">
                                @if ($programCourses->count() < 1)
                                    <div class="alert alert-warning wizard">
                                        <div class="notes"><i class="bi bi-exclamation-circle-fill pe-2 fs-5"></i>There are no courses set for this program yet.</div>
                                    </div>
                                @else
                                    <table class="table table-light table-bordered" >
                                        <tr class="table-primary">
                                            <th class="w-25">Course Title</th>
                                            <th>Course Code</th>
                                            <th>Term</th>
                                            <th><i class="bi bi-exclamation-circle-fill" style="font-style:normal;" data-bs-toggle="tooltip" data-bs-html="true" data-bs-placement="right" title="<ol><li><b>Not Mapped:</b> The course instructor has <b>not</b> mapped their course learning outcomes to the program learning outcomes.</li><li><b>Partially Mapped:</b> The course instructor has mapped <b>some</b> of their course learning outcomes to the program learning outcomes.</li><li><b>Mapped:</b> The course instructor has mapped <b>all</b> of their course learning outcomes to the program learning outcomes.</li></ol>"> Mapped to Program</i></th>
                                            <th class="text-center">Actions</th>
                                        </tr>

                                        @foreach($programCourses as $programCourse)
                                        <tr>
                                            @if($programCourse->pivot->note != NULL)
                                                <td>
                                                    {{$programCourse->course_title}}
                                                    <br>
                                                    <p class="mb-0 form-text text-muted">
                                                        @if($programCourse->pivot->course_required == 1)
                                                            Required
                                                        @elseif($programCourse->pivot->course_required == 0)
                                                            Not Required
                                                        @endif
                                                    </p>
                                                    <p class="form-text text-muted">
                                                        <b>Note: </b>{{$programCourse->pivot->note}}
                                                    </p>
                                                </td>
                                            @else
                                                <td>
                                                    {{$programCourse->course_title}}
                                                    <br>
                                                    <p class="form-text text-muted">
                                                        @if($programCourse->pivot->course_required == 1)
                                                            Required
                                                        @elseif($programCourse->pivot->course_required == 0)
                                                            Not Required
                                                        @endif
                                                    </p>
                                                </td>
                                            @endif
                                            <td>
                                                {{$programCourse->course_code}} {{$programCourse->course_num}}
                                            </td>
                                            <td>
                                                {{$programCourse->year}} {{$programCourse->semester}}
                                            </td>
                                            <td>
                                                @if($actualTotalOutcomes[$programCourse->course_id] == 0)
                                                    <i class="bi bi-exclamation-circle-fill text-danger pe-2"></i>Not Mapped
                                                @elseif ($actualTotalOutcomes[$programCourse->course_id] < $expectedTotalOutcomes[$programCourse->course_id])
                                                    <i class="bi bi-exclamation-circle-fill text-warning pe-2"></i>Partially Mapped
                                                @else
                                                    <i class="bi bi-check-circle-fill text-success pe-2"></i>Completed
                                                @endif
                                            </td>
                                            <td>
                                                <!-- Delete button -->
                                                <button style="width:70px" type="submit" class="btn btn-danger btn-sm float-end ms-2" data-bs-toggle="modal" data-bs-target="#deleteConfirmationCourse{{$programCourse->course_id}}">
                                                    Remove
                                                </button>

                                                <!-- Edit button -->
                                                <button type="button" style="width:60px" class="btn btn-secondary btn-sm float-end ms-2" data-bs-toggle="modal" data-bs-target="#editCourseModal{{$programCourse->course_id}}">
                                                    Edit
                                                </button>

                                                @if($actualTotalOutcomes[$programCourse->course_id] != $expectedTotalOutcomes[$programCourse->course_id])
                                                    <!-- If the User has been notified previously -->
                                                    @if($programCourse->pivot->map_status == 1)
                                                        <button type="button" class="btn btn-success btn-sm ms-2 float-end" disabled>
                                                            <i class="bi bi-check2-circle"></i> Notified
                                                        </button>
                                                    @elseif($programCourse->owners[0]->id == $user->id)
                                                        <!-- Allow owner to be redirected to the course to map it -->
                                                        @if ($programCourse->learningOutcomes->count() > 0)
                                                            <a type="button" class="btn btn-outline-primary btn-sm ms-2 float-end" href="{{ route('courseWizard.step5', $programCourse->course_id) }}">
                                                                Map Course
                                                            </a>
                                                        @else
                                                            <a type="button" class="btn btn-outline-primary btn-sm ms-2 float-end" href="{{ route('courseWizard.step1', $programCourse->course_id) }}">
                                                                Map Course
                                                            </a>
                                                        @endif
                                                    @endif
                                                    @foreach($programCourse->editors as $editor)
                                                        @if($editor->id == $user->id && $programCourse->pivot->map_status != 1)
                                                            <!-- Show Only If the User is not the Owner and if they haven't previously notified the instructor -->
                                                            @if ($programCourse->learningOutcomes->count() > 0)
                                                                <a type="button" class="btn btn-outline-primary btn-sm ms-2 float-end" href="{{ route('courseWizard.step5', $programCourse->course_id) }}">
                                                                    Map Course
                                                                </a>
                                                            @else
                                                                <a type="button" class="btn btn-outline-primary btn-sm ms-2 float-end" href="{{ route('courseWizard.step1', $programCourse->course_id) }}">
                                                                    Map Course
                                                                </a>
                                                            @endif
                                                        @endif
                                                    @endforeach
                                                @endif

                                                <!-- Delete Confirmation Modal -->
                                                <div class="modal fade" id="deleteConfirmationCourse{{$programCourse->course_id}}" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmationCourse" aria-hidden="true">
                                                    <div class="modal-dialog" role="document">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="exampleModalLabel">Remove Confirmation</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>

                                                            <div class="modal-body">
                                                            Are you sure you want to remove {{$programCourse->course_code . ' ' . $programCourse->course_num}} ?
                                                            </div>

                                                            <form action="{{route('courses.remove', $programCourse->course_id)}}" method="POST" class="float-end ms-2">
                                                                @csrf
                                                                {{method_field('GET')}}
                                                                <input type="hidden" class="form-check-input " name="program_id" value={{$program->program_id}}>
                                                                <div class="modal-footer">
                                                                <button style="width:60px" type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                                <button style="width:70px" type="submit" class="btn btn-danger btn-sm">Remove</button>
                                                                </div>
                                                            </form>

                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Edit Course Required Modal -->
                                                <div class="modal fade" id="editCourseModal{{$programCourse->course_id}}" tabindex="-1" role="dialog" aria-labelledby="editCourseModalLabel" aria-hidden="true">
                                                    <div class="modal-dialog modal-lg" role="document">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="editCourseModalLabel">Edit Course</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <form method="POST" action="{{ route('courseProgram.editCourseRequired', $program->program_id) }}">
                                                                @csrf

                                                                <div class="modal-body">

                                                                    <div class="mb-3 row">
                                                                        <label for="required"
                                                                            class="col-md-3 col-form-label text-md-end">Required</label>
                                                                        <div class="col-md-6">

                                                                                @if($programCourse->pivot->course_required == 0)
                                                                                    <div class="form-check ">
                                                                                        <label class="form-check-label">
                                                                                            <input type="radio" class="form-check-input" name="required" value="1">
                                                                                            Required
                                                                                        </label>
                                                                                    </div>
                                                                                    <div class="form-check">
                                                                                        <label class="form-check-label">
                                                                                            <input type="radio" class="form-check-input" name="required" value="0" checked>
                                                                                            Not Required
                                                                                        </label>
                                                                                    </div>
                                                                                @else
                                                                                    <div class="form-check ">
                                                                                        <label class="form-check-label">
                                                                                            <input type="radio" class="form-check-input" name="required" value="1" checked>
                                                                                            Required
                                                                                        </label>
                                                                                    </div>
                                                                                    <div class="form-check">
                                                                                        <label class="form-check-label">
                                                                                            <input type="radio" class="form-check-input" name="required" value="0" >
                                                                                            Not Required
                                                                                        </label>
                                                                                    </div>
                                                                                @endif
                                                                                <small class="form-text text-muted">
                                                                                    Is this course required by the program?
                                                                                </small>
                                                                        </div>
                                                                    </div>

                                                                    <div class="mb-3 row">
                                                                        <label for="required" class="col-md-3 col-form-label text-md-end">Note</label>
                                                                        <div class="col-md-6">

                                                                            <div class="form">
                                                                                @if ($programCourse->pivot->note != NULL)
                                                                                    <textarea name="note" class="form-textarea w-100" rows="2" maxlength="40">{{$programCourse->pivot->note}}</textarea>
                                                                                @else
                                                                                    <textarea name="note" class="form-textarea w-100" rows="2" maxlength="40"></textarea>
                                                                                @endif
                                                                                <small class="form-text text-muted">
                                                                                    You may add a note to further categorize courses (E.g. Chemistry Specialization). The note can not be greater than <b>40 characters.</b>
                                                                                </small>
                                                                            </div>

                                                                        </div>
                                                                    </div>

                                                                    <input type="hidden" class="form-input" name="course_id" value="{{$programCourse->course_id}}">
                                                                    <input type="hidden" class="form-input" name="program_id" value="{{$program->program_id}}">
                                                                    <input type="hidden" class="form-check-input" name="user_id" value="{{Auth::id()}}">

                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary col-2 btn-sm" data-bs-dismiss="modal">Close</button>
                                                                    <button type="submit" class="btn btn-primary col-2 btn-sm">Save</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </table>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Create Course Modal -->
                    <div class="modal fade" id="createCourseModal" tabindex="-1" role="dialog" aria-labelledby="createCourseModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="createCourseModalLabel">Create Course</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form method="POST" action="{{ action([\App\Http\Controllers\CourseController::class, 'store']) }}">
                                    @csrf
                                    <div class="modal-body">

                                        <div class="mb-3 row">
                                            <label for="course_code"
                                                class="col-md-3 col-form-label text-md-end"><span class="requiredField">*</span>Course Code</label>

                                                <div class="col-md-8">
                                                    <input id="course_code" type="text"
                                                    pattern="[A-Za-z]+"
                                                    minlength="1"
                                                    maxlength="4"
                                                    class="form-control @error('course_code') is-invalid @enderror"
                                                    name="course_code" required autofocus>

                                                    @error('course_code')
                                                    <span class="invalid-feedback" role="alert">
                                                        <strong>{{ $message }}</strong>
                                                    </span>
                                                    @enderror
                                                    <small id="helpBlock" class="form-text text-muted">
                                                        Maximum of Four letter course code e.g. SUST, ASL, COSC etc.
                                                    </small>
                                                </div>
                                        </div>

                                        <div class="mb-3 row">
                                            <label for="course_num" class="col-md-3 col-form-label text-md-end">Course Number</label>

                                            <div class="col-md-8">
                                                <input id="course_num" type="text" oninput="validateMaxlength()" onpaste="validateMaxlength()" maxlength="30" class="form-control @error('course_num') is-invalid @enderror" name="course_num" autofocus>

                                                @error('course_num')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="mb-3 row">
                                            <label for="course_title"
                                                class="col-md-3 col-form-label text-md-end"><span class="requiredField">*</span>Course Title</label>

                                            <div class="col-md-8">
                                                <input id="course_title" type="text" oninput="validateMaxlength()" onpaste="validateMaxlength()" maxlength="191"
                                                    class="form-control @error('course_title') is-invalid @enderror"
                                                    name="course_title" required autofocus>

                                                @error('course_title')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <!-- Campus -->
                                        <div class="mb-3 row">
                                            <label for="campus" class="col-md-3 col-form-label text-md-end">Campus</label>
                                            <div class="col-md-8">
                                                <select id="campus-course" class="form-select" name="campus">
                                                    <option disabled selected hidden>Open list of campuses</option>
                                                    @foreach ($campuses as $campus)
                                                        <option value="{{$campus->campus}}">{{$campus->campus}}</option>
                                                    @endforeach
                                                    <option value="Other">Other</option>
                                                </select>
                                                <input id='campus-text-course' class="form-control campus_text" name="campus" type="text" placeholder="(Optional) Enter the campus name" disabled hidden></input>
                                                @error('campus')
                                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                            </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <!-- Faculty - dropdown -->
                                        <div class="mb-3 row">
                                            <label for="faculty" class="col-md-3 col-form-label text-md-end">Faculty/School</label>
                                            <div class="col-md-8">
                                                <select id="faculty-course" class="form-select" name="faculty" disabled>
                                                    <option disabled selected hidden>Open list of faculties/schools</option>
                                                </select>
                                                <input id='faculty-text-course' class="form-control faculty_text" name="faculty" type="text" placeholder="(Optional) Enter the faculty/school" disabled hidden></input>
                                                @error('faculty')
                                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                            </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <!-- Department -->
                                        <div class="mb-3 row">
                                            <label for="department" class="col-md-3 col-form-label text-md-end">Department</label>
                                            <div class="col-md-8">
                                                <select id="department-course" class="form-select department_select" name="department" disabled>
                                                    <option disabled selected hidden>Open list of departments</option>
                                                </select>
                                                <input id='department-text-course' class="form-control" name="department" type="text" placeholder="(Optional) Enter the department" disabled hidden></input>
                                                @error('department')
                                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                            </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="mb-3 row">
                                            <label for="course_title" class="col-md-3 col-form-label text-md-end"><span class="requiredField">*</span>Year and Semester</label>

                                            <div class="col-md-3">
                                                <select id="course_semester" class="form-control @error('course_semester') is-invalid @enderror"
                                                    name="course_semester" required autofocus>
                                                    <option value="W1">Winter Term 1</option>
                                                    <option value="W2">Winter Term 2</option>
                                                    <option value="S1">Summer Term 1</option>
                                                    <option value="S2">Summer Term 2</option>

                                                @error('course_semester')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                                @enderror
                                                </select>
                                            </div>

                                            <div class="col-md-2 float-end">
                                                <select id="course_year" class="form-control @error('course_year') is-invalid @enderror"
                                                name="course_year" required autofocus>
                                                    <option value="2030">2030</option>
                                                    <option value="2029">2029</option>
                                                    <option value="2028">2028</option>
                                                    <option value="2027">2027</option>
                                                    <option value="2026">2026</option>
                                                    <option value="2025">2025</option>
                                                    <option value="2024">2024</option>
                                                    <option value="2023">2023</option>
                                                    <option value="2022" selected>2022</option>
                                                    <option value="2021">2021</option>
                                                    <option value="2020">2020</option>
                                                    <option value="2019">2019</option>
                                                    <option value="2018">2018</option>
                                                    <option value="2017">2017</option>
                                                    <option value="2016">2016</option>

                                                @error('course_year')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                                @enderror
                                                </select>
                                            </div>

                                        </div>

                                        <div class="mb-3 row">
                                            <label for="course_section" class="col-md-3 col-form-label text-md-end">Course
                                                Section</label>

                                            <div class="col-md-4">
                                                <input id="course_section" type="text" oninput="validateMaxlength()" onpaste="validateMaxlength()" maxlength="20"
                                                    class="form-control @error('course_section') is-invalid @enderror"
                                                    name="course_section" autofocus>

                                                @error('course_section')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="mb-3 row">
                                            <label for="delivery_modality" class="col-md-3 col-form-label text-md-end"><span class="requiredField">*</span>Mode of Delivery</label>

                                            <div class="col-md-3 float-end">
                                                <select id="delivery_modality" class="form-control @error('delivery_modality') is-invalid @enderror"
                                                name="delivery_modality" required autofocus>
                                                    <option value="O">online</option>
                                                    <option value="I">in-person</option>
                                                    <option value="B">hybrid</option>
                                                    <option value="M">Multi-Access</option>

                                                @error('delivery_modality')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                                @enderror
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Passes Information for Ministry Standards -->
                                        <div class="mb-3 row">
                                            <label for="standard_category_id" class="col-md-3 col-form-label text-md-end"><span class="requiredField">*</span>Map This Course Against</label>
                                            <div class="col-md-8">
                                                <select class="form-control" name="standard_category_id" id="standard_category_id" required>
                                                    <option value="" disabled selected hidden>Please Choose...</option>
                                                    @foreach($standard_categories as $standard_category)
                                                        <option value="{{ $standard_category->standard_category_id }}">{{$standard_category->sc_name}}</option>
                                                    @endforeach
                                                </select>
                                                <small id="helpBlock" class="form-text text-muted">
                                                    These are the standards from the Ministry of Post-Secondary Education and Future Skills.
                                                </small>
                                            </div>
                                        </div>

                                        <div class="mb-3 row">
                                            <label for="required" class="col-md-3 col-form-label text-md-end">Required</label>
                                            <div class="col-md-6">

                                            <div class="form-check">
                                                <label class="form-check-label">
                                                <input type="radio" class="form-check-input" name="required" value="1" >
                                                Required
                                                </label>
                                            </div>
                                            <div class="form-check">
                                                <label class="form-check-label">
                                                <input type="radio" class="form-check-input" name="required" value="0">
                                                Not Required
                                                </label>
                                            </div>
                                            <small class="form-text text-muted">
                                                Is this course required by the program?
                                            </small>
                                            </div>
                                        </div>

                                        <div class="mb-3 row">
                                            <label for="email" class="col-md-3 col-form-label text-md-end">Assign Owner For Course</label>
                                            <div class="col-md-8">
                                                <input id="email" name="email" type="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" placeholder="Enter email of the owner..." autocomplete="email">

                                                <small id="helpBlock" class="form-text text-muted">
                                                    (<b>Optional</b>) This is used to give ownership of this course to another person. If you would like to be the owner of this course then leave this field blank.
                                                </small>

                                                @error('email')
                                                    <span class="invalid-feedback" role="alert">
                                                        <strong>{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <!-- Passes 'program_id', type='assigned', and 'user_id' to be used by the CourseController store method -->
                                        <input type="hidden" class="form-check-input" name="program_id" value={{$program->program_id}}>
                                        <input type="hidden" class="form-check-input" name="type" value="assigned">
                                        <input type="hidden" class="form-check-input" name="user_id" value={{Auth::id()}}>

                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary col-2 btn-sm"
                                            data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary col-2 btn-sm">Add</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- End Create Course Modal -->

                    <!-- Add existing course Modal -->
                    <div class="modal fade" id="addCourseModal" tabindex="-1" role="dialog" aria-labelledby="createCourseModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg" role="document" style="width:1250px;">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="createCourseModalLabel">Add Existing Courses to {{$program->program}}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                @if (count($userCoursesNotInProgram) < 1)
                                    <div class="alert alert-warning wizard">
                                        <i class="bi bi-exclamation-circle-fill pe-2 fs-5"></i>There are no courses to assign.
                                    </div>
                                @else
                                    <div class="modal-body">
                                        <p>Select the courses you want to add to this program.</p>
                                        <form method="POST" id="addExistCourse" action="{{route('courseProgram.addCoursesToProgram', $program->program_id)}}">
                                            @csrf
                                            <input type="hidden" name="program_id" value="{{$program->program_id}}">
                                            <table class="table table-light table-bordered">
                                                <tr class="table-primary">
                                                    <td></td>
                                                    <th>Course Title</th>
                                                    <th>Course Code</th>
                                                    <th>Term</th>
                                                    <th>Required </i></th>
                                                </tr>
                                                @foreach($userCoursesNotInProgram as $index => $course)
                                                <tr>
                                                    <td>
                                                        <input class="form-check-input ms-0" type="checkbox" name="selectedCourses[]" value={{$course->course_id}} id="flexCheck{{$course->course_id}}">
                                                    </td>
                                                    <td>
                                                        {{$course->course_title}}
                                                    </td>
                                                    <td>
                                                        {{$course->course_code}} {{$course->course_num}}
                                                    </td>
                                                    <td>
                                                        {{$course->year}} {{$course->semester}}
                                                    </td>
                                                    <td>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input ms-0" name="require{{$course->course_id}}" type="checkbox" id="flexSwitchCheck{{$course->course_id}}">
                                                        </div>
                                                    </td>
                                                </tr>
                                                @endforeach
                                            </table>
                                        </form>
                                    </div>

                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary col-2 btn-sm" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary col-2 btn-sm" form="addExistCourse">Add Selected</button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer">
                    <div class="card-body mb-4">
                        <a href="{{route('programWizard.step2', $program->program_id)}}"><button class="btn btn-sm btn-primary col-3  float-start"><i class="bi bi-arrow-left ms-2"></i> Mapping Scale</button></a>
                        <a href="{{route('programWizard.step4', $program->program_id)}}"><button class="btn btn-sm btn-primary col-3 float-end">Program Overview <i class="bi bi-arrow-right ms-2"></i></button></a>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<script type="application/javascript">
    $(document).ready(function () {

        // Enables functionality of tool tips
        $('[data-bs-toggle="tooltip"]').tooltip({html:true});


        $("form").submit(function () {
            // prevent duplicate form submissions
            $(this).find(":submit").attr('disabled', 'disabled');
            $(this).find(":submit").html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>');

            });

        $('#campus-course').change( function() {
            // filter faculty based on campus
            campusChangeOperations('#campus-course', '#campus-text-course', '#faculty-course', '#faculty-text-course', '#department-course', '#department-text-course');

        });

        $('#faculty-course').change( function() {
            facultyChangeOperations('#faculty-course', '#faculty-text-course', '#department-course', '#department-text-course');
        });

        $('#department-course').change( function() {
            departmentChangeOperations('#department-course', '#department-text-course');
        });

    });

    function campusChangeOperations(campusFeildId, campusTextId, facultyFeildId, facultyTextId, departmentFeildId, departmentTextId) {
        // filter faculty based on campus
        if ($(campusFeildId).find(':selected').text() == 'Vancouver') {
            // Hide text / show select
            campusDefaultOption(campusTextId, facultyFeildId,
                facultyTextId, departmentFeildId, departmentTextId);

            //Displays Vancouver Faculties
            // delete drop down items
            $(facultyFeildId).empty();
            // populate drop down
            $(facultyFeildId).append($('<option disabled selected hidden>Open list of faculties/schools</option>'));
            vFaculties.forEach (faculty => $(facultyFeildId).append($('<option name="'+faculty.faculty_id+'" />').val(faculty.faculty).text(faculty.faculty)));
            $(facultyFeildId).append($('<option name="-1" />').val('Other').text('Other'));

            // enable the faculty select field
            if ($(facultyFeildId).is(':disabled')) {
                $(facultyFeildId).prop('disabled', false);
            }
            // disable the department field
            if (!($(departmentFeildId).is(':disabled'))) {
                $(departmentFeildId).empty();
                $(departmentFeildId).append($('<option disabled selected hidden>Open list of departments</option>'));
                $(departmentFeildId).prop('disabled', true);
            }

        } else if ($(campusFeildId).find(':selected').text() == 'Okanagan') {
            // Hide text / show select
            campusDefaultOption(campusTextId, facultyFeildId,
                facultyTextId, departmentFeildId, departmentTextId);

            // Display Okangan Faculties
            // delete drop down items
            $(facultyFeildId).empty();
            // populate drop down
            $(facultyFeildId).append($('<option disabled selected hidden>Open list of faculties/schools</option>'));
            oFaculties.forEach (faculty => $(facultyFeildId).append($('<option name="'+faculty.faculty_id+'" />').val(faculty.faculty).text(faculty.faculty)));
            $(facultyFeildId).append($('<option name="-1" />').val('Other').text('Other'));

            // enable the faculty select field
            if ($(facultyFeildId).is(':disabled')) {
                $(facultyFeildId).prop('disabled', false);
            }
            // disable the department field
            if (!($(departmentFeildId).is(':disabled'))) {
                $(departmentFeildId).empty();
                $(departmentFeildId).append($('<option disabled selected hidden>Open list of departments</option>'));
                $(departmentFeildId).prop('disabled', true);
            }

        } else {
            campusOtherOption(campusTextId, facultyFeildId,
                facultyTextId, departmentFeildId, departmentTextId);
        }
    }

    function facultyChangeOperations(facultyFeildId, facultyTextId, departmentFeildId, departmentTextId) {
        var facultyId = parseInt($(facultyFeildId).find(':selected').attr('name'));

        // get departments by faculty if they belong to a faculty, else display all departments
        if (facultyId >= 0) {
            // Hide text / show select
            facultyDefaultOption(facultyTextId, departmentFeildId, departmentTextId);

            // delete drop down items
            $(departmentFeildId).empty();
            // populate drop down
            $(departmentFeildId).append($('<option disabled selected hidden>Open list of departments</option>'));
            var filteredDepartments = departments.filter(item => {
                return item.faculty_id === facultyId;
            });
            filteredDepartments.forEach(department => $(departmentFeildId).append($('<option />').val(department.department).text(department.department)));


            $(departmentFeildId).append($('<option />').val('Other').text('Other'));

            // enable the faculty select field
            if ($(departmentFeildId).is(':disabled')) {
                $(departmentFeildId).prop('disabled', false);
            }

        } else {
            // Hide text / show select
            facultyOtherOption(facultyTextId, departmentFeildId, departmentTextId);
        }
    }

    function departmentChangeOperations(departmentFeildId, departmentTextId) {
        if ($(departmentFeildId).find(':selected').val() !== 'Other') {
            departmentDefaultOption(departmentTextId);
        } else {
            departmentOtherOption(departmentTextId);
        }
    }

    function departmentDefaultOption(departmentTextId) {
        // Hide text / show select
        $(departmentTextId).prop( "hidden", true );
        $(departmentTextId).prop( "disabled", true );
    }

    function departmentOtherOption(departmentTextId) {
        // Hide text / show select
        $(departmentTextId).prop( "hidden", false );
        $(departmentTextId).prop( "disabled", false );
    }

    function facultyDefaultOption(facultyTextId, departmentId, departmentTextId) {
        // Hide text / show select
        $(facultyTextId).prop( "hidden", true );
        $(facultyTextId).prop( "disabled", true );
        $(departmentId).prop( "hidden", false );
        $(departmentId).prop( "disabled", false );
        $(departmentTextId).prop( "hidden", true );
        $(departmentTextId).prop( "disabled", true );
    }

    function facultyOtherOption(facultyTextId, departmentId, departmentTextId) {
        // Hide text / show select
        $(facultyTextId).prop( "hidden", false );
        $(facultyTextId).prop( "disabled", false );
        $(departmentId).prop( "disabled", true );
        $(departmentId).prop( "hidden", true );
        $(departmentId).text('');
        $(departmentTextId).prop( "hidden", false );
        $(departmentTextId).prop( "disabled", false );
    }

    function campusDefaultOption(campusTextId, facultyId, facultyTextId, departmentId, departmentTextId) {
        // Hide text / show select
        $(campusTextId).prop( "hidden", true );
        $(campusTextId).prop( "disabled", true );
        $(facultyId).prop( "hidden", false );
        $(facultyId).prop( "disabled", false );
        $(facultyTextId).prop( "hidden", true );
        $(facultyTextId).prop( "disabled", true );
        $(departmentId).prop( "hidden", false );
        $(departmentId).prop( "disabled", false );
        $(departmentTextId).prop( "hidden", true );
        $(departmentTextId).prop( "disabled", true );
    }

    function campusOtherOption(campusTextId, facultyId, facultyTextId, departmentId, departmentTextId) {
        // Hide text / show select
        $(campusTextId).prop( "hidden", false );
        $(campusTextId).prop( "disabled", false );
        $(facultyId).prop( "disabled", true );
        $(facultyId).prop( "hidden", true );
        $(facultyId).text('');
        $(facultyTextId).prop( "hidden", false );
        $(facultyTextId).prop( "disabled", false );
        $(departmentId).prop( "disabled", true );
        $(departmentId).prop( "hidden", true );
        $(departmentId).text('');
        $(departmentTextId).prop( "hidden", false );
        $(departmentTextId).prop( "disabled", false );
    }
</script>

<style>
.tooltip-inner {
    text-align: left;
    max-width: 600px;
    width: auto;
}
</style>
@endsection
