<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Program;
use App\Support\SearchedTextHighlighter;
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

        $headlineOptions = 'StartSel=' . SearchedTextHighlighter::START_MARKER
            . ', StopSel=' . SearchedTextHighlighter::END_MARKER
            . ', MaxFragments=2, MinWords=5, MaxWords=25';

        $results = DB::table('course_material_chunks as c')
            ->join('course_material_files as f', 'f.course_material_file_id', '=', 'c.course_material_file_id')
            ->join('course_materials as m', 'm.course_material_id', '=', 'f.course_material_id')
            ->where('m.course_id', $course_id)
            ->whereRaw("c.content_tsv @@ plainto_tsquery('english', ?)", [$searchTerm])
            ->selectRaw("
                f.course_material_file_id as file_id,
                f.course_material_id,
                f.file_name,
                m.course_id,
                c.page_number,
                ts_rank(c.content_tsv, plainto_tsquery('english', ?)) as rank,
                ts_headline('english', c.content, plainto_tsquery('english', ?), ?) as snippet
            ", [$searchTerm, $searchTerm, $headlineOptions])
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

        $headlineOptions = 'StartSel=' . SearchedTextHighlighter::START_MARKER
            . ', StopSel=' . SearchedTextHighlighter::END_MARKER
            . ', MaxFragments=2, MinWords=5, MaxWords=25';

        $courseIds = DB::table('course_programs')
            ->where('program_id', $program_id)
            ->pluck('course_id');

        $results = DB::table('course_material_chunks as c')
            ->join('course_material_files as f', 'f.course_material_file_id', '=', 'c.course_material_file_id')
            ->join('course_materials as m', 'm.course_material_id', '=', 'f.course_material_id')
            ->whereIn('m.course_id', $courseIds)
            ->whereRaw("c.content_tsv @@ plainto_tsquery('english', ?)", [$searchTerm])
            ->selectRaw("
                f.course_material_file_id as file_id,
                f.course_material_id,
                f.file_name,
                m.course_id,
                c.page_number,
                ts_rank(c.content_tsv, plainto_tsquery('english', ?)) as rank,
                ts_headline('english', c.content, plainto_tsquery('english', ?), ?) as snippet
            ", [$searchTerm, $searchTerm, $headlineOptions])
            ->orderByDesc('rank')
            ->limit(20)
            ->get();

        return redirect()
            ->route('program.coverageAnalysis', ['program' => $program_id])
            ->with('search_results', $results)
            ->with('search_query', $searchTerm);
    }
}
