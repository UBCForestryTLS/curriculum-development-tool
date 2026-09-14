const metricKeys = [
    'mapped_clo_count',
    'covering_course_count',
    'required_course_count',
    'non_required_course_count',
];

/** Normalize active overall-PLO expectations without changing the draft or evidence. */
export function normalizeExpectations(draft) {
    const settings = { mode: draft.mode, metrics: {} };
    const errors = {};

    if (!['gaps', 'redundancies', 'both'].includes(draft.mode)) {
        return { settings: null, errors: { mode: 'Choose which concerns to review.' } };
    }

    for (const key of metricKeys) {
        const metric = draft.metrics[key];
        if (!metric?.enabled) continue;

        const bounds = { min: null, max: null };
        for (const bound of ['min', 'max']) {
            if ((bound === 'min' && draft.mode === 'redundancies')
                || (bound === 'max' && draft.mode === 'gaps')) continue;

            const value = String(metric[bound] ?? '').trim();
            if (value === '') continue;

            if (!/^\d+$/.test(value) || !Number.isSafeInteger(Number(value))) {
                errors[`${key}.${bound}`] = 'Enter a non-negative whole number, or leave blank.';
            } else {
                bounds[bound] = Number(value);
            }
        }

        if (bounds.min !== null && bounds.max !== null && bounds.min > bounds.max) {
            errors[`${key}.max`] = 'Maximum must be greater than or equal to minimum.';
        }

        if (bounds.min !== null || bounds.max !== null) settings.metrics[key] = bounds;
    }

    return { settings: Object.keys(errors).length ? null : settings, errors };
}
