<div class="py-4">
    <h4 id="progression-heading" tabindex="-1">Progression Report</h4>
    <p>Review the courses and course learning outcomes (CLOs) in this program. Suggested Bloom’s cognitive levels are based on matching reference verbs in each CLO, rather than interpreting its meaning. A CLO can match more than one level.</p>

    <div class="mb-3">
        <label for="progression-scope" class="form-label">Report scope</label>
        <select id="progression-scope" class="form-select" disabled>
            <option value="">Entire program</option>
        </select>
    </div>

    <div id="progression-loading" class="py-4 text-center d-none" role="status">
        <div class="spinner-border text-primary" aria-hidden="true"></div>
        <p class="mb-0 mt-2">Loading progression data…</p>
    </div>

    <div id="progression-error" class="alert alert-danger d-none" role="alert">
        <p>Progression data could not be loaded. Please try again.</p>
        <button id="progression-retry" type="button" class="btn btn-outline-danger">Retry</button>
    </div>

    <div id="progression-results" class="d-none" role="status" aria-atomic="true">
        <p id="progression-plo-description" class="text-break d-none"></p>
        <p id="progression-incomplete" class="alert alert-warning d-none">Program mappings are incomplete. Results for the selected PLO may change as mappings are completed.</p>
        <dl class="row mb-3">
            <div class="col-sm-6 col-lg-3">
                <dt id="progression-course-label">Program courses</dt>
                <dd id="progression-course-count" class="fs-4"></dd>
            </div>
            <div class="col-sm-6 col-lg-3">
                <dt>CLOs in scope</dt>
                <dd id="progression-clo-count" class="fs-4"></dd>
            </div>
            <div class="col-sm-6 col-lg-3">
                <dt>With a Bloom match</dt>
                <dd id="progression-matched-count" class="fs-4"></dd>
            </div>
            <div class="col-sm-6 col-lg-3">
                <dt>No Bloom match</dt>
                <dd id="progression-unmatched-count" class="fs-4"></dd>
            </div>
        </dl>
        <p class="small text-muted">No Bloom match means no matching cognitive level was found in the CLO wording; it does not mean the CLO is unmapped to a PLO.</p>
        <p class="mb-1"><button id="progression-review-details" type="button" class="btn btn-link p-0 text-start" aria-controls="progression-details-modal" aria-haspopup="dialog" disabled><strong>Review suggested:</strong> <span id="progression-review-count"></span> CLOs in scope</button></p>
        <p class="small text-muted">CLOs matching three or more cognitive levels are suggested for review. Broad matches may be valid; check whether the wording reflects the intended learning. All matches remain included.</p>
        <p id="progression-no-mappings" class="alert alert-info d-none">No CLOs in this program have applicable mappings to the selected PLO.</p>
        <p id="progression-no-courses" class="alert alert-info d-none">There are no courses in this program. Add courses to begin reviewing progression.</p>
        <p id="progression-no-clos" class="alert alert-info d-none">The courses in this program do not have any CLOs yet. Add course learning outcomes to begin reviewing progression.</p>
        <p id="progression-no-reference" class="alert alert-info d-none">The Bloom’s cognitive reference is unavailable. You can still review the courses and CLOs below.</p>
    </div>

    <section id="progression-chart-section" class="mb-4 d-none" aria-labelledby="progression-chart-heading">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <h5 id="progression-chart-heading" class="mb-0">Cognitive levels by course group</h5>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <label for="progression-view" class="mb-0">View</label>
                <select id="progression-view" class="form-select w-auto">
                    <option value="comparison">Comparison</option>
                    <option value="progression">Progression</option>
                </select>
                <label for="progression-units" class="mb-0">Show</label>
                <select id="progression-units" class="form-select w-auto">
                    <option value="percentages">Percentages</option>
                    <option value="counts">Counts</option>
                </select>
            </div>
        </div>
        <p id="progression-chart-description" class="small text-muted">Percentages use all CLOs in each course group and selected scope, including those with no Bloom match. A CLO can count at several levels, so percentages may total above 100%. Course numbers indicate course stage, not a student's actual sequence.</p>
        <p id="progression-trend-description" class="small text-muted d-none">Each line shows a Bloom level across course groups. Gaps indicate groups with no CLOs. Other/unknown courses appear as separate points because their course stage is unknown.</p>
        <p id="progression-chart-unavailable" class="alert alert-info d-none">The chart could not be loaded. You can still review the exact values below.</p>
        <div id="progression-chart" aria-labelledby="progression-chart-heading" aria-describedby="progression-chart-description"></div>
    </section>

    <details id="progression-table-section" class="mb-4 d-none">
        <summary class="fw-bold mb-2">Exact values by course group</summary>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Course group values">
            <table id="progression-table" class="table table-bordered table-sm align-middle">
                <caption>Cells show CLO count (percentage of all CLOs in the group and selected scope). CLOs may match several levels. N/A means there are no CLOs to calculate a percentage, or the Bloom reference is unavailable.</caption>
                <thead><tr></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </details>

    <section id="progression-groups" class="d-none" aria-labelledby="progression-groups-heading">
        <h5 id="progression-groups-heading">Explore courses and CLOs</h5>
        <div id="progression-group-buttons" class="d-flex flex-wrap gap-2"></div>
    </section>
</div>

<div class="modal fade" id="progression-details-modal" tabindex="-1" aria-labelledby="progression-details-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="progression-details-title">Course group details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="progression-details-scope" class="text-break"></p>
                <div id="progression-details-filter-section" class="mb-3">
                    <label for="progression-details-filter" class="form-label">Show CLOs</label>
                    <select id="progression-details-filter" class="form-select" aria-describedby="progression-details-filter-help"></select>
                    <p id="progression-details-filter-help" class="small text-muted mt-1">No Bloom match means the CLO wording did not match any cognitive level.</p>
                </div>
                <p id="progression-details-counts" class="text-muted" role="status"></p>
                <p id="progression-details-incomplete" class="alert alert-warning d-none">Program mappings are incomplete. Results for the selected PLO may change as mappings are completed.</p>
                <p id="progression-details-no-reference" class="alert alert-info d-none">The Bloom’s cognitive reference is unavailable. Suggested levels cannot be shown.</p>
                <p id="progression-details-empty" class="alert alert-info d-none">No CLOs match this filter in the selected course group and report scope.</p>
                <div id="progression-course-list" class="text-break"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    #progression-course-list ul::before { content: none; }
</style>

<script type="module">
    $(document).ready(function () {
        let progressionData = null;
        let progressionLoading = false;
        let progressionChart = null;

        $('#nav-progression-tab').on('shown.bs.tab', function () {
            loadProgression();
            progressionChart?.reflow();
        });
        $('#progression-units, #progression-view').on('change', renderChart);
        $('#progression-retry').on('click', loadProgression);
        $('#progression-review-details').on('click', function () {
            openDetails(progressionData.courses, 'CLOs suggested for review', this, true);
        });
        $('#progression-scope').on('change', function () {
            progressionData = null;
            loadProgression();
        });
        if ($('#nav-progression-tab').hasClass('active')) loadProgression();

        function loadProgression() {
            if (progressionData !== null || progressionLoading) return;

            progressionLoading = true;
            const scope = $('#progression-scope').val();
            $('#progression-scope').prop('disabled', true);
            $('#progression-results, #progression-groups, #progression-chart-section, #progression-table-section').addClass('d-none');
            if (document.activeElement === document.getElementById('progression-retry')) {
                document.getElementById('progression-heading').focus();
            }
            $('#progression-error').addClass('d-none');
            $('#progression-loading').removeClass('d-none');

            $.ajax({
                type: 'GET',
                url: @json(route('programWizard.progression', $program->program_id)),
                dataType: 'json',
                data: scope ? { plo_id: scope } : {},
                success: function (data) {
                    progressionData = data;
                    const selector = document.getElementById('progression-scope');
                    selector.replaceChildren(new Option('Entire program', ''));
                    data.plos.forEach(function (plo, index) {
                        const label = plo.plo_shortphrase || `PLO #${index + 1}`;
                        selector.add(new Option(label, String(plo.pl_outcome_id)));
                    });
                    selector.value = scope;
                    const totals = data.scope_totals;
                    const selected = data.selected_plo !== null;
                    const matched = data.course_groups.reduce((sum, group) => sum + group.matched_clo_count, 0);
                    const unmatched = data.course_groups.reduce((sum, group) => sum + group.unmatched_clo_count, 0);
                    $('#progression-plo-description').text(data.selected_plo?.pl_outcome ?? '').toggleClass('d-none', !selected);
                    $('#progression-incomplete').toggleClass('d-none', !data.has_incomplete_mappings);
                    $('#progression-course-label').text(selected ? 'Contributing courses' : 'Program courses');
                    $('#progression-course-count').text(totals.course_count);
                    $('#progression-clo-count').text(totals.clo_count);
                    $('#progression-matched-count').text(data.bloom_reference_available ? matched : '—');
                    $('#progression-unmatched-count').text(data.bloom_reference_available ? unmatched : '—');
                    const reviewCount = new Set(data.courses.flatMap(course => course.clos)
                        .filter(needsReview).map(clo => clo.l_outcome_id)).size;
                    $('#progression-review-count').text(data.bloom_reference_available ? reviewCount : '—');
                    $('#progression-review-details').prop('disabled', !data.bloom_reference_available || reviewCount === 0);
                    $('#progression-no-mappings').toggleClass('d-none', !selected || totals.clo_count !== 0);
                    $('#progression-no-courses').toggleClass('d-none', selected || totals.course_count !== 0);
                    $('#progression-no-clos').toggleClass('d-none', selected || totals.course_count === 0 || totals.clo_count !== 0);
                    $('#progression-no-reference').toggleClass('d-none', data.bloom_reference_available || totals.clo_count === 0);
                    renderGroups(data);
                    $('#progression-results').removeClass('d-none');
                    renderTable(data);
                    renderChart();
                },
                error: function () {
                    $('#progression-error').removeClass('d-none');
                },
                complete: function () {
                    progressionLoading = false;
                    $('#progression-scope').prop('disabled', false);
                    $('#progression-loading').addClass('d-none');
                }
            });
        }

        function renderTable(data) {
            const section = $('#progression-table-section');
            const header = $('#progression-table thead tr').empty();
            const body = $('#progression-table tbody').empty();
            section.toggleClass('d-none', data.course_groups.length === 0);
            if (!window.Highcharts) section.prop('open', true);

            ['Course group', 'Courses', 'Total CLOs', ...data.bloom_levels.map(level => level.name), 'No Bloom match']
                .forEach(title => $('<th>', { scope: 'col' }).text(title).appendTo(header));

            const value = (count, percentage) => count === null ? 'N/A' : `${count} (${percentage === null ? 'N/A' : percentage + '%'})`;
            data.course_groups.forEach(group => {
                const row = $('<tr>').appendTo(body);
                $('<th>', { scope: 'row' }).text(group.course_level === 'other' ? 'Other/unknown' : `${group.course_level}-level`).appendTo(row);
                [group.course_count, group.clo_count].forEach(count => $('<td>').text(count).appendTo(row));
                data.bloom_levels.forEach(level => {
                    const result = group.levels.find(item => item.level_id === level.id);
                    $('<td>').text(value(result.clo_count, result.percentage)).appendTo(row);
                });
                const unmatchedPercentage = data.bloom_reference_available && group.clo_count > 0
                    ? Math.round(group.unmatched_clo_count / group.clo_count * 10000) / 100 : null;
                $('<td>').text(value(group.unmatched_clo_count, unmatchedPercentage)).appendTo(row);
            });
        }

        function renderChart() {
            progressionChart?.destroy();
            progressionChart = null;
            const data = progressionData;
            const available = data?.bloom_reference_available && data.scope_totals.clo_count > 0;
            $('#progression-chart-section').toggleClass('d-none', !available);
            if (!available) return;

            $('#progression-chart-unavailable').toggleClass('d-none', Boolean(window.Highcharts));
            $('#progression-chart').toggleClass('d-none', !window.Highcharts);
            $('#progression-units, #progression-view').prop('disabled', !window.Highcharts);
            if (!window.Highcharts) return;

            const counts = $('#progression-units').val() === 'counts';
            const trend = $('#progression-view').val() === 'progression';
            const groups = [...data.course_groups];
            // Leave a break before unknown course numbers so they are not part of the trend.
            const otherIndex = groups.findIndex(group => group.course_level === 'other');
            if (trend && otherIndex > 0) groups.splice(otherIndex, 0, null);
            $('#progression-trend-description').toggleClass('d-none', !trend);
            const label = group => !group ? '' : group.course_level === 'other' ? 'Other/unknown' : `${group.course_level}-level`;
            progressionChart = Highcharts.chart('progression-chart', {
                chart: {
                    type: trend ? 'line' : 'column',
                    animation: false,
                    scrollablePlotArea: { minWidth: Math.max(360, groups.length * Math.max(100, data.bloom_levels.length * 24)) },
                },
                title: { text: null },
                xAxis: {
                    categories: groups.map(group => label(group) + (group?.clo_count === 0 ? ' (no CLOs)' : '')),
                    title: { text: 'Course group' },
                },
                yAxis: {
                    min: 0,
                    max: counts ? undefined : 100,
                    allowDecimals: !counts,
                    title: { text: counts ? 'Number of CLOs' : 'CLOs (%)' },
                },
                plotOptions: {
                    series: { animation: false },
                    line: { connectNulls: false, marker: { enabled: true } },
                },
                exporting: { enabled: false },
                credits: { enabled: false },
                accessibility: { enabled: false },
                tooltip: {
                    outside: true,
                    animation: false,
                    formatter: function () {
                        const point = this.point || this;
                        const { group, level } = point.options.custom;
                        return `<b>${label(group)}</b><br>${escapeChartText(level.name)}<br>`
                            + `${level.clo_count} of ${group.clo_count} CLOs (${level.percentage}%)<br>`
                            + `${group.unmatched_clo_count} CLOs with no Bloom match in this group`;
                    },
                },
                series: data.bloom_levels.map(level => ({
                    name: escapeChartText(level.name),
                    data: groups.map(group => {
                        if (!group) return null;
                        const result = group.levels.find(item => item.level_id === level.id);
                        return {
                            y: result.percentage === null ? null : counts ? result.clo_count : result.percentage,
                            custom: { group, level: result },
                        };
                    }),
                })),
            });
        }

        function escapeChartText(value) {
            const text = document.createElement('span');
            text.textContent = value;
            return text.innerHTML;
        }

        function renderGroups(data) {
            const buttons = $('#progression-group-buttons').empty();
            $('#progression-groups').toggleClass('d-none', data.course_groups.length === 0);
            document.getElementById('progression-course-list').replaceChildren();
            data.course_groups.forEach(group => {
                const label = group.course_level === 'other' ? 'Other/unknown' : `${group.course_level}-level`;
                $('<button>', {
                    type: 'button',
                    class: 'btn btn-outline-primary',
                    'aria-controls': 'progression-details-modal',
                    'aria-haspopup': 'dialog',
                }).text(`${label} details`).on('click', function () {
                    const courses = data.courses.filter(course => {
                        const number = String(course.course_num ?? '').trim();
                        const level = /^[1-9][0-9]{2}[a-z]*$/i.test(number) ? Number(number[0]) * 100 : 'other';
                        return String(level) === String(group.course_level);
                    });
                    openDetails(courses, `${label} — Courses and CLOs`, this);
                }).appendTo(buttons);
            });
        }

        function openDetails(courses, title, trigger, reviewOnly = false) {
            const data = progressionData;
            $('#progression-details-title').text(title);
            $('#progression-details-filter-section').toggleClass('d-none', reviewOnly);
            $('#progression-details-scope').text(data.selected_plo
                ? `PLO scope: ${data.selected_plo.pl_outcome}` : 'Scope: Entire program');
            $('#progression-details-incomplete').toggleClass('d-none', !data.has_incomplete_mappings);
            $('#progression-details-no-reference').toggleClass('d-none', data.bloom_reference_available);
            const filter = document.getElementById('progression-details-filter');
            filter.replaceChildren(new Option('All CLOs', 'all'));
            if (data.bloom_reference_available) {
                data.bloom_levels.forEach(level => filter.add(new Option(level.name, String(level.id))));
                filter.add(new Option('No Bloom match', 'unmatched'));
                filter.add(new Option('Review suggested', 'review'));
            }
            filter.value = reviewOnly ? 'review' : 'all';
            filter.disabled = !data.bloom_reference_available;
            $(filter).off('change').on('change', () => renderFilteredCourses(courses, data.bloom_reference_available)).trigger('change');
            const modal = document.getElementById('progression-details-modal');
            modal.addEventListener('hidden.bs.modal', () => trigger.focus({ preventScroll: true }), { once: true });
            bootstrap.Modal.getOrCreateInstance(modal).show(trigger);
        }

        function needsReview(clo) {
            return new Set((clo.bloom_levels ?? []).map(level => level.level_id)).size >= 3;
        }

        function renderFilteredCourses(courses, referenceAvailable) {
            const filter = $('#progression-details-filter').val();
            const filtered = filter === 'all' ? courses : courses.map(course => ({
                ...course,
                clos: course.clos.filter(clo => filter === 'unmatched'
                    ? clo.bloom_levels.length === 0
                    : filter === 'review' ? needsReview(clo)
                    : clo.bloom_levels.some(level => String(level.level_id) === filter)),
            })).filter(course => course.clos.length > 0);
            const total = courses.reduce((sum, course) => sum + course.clos.length, 0);
            const shown = filtered.reduce((sum, course) => sum + course.clos.length, 0);
            $('#progression-details-counts').text(`Showing ${shown} of ${total} CLOs · ${filtered.length} of ${courses.length} courses`);
            $('#progression-details-empty').toggleClass('d-none', filter === 'all' || shown > 0);
            renderCourses(filtered, referenceAvailable);
        }

        function renderCourses(courses, referenceAvailable) {
            const list = document.getElementById('progression-course-list');
            list.replaceChildren();

            courses.forEach(function (course) {
                const section = document.createElement('section');
                section.className = 'border rounded p-3 mb-3';
                const heading = document.createElement('h6');
                const link = document.createElement('a');
                const code = [course.course_code, course.course_num].filter(Boolean).join(' ');
                link.textContent = [code, course.course_title].filter(Boolean).join(': ') || `Course ${course.course_id}`;
                link.href = @json(route('courseWizard.step7', ['course' => '__COURSE_ID__']))
                    .replace('__COURSE_ID__', encodeURIComponent(course.course_id));
                link.target = '_blank';
                link.rel = 'noopener';
                link.setAttribute('aria-label', `${link.textContent} (opens in a new tab)`);
                heading.appendChild(link);

                const status = document.createElement('span');
                status.className = 'badge mb-2 ' + (course.course_required ? 'text-bg-primary' : 'text-bg-secondary');
                status.textContent = course.course_required === null
                    ? 'Required status unspecified'
                    : (course.course_required ? 'Required' : 'Non-required');
                section.append(heading, status);

                if (course.clos.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'mb-0 text-muted';
                    empty.textContent = 'No course learning outcomes have been added yet.';
                    section.appendChild(empty);
                } else {
                    const outcomes = document.createElement('ul');
                    outcomes.className = 'mb-0';
                    course.clos.forEach(function (clo) {
                        const item = document.createElement('li');
                        item.className = 'mb-2';
                        if (clo.clo_shortphrase) {
                            const label = document.createElement('strong');
                            label.textContent = `${clo.clo_shortphrase}: `;
                            item.appendChild(label);
                        }
                        item.appendChild(document.createTextNode(clo.l_outcome ?? ''));
                        if (referenceAvailable) {
                            if (needsReview(clo)) {
                                const review = document.createElement('p');
                                review.className = 'small text-muted mt-1 mb-0';
                                review.textContent = 'Review suggested — matches three or more cognitive levels. This may be valid; check the intended learning against the wording.';
                                item.appendChild(review);
                            }
                            const classification = document.createElement('div');
                            classification.className = 'small mt-1';
                            if (clo.bloom_levels.length === 0) {
                                classification.classList.add('text-muted');
                                classification.textContent = 'No matching cognitive verb found.';
                            } else {
                                const label = document.createElement('strong');
                                label.textContent = 'Suggested cognitive levels:';
                                classification.appendChild(label);
                                clo.bloom_levels.forEach(function (level) {
                                    const evidence = document.createElement('div');
                                    evidence.textContent = `${level.name} — Matched verbs: ${level.matched_terms.join(', ')}`;
                                    classification.appendChild(evidence);
                                });
                            }
                            item.appendChild(classification);
                        }
                        outcomes.appendChild(item);
                    });
                    section.appendChild(outcomes);
                }
                list.appendChild(section);
            });
        }
    });
</script>
