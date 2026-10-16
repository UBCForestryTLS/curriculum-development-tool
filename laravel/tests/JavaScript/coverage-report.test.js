import assert from 'node:assert/strict';
import test from 'node:test';
import { normalizeExpectations } from '../../resources/js/programs/coverage-expectations.js';
import { evaluateCoverage } from '../../resources/js/programs/coverage-report.js';

const both = { gaps: true, redundancies: true };
const level = { map_scale_id: 90, mapped_clo_count: 8, covering_course_count: 4, required_course_count: 2, non_required_course_count: 1 };
const emptySummary = { evaluated_plo_count: 0, concern_plo_count: 0, gap_plo_count: 0, redundancy_plo_count: 0 };

function data(levels = [level], totals = { course_count: 10, clo_count: 40 }) {
    return { program_totals: totals, coverage: [{ pl_outcome_id: 12, mapping_scale_histogram: levels }] };
}

function expectations(bounds, metric = 'covering_course_count') {
    return { concerns: both, metrics: { [metric]: { levels: { 90: bounds } } } };
}

function courseComparison(result) {
    return result.plos[0].comparisons.find(comparison => comparison.metric === 'covering_course_count');
}

test('uses all program CLOs or courses as denominators for the four metrics', () => {
    const settings = { concerns: both, metrics: Object.fromEntries([
        'mapped_clo_count', 'covering_course_count', 'required_course_count', 'non_required_course_count',
    ].map(metric => [metric, { levels: { 90: { min: 25, max: 35 } } }])) };
    const comparisons = evaluateCoverage(data(), settings).plos[0].comparisons;

    assert.deepEqual(comparisons.map(({ metric, count, denominator, percentage, status }) =>
        [metric, count, denominator, percentage, status]), [
        ['mapped_clo_count', 8, 40, 20, 'gap'],
        ['covering_course_count', 4, 10, 40, 'redundancy'],
        ['required_course_count', 2, 10, 20, 'gap'],
        ['non_required_course_count', 1, 10, 10, 'gap'],
    ]);
});

test('compares inclusive boundaries, one-sided ranges and explicit zero correctly', () => {
    for (const [count, min, max, status] of [
        [3, 40, 70, 'gap'], [4, 40, 70, 'within_expectations'],
        [7, 40, 70, 'within_expectations'], [8, 40, 70, 'redundancy'],
        [0, 0, null, 'within_expectations'], [0, null, 0, 'within_expectations'],
        [1, null, 0, 'redundancy'], [0, 10, null, 'gap'],
        [5, 50, 50, 'within_expectations'], [4, 50, 50, 'gap'], [6, 50, 50, 'redundancy'],
        [10, 100, 100, 'within_expectations'], [9, 100, null, 'gap'],
        [0, null, null, 'no_expectation'],
    ]) {
        const comparison = courseComparison(evaluateCoverage(
            data([{ ...level, covering_course_count: count }]), expectations({ min, max }),
        ));
        assert.equal(comparison.status, status, JSON.stringify({ count, min, max }));
        assert.equal(comparison.min, min);
        assert.equal(comparison.max, max);
    }
});

test('uses unrounded comparisons even when both sides would display as 40 percent', () => {
    for (const [count, percentage, status] of [[39999, 39.999, 'gap'], [40000, 40, 'within_expectations'], [40001, 40.001, 'redundancy']]) {
        const comparison = courseComparison(evaluateCoverage(
            data([{ ...level, covering_course_count: count }], { course_count: 100000, clo_count: 40 }),
            expectations({ min: 40, max: 40 }),
        ));
        assert.equal(comparison.percentage, percentage);
        assert.equal(comparison.percentage.toFixed(2), '40.00');
        assert.equal(comparison.status, status);
    }
});

test('consumes normalized selections and ignores inactive concerns, metrics and blank bounds', () => {
    for (const concerns of [both, { gaps: true, redundancies: false }, { gaps: false, redundancies: true }, { gaps: false, redundancies: false }]) {
        const { settings, errors } = normalizeExpectations({ concerns, metrics: {
            covering_course_count: { enabled: true, levels: { 90: {
                min: concerns.gaps ? '40' : 'unused', max: concerns.redundancies ? '60' : 'unused',
            } } },
            mapped_clo_count: { enabled: false, levels: { 90: { min: '100' } } },
            required_course_count: { enabled: true, levels: { 90: { min: '', max: '' } } },
        } }, [level]);
        assert.deepEqual(errors, {});
        const result = evaluateCoverage(data([{ ...level, covering_course_count: 7 }]), settings);
        assert.equal(courseComparison(result).status, concerns.redundancies ? 'redundancy' : concerns.gaps ? 'within_expectations' : 'no_expectation');
        assert.ok(result.plos[0].comparisons.filter(comparison => comparison.metric !== 'covering_course_count')
            .every(comparison => comparison.status === 'no_expectation'));
    }

    // Even supplied numeric bounds cannot activate a concern that is unselected.
    const settings = expectations({ min: 50, max: 0 });
    settings.concerns = { gaps: true, redundancies: false };
    assert.equal(courseComparison(evaluateCoverage(data(), settings)).status, 'gap');
    settings.concerns = { gaps: false, redundancies: true };
    assert.equal(courseComparison(evaluateCoverage(data(), settings)).status, 'redundancy');
});

test('distinguishes zero coverage from a zero denominator', () => {
    const zeroLevel = { map_scale_id: 90, mapped_clo_count: 0, covering_course_count: 0, required_course_count: 0, non_required_course_count: 0 };
    const settings = { concerns: both, metrics: {
        mapped_clo_count: { levels: { 90: { min: 20, max: null } } },
        required_course_count: { levels: { 90: { min: 20, max: null } } },
    } };
    const result = evaluateCoverage(data([zeroLevel], { course_count: 10, clo_count: 0 }), settings);
    const clo = result.plos[0].comparisons.find(comparison => comparison.metric === 'mapped_clo_count');
    const required = result.plos[0].comparisons.find(comparison => comparison.metric === 'required_course_count');
    assert.equal(clo.percentage, null);
    assert.equal(clo.status, 'not_applicable');
    assert.equal(required.percentage, 0);
    assert.equal(required.status, 'gap');

    const empty = evaluateCoverage(data([zeroLevel], { course_count: 0, clo_count: 0 }), settings);
    assert.ok(empty.plos[0].comparisons.every(comparison => comparison.percentage === null && comparison.status === 'not_applicable'));
    assert.deepEqual(empty.summary, emptySummary);
});

test('preserves configured order and independent overlapping levels, excluding N/A and absent levels', () => {
    const levels = [{ ...level, covering_course_count: 7 }, { ...level, map_scale_id: 3, covering_course_count: 8 }, { ...level, map_scale_id: 0 }];
    const settings = expectations({ min: 70, max: 70 });
    settings.metrics.covering_course_count.levels[3] = { min: 80, max: 80 };
    settings.metrics.covering_course_count.levels[0] = { min: 100, max: 100 };
    settings.metrics.covering_course_count.levels[999] = { min: 100, max: 100 };
    const result = evaluateCoverage(data(levels), settings);
    const courses = result.plos[0].comparisons.filter(comparison => comparison.metric === 'covering_course_count');
    assert.deepEqual(courses.map(comparison => comparison.map_scale_id), [90, 3]);
    assert.deepEqual(courses.map(comparison => comparison.percentage), [70, 80]);
    assert.ok(courses.every(comparison => comparison.status === 'within_expectations'));
    assert.equal(result.summary.concern_plo_count, 0);
});

test('counts each affected PLO once while allowing both concern types on the same PLO', () => {
    const fixture = data();
    fixture.coverage.push({ pl_outcome_id: 20, mapping_scale_histogram: [{ ...level, covering_course_count: 1 }] });
    fixture.coverage.push({ pl_outcome_id: 30, mapping_scale_histogram: [{ ...level, mapped_clo_count: 20, covering_course_count: 2 }] });
    const settings = expectations({ min: 20, max: 30 });
    settings.metrics.mapped_clo_count = { levels: { 90: { min: 40, max: 60 } } };
    const result = evaluateCoverage(fixture, settings);
    assert.deepEqual(result.plos.map(({ pl_outcome_id, has_gap, has_redundancy }) => [pl_outcome_id, has_gap, has_redundancy]), [
        [12, true, true], [20, true, false], [30, false, false],
    ]);
    assert.deepEqual(result.summary, { evaluated_plo_count: 3, concern_plo_count: 2, gap_plo_count: 2, redundancy_plo_count: 1 });
});

test('keeps statistics without expectations and handles programs without PLOs or levels', () => {
    for (const settings of [null, { concerns: both, metrics: {} }, { ...expectations({ min: 100, max: 0 }), concerns: { gaps: false, redundancies: false } }]) {
        const result = evaluateCoverage(data(), settings);
        assert.deepEqual(result.summary, emptySummary);
        assert.ok(result.plos[0].comparisons.every(comparison => comparison.status === 'no_expectation' && comparison.min === null && comparison.max === null));
        assert.equal(courseComparison(result).percentage, 40);
    }
    assert.deepEqual(evaluateCoverage({ program_totals: { course_count: 0, clo_count: 0 }, coverage: [] }), { plos: [], summary: emptySummary });
    for (const levels of [[], [{ ...level, map_scale_id: 0 }]]) {
        const result = evaluateCoverage(data(levels), expectations({ min: 100, max: null }));
        assert.deepEqual(result.plos[0].comparisons, []);
        assert.deepEqual(result.summary, emptySummary);
    }
});

test('does not change raw evidence, expectations or previous results when recalculating', () => {
    const fixture = data();
    fixture.coverage[0].courses = [{ course_id: 5, course_required: null }];
    fixture.coverage[0].n_a_clo_count = 2;
    fixture.coverage[0].multi_level_mapping_count = 1;
    const settings = expectations({ min: 50, max: 70 });
    const originalData = structuredClone(fixture);
    const originalSettings = structuredClone(settings);
    const first = evaluateCoverage(fixture, settings);
    const originalResult = structuredClone(first);
    const second = evaluateCoverage(fixture, expectations({ min: 0, max: 30 }));
    assert.equal(courseComparison(first).status, 'gap');
    assert.equal(courseComparison(second).status, 'redundancy');
    assert.deepEqual(first, originalResult);
    assert.deepEqual(fixture, originalData);
    assert.deepEqual(settings, originalSettings);
});
