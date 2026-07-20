<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Program;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoverageAnalysisController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('hasAccess');
    }

    public function course(Request $request, $course_id): View
    {
        $course = Course::where('course_id', $course_id)->firstOrFail();

        return view('courses.coverageAnalysis', compact('course'));
    }

    public function program(Request $request, $program_id): View
    {
        $program = Program::where('program_id', $program_id)->firstOrFail();

        $courses = $program->courses()->orderBy('course_code')->orderBy('course_num')->get();

        return view('programs.coverageAnalysis', compact('program', 'courses'));
    }

    public function searchCourse(Request $request, $course_id): View|RedirectResponse
    {
        $searchTerm = trim($request->input('query', ''));

        if ($searchTerm === '') {
            return redirect()->route('course.coverageAnalysis', ['course' => $course_id]);
        }

        $results = DB::table('course_material_chunks as c')
            ->join('course_material_files as f', 'f.course_material_file_id', '=', 'c.course_material_file_id')
            ->where('f.course_id', $course_id)
            ->whereRaw("c.content_tsv @@ plainto_tsquery('english', ?)", [$searchTerm])
            ->selectRaw("
                f.course_material_file_id as file_id,
                f.course_material_id,
                f.file_name,
                c.page_number,
                ts_rank(c.content_tsv, plainto_tsquery('english', ?)) as rank,
                ts_headline('english', c.content, plainto_tsquery('english', ?),
                    'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=5, MaxWords=25') as snippet
            ", [$searchTerm, $searchTerm])
            ->orderByDesc('rank')
            ->limit(20)
            ->get();

        return redirect()
            ->route('course.coverageAnalysis', ['course' => $course_id])
            ->with('search_results', $results)
            ->with('search_query', $searchTerm);
    }

    public function searchProgram(Request $request, $program_id): RedirectResponse
    {
        $searchTerm = trim($request->input('query', ''));

        if ($searchTerm === '') {
            return redirect()->route('program.coverageAnalysis', ['program' => $program_id]);
        }

        $courseIds = DB::table('course_programs')
            ->where('program_id', $program_id)
            ->pluck('course_id');

        $results = DB::table('course_material_chunks as c')
            ->join('course_material_files as f', 'f.course_material_file_id', '=', 'c.course_material_file_id')
            ->whereIn('f.course_id', $courseIds)
            ->whereRaw("c.content_tsv @@ plainto_tsquery('english', ?)", [$searchTerm])
            ->selectRaw("
                f.course_material_file_id as file_id,
                f.course_material_id,
                f.file_name,
                f.course_id,
                c.page_number,
                ts_rank(c.content_tsv, plainto_tsquery('english', ?)) as rank,
                ts_headline('english', c.content, plainto_tsquery('english', ?),
                    'StartSel=<mark>, StopSel=</mark>, MaxFragments=2, MinWords=5, MaxWords=25') as snippet
            ", [$searchTerm, $searchTerm])
            ->orderByDesc('rank')
            ->limit(20)
            ->get();

        return redirect()
            ->route('program.coverageAnalysis', ['program' => $program_id])
            ->with('search_results', $results)
            ->with('search_query', $searchTerm);
    }
}
