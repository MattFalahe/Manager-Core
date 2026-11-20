<?php

declare(strict_types=1);

namespace Ecosystem\Checks;

use Ecosystem\Plugin;
use Ecosystem\Report;

/**
 * The plugin's links to other plugins, checked against their manifests as
 * they stand on their work branches: a subscription to a topic nobody sends,
 * a call to a capability nobody provides, a link to a route nobody serves.
 * Each is a link this plugin owns, so it is this plugin's finding.
 *
 * Options: --manifests=<dir> reads manifests from a folder instead of
 * fetching every plugin's from GitHub.
 */
final class CrossPluginCheck
{
    /** @var array<string, true> plugins whose manifest could not be fetched */
    private array $unreadable = [];

    public function run(Plugin $p, Report $r, array $o): void
    {
        $manifests = $this->manifests($r, $o);
        $manifests[$p->key()] = $p->manifest;
        $r->covered('cross-plugin', count($manifests) . ' manifests: ' . implode(', ', array_keys($manifests)));

        $published = [];
        $served = [];
        foreach ($manifests as $key => $m) {
            foreach ((array) ($m['publishes'] ?? []) as $topic) {
                $published[$topic][] = $key;
            }
            foreach ((array) ($m['routes']['serves'] ?? []) as $route) {
                $served[$route] = $key;
            }
        }

        foreach (Plugin::everything($p->manifest, 'subscribes') as $sub) {
            $pattern = is_array($sub) ? (string) ($sub['topic'] ?? '') : '';
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            if (!preg_grep($regex, array_keys($published))) {
                $this->report($r, "Subscribes to $pattern, which no plugin publishes, so the handler never runs.");
            }
        }

        foreach ($p->manifestList('capabilities', 'calls') as $call) {
            [$target, $cap] = array_pad(explode(':', $call, 2), 2, '');
            if ($target === 'ManagerCore' || $target === $p->key()) {
                continue;
            }
            if ($target === '*') {
                if (!array_filter($manifests, fn ($m) => in_array($cap, Plugin::everything($m, 'provides'), true))) {
                    $r->notice('cross-plugin', 'ecosystem.json', null, "Calls $cap on whichever plugin provides it; none does yet.");
                }
                continue;
            }
            if (isset($this->unreadable[$target])) {
                $r->notice('cross-plugin', 'ecosystem.json', null, "Calls $target:$cap; $target's manifest could not be read, so the call was not checked.");
            } elseif (!isset($manifests[$target])) {
                $r->error('cross-plugin', 'ecosystem.json', null, "Calls $target:$cap, but no plugin named $target is in the ecosystem list.");
            } elseif (!in_array($cap, Plugin::everything($manifests[$target], 'provides'), true)) {
                $r->error('cross-plugin', 'ecosystem.json', null, "Calls $target:$cap, which $target does not provide.");
            }
        }

        foreach ($p->manifestList('routes', 'uses') as $route) {
            if (!isset($served[$route])) {
                $this->report($r, "Links to route $route, which no plugin serves.");
            }
        }

        $subscribed = [];
        foreach ($manifests as $m) {
            foreach (Plugin::everything($m, 'subscribes') as $sub) {
                $subscribed[] = '/^' . str_replace('\*', '.*', preg_quote((string) ($sub['topic'] ?? ''), '/')) . '$/';
            }
        }
        foreach ($p->manifestList('publishes') as $topic) {
            if (!array_filter($subscribed, fn ($regex) => preg_match($regex, $topic))) {
                $r->notice('cross-plugin', null, null, "No plugin subscribes to $topic yet.");
            }
        }
    }

    /** A finding, or only a note while some manifest could not be read and may hold the answer. */
    private function report(Report $r, string $message): void
    {
        if ($this->unreadable) {
            $r->notice('cross-plugin', 'ecosystem.json', null, $message . ' Not counted, since the manifest of ' . implode(', ', array_keys($this->unreadable)) . ' could not be read.');
        } else {
            $r->error('cross-plugin', 'ecosystem.json', null, $message);
        }
    }

    /** @return array<string, array> plugin key => manifest */
    private function manifests(Report $r, array $o): array
    {
        $out = [];
        if (isset($o['manifests']) && is_dir((string) $o['manifests'])) {
            foreach (glob(rtrim((string) $o['manifests'], '/') . '/*.json') as $file) {
                $m = json_decode((string) file_get_contents($file), true);
                if (is_array($m) && isset($m['plugin'])) {
                    $out[$m['plugin']] = $m;
                }
            }

            return $out;
        }

        $list = json_decode((string) file_get_contents(__DIR__ . '/../plugins.json'), true) ?: [];
        foreach ($list as $entry) {
            $url = "https://raw.githubusercontent.com/{$entry['repository']}/{$entry['branch']}/ecosystem.json";
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
            $m = $body === false ? null : json_decode($body, true);
            if (!is_array($m)) {
                // That plugin's own run reports a missing manifest; here it only limits what can be checked.
                $r->notice('cross-plugin', null, null, "Could not read the manifest of {$entry['repository']} on {$entry['branch']}.");
                $this->unreadable[$entry['plugin']] = true;
                continue;
            }
            $out[$m['plugin'] ?? $entry['plugin']] = $m;
        }

        return $out;
    }
}
