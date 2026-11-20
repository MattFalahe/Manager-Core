<?php

declare(strict_types=1);

namespace Ecosystem\Checks;

use Ecosystem\Plugin;
use Ecosystem\Report;

/**
 * Loads every class and migration against real SeAT packages, on the PHP
 * version the job runs. php -l misses what only loading finds: a parent or
 * interface that is not there, a signature that does not match its parent,
 * a constant in a trait before PHP 8.2. Queued classes are checked for the
 * limits the queue needs while they are loaded.
 *
 * Options: --autoload=<vendor/autoload.php>, --also=<dir>[,<dir>] for more
 * plugins to map from source (Manager Core on a local run).
 */
final class ClassLoadCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        $autoload = $o['autoload'] ?? null;
        if (!is_string($autoload) || !is_file($autoload)) {
            $r->error('classes', null, null, 'Pass --autoload=<path to vendor/autoload.php> of a SeAT install.');

            return;
        }

        $maps = [];
        $dirs = array_merge([$p->dir], array_filter(explode(',', (string) ($o['also'] ?? ''))));
        foreach ($dirs as $dir) {
            $composer = json_decode((string) @file_get_contents("$dir/composer.json"), true) ?: [];
            foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $paths) {
                foreach ((array) $paths as $path) {
                    $maps[] = $prefix . '=' . rtrim($dir, '/') . '/' . trim($path, '/');
                }
            }
        }

        $entries = [];
        foreach ($this->declaredClasses($p, $r) as [$class, $file]) {
            $entries[] = ["class $class", $file];
        }
        $classCount = count($entries);
        foreach ($p->migrations as $file) {
            $entries[] = ['file ' . $p->path($file), $file];
        }

        $list = tempnam(sys_get_temp_dir(), 'eco');
        file_put_contents($list, implode("\n", array_column($entries, 0)) . "\n");

        $start = 0;
        $runs = 0;
        $jobs = 0;
        while ($start < count($entries) && $runs++ < count($entries) + 1) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../lib/load.php')
                . ' ' . escapeshellarg($autoload) . ' ' . escapeshellarg($list) . ' ' . $start;
            foreach ($maps as $map) {
                $cmd .= ' ' . escapeshellarg($map);
            }
            $out = [];
            exec($cmd . ' 2>&1', $out);

            if ($start === 0 && !preg_grep('/^(OK|ERR|FATAL|JOB) \d+/', $out)) {
                // Nothing loaded at all: the autoloader itself would not start.
                $r->error('classes', null, null, 'The loader could not start on PHP ' . PHP_VERSION . ': ' . trim(implode(' ', array_slice($out, 0, 4))));
                break;
            }
            $last = $start - 1;
            $fatal = false;
            foreach ($out as $line) {
                if (!preg_match('/^(OK|ERR|FATAL|JOB) (\d+) ?(.*)$/', $line, $m)) {
                    continue;
                }
                $n = (int) $m[2];
                [$what, $file] = $entries[$n];
                $name = substr($what, strpos($what, ' ') + 1);
                if ($m[1] === 'OK' || $m[1] === 'ERR') {
                    $last = max($last, $n);
                }
                if ($m[1] === 'ERR') {
                    $r->error('classes', $file, null, (str_starts_with($what, 'file') ? 'Migration' : $name) . ' does not load: ' . $m[3]);
                } elseif ($m[1] === 'FATAL') {
                    $r->error('classes', $file, null, (str_starts_with($what, 'file') ? 'Migration' : $name) . ' stops PHP when it loads: ' . $m[3]);
                    $last = $n;
                    $fatal = true;
                } elseif ($m[1] === 'JOB') {
                    $jobs++;
                    $r->error('jobs', $file, null, "$name: {$m[3]}.");
                }
            }
            if (!$fatal && $last < count($entries) - 1) {
                // Died without saying why: report the entry after the last one that answered.
                $n = $last + 1;
                [$what, $file] = $entries[$n];
                $r->error('classes', $file, null, substr($what, strpos($what, ' ') + 1) . ' stopped PHP with no message: ' . implode(' ', array_slice($out, -3)));
                $last = $n;
            }
            $start = $last + 1;
        }
        @unlink($list);

        $r->covered('classes', $classCount . ' classes and ' . count($p->migrations) . ' migrations on PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
        $r->covered('jobs', 'queued classes have $timeout under 960s, $tries and $backoff');
    }

    /**
     * Classes declared under the plugin's PSR-4 roots, checked against the
     * path the autoloader will look for them at.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function declaredClasses(Plugin $p, Report $r): array
    {
        $out = [];
        $roots = $p->psr4;
        uksort($roots, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($p->files as $file) {
            if (!str_ends_with($file, '.php') || str_ends_with($file, '.blade.php')) {
                continue;
            }
            $inRoot = false;
            foreach ($roots as $dir) {
                if ($dir === '' || str_starts_with($file, $dir . '/')) {
                    $inRoot = true;
                    break;
                }
            }
            if (!$inRoot || preg_match('~(^|/)migrations/~i', $file)) {
                continue;
            }

            foreach ($this->declarations($p->read($file)) as $class) {
                if (!$this->autoloadsFrom($class, $file, $roots, $p->files)) {
                    $r->error('classes', $file, null, "$class is declared here, but the autoloader looks for it at another path, so it never loads on a case-sensitive file system.");
                    continue;
                }
                $out[] = [$class, $file];
            }
        }

        return $out;
    }

    /** @return string[] fully qualified names declared in the file */
    private function declarations(string $code): array
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $u = $tokens[$j];
                    if (is_array($u) && in_array($u[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace .= $u[1];
                    } elseif ($u === ';' || $u === '{') {
                        break;
                    }
                }
                continue;
            }
            if (!in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }
            // Skip Foo::class and new class (...) {}.
            for ($k = $i - 1; $k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE; $k--);
            if ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            for ($j = $i + 1; $j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $out[] = ltrim($namespace . '\\' . $tokens[$j][1], '\\');
            }
        }

        return $out;
    }

    /** @param array<string, string> $roots longest prefix first */
    private function autoloadsFrom(string $class, string $file, array $roots, array $files): bool
    {
        foreach ($roots as $prefix => $dir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $expected = ltrim($dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php', '/');
            if ($expected === $file) {
                return true;
            }
        }

        return false;
    }
}
