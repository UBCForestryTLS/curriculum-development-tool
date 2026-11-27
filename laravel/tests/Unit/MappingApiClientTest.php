<?php

namespace Tests\Unit;

use App\Exceptions\MappingApiException;
use App\Services\MappingApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MappingApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use fake credentials and block any request we haven't faked. These tests don't contact AWS.
        config(['services.lo_mapping_api' => [
            'base_url' => 'https://mapping.example.test/dev/',
            'key_id' => 'test-key',
            'key_secret' => 'test-secret',
            'timeout' => 34,
            'connect_timeout' => 4,
        ]]);
        Http::preventStrayRequests();
    }

    #[DataProvider('jobRequests')]
    public function test_job_operations_send_one_authenticated_request(
        string $operation, array $arguments, string $method, string $path, array $body,
    ): void
    {
        $response = ['job_id' => 'job-1', 'status' => 'pending'];
        Http::fake(function (Request $request, array $options) use ($response) {
            $this->assertSame(34, $options['timeout']);
            $this->assertSame(4, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response($response);
        });

        $this->assertSame($response, (new MappingApiClient)->{$operation}(...$arguments));

        // Keep the stage path and send the credentials in headers, not in the URL or body.
        Http::assertSent(fn (Request $request) => $request->method() === $method
            && $request->url() === 'https://mapping.example.test/dev'.$path
            && $request->hasHeader('X-API-Key-ID', 'test-key')
            && $request->hasHeader('X-API-Key-Secret', 'test-secret')
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === $body);
        Http::assertSentCount(1);
    }

    public static function jobRequests(): array
    {
        $inputs = [
            'job_id' => 'job-1',
            'course_learning_outcomes' => [['l_outcome_id' => 21, 'l_outcome' => 'Apply ecological principles.']],
        ];

        return [
            'create job' => ['createJob', [$inputs], 'POST', '/jobs', $inputs],
            'run batch' => ['runBatch', ['job-1'], 'POST', '/jobs/job-1/run', []],
            'read progress' => ['getJob', ['job-1'], 'GET', '/jobs/job-1', []],
            'retry failed pairs' => ['createRetryJob', ['job-1', 'retry-1'], 'POST', '/jobs/job-1/retry', ['job_id' => 'retry-1']],
        ];
    }

    public function test_results_preserve_empty_pages_and_pass_the_cursor_back_unchanged(): void
    {
        $cursor = 'opaque+/=';
        $page = ['job_id' => 'job-1', 'status' => 'in_progress', 'results' => [], 'next_cursor' => $cursor];
        $lastPage = array_replace($page, [
            'results' => [[
                'clo_id' => 21, 'plo_id' => 31, 'processed' => true,
                'mapping_scales' => [2], 'explanation' => 'Both apply ecological principles.', 'error' => null,
            ]],
            'next_cursor' => null,
        ]);
        Http::fakeSequence()->push($page)->push($lastPage);
        $client = new MappingApiClient;

        // An empty page doesn't mean we're done. The caller can still follow its cursor.
        $this->assertSame($page, $client->getResultsPage('job-1'));
        $this->assertSame($lastPage, $client->getResultsPage('job-1', $cursor, 2));

        Http::assertSentInOrder([
            fn (Request $request) => $request->method() === 'GET'
                && $request->url() === 'https://mapping.example.test/dev/jobs/job-1/results?limit=50'
                && $request->hasHeader('X-API-Key-ID', 'test-key')
                && $request->hasHeader('X-API-Key-Secret', 'test-secret'),
            fn (Request $request) => $request['cursor'] === $cursor && (int) $request['limit'] === 2,
        ]);
        Http::assertSentCount(2);
    }

    #[DataProvider('failedResponses')]
    public function test_failed_responses_report_the_action_without_retrying(int $status, array|string $body, string $action): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        try {
            (new MappingApiClient)->runBatch('job-1');
            $this->fail('Expected the client to reject this response.');
        } catch (MappingApiException $exception) {
            $this->assertSame($action, $exception->action);
            $this->assertSame($status, $exception->httpStatus);
        }
        Http::assertSentCount(1);
    }

    public static function failedResponses(): array
    {
        return [
            // Both failures use 503, so the API's action needs to take priority.
            'temporary service failure' => [503, ['detail' => ['action' => 'retry']], 'retry'],
            'permanent setup failure' => [503, ['detail' => ['action' => 'stop']], 'stop'],
            'batch lock held' => [409, ['detail' => ['action' => 'wait']], 'wait'],
            'conflicting job' => [409, ['detail' => 'Job inputs differ.'], 'stop'],
            'unauthenticated' => [401, [], 'stop'],
            'forbidden' => [403, [], 'stop'],
            'missing job' => [404, [], 'stop'],
            'invalid inputs' => [422, [], 'stop'],
            // A gateway failure can arrive without the Python API's JSON error format.
            'request timeout' => [408, 'Request timed out', 'retry'],
            'rate limited' => [429, 'Too many requests', 'retry'],
            'server error' => [500, 'Internal server error', 'retry'],
            'bad gateway' => [502, 'Bad gateway', 'retry'],
            'unavailable' => [503, 'Service unavailable', 'retry'],
            'gateway timeout' => [504, 'Gateway timeout', 'retry'],
            'redirect' => [302, '', 'stop'],
            'HTML success response' => [200, '<html>Not the API</html>', 'stop'],
            'missing job ID' => [200, ['status' => 'pending'], 'stop'],
            'invalid job status' => [200, ['job_id' => 'job-1', 'status' => 'unknown'], 'stop'],
        ];
    }

    public function test_connection_failure_is_retryable_without_exposing_the_original_exception(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection failed with test-secret')]);

        try {
            (new MappingApiClient)->runBatch('job-1');
            $this->fail('Expected a connection failure.');
        } catch (MappingApiException $exception) {
            $this->assertSame('retry', $exception->action);
            $this->assertNull($exception->httpStatus);
            // The original HTTP exception can contain the request and its credentials.
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('test-secret', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_configuration_stops_before_sending_credentials(string $setting, mixed $value): void
    {
        config(['services.lo_mapping_api.'.$setting => $value]);
        Http::fake();

        try {
            (new MappingApiClient)->getJob('job-1');
            $this->fail('Expected invalid configuration to be rejected.');
        } catch (MappingApiException $exception) {
            $this->assertSame('stop', $exception->action);
            $this->assertNull($exception->httpStatus);
        }
        Http::assertNothingSent();
    }

    public static function invalidSettings(): array
    {
        return [
            'missing URL' => ['base_url', null],
            'missing key ID' => ['key_id', null],
            'blank secret' => ['key_secret', '   '],
            'insecure remote URL' => ['base_url', 'http://mapping.example.test'],
            'URL with credentials' => ['base_url', 'https://user:secret@mapping.example.test'],
            'invalid request timeout' => ['timeout', 0],
            'invalid connection timeout' => ['connect_timeout', -1],
        ];
    }
}
