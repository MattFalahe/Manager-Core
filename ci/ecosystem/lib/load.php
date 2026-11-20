<?php

/*
 * Child process of ClassLoadCheck. Loads one class or migration file per line
 * of the list, starting at a given line, and reports each on stdout:
 *
 *   OK <n>            loaded
 *   ERR <n> <text>    threw while loading
 *   FATAL <n> <text>  stopped PHP; the parent starts a new child after it
 *   JOB <n> <text>    a queued class whose limits break the rule
 *
 * argv: autoload.php, list file, start line, then prefix=dir pairs to map.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

[, $autoload, $listFile, $start] = $argv;
$maps = array_slice($argv, 4);

require $autoload;

// A loader of its own, asked first, so the source under test wins over any
// copy of the same plugin already in the vendor tree.
$mine = new \Composer\Autoload\ClassLoader();
foreach ($maps as $map) {
    [$prefix, $dir] = explode('=', $map, 2);
    $mine->addPsr4($prefix, $dir);
}
$mine->register(true);

$lines = file($listFile, FILE_IGNORE_NEW_LINES);
$current = null;

register_shutdown_function(function () use (&$current) {
    $error = error_get_last();
    if ($current !== null && $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "FATAL $current " . str_replace("\n", ' ', $error['message']) . "\n";
    }
});

const RETRY_AFTER = 960;

for ($n = (int) $start; $n < count($lines); $n++) {
    $current = $n;
    [$kind, $target] = explode(' ', $lines[$n], 2);
    try {
        if ($kind === 'file') {
            require_once $target;
            echo "OK $n\n";
            continue;
        }
        if (!class_exists($target) && !interface_exists($target) && !trait_exists($target) && !enum_exists($target)) {
            echo "ERR $n the autoloader did not find it\n";
            continue;
        }
        echo "OK $n\n";
        jobLimits($n, $target);
    } catch (\Throwable $e) {
        echo "ERR $n " . get_class($e) . ': ' . str_replace("\n", ' ', $e->getMessage()) . "\n";
    }
}
$current = null;

function jobLimits(int $n, string $class): void
{
    if (!class_exists($class) || !interface_exists('Illuminate\Contracts\Queue\ShouldQueue')) {
        return;
    }
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract() || !$rc->implementsInterface('Illuminate\Contracts\Queue\ShouldQueue')) {
        return;
    }
    $defaults = $rc->getDefaultProperties();
    $problems = [];

    $timeout = $defaults['timeout'] ?? null;
    if (!is_int($timeout) || $timeout <= 0) {
        $problems[] = 'no $timeout, so a stuck run holds its worker until the queue retries it at ' . RETRY_AFTER . 's and runs it twice';
    } elseif ($timeout >= RETRY_AFTER) {
        $problems[] = "\$timeout is {$timeout}s, not under the queue's retry_after of " . RETRY_AFTER . 's, so a slow run is started again while it still runs';
    }
    if (($defaults['tries'] ?? null) === null && !$rc->hasMethod('retryUntil')) {
        $problems[] = 'no $tries';
    }
    if (($defaults['backoff'] ?? null) === null && !$rc->hasMethod('backoff')) {
        $problems[] = 'no $backoff';
    }
    if ($problems) {
        echo "JOB $n " . implode('; ', $problems) . "\n";
    }
}
