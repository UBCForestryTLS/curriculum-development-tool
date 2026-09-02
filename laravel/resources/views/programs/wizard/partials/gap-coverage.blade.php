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
                rows.appendChild(row);
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
    });
</script>
