@extends('layouts.app')

@section('content')

<link href=" {{ asset('css/accordions.css') }}" rel="stylesheet" type="text/css" >
<!--Link for FontAwesome Font for the arrows for the accordions.-->
<link href="https://use.fontawesome.com/releases/v5.8.2/css/all.css" integrity="sha384-oS3vJWv+0UjzBfQzYUhtDYW+Pj2yciDJxpsK1OYPAYjqT085Qq/1cq5FLXAZQ7Ay" crossorigin="anonymous" rel="stylesheet" type="text/css" >

<div>
    <div class="row justify-content-center">

        <div class="col-md-12">
            @include('courses.wizard.header')

            <div class="card">
                <h3 class="card-header wizard" >
                    Program Outcome Mapping
                    <div style="float: right;">
                            <button id="programOutcomeMappingHelp" style="border: none; background: none; outline: none;" data-bs-toggle="modal" href="#guideModal">
                                <i class="bi bi-question-circle" style="color:#002145;"></i>
                            </button>
                        </div>
                        <div class="text-start">
                            @include('layouts.guide')
                    </div>
                </h3>
                <div class="card-body">

                    @if (count($course->learningOutcomes) < 1)
                        <div class="alert alert-warning wizard">
                            <i class="bi bi-exclamation-circle-fill"></i>There are no course learning outcomes set for this course. <a class="alert-link" href="{{route('courseWizard.step1', $course->course_id)}}">Add course learning outcomes.</a>
                        </div>

                    @else
                        <div class="alert alert-primary d-flex align-items-center" role="alert" style="text-align:justify">
                            <i class="bi bi-info-circle-fill pe-2 fs-3"></i>
                            <div>
                                Now that you have inputted your course information, you are ready to map it to program learning outcomes (PLOs). Using the mapping scale provided by each program, identify the alignment between each of your course learning outcomes (CLOs) and PLOs.
                            </div>
                        </div>

                        <!-- list of programs this course belongs to -->
                        <div class="jumbotron">
                            <form action="{{action([\App\Http\Controllers\OutcomeMapController::class, 'store'])}}" method="POST">
                            @csrf
                            <input type="hidden" name="course_id" value="{{$course->course_id}}">

                            @if (count($course->programs) < 1)
                                <div class="alert alert-warning text-center">
                                    <i class="bi bi-exclamation-circle-fill pe-2 fs-3"></i>
                                    <br>
                                    <p>This course does not belong to any programs yet. Please move ahead to the next step.</p>
                                    <p>If you would like to define program learning outcomes to map this course, please create a program first. <a class="alert-link" href="{{route('home')}}">Create a Program.</a></p>
                                </div>

                            @else
                                <div class="programsAccordions" style="width:100%">

                                    @foreach($course->programs as $index => $courseProgram)

                                        <div class="modal fade" id="AiSuggestionConfirmation{{$course->course_id}}{{$courseProgram->program_id}}" tabindex="-1" role="dialog" aria-labelledby="AiSuggestionConfirmation" aria-hidden="true">
                                            <div class="modal-dialog modal-lg" role="document">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="exampleModalLabel"><strong>AI Suggestion</strong></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to proceed? AI Suggestions can only generated once.</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button style="width:60px" type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">No</button>
                                                            <button style="width:80px" type="button" class="btn btn-success btn-sm" onclick="generateAiSuggestions({{$course->course_id}}, {{$courseProgram->program_id}})">Yes</button>
                                                        </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Program accordion -->
                                        <div class="accordion" id="programAccordion{{$courseProgram->program_id}}">
                                            <div class="accordion-item mb-2">
                                                <!-- Program accordion header -->
                                                <h2 class="accordion-header fs-2 d-flex align-items-center justify-content-between" id="programAccordionHeader{{$courseProgram->program_id}}"
                                                    style="background-color: #002145;">
                                                    <button class="accordion-button collapsed program white-arrow flex-grow-1 d-flex align-items-center"
                                                            type="button"
                                                            data-bs-toggle="collapse"
                                                            data-bs-target="#collapseProgramAccordion{{$courseProgram->program_id}}"
                                                            aria-expanded="false"
                                                            aria-controls="collapseProgramAccordion{{$courseProgram->program_id}}">
                                                        <b>{{$index + 1}}</b>. {{$courseProgram->program}}
                                                    </button>

                                                    <!-- AI Suggestion button -->
                                                    @if(!$courseProgram->pivot->ai_suggestion_status)
                                                        @php
                                                            $_inFlight         = isset($aiSuggestionInFlight) && !empty($aiSuggestionInFlight[$courseProgram->program_id]);
                                                            $_manualMapStarted = (bool) $courseProgram->pivot->manual_map_status;
                                                            $_aiBtnClass     = ($_manualMapStarted && !$_inFlight) ? 'd-flex' : 'd-none';
                                                            $_aiStatusClass  = ($_manualMapStarted && $_inFlight)  ? 'd-flex' : 'd-none';
                                                        @endphp
                                                        <span id="buttonAISuggestionHeader-{{$course->course_id}}-{{$courseProgram->program_id}}"
                                                              class="btn btn-sm {{ $_aiBtnClass }} me-3 col-2 justify-content-center"
                                                              role="button"
                                                              style="background-color: #40B4E5; color:white;"
                                                              data-bs-toggle="modal" data-bs-target="#AiSuggestionConfirmation{{$course->course_id}}{{$courseProgram->program_id}}">
                                                            <img src="{{ asset('img/AISuggestionWhite.png') }}" alt="icon" style="height: 1em; width: auto;" class="me-1">
                                                            AI Suggestion
                                                        </span>
                                                        <!-- AI status container (shown while polling for results) -->
                                                        <div id="aiStatusHeader-{{$course->course_id}}-{{$courseProgram->program_id}}"
                                                             class="{{ $_aiStatusClass }} align-items-center me-3"
                                                             data-course-id="{{ $course->course_id }}"
                                                             data-program-id="{{ $courseProgram->program_id }}"
                                                             @if($_manualMapStarted && $_inFlight) data-poll-on-load="true" @endif>
                                                            <button id="aiCheckingHeader-{{$course->course_id}}-{{$courseProgram->program_id}}" type="button" class="btn btn-secondary py-1 px-2" style="font-size: 0.75rem;" disabled>
                                                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true" style="width: 0.75rem; height: 0.75rem;"></span>
                                                                Waiting for AI suggestions...
                                                            </button>
                                                            <button id="aiRefreshHeader-{{$course->course_id}}-{{$courseProgram->program_id}}" type="button" class="btn btn-sm btn-warning d-none"
                                                                    onclick="refreshAiSuggestions({{$course->course_id}}, {{$courseProgram->program_id}})">
                                                                <i class="bi bi-arrow-clockwise me-1"></i>
                                                                Refresh AI Suggestions
                                                            </button>
                                                        </div>
                                                    @endif
                                                </h2>
                                                <!-- Program Accordion body -->
                                                <div id="collapseProgramAccordion{{$courseProgram->program_id}}" class="accordion-collapse collapse" aria-labelledby="programAccordionHeader{{$courseProgram->program_id}}" data-bs-parent="programAccordion{{$courseProgram->program_id}}">
                                                    <div class="accordion-body">

                                                        @if ($courseProgram->mappingScaleLevels->count() > 0)

                                                            <!-- Mapping scale for this program -->
                                                            <p>Using the mapping scale provided, identify the alignment between each of your course learning outcomes (CLOs) and the program learning outcomes (PLOs).</p>
                                                            <p class="form-text text-primary container fw-bold ">Note: Remember to click save once you are done.</p>
                                                            <div class="container row mb-2">
                                                                <div class="col">
                                                                    <table class="table table-bordered table-sm">
                                                                        <thead>
                                                                            <tr>
                                                                                <th colspan="2">Mapping Scale</th>
                                                                            </tr>
                                                                        </thead>

                                                                        <tbody>
                                                                            @foreach($courseProgram->mappingScaleLevels as $programMappingScaleLevel)
                                                                                <tr>
                                                                                    <td style="width:20%">
                                                                                        <div style="background-color:{{$programMappingScaleLevel->colour}}; height: 10px; width: 10px;"></div>
                                                                                        {{$programMappingScaleLevel->title}}
                                                                                        <br>
                                                                                        ({{$programMappingScaleLevel->abbreviation}})
                                                                                    </td>
                                                                                    <td>
                                                                                        {{$programMappingScaleLevel->description}}
                                                                                    </td>
                                                                                </tr>
                                                                            @endforeach
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                            @if ($courseProgram->programLearningOutcomes->count() > 0)

                                                                @php
                                                                    $_inFlight = isset($aiSuggestionInFlight) && !empty($aiSuggestionInFlight[$courseProgram->program_id]);
                                                                    $_mappingOptionsClass = ($courseProgram->pivot->manual_map_status || $courseProgram->pivot->ai_suggestion_status) ? 'd-none' : 'd-flex';
                                                                    $_aiSuggestionsBtnHidden = $_inFlight;
                                                                    $_checkingBtnHidden      = !$_inFlight;
                                                                    $_refreshBtnHidden       = true; // To be shown only after a polling timeout
                                                                    $_msgClass               = 'text-center fw-bold mt-2 mb-0' . ($_inFlight ? '' : ' d-none');
                                                                    $_msgText                = $_inFlight ? 'An AI suggestion request is already in progress for this course/program. You can leave this page - results will appear automatically when ready.' : '';
                                                                @endphp
                                                                <div id="mappingOptions-{{$course->course_id}}-{{$courseProgram->program_id}}"
                                                                     class="{{ $_mappingOptionsClass }} justify-content-center gap-2"
                                                                     data-course-id="{{ $course->course_id }}"
                                                                     data-program-id="{{ $courseProgram->program_id }}"
                                                                     @if($_inFlight) data-poll-on-load="true" @endif>
                                                                    <button id="buttonManualMap[{{$course->course_id}}][{{$courseProgram->program_id}}]" type="button" class="btn btn-sm btn-primary col-3 py-2" onclick="showManualMapDiv({{$course->course_id}}, {{$courseProgram->program_id}})">Create Manually</button>
                                                                    <button id="buttonAISuggestionCenter-{{$course->course_id}}-{{$courseProgram->program_id}}" type="button" class="btn btn-sm btn-primary col-3 py-2 {{ $_aiSuggestionsBtnHidden ? 'd-none' : '' }}"
                                                                            data-bs-toggle="modal" data-bs-target="#AiSuggestionConfirmation{{$course->course_id}}{{$courseProgram->program_id}}">
                                                                        <img src="{{asset('img/AISuggestionWhite.png')}}" alt="icon" style="height: 1.5em; width: auto;" class="me-2">AI Suggestions</button>
                                                                    <button id="aiCheckingCenter-{{$course->course_id}}-{{$courseProgram->program_id}}" type="button" class="btn btn-sm btn-secondary col-3 py-2 {{ $_checkingBtnHidden ? 'd-none' : '' }}" disabled>
                                                                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                                                        Waiting for AI suggestions...
                                                                    </button>
                                                                    <button id="aiRefreshCenter-{{$course->course_id}}-{{$courseProgram->program_id}}" type="button" class="btn btn-sm btn-warning col-3 py-2 {{ $_refreshBtnHidden ? 'd-none' : '' }}"
                                                                            onclick="refreshAiSuggestions({{$course->course_id}}, {{$courseProgram->program_id}})">
                                                                        <i class="bi bi-arrow-clockwise me-2"></i>
                                                                        Refresh AI Suggestions
                                                                    </button>
                                                                </div>
                                                                <p id="aiStatusMessage-{{$course->course_id}}-{{$courseProgram->program_id}}" class="{{ $_msgClass }}">{{ $_msgText }}</p>
                                                                <!-- list of course learning outcome accordions with mapping form -->
                                                                <div id= "ManualMapBody-{{$course->course_id}}-{{$courseProgram->program_id}}" class="cloAccordions mb-4" @if(!$courseProgram->pivot->manual_map_status && !$courseProgram->pivot->ai_suggestion_status) style="display: none;" @endif>
                                                                    @foreach($l_outcomes as $index => $courseLearningOutcome)
                                                                        <div class="accordion" id="accordionGroup{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}">
                                                                            <div class="accordion-item mb-2">
                                                                                <!-- CLO accordion header -->
                                                                                <h2 class="accordion-header" id="header{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}">
                                                                                    <button class="accordion-button white-arrow clo collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}" aria-expanded="false" aria-controls="collapse{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}">
                                                                                        <b>CLO {{$index+1}} </b>. {{$courseLearningOutcome->clo_shortphrase}}
                                                                                    </button>
                                                                                </h2>

                                                                                <div id="collapse{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}" class="accordion-collapse collapse" aria-labelledby="header{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}" data-bs-parent="#accordionGroup{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}">
                                                                                    <!-- CLO accordion body -->
                                                                                    <div class="accordion-body">

                                                                                        <!-- <form id="{{$courseProgram->program_id}}-{{$courseLearningOutcome->l_outcome_id}}" action="{{action([\App\Http\Controllers\OutcomeMapController::class, 'store'])}}" method="POST"> -->
                                                                                            <!-- @csrf -->
                                                                                            <input type="hidden" name="l_outcome_id" value="{{$courseLearningOutcome->l_outcome_id}}">

                                                                                            <div class="card border-white">
                                                                                                <div class="card-body">
                                                                                                    <h5 style="margin-bottom:16px;text-align:center;font-weight: bold;">{{$courseLearningOutcome->l_outcome}}</h5>


                                                                                                            <table class="table table-bordered table-sm">
                                                                                                                <thead class="thead-light">
                                                                                                                    <tr class="table-active">
                                                                                                                        <th class="text-center">#</th>
                                                                                                                        <th>Program Learning Outcomes or Competencies</th>
                                                                                                                        <!-- Mapping Table Levels -->
                                                                                                                        @foreach($courseProgram->mappingScaleLevels as $programMappingScaleLevel)
                                                                                                                            <th data-bs-toggle="tooltip" title="{{$programMappingScaleLevel->title}}: {{$programMappingScaleLevel->description}}">
                                                                                                                                {{$programMappingScaleLevel->abbreviation}}
                                                                                                                            </th>
                                                                                                                        @endforeach
                                                                                                                        <th data-bs-toggle="tooltip" title="Not Aligned">N/A</th>
                                                                                                                    </tr>
                                                                                                                </thead>

                                                                                                                <tbody>
                                                                                                                    @if ($courseProgram->ploCategories->count() > 0)

                                                                                                                        <?php $pos = 0 ?>
                                                                                                                        @foreach ($courseProgram->ploCategories as $ploCategory)
                                                                                                                            @if ($ploCategory->plos->count() > 0)
                                                                                                                                <tr>
                                                                                                                                    <td colspan="42" class="table-active">{{$ploCategory->plo_category}}</td>
                                                                                                                                </tr>
                                                                                                                                @foreach ($ploCategory->plos as $plo)

                                                                                                                                <tr>
                                                                                                                                    <?php $pos++ ?>
                                                                                                                                    <td style="width:5%" >{{$pos}}</td>
                                                                                                                                    <td>
                                                                                                                                        <b>{{$plo->plo_shortphrase}}</b><br>
                                                                                                                                        {{$plo->pl_outcome}}
                                                                                                                                    </td>
                                                                                                                                    @php
                                                                                                                                        $plMappings = $courseLearningOutcome->programLearningOutcomes->where('pl_outcome_id', $plo->pl_outcome_id)->pluck('pivot.map_scale_id')->toArray();
                                                                                                                                    @endphp
                                                                                                                                    @foreach($courseProgram->mappingScaleLevels as $programMappingScaleLevel)
                                                                                                                                        <td>
                                                                                                                                            <div class="form-check">
{{--                                                                                                                                                <input  class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="{{$programMappingScaleLevel->map_scale_id}}" @if(isset($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot)) @if($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot->map_scale_id == $programMappingScaleLevel->map_scale_id) checked=checked @endif @endif>--}}
                                                                                                                                                <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="{{$programMappingScaleLevel->map_scale_id}}" @if(in_array($programMappingScaleLevel->map_scale_id, $plMappings)) checked=checked @endif>
                                                                                                                                                @if($courseLearningOutcome->aiSuggestedOutcomeMap()->where('l_outcome_id', $courseLearningOutcome->l_outcome_id)->where('pl_outcome_id', $plo->pl_outcome_id)->where('map_scale_id', $programMappingScaleLevel->map_scale_id)->exists())
                                                                                                                                                    <img src="{{ asset('img/AISuggestionPurple.png') }}" alt="icon" style="height: 1.5em; width: auto;">
                                                                                                                                                @endif
                                                                                                                                            </div>
                                                                                                                                        </td>
                                                                                                                                    @endforeach
                                                                                                                                    <td>
                                                                                                                                        <div class="form-check">
{{--                                                                                                                                            <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="0" @if(isset($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot)) @if($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot->map_scale_id == 0) checked=checked @endif @else checked @endif >--}}
                                                                                                                                            <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="0" @if(in_array(0, $plMappings)) checked=checked @elseif(count($plMappings) > 0) disabled @endif>
                                                                                                                                            @if($courseLearningOutcome->aiSuggestedOutcomeMap()->where('l_outcome_id', $courseLearningOutcome->l_outcome_id)->where('pl_outcome_id', $plo->pl_outcome_id)->where('map_scale_id', 0)->exists())
                                                                                                                                                <img src="{{ asset('img/AISuggestionPurple.png') }}" alt="icon" style="height: 1.5em; width: auto;">
                                                                                                                                            @endif
                                                                                                                                        </div>
                                                                                                                                    </td>
                                                                                                                                </tr>
                                                                                                                                @endforeach
                                                                                                                            @endif
                                                                                                                        @endforeach
                                                                                                                        <?php $hasRan = FALSE ?>
                                                                                                                        @foreach ($courseProgram->programLearningOutcomes as $plo)
                                                                                                                            @if (!isset($plo->category))
                                                                                                                                @if (! $hasRan)
                                                                                                                                    <tr>
                                                                                                                                        <td class="table-active" colspan="42">Uncategorized PLOs</td>
                                                                                                                                    </tr>
                                                                                                                                    <?php $hasRan = TRUE ?>
                                                                                                                                @endif
                                                                                                                            <tr>
                                                                                                                                <td>{{$pos++ + 1}}</td>
                                                                                                                                <td>
                                                                                                                                    <b>{{$plo->plo_shortphrase}}</b><br>
                                                                                                                                    {{$plo->pl_outcome}}
                                                                                                                                </td>
                                                                                                                                @php
                                                                                                                                    $plMappings = $courseLearningOutcome->programLearningOutcomes->where('pl_outcome_id', $plo->pl_outcome_id)->pluck('pivot.map_scale_id')->toArray();
                                                                                                                                @endphp
                                                                                                                                @foreach($courseProgram->mappingScaleLevels as $programMappingScaleLevel)
                                                                                                                                    <td>
                                                                                                                                        <div class="form-check">
{{--                                                                                                                                            <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="{{$programMappingScaleLevel->map_scale_id}}" @if(isset($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot)) @if($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot->map_scale_id == $programMappingScaleLevel->map_scale_id) checked=checked @endif @endif>--}}
                                                                                                                                            <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="{{$programMappingScaleLevel->map_scale_id}}" @if(in_array($programMappingScaleLevel->map_scale_id, $plMappings)) checked=checked @endif>
                                                                                                                                            @if($courseLearningOutcome->aiSuggestedOutcomeMap()->where('l_outcome_id', $courseLearningOutcome->l_outcome_id)->where('pl_outcome_id', $plo->pl_outcome_id)->where('map_scale_id', $programMappingScaleLevel->map_scale_id)->exists())
                                                                                                                                                <img src="{{ asset('img/AISuggestionPurple.png') }}" alt="icon" style="height: 1.5em; width: auto;">
                                                                                                                                            @endif
                                                                                                                                        </div>
                                                                                                                                    </td>
                                                                                                                                @endforeach
                                                                                                                                <td>
                                                                                                                                    <div class="form-check">
{{--                                                                                                                                        <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="0" @if(isset($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot)) @if($courseLearningOutcome->programLearningOutcomes->find($plo->pl_outcome_id)->pivot->map_scale_id == 0) checked=checked @endif @else checked @endif >--}}
                                                                                                                                        <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$plo->pl_outcome_id}}][]" value="0" @if(in_array(0, $plMappings)) checked=checked @elseif(count($plMappings) > 0) disabled @endif>
                                                                                                                                        @if($courseLearningOutcome->aiSuggestedOutcomeMap()->where('l_outcome_id', $courseLearningOutcome->l_outcome_id)->where('pl_outcome_id', $plo->pl_outcome_id)->where('map_scale_id', 0)->exists())
                                                                                                                                            <img src="{{ asset('img/AISuggestionPurple.png') }}" alt="icon" style="height: 1.5em; width: auto;">
                                                                                                                                        @endif
                                                                                                                                    </div>
                                                                                                                                </td>
                                                                                                                            </tr>
                                                                                                                            @endif
                                                                                                                        @endforeach
                                                                                                                    @else
                                                                                                                        <tr>
                                                                                                                            <td class="table-active" colspan="42">Uncategorized PLOs</td>
                                                                                                                        </tr>
                                                                                                                        @foreach($courseProgram->programLearningOutcomes as $index => $pl_outcome)
                                                                                                                            <tr>
                                                                                                                                <td class="text-center fw-bold" style="width:5%" >{{$index+1}}</td>
                                                                                                                                <td>
                                                                                                                                    <b>{{$pl_outcome->plo_shortphrase}}</b>
                                                                                                                                    <br>
                                                                                                                                    {{$pl_outcome->pl_outcome}}
                                                                                                                                </td>
                                                                                                                                @php
                                                                                                                                    $plMappings = $courseLearningOutcome->programLearningOutcomes->where('pl_outcome_id', $pl_outcome->pl_outcome_id)->pluck('pivot.map_scale_id')->toArray();
                                                                                                                                @endphp
                                                                                                                                @foreach($courseProgram->mappingScaleLevels as $programMappingScaleLevel)
                                                                                                                                    <td>
                                                                                                                                        <div class="form-check">
{{--                                                                                                                                        <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$pl_outcome->pl_outcome_id}}][]" value="{{$programMappingScaleLevel->map_scale_id}}" @if(isset($courseLearningOutcome->programLearningOutcomes->find($pl_outcome->pl_outcome_id)->pivot)) @if($courseLearningOutcome->programLearningOutcomes->find($pl_outcome->pl_outcome_id)->pivot->map_scale_id == $programMappingScaleLevel->map_scale_id) checked=checked @endif @endif>--}}
                                                                                                                                            <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$pl_outcome->pl_outcome_id}}][]" value="{{$programMappingScaleLevel->map_scale_id}}" @if(in_array($programMappingScaleLevel->map_scale_id, $plMappings)) checked=checked @endif>
                                                                                                                                            @if($courseLearningOutcome->aiSuggestedOutcomeMap()->where('l_outcome_id', $courseLearningOutcome->l_outcome_id)->where('pl_outcome_id', $pl_outcome->pl_outcome_id)->where('map_scale_id', $programMappingScaleLevel->map_scale_id)->exists())
                                                                                                                                                <img src="{{ asset('img/AISuggestionPurple.png') }}" alt="icon" style="height: 1.5em; width: auto;">
                                                                                                                                            @endif
                                                                                                                                        </div>
                                                                                                                                    </td>
                                                                                                                                @endforeach
                                                                                                                                <td>
                                                                                                                                    <div class="form-check">
{{--                                                                                                                                        <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$pl_outcome->pl_outcome_id}}][]" value="0" @if(isset($courseLearningOutcome->programLearningOutcomes->find($pl_outcome->pl_outcome_id)->pivot)) @if($courseLearningOutcome->programLearningOutcomes->find($pl_outcome->pl_outcome_id)->pivot->map_scale_id == 0) checked=checked @endif @else checked @endif >--}}
                                                                                                                                        <input class="form-check-input position-static" type="checkbox" name="map[{{$courseLearningOutcome->l_outcome_id}}][{{$pl_outcome->pl_outcome_id}}][]" value="0" @if(in_array(0, $plMappings)) checked=checked @elseif(count($plMappings) > 0) disabled @endif>
                                                                                                                                        @if($courseLearningOutcome->aiSuggestedOutcomeMap()->where('l_outcome_id', $courseLearningOutcome->l_outcome_id)->where('pl_outcome_id', $pl_outcome->pl_outcome_id)->where('map_scale_id', 0)->exists())
                                                                                                                                            <img src="{{ asset('img/AISuggestionPurple.png') }}" alt="icon" style="height: 1.5em; width: auto;" >
                                                                                                                                        @endif
                                                                                                                                    </div>
                                                                                                                                </td>
                                                                                                                            </tr>
                                                                                                                        @endforeach
                                                                                                                    @endif
                                                                                                                </tbody>
                                                                                                            </table>

                                                                                                </div>
                                                                                            </div>
                                                                                        <!-- </form> -->
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            @else
                                                                <div class="alert alert-warning text-center">
                                                                    <i class="bi bi-exclamation-circle-fill pe-2 fs-5"></i>Program learning outcomes have not been set for this program
                                                                </div>
                                                            @endif
                                                        @else
                                                            <div class="alert alert-warning text-center">
                                                                <i class="bi bi-exclamation-circle-fill pe-2 fs-5"></i>A mapping scale has not been set for this program.
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- End of Program Accordion -->
                                    @endforeach
                                </div>
                                <button type="submit" class="btn btn-success my-3 btn-sm float-end col-2" >Save</button>
                            @endif
                            </form>
                        </div>
                    @endif

                </div>
                <!-- card footer -->
                <div class="card-footer">
                    <div class="card-body mb-4">
                        <a href="{{route('courseWizard.step4', $course->course_id)}}">
                            <button class="btn btn-sm btn-primary col-3 float-start"><i class="bi bi-arrow-left me-2"></i> Course Alignment</button>
                        </a>
                        <a href="{{route('courseWizard.step6', $course->course_id)}}">
                            <button class="btn btn-sm btn-primary col-3 float-end">Standards and Strategic Priorities<i class="bi bi-arrow-right ms-2"></i></button>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
    $(document).ready(function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

        $("form").submit(function () {
        // prevent duplicate form submissions
        $(this).find(":submit").attr('disabled', 'disabled');
        $(this).find(":submit").html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>');
        hasUnsavedChanges = false;
        });

        // Hide and show the optional
        $("#highOpportunity").on('change', function () {
            var value = $("#highOpportunity").val();
            console.log(value);
            if (value == "1" ){
                $('#addedOptions').show();
                $("#addedOptions :input").prop("disabled", false);
            }else{
                $('#addedOptions').hide();
                $("#addedOptions :input").prop("disabled", true);
            }
        });

        $('#btnAdd').click(function() {
            add();
        });

        // Auto-resume polling for any (course, program) that was rendered with an
        // in-flight AI suggestion request (rendered in the "Checking..." state by Blade).
        // This handles: same-user-navigated-back, different-user-on-same-page.
        const startedPairs = new Set();
        document.querySelectorAll('[data-poll-on-load="true"]').forEach((el) => {
            const courseId = el.getAttribute('data-course-id');
            const programId = el.getAttribute('data-program-id');
            if (!courseId || !programId) return;
            const key = `${courseId}-${programId}`;
            if (startedPairs.has(key)) return;
            startedPairs.add(key);
            // Both the header and center status containers can carry the same data
            // attributes; Set dedupes so we only kick off one polling loop per pair.
            pollForResults(parseInt(courseId, 10), parseInt(programId, 10));
        });

        // $("form").submit(function (e) {
        //     // prevent duplicate form submissions
        //     e.preventDefault();

        //     var id = $(this).attr('id');

        //     $(this).find(":submit").attr('disabled', 'disabled');
        //     $(this).find(":submit").html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>');

        //     var form_action = $(this).attr("action");

        //     $.ajax({
        //         data: $(this).serialize(),
        //         url: form_action,
        //         type: "POST",
        //         dataType: 'json',
        //         success: function (data) {
        //             $('form[id='+id+']').find(":submit").removeAttr('disabled');
        //             $('form[id='+id+']').find(":submit").html('Save');


        //             $('form[id='+id+']').find("#alert").html("Your answers have been saved successfully");
        //             $('form[id='+id+']').find("#alert").toggleClass("alert alert-success");
        //             $('form[id='+id+']').find("#alert").delay(2000).slideUp(200, function() {
        //                 $(this).alert('close');
        //             });

        //         },
        //         error: function (data) {
        //             $('form[id='+id+']').find(":submit").removeAttr('disabled');
        //             $('form[id='+id+']').find(":submit").html('Save');


        //             $('form[id='+id+']').find("#alert").html("There was an error saving your answers");
        //             $('form[id='+id+']').find("#alert").toggleClass("alert alert-danger");
        //             $('form[id='+id+']').find("#alert").delay(2000).slideUp(200, function() {
        //                 $(this).alert('close');
        //             });
        //         }
        //     });




        // });
    });

    document.addEventListener('change', function (e) {
        if (!e.target.matches('.form-check-input.position-static')) return;
        hasUnsavedChanges = true;

        const name = e.target.name;

        const boxes = document.getElementsByName(name);

        let naBox = null;
        let scaleChecked = false;

        boxes.forEach(box => {
            if (box.value === "0") {
                naBox = box;
            } else if (box.checked) {
                scaleChecked = true;
            }
        });

        if (scaleChecked) {
            naBox.checked = false;
            naBox.disabled = true;
        } else {
            naBox.disabled = false;
        }
    });

    function showManualMapDiv(course_id, program_id) {
        fetch(`${program_id}/manualMap`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        }).then(response => {
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }

                const div = document.getElementById(`mappingOptions-${course_id}-${program_id}`);
                div.classList.add('d-none');
                div.classList.remove('d-flex');

                document.getElementById(`ManualMapBody-${course_id}-${program_id}`).style.display = "block";

                const inFlight = div.getAttribute('data-poll-on-load') === 'true';
                const headerBtn    = document.getElementById(`buttonAISuggestionHeader-${course_id}-${program_id}`);
                const headerStatus = document.getElementById(`aiStatusHeader-${course_id}-${program_id}`);
                setHidden(headerBtn,    inFlight);
                setHidden(headerStatus, !inFlight);
            }).catch(error => {
                console.error('Unable to enable manual mapping:', error);
                alert('We could not open manual mapping right now. Please try again later.');
            });
    }

    function add() {
        var length = $('#highOpportunityTable tr').length;

        var element = `
            <tr>
                <td>
                    `
                    +length+
                    `
                </td>
                <td>
                    <input class = "form-control" type="text" name="inputItem[]" spellcheck="true" >
                </td>
            </tr>`;
            var container = $('#highOpportunityTable tbody');
            container.append(element);
    }

    const AI_POLL_INTERVAL_MS = 5000;
    const AI_POLL_MAX_ATTEMPTS = 120; // ~10 minutes

    const AI_MSG_CHECKING =
        "Generating AI suggestions can take some time depending on how many PLOs and CLOs there are. " +
        "You can safely leave this page - your suggestions will be saved when ready " +
        "and will appear automatically the next time you open this page.";

    const AI_MSG_TIMED_OUT =
        "This is taking longer than usual. Click Refresh to check again, " +
        "or come back later - your suggestions will appear automatically when ready.";

    const AI_MSG_READY_UNSAVED =
        "AI suggestions are ready - reload to see. Save your unsaved changes if you wish to keep them.";

    let hasUnsavedChanges = false;

    window.addEventListener('beforeunload', function (e) {
        if (!hasUnsavedChanges) return;
        e.preventDefault();
    });

    function hideAiModal(courseId, programId) {
        const modalEl = document.getElementById(`AiSuggestionConfirmation${courseId}${programId}`);
        if (!modalEl) return;
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }

    function setHidden(el, hidden) {
        if (!el) return;
        if (hidden) {
            el.classList.add('d-none');
            el.classList.remove('d-flex');
        } else {
            el.classList.remove('d-none');
            el.classList.add('d-flex');
        }
    }

    function enterCheckingState(courseId, programId, message) {
        const aiBtnHeader = document.getElementById(`buttonAISuggestionHeader-${courseId}-${programId}`);
        const aiBtnCenter = document.getElementById(`buttonAISuggestionCenter-${courseId}-${programId}`);
        if (aiBtnHeader) aiBtnHeader.classList.add('d-none');
        if (aiBtnCenter) aiBtnCenter.classList.add('d-none');

        setHidden(document.getElementById(`aiStatusHeader-${courseId}-${programId}`), false);

        ['Header', 'Center'].forEach(loc => {
            const checking = document.getElementById(`aiChecking${loc}-${courseId}-${programId}`);
            const refresh  = document.getElementById(`aiRefresh${loc}-${courseId}-${programId}`);
            if (checking) checking.classList.remove('d-none');
            if (refresh)  refresh.classList.add('d-none');
        });

        const msgEl = document.getElementById(`aiStatusMessage-${courseId}-${programId}`);
        if (msgEl) {
            msgEl.textContent = message;
            msgEl.classList.remove('d-none');
        }
    }

    function enterManualRefreshState(courseId, programId, message = AI_MSG_TIMED_OUT) {
        ['Header', 'Center'].forEach(loc => {
            const checking = document.getElementById(`aiChecking${loc}-${courseId}-${programId}`);
            const refresh  = document.getElementById(`aiRefresh${loc}-${courseId}-${programId}`);
            if (checking) checking.classList.add('d-none');
            if (refresh)  refresh.classList.remove('d-none');
        });

        const msgEl = document.getElementById(`aiStatusMessage-${courseId}-${programId}`);
        if (msgEl) {
            msgEl.textContent = message;
            msgEl.classList.remove('d-none');
        }
    }

    function refreshAiSuggestions(courseId, programId) {
        enterCheckingState(courseId, programId, AI_MSG_CHECKING);
        pollForResults(courseId, programId);
    }

    async function generateAiSuggestions(courseId, programId) {
        const yesButton = event.target;
        yesButton.disabled = true;
        const originalText = yesButton.innerHTML;
        yesButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...';

        // Pre-submit in-flight check: catches the race where another user (or this user
        // in another tab) already submitted in the seconds between page render and click.
        try {
            const checkRes = await fetch(`/courseWizard/${courseId}/${programId}/check-in-flight`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({}),
            });
            if (checkRes.ok) {
                const checkData = await checkRes.json();
                if (checkData.in_flight) {
                    hideAiModal(courseId, programId);
                    enterCheckingState(courseId, programId,
                        "An AI suggestion request was already submitted (possibly by another user). " +
                        "Polling for results - this can take some time.");
                    pollForResults(courseId, programId);
                    yesButton.disabled = false;
                    yesButton.innerHTML = originalText;
                    return;
                }
            }
        } catch (err) {
            console.warn('Pre-click in-flight check failed, proceeding with submission:', err);
        }

        fetch(`/courseWizard/${courseId}/${programId}/generate-ai-suggestions`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                course_id: courseId,
                program_id: programId,
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('AI suggestion response:', data);

            if (data.status === 'success') {
                hideAiModal(courseId, programId);
                enterCheckingState(courseId, programId, AI_MSG_CHECKING);
                pollForResults(courseId, programId);
            } else {
                throw new Error(data.message || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error generating AI suggestions: ' + error.message + '\n\nPlease try again.');
        })
        .finally(() => {
            yesButton.disabled = false;
            yesButton.innerHTML = originalText;
        });
    }

    function pollForResults(courseId, programId) {
        let attempts = 0;

        const interval = setInterval(async () => {
            attempts++;
            try {
                const response = await fetch(`/courseWizard/${courseId}/${programId}/check-ai-results`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ course_id: courseId, program_id: programId }),
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (data.status === 'complete') {
                    clearInterval(interval);
                    if (hasUnsavedChanges) {
                        enterManualRefreshState(courseId, programId, AI_MSG_READY_UNSAVED);
                    } else {
                        window.location.reload();
                    }
                } else if (attempts >= AI_POLL_MAX_ATTEMPTS) {
                    clearInterval(interval);
                    enterManualRefreshState(courseId, programId);
                }
            } catch (err) {
                console.error('Polling error:', err);
                if (attempts >= AI_POLL_MAX_ATTEMPTS) {
                    clearInterval(interval);
                    enterManualRefreshState(courseId, programId);
                }
            }
        }, AI_POLL_INTERVAL_MS);
    }

</script>
<style>
    h3 span{width:32%;display:inline-block;}
    h3 span:last-child { text-align:right }
</style>


@endsection
