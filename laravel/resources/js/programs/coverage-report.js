const metricDenominators = {
    mapped_clo_count: 'clo_count',
    covering_course_count: 'course_count',
    required_course_count: 'course_count',
    non_required_course_count: 'course_count',
};

/**
 * Evaluate endpoint data against normalized applied expectations, without mutation.
 * Histogram entries are the program's configured levels, already ordered/deduplicated
 * by the backend. Keep raw evidence separate; identify results by PLO, metric and level.
 * Incomplete-mapping presentation is the caller's responsibility.
 */
export function evaluateCoverage(data, expectations = null) {
    const plos = data.coverage.map(plo => {
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
                } else if (max !== null && 100 * count > max * denominator) {
                    status = 'redundancy';
                } else if (min !== null || max !== null) {
                    status = 'within_expectations';
                }

                return { metric, map_scale_id: level.map_scale_id, count, denominator, percentage, min, max, status };
            }));

        return {
            pl_outcome_id: plo.pl_outcome_id,
            comparisons,
            has_gap: comparisons.some(comparison => comparison.status === 'gap'),
            has_redundancy: comparisons.some(comparison => comparison.status === 'redundancy'),
        };
    });

    return {
        plos,
        summary: {
            evaluated_plo_count: plos.filter(plo => plo.comparisons.some(comparison =>
                ['gap', 'redundancy', 'within_expectations'].includes(comparison.status))).length,
            concern_plo_count: plos.filter(plo => plo.has_gap || plo.has_redundancy).length,
            gap_plo_count: plos.filter(plo => plo.has_gap).length,
            redundancy_plo_count: plos.filter(plo => plo.has_redundancy).length,
        },
    };
}
