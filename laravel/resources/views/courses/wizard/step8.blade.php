@extends('layouts.app')

@section('content')
<div>
@include('courses.wizard.header')
    <div id="app">
        <div class="home">
                <div class="card" style="position:static">
                    <div class="card-header wizard">
                        <h3>
                        Course Description
                        <div style="float: right;">
                            <button id="tlaHelp" style="border: none; background: none; outline: none;" data-bs-toggle="modal" href="#guideModal">
                                <i class="bi bi-question-circle" style="color:#002145;"></i>
                            </button>
                        </div>
                        <div class="text-start">
                            @include('layouts.guide')
                        </div>
                    </h3>
                    </div>

                    <!-- Add Course Description Modal -->
                    <div class="modal fade" data-bs-backdrop="static" data-bs-keyboard="false" id="addCourseDescription" tabindex="-1" role="dialog"
                         aria-labelledby="addCourseDescription" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="addCourseDescriptionModalLabel"><i class="bi bi-pencil-fill btn-icon me-2"></i> Course Description
                                    </h5>
                                </div>

                                <div class="modal-body text-start">
                                    <textarea name="current_courseDescription" value="{{$courseDescription}}" id="courseDescriptionText"
                                              class="form-control @error('courseDescription') is-invalid @enderror" form="saveDescriptionChanges" required
                                    rows="10">{{$courseDescription}}</textarea>

                                </div>

                                <form method="POST" id="saveDescriptionChanges" action="{{ route('courseDescription.store', $course->course_id) }}">
                                    @csrf
                                    <div class="modal-footer">
                                        <input type="hidden" name="course_id" value="{{$course->course_id}}" form="saveDescriptionChanges">
                                        <button id="cancel" type="button" class="btn btn-secondary col-3" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-success col-3">Save Changes</button>
                                    </div>
                                </form>

                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="alert alert-primary d-flex align-items-center" role="alert" style="text-align:justify">
                            <i class="bi bi-info-circle-fill pe-2 fs-3"></i>
                            <div>
                                This step, requires instructors to provide a <b>course description</b> that outlines the course content, objectives, and expectations. This description will help students understand what the course entails and what they can expect to learn.
                            </div>
                        </div>
                        <div id="description">
                            @if(strlen($courseDescription)<1)
                                <div class="row mb-4">
                                    <div class="col">
                                        <button type="button" class="btn btn-primary col-4 float-end bg-primary text-white fs-5"  data-bs-toggle="modal" data-bs-target="#addCourseDescription">
                                            <i class="bi bi-plus me-2"></i> Course Description
                                        </button>
                                    </div>
                                </div>
                                <div class="alert alert-warning wizard">
                                    <i class="bi bi-exclamation-circle-fill"></i>There is no course description set for this course.
                                </div>
                            @else
                                <div class="row mb-4">
                                    <div class="col">
                                        <button type="button" class="btn btn-primary col-2 float-end bg-primary text-white fs-5"  data-bs-toggle="modal" data-bs-target="#addCourseDescription">
                                            <i class="bi bi-pen me-2"></i>Edit
                                        </button>
                                    </div>
                                </div>
                                <div class="p-3 mb-3 border rounded" style="border-color: #e0e0e0;">
                                    <p style="font-size: 16px; color: #555; line-height: 1.5;">{!! nl2br(e($courseDescription)) !!}</p>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="card-footer">
                    <div class="card-body mb-4">

                        <a href="{{route('courseWizard.step9', $course->course_id)}}">
                            <button class="btn btn-sm btn-primary col-3 float-right">Course Topics <i class="bi bi-arrow-right mr-2"></i></button>
                        </a>
                    </div>
                </div>
                </div>
        </div>
    </div>
</div>
@endsection

