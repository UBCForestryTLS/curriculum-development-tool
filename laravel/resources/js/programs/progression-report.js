/**
 * Summarize the cognitive levels returned by programWizard.progression.
 * data.courses already contains only the CLOs in the selected program or PLO scope.
 * data.bloom_levels is the ordered reference list, including levels with no matches.
 */
export function summarizeCognitiveLevels(data) {
    const cloIds = new Set();
    const matches = new Map(data.bloom_levels.map(level => [level.id, new Set()]));
    const courses = new Map(data.bloom_levels.map(level => [level.id, new Map()]));

    data.courses.forEach(course => {
        course.clos.forEach(clo => {
            cloIds.add(clo.l_outcome_id);
            (clo.bloom_levels ?? []).forEach(level => {
                // Count a CLO once at each level, even if it has several matching verbs.
                matches.get(level.level_id)?.add(clo.l_outcome_id);
                // List each contributing course once, even when several of its CLOs match.
                courses.get(level.level_id)?.set(course.course_id, {
                    course_id: course.course_id,
                    course_code: course.course_code ?? '',
                    course_num: course.course_num ?? '',
                    course_title: course.course_title ?? '',
                });
            });
        });
    });

    return {
        clo_count: cloIds.size,
        levels: data.bloom_levels.map(level => {
            const count = data.bloom_reference_available ? matches.get(level.id).size : null;
            return {
                level_id: level.id,
                name: level.name,
                clo_count: count,
                // Include unmatched CLOs in the total. Do not average course-group percentages.
                percentage: data.bloom_reference_available && cloIds.size > 0
                    ? Math.round(count / cloIds.size * 10000) / 100 : null,
                courses: data.bloom_reference_available ? [...courses.get(level.id).values()] : [],
            };
        }),
    };
}
