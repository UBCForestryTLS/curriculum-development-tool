<div class="py-4">
    <h4>Gap and Redundancy Report</h4>
    <p>Review how course learning outcomes currently cover each program learning outcome.</p>

    <ol class="list-unstyled d-flex flex-wrap gap-3 mb-4" aria-label="Report steps">
        <li id="gap-coverage-expectations-step" class="fw-bold" aria-current="step">1. Set expectations</li>
        <li id="gap-coverage-report-step">2. View report</li>
    </ol>

    <div id="gap-coverage-loading" class="py-4 text-center d-none" role="status">
        <div class="spinner-border text-primary" aria-hidden="true"></div>
        <p class="mb-0 mt-2">Loading coverage data…</p>
    </div>

    <div id="gap-coverage-error" class="alert alert-danger d-none" role="alert">
        <p>Coverage data could not be loaded. Please try again.</p>
        <button id="gap-coverage-retry" type="button" class="btn btn-outline-danger">Retry</button>
    </div>

    <div id="gap-coverage-empty" class="alert alert-warning d-none" role="alert">
        There are no program learning outcomes to analyze. Add program learning outcomes before setting expectations or viewing this report.
    </div>

    <div id="gap-coverage-incomplete" class="alert alert-warning d-none" role="alert">
        Some course learning outcomes have not been fully mapped to this program. Coverage results may be incomplete.
        Missing mapping decisions are different from explicit N/A mappings.
        Any potential gap or redundancy findings are provisional until mapping is complete.
        <a href="{{ route('programWizard.step3', $program->program_id) }}">Review course mappings</a>.
    </div>

    <section id="gap-coverage-expectations" aria-labelledby="gap-coverage-expectations-heading">
        <h5 id="gap-coverage-expectations-heading" tabindex="-1">Set expectations</h5>
        <p>Set optional coverage percentage ranges at each mapping level. The same expectations apply to every PLO.</p>
        <p id="gap-coverage-percentage-help">Below your minimum indicates a potential gap; above your maximum indicates a potential redundancy. Values on either boundary are within your range. Leave either bound blank for no expectation. Minimum 0% sets no lower requirement; maximum 0% means no coverage is expected.</p>
        <p class="small text-muted">Each mapping level has an independent range. Percentages across levels can total more than 100% because a course or CLO can contribute at several levels. N/A mappings do not contribute to coverage and are counted separately.</p>
        <p id="gap-coverage-no-levels" class="alert alert-info d-none">No non-N/A mapping levels are configured. You can still view statistics without setting expectations.</p>
        <form id="gap-coverage-expectations-form" novalidate>
            <fieldset id="gap-coverage-expectations-fields" disabled>
                <legend class="fs-6">Choose your expectations</legend>
                <fieldset class="mb-3" aria-describedby="gap-coverage-concerns-help">
                    <legend class="fs-6">Which potential concerns do you want to review?</legend>
                    <div class="form-check">
                        <input id="gap-coverage-review-gaps" type="checkbox" class="form-check-input" checked>
                        <label for="gap-coverage-review-gaps" class="form-check-label">Potential gaps</label>
                    </div>
                    <div class="form-check">
                        <input id="gap-coverage-review-redundancies" type="checkbox" class="form-check-input">
                        <label for="gap-coverage-review-redundancies" class="form-check-label">Potential redundancies</label>
                    </div>
                    <p id="gap-coverage-concerns-help" class="form-text mb-0">Choose either or both: gaps use minimums, redundancies use maximums. Unselected bounds are ignored and kept for editing. With neither selected, you can view statistics only.</p>
                </fieldset>

                @php
                    $coverageMetrics = [
                        'mapped_clo_count' => ['Mapped CLOs', 'Distinct CLOs mapped to each PLO at a level, as a percentage of all CLOs in program courses.'],
                        'covering_course_count' => ['Covering courses', 'Distinct courses covering each PLO at a level, as a percentage of all program courses.'],
                        'required_course_count' => ['Required covering courses', 'Distinct required courses covering each PLO at a level, as a percentage of all program courses.'],
                        'non_required_course_count' => ['Non-required covering courses', 'Distinct non-required courses covering each PLO at a level, as a percentage of all program courses.'],
                    ];
                @endphp
                @foreach ($coverageMetrics as $key => [$label, $description])
                    <div class="border rounded p-3 mb-3" data-coverage-metric="{{ $key }}" data-metric-label="{{ $label }}">
                        <div class="form-check">
                            <input id="gap-coverage-{{ $key }}-enabled" type="checkbox" class="form-check-input" aria-controls="gap-coverage-{{ $key }}-options" aria-expanded="false" aria-describedby="gap-coverage-{{ $key }}-description gap-coverage-{{ $key }}-enabled-error">
                            <label for="gap-coverage-{{ $key }}-enabled" class="form-check-label fw-bold">{{ $label }}</label>
                            <div id="gap-coverage-{{ $key }}-enabled-error" class="invalid-feedback"></div>
                        </div>
                        <p id="gap-coverage-{{ $key }}-description" class="small text-muted mb-0">{{ $description }}</p>
                        <div id="gap-coverage-{{ $key }}-options" class="mt-3 d-none">
                            <div id="gap-coverage-{{ $key }}-levels" class="row g-3"></div>
                        </div>
                    </div>
                @endforeach

                <p class="small text-muted">These settings last while this page is open. You can also view all statistics without expectations.</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">View report</button>
                    <button id="gap-coverage-view-report" type="button" class="btn btn-outline-primary">View statistics only</button>
                </div>
            </fieldset>
        </form>
    </section>

    <section id="gap-coverage-report" class="d-none" aria-labelledby="gap-coverage-report-heading">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h5 id="gap-coverage-report-heading" class="mb-0" tabindex="-1">View report</h5>
            <button id="gap-coverage-back" type="button" class="btn btn-outline-primary">Back to expectations</button>
        </div>
        <div id="gap-coverage-expectations-summary" class="mb-3">
            <p>Showing statistics only. No coverage expectations have been applied.</p>
        </div>

        <div id="gap-coverage-results" class="position-relative d-none">
            <div class="mb-3">
                <h6>Potential concerns</h6>
                <p id="gap-coverage-concern-summary" role="status"></p>
                <p id="gap-coverage-concern-scope" class="small text-muted">Counts and filtering include all applied metrics and levels, regardless of the metric displayed below. A PLO can have both potential gaps and potential redundancies.</p>
                <label for="gap-coverage-filter" class="form-label fw-bold">Show PLOs</label>
                <select id="gap-coverage-filter" class="form-select w-auto mw-100" aria-describedby="gap-coverage-concern-summary gap-coverage-concern-scope">
                    <option value="all">All PLOs</option>
                    <option value="concerns">PLOs with concerns</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="gap-coverage-metric" class="form-label fw-bold">Coverage metric</label>
                <select id="gap-coverage-metric" class="form-select w-auto mw-100" aria-describedby="gap-coverage-metric-description">
                    @foreach ($coverageMetrics as $key => [$label, $description])
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p id="gap-coverage-metric-description" class="form-text"></p>
            </div>
            <div class="mb-3">
                <label for="gap-coverage-units" class="form-label fw-bold">Display values</label>
                <select id="gap-coverage-units" class="form-select w-auto" aria-describedby="gap-coverage-units-help">
                    <option value="percentages">Percentages</option>
                    <option value="counts">Counts</option>
                </select>
                <p id="gap-coverage-units-help" class="form-text">Changes both the chart and table. Expectations and flags always use percentages.</p>
            </div>
            <p id="gap-coverage-report-no-levels" class="alert alert-info d-none">No non-N/A mapping levels are configured. Overall statistics and course details remain available below.</p>
            <p id="gap-coverage-chart-message" class="alert alert-info d-none" role="status"></p>
            <div id="gap-coverage-chart" class="mb-3" aria-hidden="true"></div>
            <p class="small text-muted">Each level is compared independently with your applied expectations. View details for overall counts and contributing courses.</p>
            <p class="small text-muted d-sm-none">Scroll horizontally to see all mapping levels and details.</p>
            <div class="table-responsive" role="region" aria-label="Coverage comparisons" tabindex="0">
                <table class="table table-bordered align-middle">
                    <caption class="visually-hidden">Coverage by PLO and mapping level</caption>
                    <thead class="table-primary">
                        <tr id="gap-coverage-columns"></tr>
                    </thead>
                    <tbody id="gap-coverage-rows"></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="gap-coverage-details-modal" tabindex="-1" aria-labelledby="gap-coverage-details-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="gap-coverage-details-title">PLO details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="gap-coverage-details-content"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<template id="gap-coverage-level-template">
    <fieldset class="col-sm-6 col-lg-4">
        <legend class="float-none fs-6 fw-bold"></legend>
        <div class="coverage-range mb-2">
            <input type="range" min="0" max="100" step="1" value="0" class="form-range" data-slider="min" disabled>
            <input type="range" min="0" max="100" step="1" value="100" class="form-range" data-slider="max" disabled>
        </div>
        <div class="row g-2">
            @foreach (['min' => 'Minimum', 'max' => 'Maximum'] as $bound => $label)
                <div class="col-6">
                    <label class="form-label small">{{ $label }} (%)</label>
                    <input type="text" inputmode="numeric" class="form-control" placeholder="Not set" data-bound="{{ $bound }}" disabled>
                    <div class="invalid-feedback"></div>
                    <div class="form-text" data-bound-status></div>
                </div>
            @endforeach
        </div>
    </fieldset>
</template>

<style>
    #gap-coverage-columns th:not(:last-child) { min-width: 12rem; }
    #gap-coverage-details-comparisons { min-width: 620px; }
    #gap-coverage-details-findings::before { content: none; }
    /* Two native range inputs share a track; only their handles receive pointer events. */
    .coverage-range { position: relative; height: 2.5rem; touch-action: none; }
    .coverage-range::before {
        content: ''; position: absolute; inset: 1rem 0;
        background: var(--bs-secondary-bg, #e9ecef); border-radius: 1rem;
    }
    .coverage-range .form-range { position: absolute; top: .5rem; left: 0; pointer-events: none; }
    .coverage-range .form-range:focus { z-index: 1; }
    .coverage-range .form-range::-webkit-slider-runnable-track { background: transparent; }
    .coverage-range .form-range::-moz-range-track { background: transparent; }
    .coverage-range .form-range::-webkit-slider-thumb { pointer-events: auto; }
    .coverage-range .form-range::-moz-range-thumb { pointer-events: auto; }
    .coverage-range .form-range:disabled::-webkit-slider-thumb { pointer-events: none; }
    .coverage-range .form-range:disabled::-moz-range-thumb { pointer-events: none; }
    /* Separate nearby handles vertically so both can be dragged, even at 0 or 100. */
    .coverage-range[data-overlap] [data-slider="min"]::-webkit-slider-thumb { transform: translateY(-.5rem); }
    .coverage-range[data-overlap] [data-slider="max"]::-webkit-slider-thumb { transform: translateY(.5rem); }
    .coverage-range[data-overlap] [data-slider="min"]::-moz-range-thumb { transform: translateY(-.5rem); }
    .coverage-range[data-overlap] [data-slider="max"]::-moz-range-thumb { transform: translateY(.5rem); }
</style>

<script type="module">
    import { normalizeExpectations } from @json(\Illuminate\Support\Facades\Vite::asset('resources/js/programs/coverage-expectations.js'));
    import { evaluateCoverage } from @json(\Illuminate\Support\Facades\Vite::asset('resources/js/programs/coverage-report.js'));

    $(document).ready(function () {
        let gapCoverageData = null;
        let gapCoverageLoading = false;
        let appliedExpectations = null;
        let mappingScaleLevels = [];
        let validationStarted = false;
        let coverageChart = null;
        const expectationsForm = document.getElementById('gap-coverage-expectations-form');
        const metricSections = [...expectationsForm.querySelectorAll('[data-coverage-metric]')];
        const reportMetric = document.getElementById('gap-coverage-metric');
        const reportFilter = document.getElementById('gap-coverage-filter');
        const reportUnits = document.getElementById('gap-coverage-units');
        const concernInputs = {
            min: document.getElementById('gap-coverage-review-gaps'),
            max: document.getElementById('gap-coverage-review-redundancies'),
        };

        function initializeLevelInputs() {
            const template = document.getElementById('gap-coverage-level-template');
            metricSections.forEach(function (section) {
                const key = section.dataset.coverageMetric;
                const container = document.getElementById(`gap-coverage-${key}-levels`);
                mappingScaleLevels.forEach(function (level) {
                    const fields = template.content.firstElementChild.cloneNode(true);
                    fields.dataset.levelId = level.map_scale_id;
                    fields.querySelector('legend').textContent = level.title + (level.abbreviation ? ` (${level.abbreviation})` : '');
                    fields.querySelectorAll('[data-bound]').forEach(function (input) {
                        const bound = input.dataset.bound;
                        const slider = fields.querySelector(`[data-slider="${bound}"]`);
                        input.id = `gap-coverage-${key}-levels-${level.map_scale_id}-${bound}`;
                        slider.id = `${input.id}-slider`;
                        input.previousElementSibling.htmlFor = input.id;
                        input.nextElementSibling.id = `${input.id}-error`;
                        const label = bound === 'min' ? 'Minimum' : 'Maximum';
                        [input, slider].forEach(function (control) {
                            control.setAttribute('aria-describedby', `gap-coverage-${key}-description gap-coverage-percentage-help ${input.id}-error`);
                            control.setAttribute('aria-label', `${label} (%), ${section.dataset.metricLabel}, ${level.title}`);
                        });
                        slider.addEventListener('input', function () {
                            const other = fields.querySelector(`[data-bound="${bound === 'min' ? 'max' : 'min'}"]`);
                            const otherValue = other.value.trim();
                            if (!other.disabled && /^\d+$/.test(otherValue) && Number(otherValue) <= 100) {
                                slider.value = bound === 'min'
                                    ? Math.min(Number(slider.value), Number(otherValue))
                                    : Math.max(Number(slider.value), Number(otherValue));
                            }
                            input.value = slider.value;
                            syncRangeInputs(fields);
                            if (validationStarted) validateExpectations();
                        });
                        input.addEventListener('input', function () {
                            syncRangeInputs(fields);
                            if (validationStarted) validateExpectations();
                        });
                        input.addEventListener('blur', function () {
                            validationStarted = true;
                            validateExpectations();
                        });
                    });
                    container.appendChild(fields);
                });
            });
            document.getElementById('gap-coverage-no-levels').classList.toggle('d-none', mappingScaleLevels.length > 0 || gapCoverageData.coverage.length === 0);
            updateExpectationInputs();
        }

        function syncRangeInputs(fields) {
            fields.querySelectorAll('[data-bound]').forEach(function (input) {
                const slider = fields.querySelector(`[data-slider="${input.dataset.bound}"]`);
                const value = input.value.trim();
                input.parentElement.querySelector('[data-bound-status]').textContent = input.disabled ? 'Not selected' : '';
                if (value === '') {
                    slider.value = input.dataset.bound === 'min' ? '0' : '100';
                    slider.setAttribute('aria-valuetext', 'Not set');
                } else if (/^\d+$/.test(value) && Number(value) <= 100) {
                    slider.value = value;
                    slider.setAttribute('aria-valuetext', `${Number(value)}%`);
                } else {
                    slider.setAttribute('aria-valuetext', 'Enter a whole percentage from 0 to 100');
                }
            });
            const min = fields.querySelector('[data-slider="min"]');
            const max = fields.querySelector('[data-slider="max"]');
            const track = fields.querySelector('.coverage-range');
            if (track.clientWidth === 0) return;
            const distance = Math.abs(Number(max.value) - Number(min.value)) / 100 * Math.max(0, track.clientWidth - 16);
            track.toggleAttribute('data-overlap', !min.disabled && !max.disabled && distance < 20);
        }

        window.addEventListener('resize', function () {
            expectationsForm.querySelectorAll('[data-level-id]').forEach(syncRangeInputs);
            coverageChart?.tooltip.hide(0);
        });

        function clearExpectationErrors() {
            expectationsForm.querySelectorAll('.is-invalid, [aria-invalid]').forEach(function (input) {
                input.classList.remove('is-invalid');
                input.removeAttribute('aria-invalid');
            });
            expectationsForm.querySelectorAll('.invalid-feedback').forEach(function (message) {
                message.textContent = '';
            });
        }

        function updateExpectationInputs() {
            Object.values(concernInputs).forEach(function (input) {
                input.disabled = mappingScaleLevels.length === 0;
            });
            metricSections.forEach(function (section) {
                const key = section.dataset.coverageMetric;
                const checkbox = document.getElementById(`gap-coverage-${key}-enabled`);
                checkbox.disabled = (!concernInputs.min.checked && !concernInputs.max.checked) || mappingScaleLevels.length === 0;
                const enabled = checkbox.checked && !checkbox.disabled;
                checkbox.setAttribute('aria-expanded', String(enabled));
                document.getElementById(`gap-coverage-${key}-options`).classList.toggle('d-none', !enabled);
                section.querySelectorAll('[data-bound], [data-slider]').forEach(function (input) {
                    input.disabled = !enabled || !concernInputs[input.dataset.bound ?? input.dataset.slider].checked;
                });
                section.querySelectorAll('[data-level-id]').forEach(syncRangeInputs);
            });
            if (validationStarted) validateExpectations();
            else clearExpectationErrors();
        }

        $('#gap-coverage-review-gaps, #gap-coverage-review-redundancies, [data-coverage-metric] input[type="checkbox"]').on('change', updateExpectationInputs);
        function validateExpectations() {
            clearExpectationErrors();
            const draft = { concerns: { gaps: concernInputs.min.checked, redundancies: concernInputs.max.checked }, metrics: {} };
            metricSections.forEach(function (section) {
                const key = section.dataset.coverageMetric;
                draft.metrics[key] = {
                    enabled: document.getElementById(`gap-coverage-${key}-enabled`).checked,
                    levels: Object.fromEntries([...section.querySelectorAll('[data-level-id]')].map(function (fields) {
                        return [fields.dataset.levelId, {
                            min: fields.querySelector('[data-bound="min"]').value,
                            max: fields.querySelector('[data-bound="max"]').value,
                        }];
                    })),
                };
            });
            const { settings, errors } = normalizeExpectations(draft, mappingScaleLevels);
            Object.entries(errors).forEach(function ([key, message]) {
                const id = `gap-coverage-${key.replaceAll('.', '-')}`;
                const input = document.getElementById(id);
                input.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
                document.getElementById(`${id}-slider`)?.setAttribute('aria-invalid', 'true');
                document.getElementById(`${id}-error`).textContent = message;
            });
            return settings;
        }

        expectationsForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (gapCoverageData === null || gapCoverageData.coverage.length === 0) return;
            validationStarted = true;
            const settings = validateExpectations();
            if (!settings) {
                expectationsForm.querySelector('.is-invalid').focus();
                return;
            }

            appliedExpectations = settings;
            reportMetric.value = Object.keys(settings.metrics)[0] ?? 'mapped_clo_count';
            renderExpectationsSummary();
            showReportStep(true);
        });

        function renderExpectationsSummary() {
            const summary = document.getElementById('gap-coverage-expectations-summary');
            summary.replaceChildren();
            if (!appliedExpectations || Object.keys(appliedExpectations.metrics).length === 0) {
                const message = document.createElement('p');
                message.textContent = 'Showing statistics only. No coverage expectations have been applied.';
                summary.appendChild(message);
                return;
            }

            const heading = document.createElement('h6');
            heading.textContent = 'Applied expectations';
            const concern = document.createElement('p');
            const selectedConcerns = [];
            if (appliedExpectations.concerns.gaps) selectedConcerns.push('Potential gaps');
            if (appliedExpectations.concerns.redundancies) selectedConcerns.push('Potential redundancies');
            concern.textContent = `Selected concerns: ${selectedConcerns.join(' and ')}.`;
            const scope = document.createElement('p');
            scope.textContent = 'These bounds apply separately to every PLO. Only applied bounds are listed.';
            const metrics = document.createElement('dl');
            metricSections.forEach(function (section) {
                const metric = appliedExpectations.metrics[section.dataset.coverageMetric];
                if (!metric) return;
                const name = document.createElement('dt');
                name.textContent = section.dataset.metricLabel;
                const details = document.createElement('dd');
                details.classList.add('mb-3');
                const description = document.createElement('p');
                description.classList.add('small', 'text-muted', 'mb-1');
                description.textContent = document.getElementById(`gap-coverage-${section.dataset.coverageMetric}-description`).textContent;
                const levels = document.createElement('ul');
                levels.classList.add('mb-0');
                mappingScaleLevels.forEach(function (level) {
                    const bounds = metric.levels[level.map_scale_id];
                    if (!bounds) return;
                    const label = level.title + (level.abbreviation ? ` (${level.abbreviation})` : '');
                    const item = document.createElement('li');
                    const range = [];
                    if (bounds.min !== null) range.push(`minimum ${bounds.min}%`);
                    if (bounds.max !== null) range.push(`maximum ${bounds.max}%`);
                    item.textContent = `${label}: ${range.join(', ')}.`;
                    levels.appendChild(item);
                });
                details.append(description, levels);
                metrics.append(name, details);
            });
            summary.append(heading, concern, scope, metrics);
        }

        [reportMetric, reportFilter, reportUnits].forEach(function (control) {
            control.addEventListener('change', function () {
                if (gapCoverageData !== null) renderGapCoverage(gapCoverageData);
            });
        });

        $('#nav-gap-coverage-tab').on('shown.bs.tab', function () {
            loadGapCoverage();
            expectationsForm.querySelectorAll('[data-level-id]').forEach(syncRangeInputs);
            coverageChart?.reflow();
        }).on('hide.bs.tab', function () {
            coverageChart?.tooltip.hide(0);
        });
        $('#gap-coverage-retry').on('click', loadGapCoverage);
        $('#gap-coverage-view-report').on('click', function () {
            if (gapCoverageData !== null && gapCoverageData.coverage.length > 0) {
                expectationsForm.reset();
                validationStarted = false;
                updateExpectationInputs();
                appliedExpectations = null;
                reportMetric.value = 'mapped_clo_count';
                renderExpectationsSummary();
                showReportStep(true);
            }
        });
        $('#gap-coverage-back').on('click', function () {
            showReportStep(false);
        });
        if ($('#nav-gap-coverage-tab').hasClass('active')) loadGapCoverage();

        function showReportStep(showReport) {
            $('#gap-coverage-expectations').toggleClass('d-none', showReport);
            $('#gap-coverage-report').toggleClass('d-none', !showReport);
            if (!showReport) {
                expectationsForm.querySelectorAll('[data-level-id]').forEach(syncRangeInputs);
                coverageChart?.tooltip.hide(0);
            }
            if (showReport) renderGapCoverage(gapCoverageData);
            $('#gap-coverage-expectations-step').toggleClass('fw-bold', !showReport)
                .attr('aria-current', showReport ? null : 'step');
            $('#gap-coverage-report-step').toggleClass('fw-bold', showReport)
                .attr('aria-current', showReport ? 'step' : null);
            document.getElementById(showReport ? 'gap-coverage-report-heading' : 'gap-coverage-expectations-heading').focus();
        }

        function loadGapCoverage() {
            if (gapCoverageData !== null || gapCoverageLoading) {
                return;
            }

            gapCoverageLoading = true;
            const retryHadFocus = document.activeElement === document.getElementById('gap-coverage-retry');
            $('#gap-coverage-error').addClass('d-none');
            $('#gap-coverage-loading').removeClass('d-none');
            if (retryHadFocus) {
                document.getElementById('gap-coverage-expectations-heading').focus();
            }

            $.ajax({
                type: 'GET',
                url: @json(route('programWizard.gapCoverage', $program->program_id)),
                dataType: 'json',
                success: function (data) {
                    gapCoverageData = data;
                    mappingScaleLevels = (data.coverage[0]?.mapping_scale_histogram ?? [])
                        .filter(level => Number(level.map_scale_id) !== 0);
                    initializeLevelInputs();
                    renderGapCoverage(data);
                    $('#gap-coverage-expectations-fields').prop('disabled', data.coverage.length === 0);
                },
                error: function () {
                    $('#gap-coverage-error').removeClass('d-none');
                },
                complete: function () {
                    gapCoverageLoading = false;
                    $('#gap-coverage-loading').addClass('d-none');
                }
            });
        }

        function renderGapCoverage(data) {
            const rows = document.getElementById('gap-coverage-rows');
            rows.replaceChildren();

            $('#gap-coverage-incomplete').toggleClass(
                'd-none',
                data.coverage.length === 0 || !data.mapping_completeness.has_incomplete_mappings
            );

            if (data.coverage.length === 0) {
                $('#gap-coverage-empty').removeClass('d-none');
                $('#gap-coverage-results').addClass('d-none');
                return;
            }

            const metric = reportMetric.value;
            document.getElementById('gap-coverage-metric-description').textContent =
                document.getElementById(`gap-coverage-${metric}-description`).textContent;
            reportMetric.disabled = mappingScaleLevels.length === 0;
            reportUnits.disabled = mappingScaleLevels.length === 0;
            $('#gap-coverage-report-no-levels').toggleClass('d-none', mappingScaleLevels.length > 0);
            renderComparisonColumns(metric);
            const report = evaluateCoverage(data, appliedExpectations);
            renderConcernSummary(report);
            const visiblePlos = [];

            data.coverage.forEach(function (coverage, index) {
                const plo = report.plos[index];
                if (reportFilter.value === 'concerns' && !plo.has_gap && !plo.has_redundancy) return;
                const row = document.createElement('tr');
                const outcomeCell = document.createElement('th');
                outcomeCell.scope = 'row';
                outcomeCell.classList.add('fw-normal');
                const outcomeName = document.createElement('strong');

                outcomeName.textContent = coverage.plo_shortphrase || `PLO #${index + 1}`;
                outcomeCell.appendChild(outcomeName);

                if (coverage.pl_outcome) {
                    outcomeCell.appendChild(document.createElement('br'));
                    outcomeCell.appendChild(document.createTextNode(coverage.pl_outcome));
                }

                row.appendChild(outcomeCell);
                const comparisons = plo.comparisons.filter(comparison => comparison.metric === metric);
                visiblePlos.push({ coverage, label: outcomeName.textContent, comparisons, allComparisons: plo.comparisons });
                comparisons.forEach(comparison => row.appendChild(createComparisonCell(comparison)));
                row.appendChild(createDetailsButtonCell(coverage, outcomeName.textContent, plo.comparisons));
                rows.appendChild(row);
            });

            if (rows.children.length === 0) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = mappingScaleLevels.length + 2;
                cell.textContent = 'No PLOs match the concern filter. Select All PLOs to view the statistics.';
                row.appendChild(cell);
                rows.appendChild(row);
            }

            $('#gap-coverage-empty').addClass('d-none');
            $('#gap-coverage-results').removeClass('d-none');
            if (!document.getElementById('gap-coverage-report').classList.contains('d-none')) {
                renderCoverageChart(visiblePlos);
            }
        }

        function renderCoverageChart(plos) {
            coverageChart?.destroy();
            coverageChart = null;
            const container = document.getElementById('gap-coverage-chart');
            const message = document.getElementById('gap-coverage-chart-message');
            let unavailable = '';
            if (mappingScaleLevels.length === 0) unavailable = 'No mapping levels are available to chart.';
            else if (plos.length === 0) unavailable = 'No PLOs match the concern filter. Select All PLOs to view the chart.';
            else if (plos.every(plo => plo.comparisons.every(comparison => comparison.percentage === null))) {
                unavailable = 'No data is available to chart for this metric. See the table below for details.';
            } else if (!window.Highcharts) unavailable = 'The chart could not be loaded. The comparison table remains available below.';
            container.classList.toggle('d-none', Boolean(unavailable));
            message.classList.toggle('d-none', !unavailable || mappingScaleLevels.length === 0);
            message.textContent = unavailable;
            if (unavailable) return;

            const counts = reportUnits.value === 'counts';
            const metricLabel = reportMetric.selectedOptions[0].textContent;
            coverageChart = Highcharts.chart(container, {
                chart: {
                    type: 'column',
                    animation: false,
                    scrollablePlotArea: { minWidth: Math.max(360, plos.length * Math.max(100, mappingScaleLevels.length * 24)) },
                },
                title: { text: metricLabel + (counts ? ' — counts' : ' — percentages') },
                xAxis: {
                    title: { text: 'Program Learning Outcomes' },
                    categories: plos.map(plo => escapeChartText(plo.label)),
                    labels: { style: { textOverflow: 'ellipsis', width: '110px' } },
                },
                yAxis: {
                    min: 0,
                    max: counts ? undefined : 100,
                    allowDecimals: !counts,
                    title: { text: counts ? (reportMetric.value === 'mapped_clo_count' ? 'Number of CLOs' : 'Number of courses') : 'Coverage (%)' },
                },
                legend: {
                    itemStyle: { cursor: 'default' },
                    events: { itemClick: function () { return false; } },
                },
                plotOptions: {
                    series: {
                        animation: false,
                        cursor: 'pointer',
                        point: {
                            events: {
                                click: function () {
                                    const plo = plos[this.index];
                                    openPloDetails(plo.coverage, plo.label, plo.allComparisons);
                                },
                            },
                        },
                    },
                },
                // The comparison table provides the accessible values and findings.
                accessibility: { enabled: false },
                exporting: { enabled: false },
                credits: { enabled: false },
                tooltip: {
                    animation: false,
                    outside: true,
                    style: { width: '260px', whiteSpace: 'normal' },
                    formatter: function () {
                        const point = this.point || this;
                        const { comparison, label, scaleLabel } = point.options.custom;
                        const targets = [];
                        if (comparison.min !== null) targets.push(`Minimum ${comparison.min}%`);
                        if (comparison.max !== null) targets.push(`Maximum ${comparison.max}%`);
                        const unit = comparison.metric === 'mapped_clo_count' ? 'CLOs in program courses' : 'program courses';
                        return `<b>${escapeChartText(label)}</b><br>${escapeChartText(scaleLabel)}<br>`
                            + `${comparison.count} of ${comparison.denominator} ${unit} (${formatCoveragePercentage(comparison)})<br>`
                            + (targets.length ? targets.join(' · ') + '<br>' : '')
                            + comparisonFinding(comparison);
                    },
                },
                series: mappingScaleLevels.map((scale, index) => {
                    const scaleLabel = scale.title + (scale.abbreviation ? ` (${scale.abbreviation})` : '');
                    return {
                        name: escapeChartText(scaleLabel),
                        color: scale.colour,
                        data: plos.map(plo => {
                            const comparison = plo.comparisons[index];
                            return {
                                y: comparison.percentage === null ? null : counts ? comparison.count : comparison.percentage,
                                custom: { comparison, label: plo.label, scaleLabel },
                            };
                        }),
                    };
                }),
            });
        }

        function escapeChartText(value) {
            const text = document.createElement('span');
            text.textContent = value;
            return text.innerHTML;
        }

        function renderConcernSummary(report) {
            const summary = report.summary;
            const hasExpectations = appliedExpectations !== null && Object.keys(appliedExpectations.metrics).length > 0;
            const canCompare = summary.evaluated_plo_count > 0;
            reportFilter.disabled = !canCompare;
            if (!canCompare) reportFilter.value = 'all';
            $('#gap-coverage-concern-scope').toggleClass('d-none', !canCompare);

            let message;
            if (!hasExpectations) {
                message = 'No concern checks applied. All PLO statistics are available.';
            } else if (!canCompare) {
                message = 'Your expectations cannot be evaluated with the available data. No concern checks could be completed.';
            } else {
                message = `PLOs with any concern: ${summary.concern_plo_count} of ${report.plos.length}. `
                    + `With potential gaps: ${summary.gap_plo_count}. With potential redundancies: ${summary.redundancy_plo_count}.`;
                if (summary.concern_plo_count === 0) {
                    message = 'No potential concerns found against your applied expectations. ' + message;
                }
                if (summary.evaluated_plo_count < report.plos.length) {
                    message += ` Comparisons were available for ${summary.evaluated_plo_count} of ${report.plos.length} PLOs.`;
                }
            }
            document.getElementById('gap-coverage-concern-summary').textContent = message;
        }

        function renderComparisonColumns(metric) {
            const columns = document.getElementById('gap-coverage-columns');
            columns.replaceChildren();
            const ploHeading = document.createElement('th');
            ploHeading.scope = 'col';
            ploHeading.textContent = 'Program Learning Outcome';
            columns.appendChild(ploHeading);
            mappingScaleLevels.forEach(function (scale) {
                const heading = document.createElement('th');
                heading.scope = 'col';
                const swatch = document.createElement('span');
                swatch.classList.add('d-inline-block', 'me-2');
                swatch.style.backgroundColor = scale.colour;
                swatch.style.height = '10px';
                swatch.style.width = '10px';
                swatch.setAttribute('aria-hidden', 'true');

                heading.append(swatch, document.createTextNode(scale.title + (scale.abbreviation ? ` (${scale.abbreviation})` : '')));
                const target = document.createElement('small');
                target.classList.add('d-block', 'fw-normal', 'mt-1');
                const bounds = appliedExpectations?.metrics[metric]?.levels[scale.map_scale_id];
                const labels = [];
                if (bounds?.min != null) labels.push(`Minimum ${bounds.min}%`);
                if (bounds?.max != null) labels.push(`Maximum ${bounds.max}%`);
                target.textContent = labels.length ? labels.join(' · ') : 'No expectation set';
                heading.appendChild(target);
                columns.appendChild(heading);
            });
            const actions = document.createElement('th');
            actions.scope = 'col';
            actions.textContent = 'Details';
            columns.appendChild(actions);
        }

        function createComparisonCell(comparison) {
            const cell = document.createElement('td');
            const value = document.createElement('strong');
            const basis = document.createElement('small');
            basis.classList.add('d-block');
            const unit = comparison.metric === 'mapped_clo_count' ? 'CLOs in program courses' : 'program courses';
            if (comparison.percentage === null) {
                value.textContent = 'Not applicable';
                basis.textContent = `No ${unit}.`;
                cell.append(value, basis);
                return cell;
            }

            const counts = reportUnits.value === 'counts';
            value.textContent = counts ? String(comparison.count) : formatCoveragePercentage(comparison);
            basis.textContent = `${comparison.count} of ${comparison.denominator} ${unit}`
                + (counts ? ` (${formatCoveragePercentage(comparison)})` : '');
            const finding = document.createElement('span');
            finding.classList.add('d-block', 'small', 'mt-1');
            if (comparison.status === 'gap') {
                cell.classList.add('table-warning');
            } else if (comparison.status === 'redundancy') {
                cell.classList.add('table-info');
            }
            finding.textContent = comparisonFinding(comparison);
            cell.append(value, basis, finding);
            return cell;
        }

        function formatCoveragePercentage(comparison) {
            let percentage = Number(comparison.percentage.toFixed(2));
            // Keep extra precision when rounding would make a flagged value look in range.
            if ((comparison.status === 'gap' && percentage >= comparison.min)
                || (comparison.status === 'redundancy' && percentage <= comparison.max)) {
                percentage = comparison.percentage;
            }
            return `${percentage}%`;
        }

        function comparisonFinding(comparison) {
            if (comparison.status === 'not_applicable') return 'Not applicable';
            if (comparison.status === 'gap') return `Potential gap — below minimum ${comparison.min}%`;
            if (comparison.status === 'redundancy') return `Potential redundancy — above maximum ${comparison.max}%`;
            return comparison.status === 'within_expectations' ? 'Within expectations' : 'No expectation set';
        }

        function createDetailsButtonCell(coverage, label, comparisons) {
            const cell = document.createElement('td');
            const button = document.createElement('button');

            button.type = 'button';
            button.id = `gap-coverage-details-button-${coverage.pl_outcome_id}`;
            button.classList.add('btn', 'btn-sm', 'btn-outline-primary', 'text-nowrap');
            button.textContent = 'View details';
            button.setAttribute('aria-controls', 'gap-coverage-details-modal');
            button.setAttribute('aria-haspopup', 'dialog');
            button.addEventListener('click', function () {
                openPloDetails(coverage, label, comparisons);
            });

            cell.appendChild(button);

            return cell;
        }

        function openPloDetails(coverage, label, comparisons) {
            coverageChart?.tooltip.hide(0);
            document.getElementById('gap-coverage-details-title').textContent = `Details — ${label}`;
            document.getElementById('gap-coverage-details-content').replaceChildren(createPloDetailsContent(coverage, comparisons));
            const modal = document.getElementById('gap-coverage-details-modal');
            const button = document.getElementById(`gap-coverage-details-button-${coverage.pl_outcome_id}`);
            // Chart entry also returns focus to Details without scrolling away from the chart.
            modal.addEventListener('hidden.bs.modal', () => button.focus({ preventScroll: true }), { once: true });
            bootstrap.Modal.getOrCreateInstance(modal).show(button);
        }

        function createPloDetailsContent(coverage, comparisons) {
            const content = document.createDocumentFragment();
            const description = document.createElement('p');
            description.textContent = coverage.pl_outcome;
            content.appendChild(description);

            if (gapCoverageData.mapping_completeness.has_incomplete_mappings) {
                const warning = document.createElement('p');
                warning.classList.add('alert', 'alert-warning', 'small');
                warning.textContent = 'Program mappings are incomplete. Potential gap and redundancy findings are provisional.';
                content.appendChild(warning);
            }

            const findingsHeading = document.createElement('h6');
            findingsHeading.textContent = 'Potential concerns across all applied metrics';
            content.appendChild(findingsHeading);
            const findings = comparisons.filter(comparison => ['gap', 'redundancy'].includes(comparison.status));
            if (findings.length) {
                const list = document.createElement('ul');
                list.id = 'gap-coverage-details-findings';
                metricSections.forEach(function (section) {
                    findings.filter(comparison => comparison.metric === section.dataset.coverageMetric).forEach(function (comparison) {
                        const scale = mappingScaleLevels.find(level => level.map_scale_id === comparison.map_scale_id);
                        const item = document.createElement('li');
                        item.textContent = `${section.dataset.metricLabel} — ${scale.title}: `
                            + `${comparison.count} of ${comparison.denominator} (${formatCoveragePercentage(comparison)}). `
                            + comparisonFinding(comparison);
                        list.appendChild(item);
                    });
                });
                content.appendChild(list);
            } else {
                const message = document.createElement('p');
                const hasExpectations = appliedExpectations !== null && Object.keys(appliedExpectations.metrics).length > 0;
                message.textContent = !hasExpectations
                    ? 'No concern checks applied. All PLO statistics are available.'
                    : comparisons.some(comparison => comparison.status === 'within_expectations')
                        ? 'No potential concerns found against your applied expectations.'
                        : 'Your expectations cannot be evaluated with the available data. No concern checks could be completed.';
                content.appendChild(message);
            }

            const label = document.createElement('label');
            label.htmlFor = 'gap-coverage-details-metric';
            label.classList.add('form-label', 'fw-bold');
            label.textContent = 'Coverage comparisons';
            const metric = document.createElement('select');
            metric.id = label.htmlFor;
            metric.classList.add('form-select', 'w-auto', 'mw-100', 'mb-2');
            metric.replaceChildren(...[...reportMetric.options].map(option => option.cloneNode(true)));
            metric.value = reportMetric.value;
            metric.disabled = mappingScaleLevels.length === 0;
            metric.setAttribute('aria-describedby', 'gap-coverage-details-metric-description');
            const metricDescription = document.createElement('p');
            metricDescription.id = 'gap-coverage-details-metric-description';
            metricDescription.classList.add('small', 'text-muted');
            const region = document.createElement('div');
            region.classList.add('table-responsive', 'mb-4');
            region.setAttribute('role', 'region');
            region.setAttribute('aria-label', 'PLO coverage comparisons');
            region.tabIndex = 0;

            const renderComparisons = function () {
                metricDescription.textContent = document.getElementById(`gap-coverage-${metric.value}-description`).textContent;
                region.replaceChildren(createPloComparisonTable(comparisons.filter(comparison => comparison.metric === metric.value)));
            };
            metric.addEventListener('change', renderComparisons);
            renderComparisons();
            content.append(label, metric, metricDescription, region);

            const totalsHeading = document.createElement('h6');
            totalsHeading.textContent = 'Overall PLO totals';
            content.append(totalsHeading, createCoverageSummary(coverage));
            const evidenceHeading = document.createElement('h6');
            evidenceHeading.textContent = 'Contributing courses and CLOs';
            content.append(evidenceHeading, createCourseEvidence(coverage.courses));
            return content;
        }

        function createPloComparisonTable(comparisons) {
            const table = document.createElement('table');
            table.id = 'gap-coverage-details-comparisons';
            table.classList.add('table', 'table-bordered', 'align-middle');
            const caption = table.createCaption();
            caption.classList.add('visually-hidden');
            caption.textContent = 'Actual coverage and applied expectations for this PLO';
            const header = table.createTHead().insertRow();
            header.classList.add('table-primary');
            ['Mapping level', 'Actual coverage', 'Minimum', 'Maximum', 'Result'].forEach(function (text) {
                const cell = document.createElement('th');
                cell.scope = 'col';
                cell.textContent = text;
                header.appendChild(cell);
            });
            const body = table.createTBody();
            comparisons.forEach(function (comparison) {
                const row = body.insertRow();
                const scale = mappingScaleLevels.find(level => level.map_scale_id === comparison.map_scale_id);
                const level = document.createElement('th');
                level.scope = 'row';
                level.textContent = scale.title + (scale.abbreviation ? ` (${scale.abbreviation})` : '');
                row.appendChild(level);
                const unit = comparison.metric === 'mapped_clo_count' ? 'CLOs in program courses' : 'program courses';
                const actual = row.insertCell();
                actual.textContent = comparison.percentage === null
                    ? `Not applicable — no ${unit}.`
                    : `${comparison.count} of ${comparison.denominator} ${unit} (${formatCoveragePercentage(comparison)})`;
                row.insertCell().textContent = comparison.min === null ? 'Not set' : `${comparison.min}%`;
                row.insertCell().textContent = comparison.max === null ? 'Not set' : `${comparison.max}%`;
                const result = row.insertCell();
                result.textContent = comparisonFinding(comparison);
                if (comparison.status === 'gap') result.classList.add('table-warning');
                else if (comparison.status === 'redundancy') result.classList.add('table-info');
            });
            if (comparisons.length === 0) {
                const cell = body.insertRow().insertCell();
                cell.colSpan = 5;
                cell.textContent = 'No non-N/A mapping levels are configured. Overall statistics remain available below.';
            }
            return table;
        }

        function createCoverageSummary(coverage) {
            const content = document.createDocumentFragment();
            const summary = document.createElement('p');

            summary.classList.add('mb-3');
            summary.textContent = `${coverage.mapped_clo_count} CLOs across ${coverage.covering_course_count} courses: ${coverage.required_course_count} required, ${coverage.non_required_course_count} non-required, and ${coverage.n_a_clo_count} N/A CLOs.`;
            content.appendChild(summary);
            if (coverage.multi_level_mapping_count > 0) {
                const multiLevel = document.createElement('p');
                multiLevel.classList.add('small');
                multiLevel.textContent = `${coverage.multi_level_mapping_count} CLO mapping(s) use multiple levels. Overall counts count each CLO or course once.`;
                content.appendChild(multiLevel);
            }

            return content;
        }

        function createCourseEvidence(courses) {
            const content = document.createDocumentFragment();
            if (courses.length === 0) {
                content.appendChild(document.createTextNode('No courses currently provide coverage for this PLO.'));
                return content;
            }

            const orderedCourses = [...courses].sort((a, b) =>
                String(a.course_num).localeCompare(String(b.course_num), undefined, { numeric: true })
                || a.course_code.localeCompare(b.course_code));
            orderedCourses.forEach(function (course, index) {
                const courseSection = document.createElement('div');
                const courseName = document.createElement('a');
                const courseType = document.createElement('span');
                const outcomes = document.createElement('ul');

                courseSection.classList.add('py-2');
                if (index < courses.length - 1) {
                    courseSection.classList.add('border-bottom');
                }

                courseName.textContent = `${course.course_code} ${course.course_num}: ${course.course_title}`;
                courseName.classList.add('fw-bold');
                courseName.href = @json(route('courseWizard.step7', ['course' => '__COURSE_ID__']))
                    .replace('__COURSE_ID__', encodeURIComponent(course.course_id));
                courseName.target = '_blank';
                courseName.rel = 'noopener';
                courseName.setAttribute('aria-label', `${courseName.textContent} (opens in a new tab)`);
                courseType.classList.add('badge', 'ms-2');
                courseType.classList.add(course.course_required ? 'bg-primary' : 'bg-secondary');
                courseType.textContent = course.course_required === null
                    ? 'Required status unspecified'
                    : (course.course_required ? 'Required' : 'Non-Required');
                outcomes.classList.add('mb-0', 'mt-2');

                // A CLO can have one row per mapping level.
                const groupedOutcomes = new Map();
                course.learning_outcomes.forEach(function (outcome) {
                    if (!groupedOutcomes.has(outcome.l_outcome_id)) {
                        groupedOutcomes.set(outcome.l_outcome_id, { outcome, scaleIds: new Set() });
                    }
                    groupedOutcomes.get(outcome.l_outcome_id).scaleIds.add(outcome.map_scale_id);
                });

                // Place multi-level CLOs at their first configured level, showing each CLO once.
                const firstLevel = ({ scaleIds }) => {
                    const index = mappingScaleLevels.findIndex(scale => scaleIds.has(scale.map_scale_id));
                    return index === -1 ? mappingScaleLevels.length : index;
                };
                const orderedOutcomes = [...groupedOutcomes.values()].sort((a, b) => firstLevel(a) - firstLevel(b));
                orderedOutcomes.forEach(function ({ outcome, scaleIds }) {
                    const item = document.createElement('li');

                    if (outcome.clo_shortphrase) {
                        const outcomeName = document.createElement('strong');
                        outcomeName.textContent = outcome.clo_shortphrase;
                        item.appendChild(outcomeName);
                        item.appendChild(document.createTextNode(`: ${outcome.l_outcome}`));
                    } else {
                        item.appendChild(document.createTextNode(outcome.l_outcome));
                    }

                    const levels = document.createElement('small');
                    levels.classList.add('d-block', 'text-muted');
                    levels.textContent = 'Mapping levels: ' + mappingScaleLevels
                        .filter(scale => scaleIds.has(scale.map_scale_id))
                        .map(scale => scale.title + (scale.abbreviation ? ` (${scale.abbreviation})` : ''))
                        .join(', ');
                    item.appendChild(levels);
                    outcomes.appendChild(item);
                });

                courseSection.appendChild(courseName);
                courseSection.appendChild(courseType);
                courseSection.appendChild(outcomes);
                content.appendChild(courseSection);
            });

            return content;
        }
    });
</script>
