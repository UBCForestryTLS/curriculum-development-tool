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
                <p class="small text-muted">Your selected ranges will appear alongside the statistics. Highlighting based on these selections is not available yet.</p>
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

        <div id="gap-coverage-results" class="table-responsive position-relative d-none">
            <table class="table table-bordered align-middle">
                <thead class="table-primary">
                    <tr>
                        <th class="text-start">Program Learning Outcome</th>
                        <th>CLOs</th>
                        <th>Courses</th>
                        <th>Required Courses</th>
                        <th>Non-Required Courses</th>
                        <th>N/A CLOs</th>
                        <th class="text-start">Mapping Scales</th>
                        <th><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="gap-coverage-rows"></tbody>
            </table>
        </div>
    </section>
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

    $(document).ready(function () {
        let gapCoverageData = null;
        let gapCoverageLoading = false;
        let appliedExpectations = null;
        let mappingScaleLevels = [];
        let validationStarted = false;
        const expectationsForm = document.getElementById('gap-coverage-expectations-form');
        const metricSections = [...expectationsForm.querySelectorAll('[data-coverage-metric]')];
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
                input.parentElement.querySelector('[data-bound-status]').textContent = input.disabled ? 'Not selected' : value === '' ? 'No bound set' : '';
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
            heading.textContent = 'Your expectations — percentage ranges for each PLO';
            const concern = document.createElement('p');
            const selectedConcerns = [];
            if (appliedExpectations.concerns.gaps) selectedConcerns.push('Potential gaps');
            if (appliedExpectations.concerns.redundancies) selectedConcerns.push('Potential redundancies');
            concern.textContent = `Selected concerns: ${selectedConcerns.join(' and ')}.`;
            const list = document.createElement('ul');
            metricSections.forEach(function (section) {
                const metric = appliedExpectations.metrics[section.dataset.coverageMetric];
                if (!metric) return;
                mappingScaleLevels.forEach(function (level) {
                    const bounds = metric.levels[level.map_scale_id];
                    if (!bounds) return;
                    const label = level.title + (level.abbreviation ? ` (${level.abbreviation})` : '');
                    const item = document.createElement('li');
                    const range = [];
                    if (bounds.min !== null) range.push(`minimum ${bounds.min}%`);
                    if (bounds.max !== null) range.push(`maximum ${bounds.max}%`);
                    item.textContent = `${section.dataset.metricLabel}: ${range.join(', ')} (${label}).`;
                    list.appendChild(item);
                });
            });
            const note = document.createElement('p');
            note.classList.add('small', 'text-muted');
            note.textContent = 'Expectations are shown for reference. Coverage statistics are not highlighted.';
            summary.append(heading, concern, list, note);
        }

        $('#nav-gap-coverage-tab').on('shown.bs.tab', function () {
            loadGapCoverage();
            expectationsForm.querySelectorAll('[data-level-id]').forEach(syncRangeInputs);
        });
        $('#gap-coverage-retry').on('click', loadGapCoverage);
        $('#gap-coverage-view-report').on('click', function () {
            if (gapCoverageData !== null && gapCoverageData.coverage.length > 0) {
                expectationsForm.reset();
                validationStarted = false;
                updateExpectationInputs();
                appliedExpectations = null;
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
            if (!showReport) expectationsForm.querySelectorAll('[data-level-id]').forEach(syncRangeInputs);
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
                    renderGapCoverage(data);
                    gapCoverageData = data;
                    mappingScaleLevels = (data.coverage[0]?.mapping_scale_histogram ?? [])
                        .filter(level => Number(level.map_scale_id) !== 0);
                    initializeLevelInputs();
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

            data.coverage.forEach(function (coverage, index) {
                const row = document.createElement('tr');
                const outcomeCell = document.createElement('td');
                const outcomeName = document.createElement('strong');

                outcomeName.textContent = coverage.plo_shortphrase || `PLO #${index + 1}`;
                outcomeCell.appendChild(outcomeName);

                if (coverage.pl_outcome) {
                    outcomeCell.appendChild(document.createElement('br'));
                    outcomeCell.appendChild(document.createTextNode(coverage.pl_outcome));
                }

                row.appendChild(outcomeCell);
                appendCountCell(row, coverage.mapped_clo_count);
                appendCountCell(row, coverage.covering_course_count);
                appendCountCell(row, coverage.required_course_count);
                appendCountCell(row, coverage.non_required_course_count);
                appendCountCell(row, coverage.n_a_clo_count);
                row.appendChild(createScaleCell(
                    coverage.mapping_scale_histogram,
                    coverage.multi_level_mapping_count
                ));
                row.appendChild(createDetailsButtonCell(coverage));
                rows.appendChild(row);
                rows.appendChild(createDetailsRow(coverage));
            });

            $('#gap-coverage-empty').addClass('d-none');
            $('#gap-coverage-results').removeClass('d-none');
        }

        function appendCountCell(row, count) {
            const cell = document.createElement('td');
            cell.classList.add('text-center');
            cell.textContent = count;
            row.appendChild(cell);
        }

        function createScaleCell(scales, multiLevelMappingCount) {
            const cell = document.createElement('td');

            if (scales.length === 0) {
                cell.textContent = 'No mapping scale configured';
                return cell;
            }

            scales.forEach(function (scale, index) {
                if (index > 0) {
                    cell.appendChild(document.createElement('br'));
                }

                const swatch = document.createElement('span');
                swatch.classList.add('d-inline-block', 'me-2');
                swatch.style.backgroundColor = scale.colour;
                swatch.style.height = '10px';
                swatch.style.width = '10px';
                swatch.setAttribute('aria-hidden', 'true');

                cell.appendChild(swatch);
                cell.appendChild(document.createTextNode(
                    `${scale.abbreviation || scale.title}: ${scale.mapped_clo_count} CLOs / ${scale.covering_course_count} courses (${scale.required_course_count} required, ${scale.non_required_course_count} non-required)`
                ));
            });

            if (multiLevelMappingCount > 0) {
                const warning = document.createElement('small');
                warning.classList.add('d-block', 'mt-2', 'text-warning');
                warning.textContent = `${multiLevelMappingCount} CLO mapping(s) use multiple levels.`;
                cell.appendChild(warning);
            }

            return cell;
        }

        function createDetailsButtonCell(coverage) {
            const cell = document.createElement('td');
            const button = document.createElement('button');
            const detailsId = `gap-coverage-details-${coverage.pl_outcome_id}`;

            button.type = 'button';
            button.classList.add('btn', 'btn-sm', 'btn-outline-primary');
            button.textContent = 'View details';
            button.setAttribute('aria-controls', detailsId);
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', function () {
                const detailsRow = document.getElementById(detailsId);
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                detailsRow.classList.toggle('d-none', isExpanded);
                button.setAttribute('aria-expanded', String(!isExpanded));
                button.textContent = isExpanded ? 'View details' : 'Hide details';
            });

            cell.appendChild(button);

            return cell;
        }

        function createDetailsRow(coverage) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            const summary = document.createElement('p');

            row.id = `gap-coverage-details-${coverage.pl_outcome_id}`;
            row.classList.add('d-none');
            cell.colSpan = 8;

            summary.classList.add('mb-3');
            summary.textContent = `${coverage.mapped_clo_count} CLOs across ${coverage.covering_course_count} courses: ${coverage.required_course_count} required, ${coverage.non_required_course_count} non-required, and ${coverage.n_a_clo_count} N/A CLOs.`;
            cell.appendChild(summary);

            if (coverage.courses.length === 0) {
                cell.appendChild(document.createTextNode('No courses currently provide coverage for this PLO.'));
                row.appendChild(cell);
                return row;
            }

            coverage.courses.forEach(function (course, index) {
                const courseSection = document.createElement('div');
                const courseName = document.createElement('strong');
                const courseType = document.createElement('span');
                const outcomes = document.createElement('ul');

                courseSection.classList.add('py-2');
                if (index < coverage.courses.length - 1) {
                    courseSection.classList.add('border-bottom');
                }

                courseName.textContent = `${course.course_code} ${course.course_num}: ${course.course_title}`;
                courseType.classList.add('badge', 'ms-2');
                courseType.classList.add(course.course_required ? 'bg-primary' : 'bg-secondary');
                courseType.textContent = course.course_required ? 'Required' : 'Non-Required';
                outcomes.classList.add('mb-0', 'mt-2');

                course.learning_outcomes.forEach(function (outcome) {
                    const item = document.createElement('li');
                    const scaleName = outcome.map_scale_abbreviation || outcome.map_scale_title;

                    if (outcome.clo_shortphrase) {
                        const outcomeName = document.createElement('strong');
                        outcomeName.textContent = outcome.clo_shortphrase;
                        item.appendChild(outcomeName);
                        item.appendChild(document.createTextNode(`: ${outcome.l_outcome}`));
                    } else {
                        item.appendChild(document.createTextNode(outcome.l_outcome));
                    }

                    item.appendChild(document.createTextNode(` - ${scaleName}`));
                    outcomes.appendChild(item);
                });

                courseSection.appendChild(courseName);
                courseSection.appendChild(courseType);
                courseSection.appendChild(outcomes);
                cell.appendChild(courseSection);
            });

            row.appendChild(cell);

            return row;
        }
    });
</script>
