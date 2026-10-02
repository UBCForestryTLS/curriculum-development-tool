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
        <p id="progression-no-mappings" class="alert alert-info d-none">No CLOs in this program have applicable mappings to the selected PLO.</p>
        <p id="progression-no-courses" class="alert alert-info d-none">There are no courses in this program. Add courses to begin reviewing progression.</p>
        <p id="progression-no-clos" class="alert alert-info d-none">The courses in this program do not have any CLOs yet. Add course learning outcomes to begin reviewing progression.</p>
        <p id="progression-no-reference" class="alert alert-info d-none">The Bloom’s cognitive reference is unavailable. You can still review the courses and CLOs below.</p>
    </div>

    <section id="progression-courses" class="d-none" aria-labelledby="progression-courses-heading">
        <h5 id="progression-courses-heading">Courses and learning outcomes</h5>
        <div id="progression-course-list" class="text-break"></div>
    </section>
</div>

<style>
    #progression-course-list ul::before { content: none; }
</style>

<script type="module">
    $(document).ready(function () {
        let progressionData = null;
        let progressionLoading = false;

        $('#nav-progression-tab').on('shown.bs.tab', loadProgression);
        $('#progression-retry').on('click', loadProgression);
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
            $('#progression-results, #progression-courses').addClass('d-none');
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
                    $('#progression-no-mappings').toggleClass('d-none', !selected || totals.clo_count !== 0);
                    $('#progression-no-courses').toggleClass('d-none', selected || totals.course_count !== 0);
                    $('#progression-no-clos').toggleClass('d-none', selected || totals.course_count === 0 || totals.clo_count !== 0);
                    $('#progression-no-reference').toggleClass('d-none', data.bloom_reference_available || totals.clo_count === 0);
                    renderCourses(data.courses, data.bloom_reference_available);
                    $('#progression-results').removeClass('d-none');
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

        function renderCourses(courses, referenceAvailable) {
            const list = document.getElementById('progression-course-list');
            list.replaceChildren();
            $('#progression-courses').toggleClass('d-none', courses.length === 0);

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
