<?php

// Precheck for `composer test`. Verifies the test database is reachable and
// prints the exact command to create it on failure. Saves a confusing
// SQLSTATE error when the test DB has not been initialized.

$envFile = __DIR__ . '/../.env.testing';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Missing laravel/.env.testing. Copy .env.testing.example and edit.\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, " \t\"'");
}

$required = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'];
foreach ($required as $key) {
    if (!array_key_exists($key, $env)) {
        fwrite(STDERR, "Missing $key in .env.testing.\n");
        exit(1);
    }
}

$dsn = sprintf(
    '%s:host=%s;port=%s;dbname=%s',
    $env['DB_CONNECTION'],
    $env['DB_HOST'],
    $env['DB_PORT'],
    $env['DB_DATABASE']
);

try {
    new PDO($dsn, $env['DB_USERNAME'], $env['DB_PASSWORD'] ?? '');
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'does not exist')) {
        $db = $env['DB_DATABASE'];
        $user = $env['DB_USERNAME'];
        $host = $env['DB_HOST'];
        $cmd = match ($env['DB_CONNECTION']) {
            'pgsql' => "psql -U $user -h $host -c \"CREATE DATABASE $db;\"",
            'mysql' => "mysql -u $user -h $host -p -e \"CREATE DATABASE $db;\"",
            default => "Create database \"$db\" on $host using your DB client.",
        };
        fwrite(STDERR, "\nTest database \"$db\" does not exist on {$host}:{$env['DB_PORT']}.\n");
        fwrite(STDERR, "Create it with:\n  $cmd\n\n");
        exit(1);
    }
    fwrite(STDERR, "Cannot connect to test database: {$e->getMessage()}\n");
    exit(1);
}
