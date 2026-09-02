<div class="py-4">
    <h4>Gap Coverage</h4>
    <p>Review how course learning outcomes currently cover each program learning outcome.</p>

    <div id="gap-coverage-loading" class="py-5 text-center d-none">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading gap coverage</span>
        </div>
    </div>

    <div id="gap-coverage-error" class="alert alert-danger d-none" role="alert">
        Gap coverage data could not be loaded. Please try again.
    </div>

    <div id="gap-coverage-incomplete" class="alert alert-warning d-none" role="alert">
        Some course learning outcomes have not been fully mapped to this program. Coverage results may be incomplete.
        <a href="{{ route('programWizard.step3', $program->program_id) }}">Review course mappings</a>.
    </div>

    <div id="gap-coverage-empty" class="alert alert-warning d-none" role="alert">
        There are no program learning outcomes to analyze.
    </div>

    <div id="gap-coverage-results" class="table-responsive d-none">
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
</div>

<script type="text/javascript">
    $(document).ready(function () {
        let gapCoverageLoaded = false;

        $('#nav-gap-coverage-tab').click(function () {
            if (gapCoverageLoaded) {
                return;
            }

            $('#gap-coverage-error').addClass('d-none');
            $('#gap-coverage-loading').removeClass('d-none');

            $.ajax({
                type: 'GET',
                url: @json(route('programWizard.gapCoverage', $program->program_id)),
                dataType: 'json',
                success: function (data) {
                    renderGapCoverage(data);
                    gapCoverageLoaded = true;
                },
                error: function () {
                    $('#gap-coverage-error').removeClass('d-none');
                },
                complete: function () {
                    $('#gap-coverage-loading').addClass('d-none');
                }
            });
        });

        function renderGapCoverage(data) {
            const rows = document.getElementById('gap-coverage-rows');
            rows.replaceChildren();

            $('#gap-coverage-incomplete').toggleClass(
                'd-none',
                !data.mapping_completeness.has_incomplete_mappings
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
                row.appendChild(createScaleCell(coverage.mapping_scale_distribution));
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

        function createScaleCell(scales) {
            const cell = document.createElement('td');

            if (scales.length === 0) {
                cell.textContent = 'No coverage';
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
                    `${scale.abbreviation || scale.title}: ${scale.clo_count}`
                ));
            });

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

            row.id = `gap-coverage-details-${coverage.pl_outcome_id}`;
            row.classList.add('d-none');
            cell.colSpan = 8;

            if (coverage.courses.length === 0) {
                cell.textContent = 'No courses currently provide coverage for this PLO.';
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
