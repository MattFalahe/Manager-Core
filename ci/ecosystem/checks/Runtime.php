<?php

declare(strict_types=1);

namespace Ecosystem\Checks;

use Ecosystem\Plugin;
use Ecosystem\Report;
use Ecosystem\Source;

/** Boots the SeAT install the workflow built, once per process. */
final class Seat
{
    private static bool $booted = false;

    public static function boot(array $o, Report $r, string $check): bool
    {
        if (self::$booted) {
            return true;
        }
        $seat = $o['seat'] ?? null;
        if (!is_string($seat) || !is_file("$seat/bootstrap/app.php")) {
            $r->error($check, null, null, 'Pass --seat=<path to the SeAT install>.');

            return false;
        }
        require_once "$seat/vendor/autoload.php";
        $app = require "$seat/bootstrap/app.php";
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        self::$booted = true;

        return true;
    }

    public static function mcVersion(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class) || !\Composer\InstalledVersions::isInstalled('mattfalahe/manager-core')) {
            return null;
        }

        return \Composer\InstalledVersions::getPrettyVersion('mattfalahe/manager-core');
    }

    /** @return string[] tables the plugin's migrations create */
    public static function pluginTables(Plugin $p): array
    {
        $tables = [];
        foreach ($p->migrations as $file) {
            preg_match_all('/Schema::create\(\s*[\'"]([\w]+)[\'"]/', Source::phpWithoutComments($p->read($file)), $m);
            foreach ($m[1] as $table) {
                $tables[$table] = true;
            }
        }

        return array_keys($tables);
    }
}

/**
 * What only a booted install shows: the capabilities and subscriptions the
 * plugin registers, the topics and capabilities this Manager Core knows,
 * the routes, the published assets and the seeded schedule.
 */
final class RuntimeCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        $variant = (string) ($o['mc'] ?? '');
        if (!Seat::boot($o, $r, 'runtime')) {
            return;
        }
        $hasMc = class_exists(\ManagerCore\ManagerCore::class);
        $mcVersion = Seat::mcVersion();
        $label = $hasMc ? 'Manager Core ' . ($variant === 'dev' ? 'dev' : $mcVersion) : 'no Manager Core';
        $r->covered('runtime', "booted with $label");

        if ($variant === 'absent' && $hasMc) {
            $r->error('runtime', null, null, 'This run is meant to have no Manager Core, but it is installed.');
        }
        if ($variant !== 'absent' && !$hasMc) {
            $r->error('runtime', null, null, "This run is meant to have Manager Core ($variant), but it is not installed.");
        }

        foreach ((array) ($p->composer['extra']['laravel']['providers'] ?? []) as $provider) {
            if (!app()->getProvider($provider)) {
                $r->error('runtime', 'composer.json', null, "$provider is not registered, so the plugin never boots.");
            }
        }

        if ($hasMc && $p->relation() !== 'none') {
            $this->contract($p, $r, $variant, $label);
        }
        $this->routes($p, $r);
        $this->assets($p, $r);
        $this->schedules($p, $r);
    }

    private function contract(Plugin $p, Report $r, string $variant, string $label): void
    {
        $key = $p->key();
        $bridge = app(\ManagerCore\Services\PluginBridge::class);
        $atMinimum = $variant !== 'dev' && $variant !== 'self';
        $r->covered('mc-contract', "topics, capabilities and subscriptions against $label");

        // Capabilities this plugin registers.
        // The install holds only the plugin and Manager Core, so what it makes
        // only beside another plugin is noted, not required.
        $registered = $bridge->registeredCapabilityNames($key)[$key] ?? [];
        $declared = $p->manifestList('capabilities', 'provides');
        foreach (array_diff($registered, Plugin::everything($p->manifest, 'provides')) as $cap) {
            $r->error('mc-contract', 'ecosystem.json', null, "The plugin registers capability $cap, which the manifest does not list.");
        }
        foreach (array_diff($declared, $registered) as $cap) {
            $r->error('mc-contract', 'ecosystem.json', null, "The manifest lists capability $cap, but the plugin does not register it with $label.");
        }
        foreach (Plugin::conditional($p->manifest, 'provides') as $other => $caps) {
            if ($missing = array_diff($caps, $registered)) {
                $r->notice('mc-contract', 'ecosystem.json', null, 'Not registered here, since they need ' . $other . ', which this install does not have: ' . implode(', ', $missing) . '.');
            }
        }

        // Subscriptions it stores.
        $rows = \ManagerCore\Models\EventSubscription::where('subscriber_plugin', $key)->where('is_active', true)->get();
        $stored = [];
        foreach ($rows as $row) {
            // A class handler's handler_capability is an internal key Manager
            // Core makes up for its unique index, not a capability.
            if ($row->handler_class) {
                $handler = $row->handler_class . '@' . ($row->handler_method ?: 'handle');
                $stored[$row->event_pattern . ' ' . $handler] = [$row->event_pattern, null, $row->handler_class];
            } else {
                $stored[$row->event_pattern . ' ' . $row->handler_capability] = [$row->event_pattern, $row->handler_capability, null];
            }
        }
        $ids = function (array $subs): array {
            $out = [];
            foreach ($subs as $sub) {
                if (is_array($sub) && isset($sub['topic'], $sub['handler'])) {
                    $out[$sub['topic'] . ' ' . $sub['handler']] = true;
                }
            }

            return $out;
        };
        $declaredSubs = $ids($p->manifestList('subscribes'));
        foreach (array_diff_key($stored, $ids(Plugin::everything($p->manifest, 'subscribes'))) as $id => $_) {
            $r->error('mc-contract', 'ecosystem.json', null, "The plugin subscribes to $id (topic, handler), which the manifest does not list.");
        }
        foreach (array_diff_key($declaredSubs, $stored) as $id => $_) {
            $r->error('mc-contract', 'ecosystem.json', null, "The manifest lists the subscription $id (topic, handler), but the plugin does not make it with $label.");
        }
        foreach (Plugin::conditional($p->manifest, 'subscribes') as $other => $subs) {
            if ($missing = array_keys(array_diff_key($ids($subs), $stored))) {
                $r->notice('mc-contract', 'ecosystem.json', null, 'Not subscribed here, since they need ' . $other . ', which this install does not have: ' . implode(', ', $missing) . '.');
            }
        }
        foreach ($stored as [$pattern, $capability, $class]) {
            if ($capability && !$bridge->hasCapability($key, $capability)) {
                $r->error('mc-contract', null, null, "The subscription to $pattern calls capability $key:$capability, which is not registered, so every event it receives fails.");
            }
            if (!$capability && $class && !class_exists($class)) {
                $r->error('mc-contract', null, null, "The subscription to $pattern calls $class, which does not exist.");
            }
        }

        // Topics it publishes.
        foreach ($p->manifestList('publishes') as $topic) {
            $entry = \ManagerCore\Topics::describe($topic);
            if ($entry === null && $p->relation() === 'self') {
                // The Topics rule binds the plugins; Manager Core owns the bus.
                $r->notice('mc-contract', 'ecosystem.json', null, "Manager Core sends $topic on its own bus, outside the Topics registry.");
            } elseif ($entry === null) {
                $r->error('mc-contract', 'ecosystem.json', null, $atMinimum
                    ? "$label does not know topic $topic, so it drops every one the plugin sends. Raise manager_core.minimum, or register the topic in that release."
                    : "Manager Core does not register topic $topic, so it drops every one the plugin sends. Register it in Manager Core's Topics.");
            } elseif ($p->relation() !== 'self' && ($entry['publisher'] ?? null) !== $key) {
                $r->error('mc-contract', 'ecosystem.json', null, "Manager Core registers topic $topic for publisher '" . ($entry['publisher'] ?? '?') . "', not '$key'.");
            }
        }

        // Manager Core capabilities it calls.
        $mcCaps = $bridge->registeredCapabilityNames(\ManagerCore\Services\PluginBridge::SELF_KEY)[\ManagerCore\Services\PluginBridge::SELF_KEY] ?? [];
        foreach ($p->manifestList('capabilities', 'calls') as $call) {
            [$target, $cap] = array_pad(explode(':', $call, 2), 2, '');
            if ($target !== \ManagerCore\Services\PluginBridge::SELF_KEY || in_array($cap, $mcCaps, true)) {
                continue;
            }
            if (!$atMinimum) {
                $r->error('mc-contract', 'ecosystem.json', null, "Manager Core has no capability $cap. The call returns null every time.");
            } elseif ($this->guarded($p, $cap)) {
                $r->notice('mc-contract', 'ecosystem.json', null, "$label has no $cap; the plugin checks hasCapability() first.");
            } else {
                $r->error('mc-contract', 'ecosystem.json', null, "$label has no capability $cap and the plugin calls it unchecked. Raise manager_core.minimum, or check hasCapability() first.");
            }
        }
    }

    private function guarded(Plugin $p, string $cap): bool
    {
        // A file that names the capability and asks hasCapability() first,
        // directly or through a helper that takes the name.
        foreach ($p->filesMatching('~^src/.*\.php$~') as $file) {
            $code = Source::phpWithoutComments($p->read($file));
            if (preg_match('/[\'"]' . preg_quote($cap, '/') . '[\'"]/', $code) && str_contains($code, 'hasCapability(')) {
                return true;
            }
        }

        return false;
    }

    private function routes(Plugin $p, Report $r): void
    {
        $router = app('router');
        $routes = $router->getRoutes();
        $served = $p->manifestList('routes', 'serves');
        foreach ($served as $name) {
            if (!$routes->hasNamedRoute($name)) {
                $r->error('routes', 'ecosystem.json', null, "Route $name, which other plugins link to, is not registered.");
            }
        }
        $r->covered('routes', count($served) . ' routes served to other plugins');
    }

    private function assets(Plugin $p, Report $r): void
    {
        $checked = 0;
        $dynamic = 0;
        foreach ($p->filesMatching('~^(src|resources)/.*\.php$~') as $file) {
            $text = Source::withoutComments($file, $p->read($file));
            preg_match_all('/\basset\(\s*([\'"])(.*?)\1\s*([.)])/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($m as $hit) {
                if ($hit[3][0] === '.' || str_contains($hit[2][0], '{') || str_contains($hit[2][0], '$')) {
                    $dynamic++;
                    continue;
                }
                $path = preg_replace('/[?#].*$/', '', $hit[2][0]);
                $checked++;
                if (!is_file(public_path(ltrim($path, '/')))) {
                    $r->error('assets', $file, Source::lineOf($text, $hit[0][1]), "asset('$path') is not there after vendor:publish, so the page loads without it.");
                }
            }
        }
        $r->covered('assets', "$checked asset() paths exist after vendor:publish");
        if ($dynamic) {
            $r->notice('assets', null, null, "$dynamic asset() paths are built at render time and were not checked.");
        }
    }

    private function schedules(Plugin $p, Report $r): void
    {
        $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());
        $seeders = 0;
        $prefixes = array_keys($p->psr4);
        foreach ((array) config('seat.seeders', []) as $class) {
            if (!array_filter($prefixes, fn ($prefix) => str_starts_with($class, $prefix))) {
                continue;
            }
            if (!class_exists($class)) {
                $r->error('schedule', null, null, "The plugin registers seeder $class, which does not exist.");
                continue;
            }
            if (!is_subclass_of($class, \Seat\Services\Seeding\AbstractScheduleSeeder::class)) {
                continue;
            }
            $seeders++;
            $seeder = app($class);
            $schedules = $seeder->getSchedules();
            $deprecated = array_map('strval', $seeder->getDeprecatedSchedules());
            foreach ($schedules as $schedule) {
                $command = (string) ($schedule['command'] ?? '');
                $name = strtok($command, ' ');
                if (!in_array($name, $commands, true)) {
                    $r->error('schedule', null, null, "$class schedules '$command', which is not a registered command.");
                }
                if (in_array($command, $deprecated, true)) {
                    $r->error('schedule', null, null, "$class lists '$command' as both scheduled and deprecated, so the seeder adds it and removes it again and it never runs.");
                }
                if (!\Illuminate\Support\Facades\DB::table('schedules')->where('command', $command)->exists()) {
                    $r->error('schedule', null, null, "'$command' is not in the schedules table after seeding.");
                }
            }
        }
        $r->covered('schedule', "$seeders schedule seeder(s)");
    }
}

/**
 * Runs again every migration this push adds (every one, before the first
 * release), as a part-migrated install would, and seeds a second time, as every restart does. Neither
 * may fail or change anything.
 */
final class RerunCheck
{
    public function run(Plugin $p, Report $r, array $o): void
    {
        if (!Seat::boot($o, $r, 'rerun')) {
            return;
        }
        $db = \Illuminate\Support\Facades\DB::connection();
        $names = array_map(fn ($f) => basename($f, '.php'), $p->newMigrations);
        $r->covered('rerun-migrations', count($names) . ' migrations run a second time');

        $before = $this->schema($db);
        if ($names) {
            $db->table('migrations')->whereIn('migration', $names)->delete();
            $out = new \Symfony\Component\Console\Output\BufferedOutput();
            try {
                \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true], $out);
            } catch (\Throwable $e) {
                $r->error('rerun-migrations', null, null, 'Running the migrations again failed: ' . $e->getMessage() . ' ' . trim(substr($out->fetch(), -400)));
            }
            $after = $this->schema($db);
            foreach (array_diff_assoc($after, $before) as $what => $definition) {
                $r->error('rerun-migrations', null, null, "Running again changed $what to $definition" . (isset($before[$what]) ? " (was {$before[$what]})." : '.'));
            }
            foreach (array_diff_key($before, $after) as $what => $_) {
                $r->error('rerun-migrations', null, null, "Running again removed $what.");
            }
            $ran = $db->table('migrations')->whereIn('migration', $names)->count();
            if ($ran !== count($names)) {
                $r->error('rerun-migrations', null, null, 'Only ' . $ran . ' of ' . count($names) . ' migrations recorded after running again.');
            }
        }

        $tables = array_values(array_filter(Seat::pluginTables($p), fn ($t) => \Illuminate\Support\Facades\Schema::hasTable($t)));
        $tables[] = 'schedules';
        $count = fn () => array_combine($tables, array_map(fn ($t) => $db->table($t)->count(), $tables));
        $first = $count();
        try {
            \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Seat\\Services\\Database\\Seeders\\PluginDatabaseSeeder', '--force' => true]);
        } catch (\Throwable $e) {
            $r->error('rerun-seeders', null, null, 'Seeding a second time failed: ' . $e->getMessage());
        }
        foreach ($count() as $table => $rows) {
            if ($rows !== $first[$table]) {
                $r->error('rerun-seeders', null, null, "Seeding a second time changed the rows in $table from {$first[$table]} to $rows. Every restart seeds again.");
            }
        }
        $r->covered('rerun-seeders', 'seeded twice across ' . count($tables) . ' tables');
    }

    /** @return array<string, string> column and index => definition */
    private function schema($db): array
    {
        $schema = $db->getDatabaseName();
        $out = [];
        foreach ($db->select('SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ty, IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?', [$schema]) as $row) {
            $out["column {$row->t}.{$row->c}"] = "{$row->ty} " . ($row->n === 'YES' ? 'null' : 'not null') . ' default ' . var_export($row->d, true);
        }
        foreach ($db->select('SELECT TABLE_NAME t, INDEX_NAME i, NON_UNIQUE u, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE', [$schema]) as $row) {
            $out["index {$row->t}.{$row->i}"] = ($row->u ? 'index' : 'unique') . " ({$row->cols})";
        }

        return $out;
    }
}
