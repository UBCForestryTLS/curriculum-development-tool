<div class="search-result-match">
    <p>
        <strong>{{ $match->property === 'learning outcome' ? 'Learning Objective' : ucwords($match->property) }}:</strong>

        @if($match->property === 'material content')
            <a href="{{ route('course.material.files.view', ['course' => $match->course_id, 'material' => $match->course_material_id, 'file' => $match->file_id]) }}#page={{ $match->page_number }}"
                target="_blank" rel="noopener noreferrer">
                {{ $match->file_name }}, Page {{ $match->page_number }}
            </a>
            <br>
        @endif

        @include('search.partials.highlighted-snippet', ['snippet' => $match->snippet])
    </p>
</div>
