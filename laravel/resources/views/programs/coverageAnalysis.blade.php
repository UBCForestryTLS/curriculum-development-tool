@extends('layouts.app')

@section('content')
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
        <div class="card-header"><h5 class="mb-0">Search (Temp)</h5></div>
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
                        <a href="{{ route('course.material.files.view', ['course' => $result->course_id, 'material' => $result->course_material_id, 'file' => $result->file_id]) }}#page={{ $result->page_number }}"
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
                                        <img src="{{ route('course.material.files.thumbnail', ['course' => $result->course_id, 'material' => $result->course_material_id, 'file' => $result->file_id, 'page' => $result->page_number]) }}"
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
</div>
@endsection
