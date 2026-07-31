@use('\App\Support\SearchedTextHighlighter')

@if ($results->isEmpty())
    <p class="text-muted mb-0">No results found.</p>
@else
    @foreach ($results as $result)
        <a href="{{ route('course.material.files.view', ['course' => $result->course_id, 'material' => $result->course_material_id, 'file' => $result->file_id]) }}#page={{ $result->page_number }}"
           target="_blank" class="text-decoration-none text-reset">
            <div class="border rounded p-3 mb-2">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-md">
                        <div class="mb-1">
                            <strong>{{ $result->file_name }}</strong>
                            <span class="text-muted ms-2">Page {{ $result->page_number }}</span>
                        </div>
                        <div>{!! SearchedTextHighlighter::render($result->snippet) !!}</div>
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
