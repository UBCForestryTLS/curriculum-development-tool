<div class="py-4">
    <h4 id="progression-heading" tabindex="-1">Progression Report</h4>
    <p>Review the courses and course learning outcomes (CLOs) in this program. Classification using Bloom’s cognitive taxonomy is not available yet.</p>

    <div id="progression-loading" class="py-4 text-center d-none" role="status">
        <div class="spinner-border text-primary" aria-hidden="true"></div>
        <p class="mb-0 mt-2">Loading progression data…</p>
    </div>

    <div id="progression-error" class="alert alert-danger d-none" role="alert">
        <p>Progression data could not be loaded. Please try again.</p>
        <button id="progression-retry" type="button" class="btn btn-outline-danger">Retry</button>
    </div>

    <div id="progression-results" class="d-none" role="status" aria-atomic="true">
        <dl class="row mb-3">
            <div class="col-sm-6 col-lg-4">
                <dt>Program courses</dt>
                <dd id="progression-course-count" class="fs-4"></dd>
            </div>
            <div class="col-sm-6 col-lg-4">
                <dt>Course learning outcomes (CLOs)</dt>
                <dd id="progression-clo-count" class="fs-4"></dd>
            </div>
        </dl>
        <p id="progression-no-courses" class="alert alert-info d-none">There are no courses in this program. Add courses to begin reviewing progression.</p>
        <p id="progression-no-clos" class="alert alert-info d-none">The courses in this program do not have any CLOs yet. Add course learning outcomes to begin reviewing progression.</p>
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
        if ($('#nav-progression-tab').hasClass('active')) loadProgression();

        function loadProgression() {
            if (progressionData !== null || progressionLoading) return;

            progressionLoading = true;
            if (document.activeElement === document.getElementById('progression-retry')) {
                document.getElementById('progression-heading').focus();
            }
            $('#progression-error').addClass('d-none');
            $('#progression-loading').removeClass('d-none');

            $.ajax({
                type: 'GET',
                url: @json(route('programWizard.progression', $program->program_id)),
                dataType: 'json',
                success: function (data) {
                    progressionData = data;
                    const totals = data.program_totals;
                    $('#progression-course-count').text(totals.course_count);
                    $('#progression-clo-count').text(totals.clo_count);
                    $('#progression-no-courses').toggleClass('d-none', totals.course_count !== 0);
                    $('#progression-no-clos').toggleClass('d-none', totals.course_count === 0 || totals.clo_count !== 0);
                    renderCourses(data.courses);
                    $('#progression-results').removeClass('d-none');
                },
                error: function () {
                    $('#progression-error').removeClass('d-none');
                },
                complete: function () {
                    progressionLoading = false;
                    $('#progression-loading').addClass('d-none');
                }
            });
        }

        function renderCourses(courses) {
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
                        if (clo.clo_shortphrase) {
                            const label = document.createElement('strong');
                            label.textContent = `${clo.clo_shortphrase}: `;
                            item.appendChild(label);
                        }
                        item.appendChild(document.createTextNode(clo.l_outcome ?? ''));
                        outcomes.appendChild(item);
                    });
                    section.appendChild(outcomes);
                }
                list.appendChild(section);
            });
        }
    });
</script>
