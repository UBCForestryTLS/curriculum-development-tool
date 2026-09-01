<?php

namespace App\Helpers;

use App\Models\Program;
use App\Models\ProgramLearningOutcome;
use Illuminate\Support\Collection;

class ProgramGapCoverage
{
    /**
     * Builds the raw course and CLO coverage data for each PLO in a program.
     */
    public static function analyze(Program $program): Collection
    {
        $rows = ProgramLearningOutcome::query()
            ->where('program_learning_outcomes.program_id', $program->program_id)
            ->leftJoin('outcome_maps', 'program_learning_outcomes.pl_outcome_id', '=', 'outcome_maps.pl_outcome_id')
            ->leftJoin('learning_outcomes', 'outcome_maps.l_outcome_id', '=', 'learning_outcomes.l_outcome_id')
            ->leftJoin('course_programs', function ($join) use ($program) {
                $join->on('learning_outcomes.course_id', '=', 'course_programs.course_id')
                    ->where('course_programs.program_id', $program->program_id);
            })
            ->leftJoin('courses', 'course_programs.course_id', '=', 'courses.course_id')
            ->leftJoin('mapping_scales', 'outcome_maps.map_scale_id', '=', 'mapping_scales.map_scale_id')
            ->select([
                'program_learning_outcomes.pl_outcome_id',
                'program_learning_outcomes.pl_outcome',
                'program_learning_outcomes.plo_shortphrase',
                'program_learning_outcomes.plo_category_id',
                'learning_outcomes.l_outcome_id',
                'learning_outcomes.l_outcome',
                'learning_outcomes.clo_shortphrase',
                'course_programs.id as program_course_id',
                'course_programs.course_required',
                'courses.course_id',
                'courses.course_code',
                'courses.course_num',
                'courses.course_title',
                'outcome_maps.map_scale_id',
                'mapping_scales.title as map_scale_title',
                'mapping_scales.abbreviation as map_scale_abbreviation',
                'mapping_scales.colour as map_scale_colour',
            ])
            ->orderBy('program_learning_outcomes.pl_outcome_id')
            ->orderBy('courses.course_code')
            ->orderBy('courses.course_num')
            ->get();

        return $rows->groupBy('pl_outcome_id')
            ->map(function (Collection $ploRows) {
                $plo = $ploRows->first();

                // Ignore mappings from courses that are not currently part of this program.
                $programRows = $ploRows->whereNotNull('program_course_id');
                $coveredRows = $programRows->filter(function ($row) {
                    return $row->map_scale_id !== null && (int) $row->map_scale_id !== 0;
                });

                $mappingScaleDistribution = $coveredRows
                    ->groupBy('map_scale_id')
                    ->map(function (Collection $scaleRows) {
                        $scale = $scaleRows->first();

                        return [
                            'map_scale_id' => (int) $scale->map_scale_id,
                            'title' => $scale->map_scale_title,
                            'abbreviation' => $scale->map_scale_abbreviation,
                            'colour' => $scale->map_scale_colour,
                            'clo_count' => $scaleRows->pluck('l_outcome_id')->unique()->count(),
                        ];
                    })
                    ->sortBy('map_scale_id')
                    ->values()
                    ->all();

                $courses = $coveredRows
                    ->groupBy('course_id')
                    ->map(function (Collection $courseRows) {
                        $course = $courseRows->first();

                        return [
                            'course_id' => (int) $course->course_id,
                            'course_code' => $course->course_code,
                            'course_num' => $course->course_num,
                            'course_title' => $course->course_title,
                            'course_required' => $course->course_required === null ? null : (bool) $course->course_required,
                            'learning_outcomes' => $courseRows->map(function ($row) {
                                return [
                                    'l_outcome_id' => (int) $row->l_outcome_id,
                                    'l_outcome' => $row->l_outcome,
                                    'clo_shortphrase' => $row->clo_shortphrase,
                                    'map_scale_id' => (int) $row->map_scale_id,
                                    'map_scale_title' => $row->map_scale_title,
                                    'map_scale_abbreviation' => $row->map_scale_abbreviation,
                                ];
                            })->values()->all(),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'pl_outcome_id' => (int) $plo->pl_outcome_id,
                    'pl_outcome' => $plo->pl_outcome,
                    'plo_shortphrase' => $plo->plo_shortphrase,
                    'plo_category_id' => $plo->plo_category_id === null ? null : (int) $plo->plo_category_id,
                    'mapped_clo_count' => $coveredRows->pluck('l_outcome_id')->unique()->count(),
                    'covering_course_count' => $coveredRows->pluck('course_id')->unique()->count(),
                    'required_course_count' => $coveredRows->where('course_required', 1)->pluck('course_id')->unique()->count(),
                    'non_required_course_count' => $coveredRows->where('course_required', 0)->pluck('course_id')->unique()->count(),
                    'n_a_clo_count' => $programRows->where('map_scale_id', 0)->pluck('l_outcome_id')->unique()->count(),
                    'mapping_scale_distribution' => $mappingScaleDistribution,
                    'courses' => $courses,
                ];
            })
            ->values();
    }
}
