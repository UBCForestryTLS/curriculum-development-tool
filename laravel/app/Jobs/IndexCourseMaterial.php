<?php

namespace App\Jobs;

use App\Models\CourseMaterialChunk;
use App\Models\CourseMaterialFile;
use App\Models\CourseTopic;
use App\Models\SuggestedTopic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;


class IndexCourseMaterial implements ShouldQueue
{
    // 'Chunk' here currently means a whole page, but we can more finely index later

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public int $courseMaterialFileId;

    public function __construct(int $courseMaterialFileId)
    {
        $this->courseMaterialFileId = $courseMaterialFileId;
    }

    public function handle(): void
    {
        $file = CourseMaterialFile::find($this->courseMaterialFileId);

        if (!$file) {
            Log::error("IndexCourseMaterial: file {$this->courseMaterialFileId} not found, cancelling.");
            return;
        }

        $file->update(['status' => CourseMaterialFile::STATUS_INDEXING, 'error_message' => null]);

        try {
            $startTime = microtime(true);

            $absolutePath = Storage::disk('local')->path($file->file_path);
            $fileBytes = file_get_contents($absolutePath);

            $existingTopics = CourseTopic::where('course_id', $file->course_id)
                ->pluck('topic')
                ->all();

            $response = Http::timeout($this->timeout)
                ->post(config('services.text_extraction.base_url') . '/extract', [
                    'file' => base64_encode($fileBytes),
                    'ocr_enabled' => $file->ocr_enabled,
                    'extraction_engine' => $file->extraction_engine,
                    'ocr_threshold' => $file->ocr_threshold,
                    'material_type' => $file->courseMaterial?->type,
                    'existing_topics' => $existingTopics,
                ]);

            $response->throw();
            $data = $response->json();
            $pages = $data['pages'] ?? [];

            $file->update(['page_count' => $data['page_count'] ?? count($pages)]);

            $rows = [];
            foreach ($pages as $page) {
                if (!empty($page['content'])) {
                    $rows[] = $this->chunkDBRow($file, $page['page_number'], $page['content']);
                }
            }

            $this->saveTextChunks($file, $rows);

            $this->saveExtractedTopics($file, $data['topics'] ?? []);

            $processingTime = (int) round(microtime(true) - $startTime);
            $file->update(['processing_time_seconds' => $processingTime]);
        } catch (Throwable $exception) {
            Log::error("IndexCourseMaterial failed for file {$file->course_material_file_id}: " . $exception->getMessage());
            $file->update([
                'status' => CourseMaterialFile::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            throw $exception;
        }
    }

    private function chunkDBRow(CourseMaterialFile $file, int $pageNumber, string $text): array
    {
        return [
            'course_material_file_id' => $file->course_material_file_id,
            'page_number' => $pageNumber,
            'chunk_index' => 0,
            'content' => $text,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function saveTextChunks(CourseMaterialFile $file, array $rows): void
    {
        // The array can be really long for articles/textbooks,
        // so insert chunks of 100 at a time
        foreach (array_chunk($rows, 100) as $batch) {
            CourseMaterialChunk::insert($batch);
        }

        $file->update(['status' => CourseMaterialFile::STATUS_INDEXED]);
    }

    private function saveExtractedTopics(CourseMaterialFile $file, array $topics): void
    {
        // Delete non-rejected suggested topics from any previous extraction for this file
        $file->suggestedTopics()
            ->where('status', '!=', SuggestedTopic::STATUS_REJECTED)
            ->delete();

        $rejectedTopics = $file->suggestedTopics()
            ->where('status', SuggestedTopic::STATUS_REJECTED)
            ->pluck('topic')
            ->flip();

        $rows = [];
        foreach ($topics as $topic) {
            $text = trim($topic['topic'] ?? '');
            if ($text === '' || $rejectedTopics->has($text)) {
                continue;
            }
            $rows[] = [
                'course_material_file_id' => $file->course_material_file_id,
                'topic' => $text,
                'score' => $topic['score'] ?? null,
                'status' => SuggestedTopic::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        SuggestedTopic::insert($rows);
    }
}
