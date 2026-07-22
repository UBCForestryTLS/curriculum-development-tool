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
    public bool $refreshingTopicsOnly; // Only refreshing keyword/matched topics, not re-indexing text

    public function __construct(int $courseMaterialFileId, bool $refreshingTopicsOnly = false)
    {
        $this->courseMaterialFileId = $courseMaterialFileId;
        $this->refreshingTopicsOnly = $refreshingTopicsOnly;
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

            $existingTopics = CourseTopic::where('course_id', $file->course_id)
                ->pluck('topic')
                ->all();

            if ($this->refreshingTopicsOnly) {
                $pages = $file->chunks()
                    ->orderBy('page_number')
                    ->get()
                    ->map(fn($chunk) => ['page_number' => $chunk->page_number, 'content' => $chunk->content])
                    ->all();

                $response = Http::timeout($this->timeout)
                    ->post(config('services.text_extraction.base_url') . '/refresh-topics', [
                        'pages' => $pages,
                        'material_type' => $file->courseMaterial?->type,
                        'existing_topics' => $existingTopics,
                    ]);
            } else {
                $absolutePath = Storage::disk('local')->path($file->file_path);
                $fileBytes = file_get_contents($absolutePath);

                $response = Http::timeout($this->timeout)
                    ->post(config('services.text_extraction.base_url') . '/extract', [
                        'file' => base64_encode($fileBytes),
                        'ocr_enabled' => $file->ocr_enabled,
                        'extraction_engine' => $file->extraction_engine,
                        'ocr_threshold' => $file->ocr_threshold,
                        'material_type' => $file->courseMaterial?->type,
                        'existing_topics' => $existingTopics,
                    ]);
            }

            $response->throw();
            $data = $response->json();

            if (!$this->refreshingTopicsOnly) {
                $pages = $data['pages'] ?? [];
                $file->update(['page_count' => $data['page_count'] ?? count($pages)]);

                $rows = [];
                foreach ($pages as $page) {
                    if (!empty($page['content'])) {
                        $rows[] = $this->chunkDBRow($file, $page['page_number'], $page['content']);
                    }
                }

                $this->saveTextChunks($file, $rows);
            }

            $this->saveExtractedTopics($file, $data['topics'] ?? [], $this->refreshingTopicsOnly);

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

    private function saveExtractedTopics(CourseMaterialFile $file, array $topics, bool $refreshingTopicsOnly = false): void
    {
        if ($refreshingTopicsOnly) {
            // Only delete topics that can be re-extracted (keyword and match)
            $file->suggestedTopics()
                ->where('status', '!=', SuggestedTopic::STATUS_REJECTED)
                ->whereIn('source', ['keyword', 'match'])
                ->delete();
        } else {
            // Full extraction: delete all non-rejected topics
            $file->suggestedTopics()
                ->where('status', '!=', SuggestedTopic::STATUS_REJECTED)
                ->delete();
        }

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
                'source' => $topic['source'] ?? 'keyword',
                'status' => SuggestedTopic::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        SuggestedTopic::insert($rows);
    }
}
