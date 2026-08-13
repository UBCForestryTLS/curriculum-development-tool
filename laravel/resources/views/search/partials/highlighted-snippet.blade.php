@php
    // Escape stored content first, then restore only the highlight tags added by ts_headline.
    $safeSnippet = str_replace(
        ['&lt;mark&gt;', '&lt;/mark&gt;'],
        ['<mark>', '</mark>'],
        e($snippet)
    );
@endphp

{!! $safeSnippet !!}
