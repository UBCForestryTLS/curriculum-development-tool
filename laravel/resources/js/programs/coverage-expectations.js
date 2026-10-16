const metricKeys = [
    'mapped_clo_count',
    'covering_course_count',
    'required_course_count',
    'non_required_course_count',
];

/**
 * Normalize active expectations without changing the draft or coverage evidence.
 * Example draft (level keys are mapping-scale IDs):
 * {
 *     concerns: { gaps: true, redundancies: true },
 *     metrics: {
 *         covering_course_count: { enabled: true, levels: { '1': { min: '25', max: '50' } } }
 *     }
 * }
 */
export function normalizeExpectations(draft, configuredLevels = []) {
    if (draft.concerns.gaps !== true && draft.concerns.redundancies !== true) {
        return {
            settings: { concerns: { gaps: false, redundancies: false }, metrics: {} },
            errors: {},
        };
    }

    const concerns = {
        gaps: draft.concerns.gaps === true,
        redundancies: draft.concerns.redundancies === true,
    };
    const settings = { concerns, metrics: {} };
    const errors = {};
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
            if (concerns.gaps) {
                const value = String(metric.levels?.[id]?.min ?? '').trim();
                if (value !== '') {
                    if (!/^\d+$/.test(value) || Number(value) > 100) {
                        errors[`${key}.levels.${id}.min`] = 'Enter a whole percentage from 0 to 100, or leave blank.';
                    } else {
                        bounds.min = Number(value);
                    }
                }
            }
            if (concerns.redundancies) {
                const value = String(metric.levels?.[id]?.max ?? '').trim();
                if (value !== '') {
                    if (!/^\d+$/.test(value) || Number(value) > 100) {
                        errors[`${key}.levels.${id}.max`] = 'Enter a whole percentage from 0 to 100, or leave blank.';
                    } else {
                        bounds.max = Number(value);
                    }
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
