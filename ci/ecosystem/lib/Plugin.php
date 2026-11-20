<?php

declare(strict_types=1);

namespace Ecosystem;

/**
 * What the checks need to know about the plugin under test, read once.
 */
final class Plugin
{
    public string $dir;
    public array $composer = [];
    public ?array $manifest = null;
    public string $package = '';
    /** @var array<string, string> namespace prefix => directory relative to the plugin */
    public array $psr4 = [];
    public ?string $lastTag = null;
    /** @var string[] relative paths of every migration file */
    public array $migrations = [];
    /**
     * Migrations the naming rules apply to: every one before the first
     * release, and after it only those this push adds, since a pushed one has
     * been pulled by the servers that follow the work branch.
     *
     * @var string[]
     */
    public array $newMigrations = [];
    /** Commit the push started from, when the workflow knows it. */
    public ?string $since = null;
    /** @var string[] relative paths of every file in the repository */
    public array $files = [];

    public static function load(string $dir, Report $report, ?string $since = null): self
    {
        $p = new self();
        $p->dir = rtrim(str_replace('\\', '/', realpath($dir)), '/');

        $composer = $p->dir . '/composer.json';
        $p->composer = is_file($composer) ? (json_decode((string) file_get_contents($composer), true) ?: []) : [];
        $p->package = (string) ($p->composer['name'] ?? '');
        foreach ($p->composer['autoload']['psr-4'] ?? [] as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $p->psr4[$prefix] = trim(str_replace('\\', '/', $path), '/');
            }
        }

        $manifest = $p->dir . '/ecosystem.json';
        if (is_file($manifest)) {
            $decoded = json_decode((string) file_get_contents($manifest), true);
            if (is_array($decoded)) {
                $p->manifest = $decoded;
            } else {
                $report->error('manifest', 'ecosystem.json', null, 'ecosystem.json is not valid JSON: ' . json_last_error_msg());
            }
        }

        $p->files = $p->listFiles();
        $p->lastTag = $p->findLastTag();

        foreach ($p->files as $file) {
            if (preg_match('~(^|/)migrations/[^/]+\.php$~i', $file)) {
                $p->migrations[] = $file;
            }
        }
        sort($p->migrations);

        if ($since !== null && preg_match('/^[0-9a-f]{7,40}$/', $since) && trim($since, '0') !== '' && $p->git('cat-file', '-e', $since . '^{commit}') !== null) {
            $p->since = $since;
        }
        if (!$p->lastTag) {
            $p->newMigrations = $p->migrations;
        } elseif ($p->since) {
            $before = $p->filesAtTag($p->since);
            $p->newMigrations = array_values(array_filter($p->migrations, fn ($m) => !in_array($m, $before, true)));
        }

        return $p;
    }

    public function key(): string
    {
        return (string) ($this->manifest['plugin'] ?? '');
    }

    public function relation(): string
    {
        return (string) ($this->manifest['manager_core']['relation'] ?? '');
    }

    public function minimumMc(): ?string
    {
        $min = $this->manifest['manager_core']['minimum'] ?? null;

        return is_string($min) ? $min : null;
    }

    /** @return string[] the Manager Core installs to test against */
    public function mcVariants(): array
    {
        $min = $this->minimumMc();

        return match ($this->relation()) {
            'self' => ['self'],
            'required' => array_values(array_unique(array_filter([$min, 'dev']))),
            'optional' => array_values(array_unique(array_filter(['absent', $min, 'dev']))),
            // No link to Manager Core, but it must still install beside it.
            default => ['absent', 'dev'],
        };
    }

    /** @return string[] */
    public function manifestList(string ...$path): array
    {
        $value = $this->manifest;
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * What the plugin provides or subscribes to only while another plugin is
     * installed, from when_installed: plugin key => list.
     *
     * @param 'provides'|'subscribes' $what
     */
    public static function conditional(?array $manifest, string $what): array
    {
        $out = [];
        foreach ((array) ($manifest['when_installed'] ?? []) as $plugin => $section) {
            $list = is_array($section) ? ($section[$what] ?? []) : [];
            $out[(string) $plugin] = is_array($list) ? array_values($list) : [];
        }

        return $out;
    }

    /**
     * Every capability or subscription a manifest names, its own and those it
     * makes only with another plugin installed.
     *
     * @param 'provides'|'subscribes' $what
     */
    public static function everything(?array $manifest, string $what): array
    {
        $own = $what === 'provides' ? ($manifest['capabilities']['provides'] ?? []) : ($manifest['subscribes'] ?? []);
        $all = is_array($own) ? array_values($own) : [];
        foreach (self::conditional($manifest, $what) as $list) {
            array_push($all, ...$list);
        }

        return $all;
    }

    public function path(string $relative): string
    {
        return $this->dir . '/' . $relative;
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    /** @return string[] files whose relative path matches the pattern */
    public function filesMatching(string $pattern): array
    {
        return array_values(array_filter($this->files, fn ($f) => preg_match($pattern, $f) === 1));
    }

    /** Help pages and their language files are the one place em-dashes are allowed. */
    public static function isHelp(string $relative): bool
    {
        return preg_match('~(^|/)help(/|\.|_)~i', $relative) === 1;
    }

    /** @return string[] */
    private function listFiles(): array
    {
        $out = [];
        $skip = '~(^|/)(\.git|vendor|node_modules|\.idea|\.vscode)(/|$)~';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($skip) {
                    $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->dir) + 1);

                    return preg_match($skip, $relative) !== 1;
                }
            )
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->dir) + 1);
            }
        }
        sort($out);

        return $out;
    }

    public function git(string ...$args): ?string
    {
        $cmd = 'git -C ' . escapeshellarg($this->dir);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $out = [];
        $code = 0;
        exec($cmd . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $out, $code);

        return $code === 0 ? implode("\n", $out) : null;
    }

    private function findLastTag(): ?string
    {
        $tags = $this->git('tag', '--list');
        if ($tags === null) {
            return null;
        }
        $versions = array_filter(explode("\n", $tags), fn ($t) => preg_match('/^v?\d+\.\d+\.\d+$/', $t) === 1);
        usort($versions, fn ($a, $b) => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));

        return $versions ? end($versions) : null;
    }

    /** @return string[] migration files at a tag or commit */
    public function filesAtTag(string $tag): array
    {
        $list = $this->git('ls-tree', '-r', '--name-only', $tag);

        return $list === null ? [] : array_values(array_filter(explode("\n", $list), fn ($f) => preg_match('~(^|/)migrations/[^/]+\.php$~i', $f) === 1));
    }
}
