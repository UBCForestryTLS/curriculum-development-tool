<?php

namespace App\Http\Controllers;

use App\Jobs\IndexCourseMaterial;
use App\Models\CourseMaterialFile;
use App\Models\CourseTopic;
use App\Models\SuggestedTopic;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\PdfPageRenderer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseMaterialFileController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('hasAccess');
    }

    public function store(Request $request, $course_id, $material_id): RedirectResponse
    {
        $this->assertIsEditor((int) $course_id);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:51200'],
            'ocr_enabled' => ['sometimes', 'boolean'],
            'extraction_engine' => ['required_if:ocr_enabled,1', 'in:tesseract,textract'],
            'ocr_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);

        $uploaded = $request->file('file');
        $diskPath = 'course-materials/' . $course_id . '/' . Str::uuid()->toString() . '.pdf';

        Storage::disk('local')->put($diskPath, file_get_contents($uploaded->getRealPath()));

        $file = CourseMaterialFile::create([
            'course_material_id' => $material_id,
            'course_id' => $course_id,
            'uploaded_by' => Auth::id(),
            'file_name' => $uploaded->getClientOriginalName(),
            'file_path' => $diskPath,
            'file_size' => $uploaded->getSize(),
            'status' => CourseMaterialFile::STATUS_PENDING,
            'extraction_engine' => $request->input('extraction_engine', 'tesseract'),
            'ocr_enabled' => $request->boolean('ocr_enabled'),
            'ocr_threshold' => $request->boolean('ocr_enabled') ? (int) $request->input('ocr_threshold', 0) : 0,
        ]);

        IndexCourseMaterial::dispatch($file->course_material_file_id);

        return redirect()
            ->route('courseWizard.step10', ['course' => $course_id])
            ->with('success', 'File uploaded. Indexing in the background.');
    }

    public function destroy(Request $request, $course_id, $material_id, $file_id): RedirectResponse
    {
        $this->assertIsEditor((int) $course_id);

        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        if (Storage::disk('local')->exists($file->file_path)) {
            Storage::disk('local')->delete($file->file_path);
        }
        $file->delete();

        return redirect()
            ->route('courseWizard.step10', ['course' => $course_id])
            ->with('success', 'File deleted.');
    }

    public function refresh(Request $request, $course_id, $material_id, $file_id): RedirectResponse
    {
        $this->assertIsEditor((int) $course_id);

        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        $file->update([
            'status' => CourseMaterialFile::STATUS_PENDING,
            'error_message' => null,
            'processing_time_seconds' => null,
        ]);

        $refreshingTopicsOnly = $file->chunks()->exists();
        IndexCourseMaterial::dispatch($file->course_material_file_id, $refreshingTopicsOnly);

        return redirect()
            ->back()
            ->with('success', $refreshingTopicsOnly ? 'Refreshing topics.' : 'Refreshing extracted text and topics.');
    }

    public function download($course_id, $material_id, $file_id): StreamedResponse
    {
        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        abort_unless(Storage::disk('local')->exists($file->file_path), 404);

        return Storage::disk('local')->download($file->file_path, $file->file_name);
    }

    public function view($course_id, $material_id, $file_id): StreamedResponse
    {
        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        abort_unless(Storage::disk('local')->exists($file->file_path), 404);

        return Storage::disk('local')->response($file->file_path, $file->file_name);
    }

    public function thumbnail(Request $request, $course_id, $material_id, $file_id): Response
    {
        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        $absolutePath = Storage::disk('local')->path($file->file_path);
        abort_unless(file_exists($absolutePath), 404);

        $page = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:' . $file->page_count]])['page'] ?? 1;

        $pngPath = PdfPageRenderer::pdfToImage($absolutePath, $page, 96);
        try {
            return response(file_get_contents($pngPath), 200)
                ->header('Content-Type', 'image/png');
        } finally {
            @unlink($pngPath);
        }
    }

    public function show($course_id, $material_id, $file_id)
    {
        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->with([
                'courseMaterial',
                'uploader',
                'chunks' => fn($q) => $q->orderBy('page_number')->orderBy('chunk_index'),
                'topics' => fn($q) => $q->orderBy('position'),
            ])
            ->firstOrFail();

        $courseTopics = CourseTopic::where('course_id', $course_id)
            ->orderBy('position')
            ->get();

        $fileTopicTexts = $file->topics->pluck('topic')->map(fn($t) => strtolower($t))->all();

        $suggestedTopics = $file->suggestedTopics()
            ->where('status', SuggestedTopic::STATUS_PENDING)
            ->orderBy('created_at')
            ->get()
            // TODO: Should this be filtered out somewhere else instead?
            ->filter(fn($s) => !in_array(strtolower($s->topic), $fileTopicTexts));

        return view('courses.material_file', [
            'file'            => $file,
            'course_id'       => $course_id,
            'material_id'     => $material_id,
            'courseTopics'    => $courseTopics,
            'suggestedTopics' => $suggestedTopics,
        ]);
    }

    public function updateTopics(Request $request, $course_id, $material_id, $file_id)
    {
        $this->assertIsEditor((int) $course_id);

        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        $request->validate([
            'topic_ids' => 'required|array',
            'topic_ids.*' => 'required|integer|exists:course_topics,course_topic_id',
        ]);

        $topicIds = $request->input('topic_ids', []);

        // Verify all topic IDs belong to this course
        $validCount = CourseTopic::where('course_id', $course_id)
            ->whereIn('course_topic_id', $topicIds)
            ->count();

        if ($validCount !== count($topicIds)) {
            return redirect()->back()->with('error', 'One or more selected topics do not belong to this course.');
        }

        $file->topics()->sync($topicIds);

        return redirect()->back()->with('success', 'Topics updated successfully.');
    }


    public function reviewTopics(Request $request, $course_id, $material_id, $file_id)
    {
        $this->assertIsEditor((int) $course_id);

        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        $request->validate([
            'decisions' => 'required|array|min:1',
            'decisions.*.id' => 'required|integer|exists:suggested_topics,suggested_topic_id',
            'decisions.*.action' => 'required|in:confirm,reject',
        ]);

        $decisions = $request->input('decisions');
        $confirmIds = array_column(array_filter($decisions, fn($d) => $d['action'] === 'confirm'), 'id');
        $rejectIds  = array_column(array_filter($decisions, fn($d) => $d['action'] === 'reject'), 'id');

        DB::transaction(function () use ($file, $confirmIds, $rejectIds) {
            if ($confirmIds) {
                $topicTexts = SuggestedTopic::where('course_material_file_id', $file->course_material_file_id)
                    ->where('suggested_topic_id', $confirmIds)
                    ->where('status', SuggestedTopic::STATUS_PENDING)
                    ->pluck('topic')
                    ->all();

                $this->applyTopicDecisions($file, $topicTexts);
            }

            if ($rejectIds) {
                SuggestedTopic::where('course_material_file_id', $file->course_material_file_id)
                    ->where('suggested_topic_id', $rejectIds)
                    ->where('status', SuggestedTopic::STATUS_PENDING)
                    ->update(['status' => SuggestedTopic::STATUS_REJECTED]);
            }
        });

        return redirect()->back()->with('success', 'Topics reviewed.');
    }

    public function acceptAllTopics(Request $request, $course_id, $material_id, $file_id)
    {
        $this->assertIsEditor((int) $course_id);

        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        DB::transaction(function () use ($file) {
            $topicTexts = $file->suggestedTopics()
                ->where('status', SuggestedTopic::STATUS_PENDING)
                ->pluck('topic')
                ->all();

            $this->applyTopicDecisions($file, $topicTexts);
        });

        return redirect()->back()->with('success', 'All suggested topics accepted.');
    }

    public function rejectAllTopics(Request $request, $course_id, $material_id, $file_id)
    {
        $this->assertIsEditor((int) $course_id);

        $file = CourseMaterialFile::where('course_material_file_id', $file_id)
            ->where('course_material_id', $material_id)
            ->where('course_id', $course_id)
            ->firstOrFail();

        $file->suggestedTopics()
            ->where('status', SuggestedTopic::STATUS_PENDING)
            ->update(['status' => SuggestedTopic::STATUS_REJECTED]);

        return redirect()->back()->with('success', 'All suggested topics rejected.');
    }

    private function applyTopicDecisions(CourseMaterialFile $file, array $topicTexts): void
    {
        if (empty($topicTexts)) {
            return;
        }

        $originalByLower = [];
        foreach ($topicTexts as $text) {
            $key = strtolower($text);
            if (!isset($originalByLower[$key])) {
                $originalByLower[$key] = $text;
            }
        }
        $lowerKeys = array_keys($originalByLower);

        $existingTopics = CourseTopic::where('course_id', $file->course_id)
            ->whereIn(DB::raw('LOWER(topic)'), $lowerKeys)
            ->get()
            ->keyBy(fn($t) => strtolower($t->topic));

        $existingIds = [];
        $newNames = [];

        foreach ($lowerKeys as $key) {
            if ($existingTopics->has($key)) {
                $existingIds[] = $existingTopics->get($key)->course_topic_id;
            } else {
                $newNames[] = $originalByLower[$key];
            }
        }

        $newIds = [];
        if ($newNames) {
            $maxPosition = CourseTopic::where('course_id', $file->course_id)->max('position') ?? 0;

            $rows = array_map(
                fn($i, $name) => [
                    'course_id'  => $file->course_id,
                    'topic'      => $name,
                    'position'   => $maxPosition + $i + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                range(0, count($newNames) - 1),
                $newNames
            );

            DB::table('course_topics')->insert($rows);

            $newLowerNames = array_map('strtolower', $newNames);
            $newIds = CourseTopic::where('course_id', $file->course_id)
                ->whereIn(DB::raw('LOWER(topic)'), $newLowerNames)
                ->pluck('course_topic_id')
                ->all();
        }

        $allTopicIds = array_unique(array_merge($existingIds, $newIds));

        if ($allTopicIds) {
            $file->topics()->syncWithoutDetaching($allTopicIds);
        }

        SuggestedTopic::where('course_material_file_id', $file->course_material_file_id)
            ->where('status', SuggestedTopic::STATUS_PENDING)
            ->whereIn(DB::raw('LOWER(topic)'), $lowerKeys)
            ->delete();
    }


    private function assertIsEditor(int $course_id): void
    {
        $permission = User::find(Auth::id())?->effectivePermissionForCourse($course_id) ?? 0;
        abort_unless(in_array($permission, [1, 2], true), 403, 'Editor access required.');
    }
}
