const metricKeys = [
    'mapped_clo_count',
    'covering_course_count',
    'required_course_count',
    'non_required_course_count',
];

/** Normalize active expectations without changing the draft or coverage evidence. */
export function normalizeExpectations(draft, configuredLevels = []) {
    const concerns = {
        gaps: draft.concerns.gaps === true,
        redundancies: draft.concerns.redundancies === true,
    };
    const settings = { concerns, metrics: {} };
    const errors = {};
    if (!concerns.gaps && !concerns.redundancies) return { settings, errors };
    const levelIds = configuredLevels.map(level => String(level.map_scale_id)).filter(id => id !== '0');

    for (const key of metricKeys) {
        const metric = draft.metrics[key];
        if (!metric?.enabled) continue;

        if (levelIds.length === 0) {
            errors[`${key}.enabled`] = 'No mapping levels are configured. View statistics without expectations.';
            continue;
        }
        if (Object.keys(metric.levels ?? {}).some(id => !levelIds.includes(id))) {
            errors[`${key}.enabled`] = 'Only current, non-N/A mapping levels can have expectations.';
            continue;
        }

        const levels = {};
        for (const id of levelIds) {
            const bounds = { min: null, max: null };
            for (const bound of ['min', 'max']) {
                if (bound === 'min' ? !concerns.gaps : !concerns.redundancies) continue;
                const value = String(metric.levels?.[id]?.[bound] ?? '').trim();
                if (value === '') continue;
                if (!/^\d+$/.test(value) || Number(value) > 100) {
                    errors[`${key}.levels.${id}.${bound}`] = 'Enter a whole percentage from 0 to 100, or leave blank.';
                } else {
                    bounds[bound] = Number(value);
                }
            }
            if (bounds.min !== null && bounds.max !== null && bounds.min > bounds.max) {
                errors[`${key}.levels.${id}.max`] = 'Maximum must be greater than or equal to minimum.';
            }
            if (bounds.min !== null || bounds.max !== null) levels[id] = bounds;
        }
        if (Object.keys(levels).length) settings.metrics[key] = { levels };
    }

    return { settings: Object.keys(errors).length ? null : settings, errors };
}
