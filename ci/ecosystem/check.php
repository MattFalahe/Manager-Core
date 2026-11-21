<?php

/*
 * Ecosystem checks for SeAT plugins, run by .github/workflows/ecosystem.yml.
 *
 *   php check.php plan     <plugin-dir>
 *   php check.php static   <plugin-dir> [--since=<commit the push started from>]
 *   php check.php classes  <plugin-dir> --autoload=<seat-dir>/vendor/autoload.php
 *   php check.php runtime  <plugin-dir> --seat=<seat-dir> --mc=<absent|self|dev|x.y.z>
 *   php check.php rerun    <plugin-dir> --seat=<seat-dir> [--since=<commit>]
 *   php check.php cross    <plugin-dir> [--manifests=<dir>]
 *
 * Needs plain PHP 8.1 and nothing else, so it runs before composer does.
 * README.md next to this file describes each check and the manifest.
 */

declare(strict_types=1);

error_reporting(E_ALL);

require __DIR__ . '/lib/Report.php';
require __DIR__ . '/lib/Plugin.php';
require __DIR__ . '/lib/Source.php';
require __DIR__ . '/lib/Blade.php';
foreach (glob(__DIR__ . '/checks/*.php') as $file) {
    require $file;
}

use Ecosystem\Plugin;
use Ecosystem\Report;

$args = array_slice($argv, 1);
$options = [];
$positional = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif (str_starts_with($arg, '--')) {
        $options[substr($arg, 2)] = true;
    } else {
        $positional[] = $arg;
    }
}

[$suite, $dir] = $positional + [null, null];
if (!$suite || !$dir || !is_dir($dir)) {
    fwrite(STDERR, "usage: php check.php <plan|static|classes|runtime|rerun|cross> <plugin-dir> [--seat=dir] [--mc=variant]\n");
    exit(2);
}

$report = new Report($suite);
$plugin = Plugin::load($dir, $report, isset($options['since']) ? (string) $options['since'] : null);

if ($suite === 'plan') {
    // Read by the workflow, so it prints outputs and nothing else.
    // With no manifest yet, the install still runs beside Manager Core and without it.
    $variants = $plugin->manifest === null ? ['absent', 'dev'] : $plugin->mcVariants();
    echo 'mc-variants=' . json_encode($variants) . "\n";
    echo 'package=' . $plugin->package . "\n";
    echo 'relation=' . ($plugin->relation() ?: 'unknown') . "\n";
    echo 'released=' . ($plugin->lastTag ? 'true' : 'false') . "\n";
    exit(0);
}

$suites = [
    'static' => [
        Ecosystem\Checks\ManifestCheck::class,
        Ecosystem\Checks\BladeCheck::class,
        Ecosystem\Checks\MigrationCheck::class,
        Ecosystem\Checks\FrozenMigrationCheck::class,
        Ecosystem\Checks\CommentCheck::class,
        Ecosystem\Checks\CdnCheck::class,
        Ecosystem\Checks\ModalCheck::class,
        Ecosystem\Checks\ManifestSourceCheck::class,
    ],
    'classes' => [Ecosystem\Checks\ClassLoadCheck::class],
    'runtime' => [Ecosystem\Checks\RuntimeCheck::class],
    'rerun' => [Ecosystem\Checks\RerunCheck::class],
    'cross' => [Ecosystem\Checks\CrossPluginCheck::class],
];

if (!isset($suites[$suite])) {
    fwrite(STDERR, "unknown suite '$suite'\n");
    exit(2);
}

if ($plugin->manifest === null && $suite !== 'static') {
    // The static suite reports the missing manifest; the others have nothing to go on.
    $report->error('manifest', 'ecosystem.json', null, 'No ecosystem.json, so this suite cannot run.');
} else {
    foreach ($suites[$suite] as $class) {
        (new $class())->run($plugin, $report, $options);
    }
}

exit($report->finish());
