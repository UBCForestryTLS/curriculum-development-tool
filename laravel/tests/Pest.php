<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->browser()->timeout(20 * 1000); // 20 second timeout

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->group('ai-suggestions-e2e')
    ->beforeAll(function () {
        \Tests\Helpers\AiSuggestionsE2E\ensureLOMappingServiceReachable();
        startTestingDbServer();
    })
    ->beforeEach(fn() => clearDynamoDb())
    ->in('Browser/AiSuggestionsE2E');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Pest tests do run their own servers each but they're on dynamic ports,
 * and we need a fixed port for FastAPI to deliver the AI suggestions here.
 */
function startTestingDbServer(): void
{
    // So only one server starts for the whole test suite
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;

    $port = (int) (getenv('E2E_LARAVEL_PORT') ?: 8010);

    // We need to load env separately because
    // this process is separate from the pest tests
    $server = new \Symfony\Component\Process\Process(
        ['php', 'artisan', 'serve', '--port=' . $port],
        dirname(__DIR__),
        array_merge(getenv(), ['APP_ENV' => 'testing']),
    );
    $server->setTimeout(null);
    $server->disableOutput();
    $server->start();

    // Wait until the server accepts connections before any test runs.
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($conn !== false) {
            fclose($conn);
            break;
        }
        usleep(200_000);
    }

    register_shutdown_function(fn() => $server->isRunning() && $server->stop(5));
}
