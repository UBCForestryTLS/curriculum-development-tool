import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeExpectations } from '../../resources/js/programs/coverage-expectations.js';

const configuredLevels = [{ map_scale_id: 90 }, { map_scale_id: 3 }, { map_scale_id: 0 }];

test('preserves independent percentage targets, zero, and drafts across all four metrics', () => {
    const keys = ['mapped_clo_count', 'covering_course_count', 'required_course_count', 'non_required_course_count'];
    const draft = { reviewGaps: true, metrics: Object.fromEntries(keys.map(key => [key, {
        enabled: true, levels: { 90: ' 65 ', 3: '70' },
    }])) };
    draft.metrics.mapped_clo_count.levels[90] = '0';
    draft.metrics.non_required_course_count.levels[3] = '100';
    const before = structuredClone(draft);
    assert.deepEqual(normalizeExpectations(draft, configuredLevels), {
        errors: {}, settings: { reviewGaps: true, metrics: {
            mapped_clo_count: { levels: { 90: 0, 3: 70 } },
            covering_course_count: { levels: { 90: 65, 3: 70 } },
            required_course_count: { levels: { 90: 65, 3: 70 } },
            non_required_course_count: { levels: { 90: 65, 3: 100 } },
        } },
    });
    assert.deepEqual(draft, before, 'normalizing must not mutate the editable draft');
});

test('blank targets and unchecked metrics do not become applied checks', () => {
    const draft = { reviewGaps: true, metrics: {
        mapped_clo_count: { enabled: true, levels: { 90: '', 3: '  ' } },
        covering_course_count: { enabled: true, levels: { 90: '40' } },
        required_course_count: { enabled: false, levels: { 999: 'invalid' } },
    } };
    assert.deepEqual(normalizeExpectations(draft, configuredLevels), {
        errors: {}, settings: { reviewGaps: true, metrics: { covering_course_count: { levels: { 90: 40 } } } },
    });
    assert.deepEqual(normalizeExpectations({ reviewGaps: true, metrics: {} }, []), {
        errors: {}, settings: { reviewGaps: true, metrics: {} },
    });
});

test('unchecking potential gaps ignores all targets without changing the draft', () => {
    const draft = { reviewGaps: false, metrics: {
        covering_course_count: { enabled: true, levels: { 90: 'invalid', 3: '70' } },
    } };
    const before = structuredClone(draft);
    assert.deepEqual(normalizeExpectations(draft, configuredLevels), {
        errors: {}, settings: { reviewGaps: false, metrics: {} },
    });
    assert.deepEqual(draft, before);
});

test('invalid percentages prevent partially applying otherwise valid preferences', () => {
    for (const value of ['-1', '101', '1.5', '1e2', 'abc', 'Infinity', 'NaN', '50%', '9007199254740992']) {
        const result = normalizeExpectations({ reviewGaps: true, metrics: {
            mapped_clo_count: { enabled: true, levels: { 90: value } },
            covering_course_count: { enabled: true, levels: { 3: '20' } },
        } }, configuredLevels);
        assert.equal(result.settings, null, value + ' must prevent application');
        assert.deepEqual(Object.keys(result.errors), ['mapped_clo_count.levels.90']);
    }
});

test('accepts a custom single level but rejects N/A, unknown, or unavailable levels', () => {
    assert.deepEqual(normalizeExpectations({ reviewGaps: true, metrics: {
        mapped_clo_count: { enabled: true, levels: { 41: '50' } },
    } }, [{ map_scale_id: 41 }]), {
        errors: {}, settings: { reviewGaps: true, metrics: { mapped_clo_count: { levels: { 41: 50 } } } },
    });
    for (const [levels, available] of [
        [{ 0: '50' }, configuredLevels],
        [{ 999: '50' }, configuredLevels],
        [{}, []],
        [{}, [{ map_scale_id: 0 }]],
    ]) {
        const result = normalizeExpectations({ reviewGaps: true, metrics: {
            non_required_course_count: { enabled: true, levels },
        } }, available);
        assert.equal(result.settings, null);
        assert.deepEqual(Object.keys(result.errors), ['non_required_course_count.enabled']);
    }
});
