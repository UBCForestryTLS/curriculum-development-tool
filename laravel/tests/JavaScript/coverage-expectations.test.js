import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeExpectations } from '../../resources/js/programs/coverage-expectations.js';

test('normalizes active bounds without changing draft values', () => {
    const cases = [
        { mode: 'both', min: '', max: '', expected: {} },
        { mode: 'both', min: '0', max: '', expected: { min: 0, max: null } },
        { mode: 'both', min: '', max: '0', expected: { min: null, max: 0 } },
        { mode: 'both', min: ' 2 ', max: '2', expected: { min: 2, max: 2 } },
        { mode: 'gaps', min: '3', max: 'invalid', expected: { min: 3, max: null } },
        { mode: 'redundancies', min: '-4', max: '5', expected: { min: null, max: 5 } },
    ];

    for (const { mode, min, max, expected } of cases) {
        const draft = { mode, metrics: { mapped_clo_count: { enabled: true, min, max } } };
        const original = structuredClone(draft);
        const result = normalizeExpectations(draft);
        assert.deepEqual(result.errors, {});
        assert.deepEqual(result.settings, {
            mode,
            metrics: Object.keys(expected).length ? { mapped_clo_count: expected } : {},
        });
        assert.deepEqual(draft, original, 'normalization must preserve the editable draft');
    }

    const metrics = Object.fromEntries([
        'mapped_clo_count', 'covering_course_count', 'required_course_count', 'non_required_course_count',
    ].map(key => [key, { enabled: true, min: '1', max: '4' }]));
    const result = normalizeExpectations({ mode: 'both', metrics });
    assert.deepEqual(Object.keys(result.settings.metrics), Object.keys(metrics));
    assert.deepEqual(normalizeExpectations({
        mode: 'both', metrics: { covering_course_count: { enabled: false, min: '-1', max: 'bad' } },
    }), { settings: { mode: 'both', metrics: {} }, errors: {} });
});

test('rejects invalid active counts and inverted ranges without partially applying settings', () => {
    for (const value of ['-1', '1.5', '1e2', 'abc', 'Infinity', '9007199254740992']) {
        for (const bound of ['min', 'max']) {
            const result = normalizeExpectations({
                mode: 'both',
                metrics: {
                    mapped_clo_count: { enabled: true, [bound]: value },
                    covering_course_count: { enabled: true, min: '2' },
                },
            });
            assert.equal(result.settings, null);
            assert.ok(result.errors[`mapped_clo_count.${bound}`], `${value} must be rejected`);
        }
    }
    const inverted = normalizeExpectations({
        mode: 'both', metrics: { required_course_count: { enabled: true, min: '4', max: '3' } },
    });
    assert.equal(inverted.settings, null);
    assert.ok(inverted.errors['required_course_count.max']);
    const invalidMode = normalizeExpectations({ mode: 'unknown', metrics: {} });
    assert.equal(invalidMode.settings, null);
    assert.ok(invalidMode.errors.mode);
});
