const metricDenominators = {
    mapped_clo_count: 'clo_count',
    covering_course_count: 'course_count',
    required_course_count: 'course_count',
    non_required_course_count: 'course_count',
};

/**
 * Evaluate endpoint data against normalized applied expectations, without mutation.
 * Data comes from GET /programWizard/{program}/gap-coverage (programWizard.gapCoverage).
 * Uses program_totals (clo_count, course_count) and coverage entries containing
 * pl_outcome_id and mapping_scale_histogram (map_scale_id and counts for each metric in metricDenominators).
 * Histogram entries are the program's configured levels, already ordered/deduplicated
 * by the backend. Keep raw evidence separate; identify results by PLO, metric and level.
 * Incomplete-mapping presentation is the caller's responsibility.
 */
export function evaluateCoverage(data, expectations = null) {
    let evaluatedPloCount = 0;
    let concernPloCount = 0;
    let gapPloCount = 0;
    let redundancyPloCount = 0;

    const plos = data.coverage.map(plo => {
        let hasGap = false;
        let hasRedundancy = false;
        let hasEvaluatedComparison = false;
        const comparisons = plo.mapping_scale_histogram
            .filter(level => Number(level.map_scale_id) !== 0)
            .flatMap(level => Object.entries(metricDenominators).map(([metric, denominatorKey]) => {
                const count = level[metric];
                const denominator = data.program_totals[denominatorKey];
                const bounds = expectations?.metrics[metric]?.levels[level.map_scale_id];
                const min = expectations?.concerns.gaps ? bounds?.min ?? null : null;
                const max = expectations?.concerns.redundancies ? bounds?.max ?? null : null;
                const percentage = denominator === 0 ? null : 100 * count / denominator;

                let status = 'no_expectation';
                if (denominator === 0) {
                    status = 'not_applicable';
                } else if (min !== null && 100 * count < min * denominator) {
                    status = 'gap';
                    hasGap = true;
                } else if (max !== null && 100 * count > max * denominator) {
                    status = 'redundancy';
                    hasRedundancy = true;
                } else if (min !== null || max !== null) {
                    status = 'within_expectations';
                }

                if (['gap', 'redundancy', 'within_expectations'].includes(status)) hasEvaluatedComparison = true;

                return {
                    metric,
                    map_scale_id: level.map_scale_id,
                    count,
                    denominator,
                    percentage,
                    min,
                    max,
                    status,
                };
            }));

        if (hasEvaluatedComparison) evaluatedPloCount++;
        if (hasGap || hasRedundancy) concernPloCount++;
        if (hasGap) gapPloCount++;
        if (hasRedundancy) redundancyPloCount++;

        return {
            pl_outcome_id: plo.pl_outcome_id,
            comparisons,
            has_gap: hasGap,
            has_redundancy: hasRedundancy,
        };
    });

    return {
        plos,
        summary: {
            evaluated_plo_count: evaluatedPloCount,
            concern_plo_count: concernPloCount,
            gap_plo_count: gapPloCount,
            redundancy_plo_count: redundancyPloCount,
        },
    };
}
