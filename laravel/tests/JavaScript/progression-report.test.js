import assert from 'node:assert/strict';
import test from 'node:test';
import { summarizeCognitiveLevels } from '../../resources/js/programs/progression-report.js';

const levels = [
    { id: 10, name: 'Remember', position: 1 },
    { id: 30, name: 'Analyze', position: 2 },
    { id: 20, name: 'Create', position: 3 },
];

function clo(id, levelIds = []) {
    return { l_outcome_id: id, bloom_levels: levelIds.map(level_id => ({ level_id })) };
}

function data(courses, overrides = {}) {
    return { scope: 'program', bloom_reference_available: true, bloom_levels: levels, courses, ...overrides };
}

test('uses all scoped CLOs rather than averaging course-group percentages', () => {
    // One course has 100% Remember coverage and the other has 33.33%.
    // Together, two of four CLOs match: 50%, not the average of those percentages.
    const result = summarizeCognitiveLevels(data([
        { course_id: 1, course_num: '101', clos: [clo(1, [10])] },
        { course_id: 2, course_num: '201', clos: [clo(2, [10]), clo(3), clo(4)] },
    ]));

    assert.equal(result.clo_count, 4);
    assert.deepEqual(result.levels, [
        { level_id: 10, name: 'Remember', clo_count: 2, percentage: 50, courses: [
            { course_id: 1, course_code: '', course_num: '101', course_title: '' },
            { course_id: 2, course_code: '', course_num: '201', course_title: '' },
        ] },
        { level_id: 30, name: 'Analyze', clo_count: 0, percentage: 0, courses: [] },
        { level_id: 20, name: 'Create', clo_count: 0, percentage: 0, courses: [] },
    ]);
});

test('counts distinct CLOs at each level and allows overlapping matches', () => {
    const broadMatch = clo(1, [10, 10, 30, 20]);
    const result = summarizeCognitiveLevels(data([
        { course_id: 1, clos: [broadMatch, clo(2, [30])] },
        { course_id: 1, clos: [broadMatch, clo(2, [30])] },
    ]));

    // Repeated records and matches must not inflate the count. A CLO that matches
    // different levels still belongs in each one, so their percentages can exceed 100%.
    assert.equal(result.clo_count, 2);
    assert.deepEqual(result.levels.map(level => level.clo_count), [1, 2, 1]);
    assert.deepEqual(result.levels.map(level => level.percentage), [50, 100, 50]);
});

test('uses the selected PLO courses rather than the whole-program totals', () => {
    const program = data([
        { course_id: 1, clos: [clo(1, [10]), clo(2), clo(3)] },
        { course_id: 2, clos: [clo(4, [30])] },
    ], { program_totals: { course_count: 2, clo_count: 4 } });
    const selected = {
        ...program,
        scope: 'plo',
        scope_totals: { course_count: 1, clo_count: 1 },
        courses: [program.courses[1]],
    };
    const original = structuredClone(program);

    assert.equal(summarizeCognitiveLevels(program).levels[1].percentage, 25);
    const result = summarizeCognitiveLevels(selected);
    assert.equal(result.clo_count, 1);
    assert.deepEqual(result.levels.map(level => level.clo_count), [0, 1, 0]);
    assert.deepEqual(result.levels.map(level => level.percentage), [0, 100, 0]);
    assert.deepEqual(result.levels.map(level => level.courses.map(course => course.course_id)), [[], [2], []]);

    // Switching back should give the original result without changing the response data.
    assert.equal(summarizeCognitiveLevels(program).levels[1].percentage, 25);
    assert.deepEqual(program, original);
});

test('keeps reference order and includes levels with no matches', () => {
    const result = summarizeCognitiveLevels(data([
        { course_id: 1, clos: [clo(1, [20]), clo(2), clo(3)] },
    ]));

    assert.deepEqual(result.levels.map(level => level.level_id), [10, 30, 20]);
    assert.deepEqual(result.levels.map(level => level.percentage), [0, 0, 33.33]);
});

test('returns no percentage when the scope has no CLOs', () => {
    for (const courses of [[], [{ course_id: 1, clos: [] }]]) {
        const result = summarizeCognitiveLevels(data(courses));
        assert.equal(result.clo_count, 0);
        assert.deepEqual(result.levels.map(level => level.clo_count), [0, 0, 0]);
        assert.deepEqual(result.levels.map(level => level.percentage), [null, null, null]);
    }
});

test('keeps an unavailable reference distinct from zero matches', () => {
    const response = data([
        { course_id: 1, clos: [{ l_outcome_id: 1, bloom_levels: null }] },
    ], { bloom_reference_available: false });
    const result = summarizeCognitiveLevels(response);

    assert.equal(result.clo_count, 1);
    assert.deepEqual(result.levels.map(level => level.clo_count), [null, null, null]);
    assert.deepEqual(result.levels.map(level => level.percentage), [null, null, null]);
    assert.deepEqual(result.levels.map(level => level.courses), [[], [], []]);
    assert.deepEqual(summarizeCognitiveLevels({ ...response, bloom_levels: [] }), {
        clo_count: 1,
        levels: [],
    });
});

test('lists each contributing course once per level with its display details', () => {
    const course = {
        course_id: 1, course_code: 'TEST', course_num: '101', course_title: 'Foundations',
        clos: [clo(1, [10, 10, 30]), clo(2, [10])],
    };
    const otherCourse = {
        course_id: 2, course_code: 'TEST', course_num: '201', course_title: 'Advanced topics',
        clos: [clo(3, [30])],
    };
    const result = summarizeCognitiveLevels(data([
        course, course, otherCourse,
        { course_id: 3, clos: [clo(4)] },
    ]));
    const { clos: firstClos, ...firstDetails } = course;
    const { clos: otherClos, ...otherDetails } = otherCourse;

    // Repeated CLO matches and course records should not repeat names in the tooltip.
    assert.deepEqual(result.levels.map(level => level.courses), [
        [firstDetails], [firstDetails, otherDetails], [],
    ]);
});
