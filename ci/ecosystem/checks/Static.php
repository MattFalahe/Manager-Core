<?php

declare(strict_types=1);

namespace Ecosystem\Checks;

use Ecosystem\Blade;
use Ecosystem\Plugin;
use Ecosystem\Report;
use Ecosystem\Source;

/** The manifest exists and says something the other checks can use. */
final class ManifestCheck
{
    private const KEYS = ['plugin', 'manager_core', 'publishes', 'subscribes', 'capabilities', 'when_installed', 'routes', 'migration_identifier', 'notes'];

    public function run(Plugin $p, Report $r, array $o): void
    {
        $r->covered('manifest', 'ecosystem.json');
        $m = $p->manifest;
        if ($m === null) {
            $r->error('manifest', null, null, 'There is no ecosystem.json. Every plugin keeps one; the kit README describes it.');

            return;
        }

        foreach (array_diff(array_keys($m), self::KEYS) as $unknown) {
            $r->error('manifest', 'ecosystem.json', null, "Unknown key '$unknown'. Known keys: " . implode(', ', self::KEYS) . '.');
        }

        if (!is_string($m['plugin'] ?? null) || !preg_match('/^([a-z0-9]+(-[a-z0-9]+)*|ManagerCore)$/', $m['plugin'])) {
            $r->error('manifest', 'ecosystem.json', null, "'plugin' must be the plugin's bridge key, as it passes to registerCapability() and subscribe().");
        }

        $relation = $m['manager_core']['relation'] ?? null;
        if (!in_array($relation, ['optional', 'required', 'self', 'none'], true)) {
            $r->error('manifest', 'ecosystem.json', null, "'manager_core.relation' must be optional, required, self or none.");
        }
        if (in_array($relation, ['optional', 'required'], true)) {
            $min = $m['manager_core']['minimum'] ?? null;
            if (!is_string($min) || !preg_match('/^\d+\.\d+\.\d+$/', $min)) {
                $r->error('manifest', 'ecosystem.json', null, "'manager_core.minimum' must be the oldest Manager Core tag the plugin works with, such as 1.1.0.");
            }
        }

        $lists = [
            ['publishes'], ['capabilities', 'provides'], ['capabilities', 'calls'], ['routes', 'serves'], ['routes', 'uses'],
        ];
        foreach ($lists as $path) {
            $value = $m;
            foreach ($path as $key) {
                $value = is_array($value) ? ($value[$key] ?? []) : null;
            }
            if (!is_array($value) || array_filter($value, fn ($v) => !is_string($v) || $v === '')) {
                $r->error('manifest', 'ecosystem.json', null, "'" . implode('.', $path) . "' must be a list of names.");
            } elseif (count($value) !== count(array_unique($value))) {
                $r->error('manifest', 'ecosystem.json', null, "'" . implode('.', $path) . "' lists a name twice.");
            }
        }
        foreach ($p->manifestList('capabilities', 'calls') as $call) {
            if (is_string($call) && !preg_match('/^([A-Za-z0-9-]+|\*):[\w.]+$/', $call)) {
                $r->error('manifest', 'ecosystem.json', null, "Capability call '$call' must read plugin:capability, such as ManagerCore:pricing.getPrices, or *:capability for whichever plugin provides it.");
            }
        }
        foreach ($p->manifestList('subscribes') as $i => $sub) {
            if (!is_array($sub) || !is_string($sub['topic'] ?? null) || !is_string($sub['handler'] ?? null)) {
                $r->error('manifest', 'ecosystem.json', null, "subscribes[$i] must be {\"topic\": ..., \"handler\": ...}.");
            }
        }

        $when = $m['when_installed'] ?? [];
        if (!is_array($when) || ($when !== [] && array_is_list($when))) {
            $r->error('manifest', 'ecosystem.json', null, "'when_installed' must map a plugin key to what this plugin provides and subscribes to only while that plugin is installed.");
            $when = [];
        }
        $own = array_merge($p->manifestList('capabilities', 'provides'), array_map(fn ($s) => json_encode($s), $p->manifestList('subscribes')));
        foreach ($when as $plugin => $section) {
            $at = "when_installed.$plugin";
            if (!preg_match('/^([a-z0-9]+(-[a-z0-9]+)*|ManagerCore)$/', (string) $plugin) || $plugin === ($m['plugin'] ?? null)) {
                $r->error('manifest', 'ecosystem.json', null, "'$at' must be keyed by another plugin's bridge key.");
            }
            if (!is_array($section) || array_diff(array_keys($section), ['provides', 'subscribes'])) {
                $r->error('manifest', 'ecosystem.json', null, "'$at' may hold only provides and subscribes.");
                continue;
            }
            $provides = $section['provides'] ?? [];
            if (!is_array($provides) || !array_is_list($provides) || array_filter($provides, fn ($v) => !is_string($v) || $v === '')) {
                $r->error('manifest', 'ecosystem.json', null, "'$at.provides' must be a list of names.");
                $provides = [];
            }
            foreach ((array) ($section['subscribes'] ?? []) as $i => $sub) {
                if (!is_array($sub) || !is_string($sub['topic'] ?? null) || !is_string($sub['handler'] ?? null)) {
                    $r->error('manifest', 'ecosystem.json', null, "$at.subscribes[$i] must be {\"topic\": ..., \"handler\": ...}.");
                }
            }
            $named = array_merge($provides, array_map(fn ($s) => json_encode($s), (array) ($section['subscribes'] ?? [])));
            foreach (array_intersect($named, $own) as $twice) {
                $r->error('manifest', 'ecosystem.json', null, "$twice is under both the plugin's own lists and '$at'. It is one or the other.");
            }
        }

        if ($p->migrations && !is_string($m['migration_identifier'] ?? null)) {
            $r->error('manifest', 'ecosystem.json', null, "'migration_identifier' must name the word every migration file carries, such as buyback.");
        }

        $requires = array_keys(($p->composer['require'] ?? []) + ($p->composer['require-dev'] ?? []));
        if ($relation === 'optional' && in_array('mattfalahe/manager-core', $requires, true)) {
            $r->error('manifest', 'composer.json', null, 'composer.json requires mattfalahe/manager-core, but Manager Core is optional for this plugin. Detect it with class_exists() instead.');
        }
    }
}

/** Every Blade block closes, read the way the compiler reads it. */
final class BladeCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        $views = $p->filesMatching('/\.blade\.php$/');
        $r->covered('blade', count($views) . ' views');
        foreach ($views as $view) {
            foreach (Blade::problems($p->read($view)) as [$line, $problem]) {
                $r->error('blade', $view, $line, $problem);
            }
        }
    }
}

/**
 * Migration names, classes and order. These rules hold when a migration is
 * written: once a released plugin has pushed one, the servers that follow
 * its work branch have run it and it can no longer be renamed, so on a
 * released plugin they apply to the migrations this push adds.
 */
final class MigrationCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        $new = $p->newMigrations;
        if (!$p->lastTag) {
            $scope = "all " . count($new) . " (no release yet)";
        } elseif ($p->since) {
            $scope = count($new) . " added by this push";
        } else {
            $scope = "none, since this run has no push to compare with";
        }
        $r->covered("migrations", count($p->migrations) . " migrations; naming rules on $scope");

        $prefixes = [];
        $before = "";
        foreach ($p->migrations as $file) {
            $base = basename($file);
            if (!preg_match("/^(\d{4}_\d{2}_\d{2}_\d{6})_([a-z0-9_]+)\.php$/", $base, $m)) {
                if (in_array($file, $new, true)) {
                    $r->error("migrations", $file, null, "A migration file is named YYYY_MM_DD_NNNNNN_snake_case_name.php.");
                }
                continue;
            }
            $prefixes[$m[1]][] = $file;
            if (!in_array($file, $new, true)) {
                $before = max($before, $base);
            }
        }
        foreach ($prefixes as $prefix => $files) {
            if (count($files) > 1 && array_intersect($files, $new)) {
                $r->error("migrations", $files[1], null, "Two migrations share $prefix, so only their names decide which runs first: " . implode(", ", array_map("basename", $files)) . ". Give the new one the next number.");
            }
        }

        $identifier = (string) ($p->manifest["migration_identifier"] ?? "");
        foreach ($new as $file) {
            $base = basename($file);
            if (!preg_match("/^\d{4}_\d{2}_\d{2}_\d{6}_([a-z0-9_]+)\.php$/", $base, $m)) {
                continue;
            }
            $code = Source::phpWithoutComments($p->read($file));
            $class = Source::studly($m[1]);

            if (preg_match("/return\s+new\s+class\b/", $code)) {
                $r->error("migrations", $file, null, "Anonymous migration class. Use a named class, $class, since every plugin\x27s migrations share one namespace.");
            } elseif (!preg_match("/\bclass\s+" . preg_quote($class, "/") . "\s+extends\s+\\\\?([\w\\\\]*\\\\)?Migration\b/", $code)) {
                $r->error("migrations", $file, null, "The migrator looks for class $class in this file and will not find it.");
            }
            if ($identifier !== "" && !str_contains($m[1], $identifier)) {
                $r->error("migrations", $file, null, "The name carries no \x27$identifier\x27, so it can collide with another plugin\x27s migration.");
            }
            if ($before !== "" && strcmp($base, $before) < 0) {
                $r->error("migrations", $file, null, "Sorts before $before, which installs have already run, so it runs out of order on them.");
            }
        }
    }
}

/**
 * On a released plugin, migrations only ever move forward: none that the
 * last tag shipped, and none that an earlier push put on the work branch,
 * may change or go away. Fix forward with the next migration instead.
 */
final class FrozenMigrationCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        if (!$p->lastTag) {
            $r->covered("frozen-migrations", "no release yet, so migrations may still change");

            return;
        }
        $refs = [$p->lastTag => "shipped in {$p->lastTag}"];
        if ($p->since) {
            $refs[$p->since] = "already pushed to the work branch";
        }
        $covered = [];
        $reported = [];
        foreach ($refs as $ref => $why) {
            $files = $p->filesAtTag($ref);
            $covered[] = count($files) . " $why";
            if (!$files) {
                continue;
            }
            $diff = $p->git("diff", "--name-status", "--no-renames", $ref, "--", ...$files);
            if ($diff === null) {
                $r->error("frozen-migrations", null, null, "Could not compare with $ref. The checkout needs its full history and tags (fetch-depth: 0).");
                continue;
            }
            foreach (array_filter(explode("\n", $diff)) as $line) {
                [$status, $file] = preg_split("/\s+/", $line, 2);
                if (isset($reported[$file])) {
                    continue;
                }
                $reported[$file] = true;
                $r->error("frozen-migrations", $file, null, ($status === "D" ? "Removed or renamed" : "Changed") . " after it was $why. Installs have run it; restore it and fix forward with a new migration.");
            }
        }
        $r->covered("frozen-migrations", implode(", ", $covered));
    }
}

/** Comments explain the code to strangers and say nothing about how it was written. */
final class CommentCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        $rules = [
            '/\b(?:feedback|project|reference)_[a-z0-9]+(?:_[a-z0-9]+){2,}\b|\b[\w-]+_[\w-]+\.md\b(?<!README\.md)(?<!CHANGELOG\.md)|\bMEMORY\.md\b|\[\[[a-z0-9-]+\]\]/i'
                => 'names a private note',
            '/\((?:see )?memory\)|\b(?:project|session) memory\b|\bmemory (?:note|file)s?\b/i'
                => 'points at private notes',
            '/\b(?:' . implode('|', ['Cl' . 'aude', 'Anthr' . 'opic', 'Chat' . 'GPT', 'Open' . 'AI', 'Co' . 'pilot', 'Gem' . 'ini', 'LL' . 'M']) . ')\b/'
                => 'names an AI model or vendor',
            '/\bAI[- ](?:written|generated|assisted)\b|\bgenerated (?:by|with) (?:an? )?AI\b|\bCo-Authored-By\b/i'
                => 'says the code was AI-written',
        ];
        $paths = '~\b[A-Za-z]:[\\\\/]+(?:Users|Claude)[\\\\/]~';

        $scanned = 0;
        foreach ($p->files as $file) {
            if (!Source::isText($file)) {
                continue;
            }
            $scanned++;
            $text = $p->read($file);

            if (preg_match_all($paths, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$hit, $offset]) {
                    $r->error('comments', $file, Source::lineOf($text, $offset), "A local path ('$hit…') is published with the repository.");
                }
            }

            foreach (Source::comments($file, $text) as [$comment, $line]) {
                foreach ($rules as $pattern => $why) {
                    if (preg_match($pattern, $comment, $m, PREG_OFFSET_CAPTURE)) {
                        $r->error('comments', $file, $line + substr_count($comment, "\n", 0, $m[0][1]), "Comment $why: '{$m[0][0]}'.");
                    }
                }
            }
        }
        $r->covered('comments', "$scanned text files");
    }
}

/** Third-party scripts and styles ship in the plugin, never from a CDN. */
final class CdnCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        $patterns = [
            '~<script\b[^>]*\bsrc\s*=\s*["\']((?:https?:)?//[^"\']+)~i',
            '~<link\b[^>]*\bhref\s*=\s*["\']((?:https?:)?//[^"\']+)["\'][^>]*>~i',
            '~@import\s+(?:url\()?\s*["\']?((?:https?:)?//[^"\')\s]+)~i',
            '~\$\.getScript\(\s*["\']((?:https?:)?//[^"\']+)~',
        ];
        $files = $p->filesMatching('~^(src|resources)/.*\.(php|js|css|html?)$~');
        $r->covered('cdn', count($files) . ' shipped files');
        foreach ($files as $file) {
            $text = Source::withoutComments($file, $p->read($file));
            foreach ($patterns as $i => $pattern) {
                preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($matches as $m) {
                    // A <link> that is not a stylesheet (an icon, a preconnect) loads no code.
                    if ($i === 1 && !preg_match('/stylesheet|\.css\b/i', $m[0][0])) {
                        continue;
                    }
                    $r->error('cdn', $file, Source::lineOf($text, $m[0][1]), "Loads {$m[1][0]} from another host. Ship the file in the plugin's assets so a strict content-security policy allows it.");
                }
            }
        }
    }
}

/**
 * Every modal moves to <body> before it shows. AdminLTE's content wrapper
 * is its own stacking context, so a modal left inside it sits under its
 * backdrop and every click lands on the backdrop.
 */
final class ModalCheck
{
    private const TO_BODY = '~\.appendTo\(\s*(?:["\']body["\']|document\.body|\$\(\s*["\']?body["\']?\s*\)|\$\(\s*document\.body\s*\))\s*\)|document\.body\.append(?:Child)?\(|\$\(\s*["\']body["\']\s*\)\.append\(~';

    public function run(Plugin $p, Report $r, array $o): void
    {
        $files = array_values(array_filter($p->filesMatching('~\.(blade\.php|js)$~'), fn ($f) => !str_ends_with($f, '.min.js')));
        $texts = [];
        foreach ($files as $file) {
            $texts[$file] = Source::withoutComments($file, $p->read($file));
        }

        // Ids named near a move to <body>, files that move every modal at
        // once, and ids Bootstrap opens: near a .modal() call or the target
        // of a data-toggle="modal". A modal shown by setting its display
        // has no backdrop to sit under, so it needs no move.
        $moved = [];
        $movesAll = [];
        $opened = [];
        foreach ($texts as $file => $text) {
            $lines = explode("\n", $text);
            foreach ($lines as $n => $line) {
                $window = null;
                if (preg_match(self::TO_BODY, $line)) {
                    $window = implode("\n", array_slice($lines, max(0, $n - 8), 9));
                    foreach ($this->ids($window) as $id) {
                        $moved[$id] = true;
                    }
                    if (preg_match('/["\']\.modal["\']|\$\(\s*this\s*\)/', $window) && !preg_match('/#[A-Za-z][\w-]*/', $window)) {
                        $movesAll[$file] = true;
                    }
                }
                if (preg_match('/\.modal\(/', $line)) {
                    foreach ($this->ids($window ?? implode("\n", array_slice($lines, max(0, $n - 8), 9))) as $id) {
                        $opened[$id] = true;
                    }
                }
            }
            preg_match_all('/data-(?:bs-)?toggle\s*=\s*["\']modal["\'][^>]*?(?:data-(?:bs-)?target|href)\s*=\s*["\']#([\w-]+)|(?:data-(?:bs-)?target|href)\s*=\s*["\']#([\w-]+)["\'][^>]*?data-(?:bs-)?toggle\s*=\s*["\']modal/s', $text, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $opened[$hit[1] ?: $hit[2]] = true;
            }
        }

        $count = 0;
        foreach ($texts as $file => $text) {
            if (!str_ends_with($file, '.blade.php')) {
                continue;
            }
            preg_match_all('~<div\b[^>]*\bclass\s*=\s*["\'](?:[^"\']*\s)?modal(?:\s[^"\']*)?["\'][^>]*>~i', $text, $tags, PREG_OFFSET_CAPTURE);
            foreach ($tags[0] as [$tag, $offset]) {
                $count++;
                preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/', $tag, $id);
                $id = $id[1] ?? null;
                if ($id !== null && str_contains($id, '{{')) {
                    $r->notice('modals', $file, Source::lineOf($text, $offset), "Modal id '$id' is built at render time, so this check cannot follow it.");
                    continue;
                }
                if ($id === null || !isset($opened[$id])) {
                    $r->notice('modals', $file, Source::lineOf($text, $offset), 'Modal ' . ($id ? "#$id " : '') . 'is not opened through Bootstrap here, so it was not checked.');
                    continue;
                }
                if (($id !== null && isset($moved[$id])) || isset($movesAll[$file]) || $this->includedByAMover($file, $texts, $movesAll)) {
                    continue;
                }
                $r->error('modals', $file, Source::lineOf($text, $offset), 'Modal ' . ($id ? "#$id " : '') . "is never moved to <body>. Call .appendTo('body') before it shows (on show.bs.modal covers data-toggle triggers too).");
            }
        }
        $r->covered('modals', "$count modals in " . count($files) . ' views and scripts');
    }

    /** @return string[] element ids a stretch of script names */
    private function ids(string $window): array
    {
        preg_match_all('/#([A-Za-z][\w-]*)|["\']([A-Za-z][\w-]*Modal[\w-]*)["\']|getElementById\(\s*["\']([\w-]+)["\']/', $window, $m, PREG_SET_ORDER);

        return array_map(fn ($hit) => ($hit[3] ?? '') ?: (($hit[2] ?? '') ?: $hit[1]), $m);
    }

    /** True when a file that moves every modal includes this view. */
    private function includedByAMover(string $view, array $texts, array $movesAll): bool
    {
        if (!preg_match('~views/(.+)\.blade\.php$~', $view, $m)) {
            return false;
        }
        $dotted = str_replace('/', '.', $m[1]);
        foreach (array_keys($movesAll) as $mover) {
            if (preg_match('/@include\w*\(\s*["\'](?:[\w-]+::)?' . preg_quote($dotted, '/') . '["\']/', $texts[$mover])) {
                return true;
            }
        }

        return false;
    }
}

/** The manifest and the source agree on what the plugin sends, calls and serves. */
final class ManifestSourceCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        if ($p->manifest === null) {
            return;
        }
        $code = '';
        $literals = [];
        foreach ($p->filesMatching('~^(src|resources)/.*\.php$~') as $file) {
            $text = $p->read($file);
            $plain = str_ends_with($file, '.blade.php') ? Source::withoutComments($file, $text) : Source::phpWithoutComments($text);
            $code .= "\n" . $plain;
            preg_match_all('/([\'"])([^\'"\n]+)\1/', $plain, $m);
            foreach ($m[2] as $literal) {
                $literals[$literal] = true;
            }
        }
        $r->covered('manifest-vs-source', 'publishes, subscribes, capabilities and routes');

        $declared = fn (string ...$path) => $p->manifestList(...$path);
        // A name that is not a string is the manifest check's finding.
        $absent = function (mixed $name, string $what) use ($literals, $r) {
            if (is_string($name) && !isset($literals[$name])) {
                $r->error('manifest-vs-source', 'ecosystem.json', null, "$what '$name' is in the manifest but nowhere in the source.");
            }
        };

        foreach ($declared('publishes') as $topic) {
            // 'structure.alert.' . $flavor builds the name from two literals.
            $cut = strrpos($topic, '.');
            if ($cut !== false && isset($literals[substr($topic, 0, $cut + 1)], $literals[substr($topic, $cut + 1)])) {
                continue;
            }
            $absent($topic, 'Published topic');
        }
        foreach (Plugin::everything($p->manifest, 'subscribes') as $sub) {
            if (is_array($sub) && isset($sub['topic'], $sub['handler'])) {
                $absent((string) $sub['topic'], 'Subscribed topic');
                $handler = (string) $sub['handler'];
                if (str_contains($handler, '@')) {
                    // A class handler is named by its class, usually as Foo::class.
                    $class = explode('@', $handler)[0];
                    $short = substr($class, strrpos('\\' . $class, '\\'));
                    if (!preg_match('/\b' . preg_quote($short, '/') . '::class\b/', $code)) {
                        $absent($class, 'Subscription handler');
                    }
                } else {
                    $absent($handler, 'Subscription handler');
                }
            }
        }
        foreach (Plugin::everything($p->manifest, 'provides') as $cap) {
            $absent($cap, 'Provided capability');
        }
        foreach ($declared('capabilities', 'calls') as $call) {
            $absent((string) (explode(':', $call, 2)[1] ?? $call), 'Called capability');
        }
        foreach ($declared('routes', 'serves') as $route) {
            $absent($route, 'Served route');
        }
        foreach ($declared('routes', 'uses') as $route) {
            $absent($route, 'Used route');
        }

        // Literal topics and capability calls in the code must be declared.
        $topics = array_flip($declared('publishes'));
        preg_match_all('/(?:Topics::publish|->publish|->publishSanitized)\(\s*([\'"])([a-z0-9_]+(?:\.[a-z0-9_*]+)+)\1/', $code, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            if (!isset($topics[$hit[2]])) {
                $r->error('manifest-vs-source', 'ecosystem.json', null, "The code publishes '{$hit[2]}', which the manifest does not list.");
                $topics[$hit[2]] = true;
            }
        }

        $own = $p->key();
        $calls = array_flip($declared('capabilities', 'calls'));
        $callCaps = [];
        foreach (array_keys($calls) as $call) {
            $callCaps[explode(':', $call, 2)[1] ?? $call] = true;
        }
        preg_match_all('/->(?:call|callOrFail|hasCapability)\(\s*([^,()]+?)\s*,\s*([\'"])([A-Za-z][\w.]*)\2/', $code, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $target = trim($hit[1]);
            $cap = $hit[3];
            if (preg_match('/^([\'"])([^\'"]+)\1$/', $target, $t)) {
                $target = $t[2];
                if ($target === $own) {
                    continue;
                }
                if (!isset($calls["$target:$cap"])) {
                    $r->error('manifest-vs-source', 'ecosystem.json', null, "The code calls $target:$cap, which the manifest does not list.");
                    $calls["$target:$cap"] = true;
                }
            } elseif (!isset($callCaps[$cap]) && !in_array($cap, Plugin::everything($p->manifest, 'provides'), true)) {
                $r->error('manifest-vs-source', 'ecosystem.json', null, "The code calls capability '$cap' (on $target), which the manifest does not list.");
                $callCaps[$cap] = true;
            }
        }
    }
}
