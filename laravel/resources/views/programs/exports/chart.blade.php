<div style="page-break-inside: avoid;">
    <h2>Report chart</h2>
    @if (! empty($chartImage))
        <img src="{{ $chartImage }}" alt="Report chart" style="width: 100%; height: auto;">
    @else
        <p class="muted">The chart is unavailable. Exact values remain available in the tables below.</p>
    @endif
</div>
