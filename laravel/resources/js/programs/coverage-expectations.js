const metricKeys = [
    'mapped_clo_count',
    'covering_course_count',
    'required_course_count',
    'non_required_course_count',
];

/** Normalize active expectations without changing the draft or coverage evidence. */
export function normalizeExpectations(draft, configuredLevels = []) {
    const settings = { reviewGaps: draft.reviewGaps === true, metrics: {} };
    const errors = {};
    if (!settings.reviewGaps) return { settings, errors };
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
            const value = String(metric.levels?.[id] ?? '').trim();
            if (value === '') continue;
            if (!/^\d+$/.test(value) || Number(value) > 100) {
                errors[`${key}.levels.${id}`] = 'Enter a whole percentage from 0 to 100, or leave blank.';
            } else {
                levels[id] = Number(value);
            }
        }
        if (Object.keys(levels).length) settings.metrics[key] = { levels };
    }

    return { settings: Object.keys(errors).length ? null : settings, errors };
}
