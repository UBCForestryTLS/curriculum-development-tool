import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeExpectations } from '../../resources/js/programs/coverage-expectations.js';

const configuredLevels = [{ map_scale_id: 90 }, { map_scale_id: 3 }, { map_scale_id: 0 }];
const both = { gaps: true, redundancies: true };

test('normalizes independent ranges across all metrics without changing drafts', () => {
    const keys = ['mapped_clo_count', 'covering_course_count', 'required_course_count', 'non_required_course_count'];
    const draft = { concerns: both, metrics: Object.fromEntries(keys.map(key => [key, {
        enabled: true, levels: { 90: { min: ' 65 ', max: '90' }, 3: { min: '70', max: '100' } },
    }])) };
    const before = structuredClone(draft);
    const expected = Object.fromEntries(keys.map(key => [key, {
        levels: { 90: { min: 65, max: 90 }, 3: { min: 70, max: 100 } },
    }]));
    assert.deepEqual(normalizeExpectations(draft, configuredLevels), {
        settings: { concerns: both, metrics: expected }, errors: {},
    });
    assert.deepEqual(draft, before);
});

test('accepts blank, one-sided, zero and equal bounds', () => {
    for (const [min, max, expected] of [
        ['', '', null],
        ['0', '', { min: 0, max: null }],
        ['', '0', { min: null, max: 0 }],
        ['0', '0', { min: 0, max: 0 }],
        ['50', '50', { min: 50, max: 50 }],
        ['100', '100', { min: 100, max: 100 }],
    ]) {
        const result = normalizeExpectations({ concerns: both, metrics: {
            covering_course_count: { enabled: true, levels: { 90: { min, max } } },
        } }, configuredLevels);
        assert.deepEqual(result, { errors: {}, settings: { concerns: both,
            metrics: expected ? { covering_course_count: { levels: { 90: expected } } } : {},
        } });
    }
});

test('validates only selected concerns and metrics, preserving inactive drafts', () => {
    for (const concerns of [both, { gaps: true, redundancies: false }, { gaps: false, redundancies: true }, { gaps: false, redundancies: false }]) {
        const draft = { concerns, metrics: {
            covering_course_count: { enabled: true, levels: {
                90: { min: concerns.gaps ? '40' : 'invalid', max: concerns.redundancies ? '60' : '-1' },
            } },
            required_course_count: { enabled: false, levels: { 999: { min: 'invalid' } } },
        } };
        const before = structuredClone(draft);
        assert.deepEqual(normalizeExpectations(draft, configuredLevels), {
            errors: {}, settings: { concerns, metrics: concerns.gaps || concerns.redundancies ? {
                covering_course_count: { levels: { 90: {
                    min: concerns.gaps ? 40 : null, max: concerns.redundancies ? 60 : null,
                } } },
            } : {} },
        });
        assert.deepEqual(draft, before);
    }
});

test('rejects invalid active percentages and inverted ranges without partial application', () => {
    for (const value of ['-1', '101', '1.5', '1e2', 'abc', 'Infinity', 'NaN', '50%']) {
        for (const bound of ['min', 'max']) {
            const result = normalizeExpectations({ concerns: both, metrics: {
                mapped_clo_count: { enabled: true, levels: { 90: { [bound]: value } } },
                covering_course_count: { enabled: true, levels: { 3: { min: '20', max: '80' } } },
            } }, configuredLevels);
            assert.equal(result.settings, null);
            assert.deepEqual(Object.keys(result.errors), ['mapped_clo_count.levels.90.' + bound]);
        }
    }
    const result = normalizeExpectations({ concerns: both, metrics: {
        mapped_clo_count: { enabled: true, levels: { 90: { min: '70', max: '60' } } },
    } }, configuredLevels);
    assert.equal(result.settings, null);
    assert.deepEqual(Object.keys(result.errors), ['mapped_clo_count.levels.90.max']);
});

test('accepts custom levels and statistics only, but rejects N/A or unavailable targets', () => {
    assert.deepEqual(normalizeExpectations({ concerns: both, metrics: {
        mapped_clo_count: { enabled: true, levels: { 41: { min: '50', max: '80' } } },
    } }, [{ map_scale_id: 41 }]), {
        errors: {}, settings: { concerns: both, metrics: { mapped_clo_count: { levels: { 41: { min: 50, max: 80 } } } } },
    });
    assert.deepEqual(normalizeExpectations({ concerns: both, metrics: {} }, []), {
        errors: {}, settings: { concerns: both, metrics: {} },
    });
    for (const [levels, available] of [
        [{ 0: { min: '50' } }, configuredLevels],
        [{ 999: { max: '50' } }, configuredLevels],
        [{}, []],
        [{}, [{ map_scale_id: 0 }]],
    ]) {
        const result = normalizeExpectations({ concerns: both, metrics: {
            non_required_course_count: { enabled: true, levels },
        } }, available);
        assert.equal(result.settings, null);
        assert.deepEqual(Object.keys(result.errors), ['non_required_course_count.enabled']);
    }
});
