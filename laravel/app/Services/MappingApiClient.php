<?php

namespace App\Services;

use App\Exceptions\MappingApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MappingApiClient
{
    /** Save the job inputs. The caller supplies a stable job_id so creation can safely be repeated. */
    public function createJob(array $inputs): array
    {
        return $this->sendRequest('POST', '/jobs', ['json' => $inputs]);
    }

    /** Process one batch and return saved progress. The worker decides when to request another. */
    public function runBatch(string $jobId): array
    {
        return $this->sendRequest('POST', '/jobs/'.rawurlencode($jobId).'/run');
    }

    /** Read the current job status and counts without starting any processing. */
    public function getJob(string $jobId): array
    {
        return $this->sendRequest('GET', '/jobs/'.rawurlencode($jobId));
    }

    /** Read one page of saved outcomes. An empty page can still have a next_cursor to follow. */
    public function getResultsPage(string $jobId, ?string $cursor = null, int $limit = 50): array
    {
        $query = ['limit' => $limit];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        return $this->sendRequest('GET', '/jobs/'.rawurlencode($jobId).'/results', ['query' => $query]);
    }

    /** Create a new job for failed pairs only; reuse retryJobId if this request needs to be repeated. */
    public function createRetryJob(string $sourceJobId, string $retryJobId): array
    {
        return $this->sendRequest('POST', '/jobs/'.rawurlencode($sourceJobId).'/retry', [
            'json' => ['job_id' => $retryJobId],
        ]);
    }

    private function sendRequest(string $method, string $path, array $options = []): array
    {
        // Read Laravel's config so this also works when configuration is cached for a worker.
        $settings = config('services.lo_mapping_api');
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        $keyId = (string) ($settings['key_id'] ?? '');
        $keySecret = (string) ($settings['key_secret'] ?? '');
        $timeout = $settings['timeout'] ?? 35;
        $connectTimeout = $settings['connect_timeout'] ?? 5;
        if ($baseUrl === '' || trim($keyId) === '' || trim($keySecret) === '') {
            throw new MappingApiException('Mapping API URL and credentials must be configured.');
        }
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new MappingApiException('Mapping API timeouts must be greater than zero.');
        }

        // Credentials go in headers - allow plain HTTP only for a local development server 
        $url = parse_url($baseUrl);
        $localHttp = ($url['scheme'] ?? '') === 'http'
            && in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)
            || (($url['scheme'] ?? '') !== 'https' && !$localHttp)
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new MappingApiException('Mapping API URL must use HTTPS, or HTTP on localhost, without credentials, a query or a fragment.');
        }

        try {
    //send once. The worker will own retry limits and check progress after an uncertain response
            $response = Http::baseUrl(rtrim($baseUrl, '/'))
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-API-Key-ID' => $keyId, 'X-API-Key-Secret' => $keySecret])
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                // Don't forward the credentials if the configured endpoint redirects elsewhere.
                ->withoutRedirecting()
                ->send($method, $path, $options);
        } catch (ConnectionException) {
            throw new MappingApiException('Could not connect to the mapping API or the request timed out.', 'retry');
        }

        $data = $response->json();
        if (!$response->successful()) {
            /*
             * Tell the worker what to do next. This client does not retry the request itself.
             *
             * - 503 with detail.action: use the API's retry/stop instruction first. Our API
             *   uses 503 for both temporary failures and setup problems, such as missing AWS
             *   permissions. Retrying a setup problem won't fix it.
             * - 409 with action=wait: another batch holds the job lock. Wait and check progress
             *   before trying again, so we don't start overlapping work.
             * - Otherwise, retry 408 (request timeout), 429 (too many requests), 500 (server
             *   error), 502 (bad upstream response), 503 (service unavailable) and 504 (gateway
             *   timeout). These may be temporary, and gateway errors may not include our JSON.
             * - Stop automatic retries for other responses, including 401/403 (authentication
             *   or permission problems), 404 (not found), 422 (invalid inputs), and a 409 without
             *   wait (for example, a conflicting job ID). These need attention before resending.
             *
             * The worker will enforce limits and delays for retry/wait. A timeout doesn't prove
             * processing stopped, so it should check saved progress before sending another batch.
             */
            $status = $response->status();
            $apiAction = data_get($data, 'detail.action');
            $action = 'stop';
            if ($status === 503 && in_array($apiAction, ['retry', 'stop'], true)) {
                $action = $apiAction;
            } elseif ($status === 409 && $apiAction === 'wait') {
                $action = 'wait';
            } elseif (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
                $action = 'retry';
            }
            throw new MappingApiException("Mapping API request failed (HTTP {$status}).", $action, $status);
        }

        // A 2xx response must still be a job response, not an HTML page or unrelated JSON.
        if (!is_array($data) || !is_string($data['job_id'] ?? null)
            || !in_array($data['status'] ?? null, ['pending', 'in_progress', 'completed', 'failed'], true)) {
            throw new MappingApiException('Mapping API returned an invalid job response.', 'stop', $response->status());
        }

        return $data;
    }
}
