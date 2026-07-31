@extends('layouts.app')

@section('content')
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

    <div class="card mt-4">
        <div class="card-header"><h5 class="mb-0">Search (Temp)</h5></div>
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
                <span class="text-muted fw-normal">({{ session('search_results')->count() }} result(s))</span>
            </h6></div>
            <div class="card-body">
                @include('partials.search-result', ['results' => session('search_results')])
            </div>
        </div>
    @endif
</div>
@endsection
