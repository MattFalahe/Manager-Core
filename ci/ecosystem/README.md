# Ecosystem checks

Every plugin in the suite runs these checks on GitHub, on every push. They live here in Manager Core because most of what they guard is the contract between a plugin and Manager Core, and between plugins through it.

A plugin needs two files:

- `.github/workflows/ecosystem.yml`, which calls the shared workflow:

  ```yaml
  name: Ecosystem

  on:
    push:
    pull_request:

  permissions:
    contents: read

  jobs:
    ecosystem:
      uses: MattFalahe/manager-core/.github/workflows/ecosystem.yml@dev
  ```

- `ecosystem.json` at its root, the manifest described below.

## Which branches

Two branches per plugin are checked: the default branch, where the release lives, and the work branch `plugins.json` names. A push to any other branch skips the checks with a note saying why, so a branch the work has moved on from costs nothing. When work moves to a new branch, change that plugin's line in `plugins.json`.

A plugin that `plugins.json` does not list names its work branch in its own workflow instead:

```yaml
    uses: MattFalahe/manager-core/.github/workflows/ecosystem.yml@dev
    with:
      work-branch: dev-1.0
```

## What runs

**Source.** One job reads the code without running it:

- the manifest is complete and agrees with the code: every topic it publishes, capability it calls or provides, subscription it makes and route it serves or links to is in the source, and every literal one in the source is in the manifest;
- every Blade block closes, paired the way Blade reads directives (`\B@\w+`), so `{{ $h }}h@endif` is caught;
- migrations added by the push are named `YYYY_MM_DD_NNNNNN_name.php`, carry the plugin's identifier, hold a named class matching the file and sort after every migration before them;
- on a released plugin, no migration the last tag shipped, and none an earlier push put on the branch, has changed or gone;
- comments name no private notes or AI tools, and no file holds a local path;
- no script or stylesheet loads from a CDN;
- every Bootstrap modal moves to `<body>` before it shows.

**Classes, on PHP 8.1, 8.2, 8.3 and 8.4.** Each PHP file parses, then every class and migration loads against a real SeAT install with Manager Core beside it, which finds what `php -l` cannot: a missing parent, a signature that does not match, a constant in a trait before PHP 8.2. Every queued class has a `$timeout` under the queue's 960 second `retry_after`, plus `$tries` and `$backoff`.

**SeAT install, once per Manager Core variant.** SeAT on PHP 8.4, MariaDB 10.11 and Redis, set up the way the Docker image boots: require the plugin, publish, cache config and routes, migrate, seed. Then:

- Manager Core's contract: the capabilities and subscriptions the plugin registers match the manifest; every topic it publishes is registered in that Manager Core, for this plugin; every Manager Core capability it calls exists there, or is checked with `hasCapability()` first;
- every route other plugins link to is registered, every `asset()` path exists after `vendor:publish`, and every scheduled command exists and is seeded;
- the migrations the push adds run a second time on the migrated database without failing or changing the schema, as on an install that stopped part way;
- seeding a second time, as every restart does, adds no rows.

The variants come from the manifest: no Manager Core, its minimum tag and `dev` for a plugin that works with it, the minimum and `dev` for one that needs it, and no Manager Core and `dev` for one with no link to it, so it is still proven to install beside it.

**Links to other plugins.** The manifest is checked against every other plugin's manifest on its work branch: a subscription to a topic nobody publishes, a call to a capability its plugin does not provide, a link to a route nobody serves.

**Nightly.** `ecosystem-nightly.yml` runs all of it for every plugin in `plugins.json`, on its default branch and its work branch, against Manager Core `dev`. A change in one plugin that breaks another shows the next morning, and so does a release that has fallen out of step with the rest, as something for its next release. A default branch whose release came before these checks has no `ecosystem.json` and is skipped until the release that brings one.

## The manifest

```json
{
    "plugin": "buyback-manager",
    "manager_core": { "relation": "optional", "minimum": "1.1.0" },
    "migration_identifier": "buyback",
    "publishes": ["buyback.contract.matched"],
    "subscribes": [{ "topic": "mining.*", "handler": "hr.onMiningEvent" }],
    "capabilities": {
        "provides": ["blueprint.getCorpSummary"],
        "calls": ["ManagerCore:pricing.getPrices", "corp-wallet-manager:wallet.getCorpSummary"]
    },
    "when_installed": {
        "structure-manager": {
            "provides": ["structure.notify_alert"],
            "subscribes": [{ "topic": "structure.alert.*", "handler": "structure.notify_alert" }]
        }
    },
    "routes": {
        "serves": ["buyback.appraisal.create"],
        "uses": ["structure-manager.doctrines.index"]
    }
}
```

- `plugin`: the key the plugin passes to `registerCapability()` and `subscribe()`.
- `manager_core.relation`: `optional`, `required`, `none`, or `self` for Manager Core. `minimum` is the oldest Manager Core tag the plugin is meant to work with.
- `migration_identifier`: the word every new migration's name carries.
- `subscribes[].handler`: a capability name, or `Class@method` for a class handler.
- `capabilities.calls`: `plugin:capability`; `*:capability` calls whichever plugin provides it.
- `when_installed`: what the plugin provides and subscribes to only while another plugin is installed, keyed by that plugin. The install job has only the plugin and Manager Core, so it notes these rather than requiring them. The source and link checks treat them like the rest. Anything that works without the other plugin belongs in the plugin's own lists.
- `routes.serves`: routes another plugin links to; `routes.uses`: routes of another plugin this one links to.

When code starts or stops publishing a topic, subscribing to one, calling a capability or serving a route another plugin uses, the manifest changes in the same commit.

## Running it locally

The source and link checks need only PHP:

```
php ci/ecosystem/check.php static ../Buyback-Manager
php ci/ecosystem/check.php cross ../Buyback-Manager --manifests=<folder of manifests>
```

The class check needs a SeAT vendor folder, and the install checks a SeAT install with a database:

```
php ci/ecosystem/check.php classes ../Buyback-Manager --autoload=<seat>/vendor/autoload.php
php ci/ecosystem/check.php runtime ../Buyback-Manager --seat=<seat> --mc=dev
```

## Not covered yet

- The SDE. Migrations run on an empty database, as on a fresh Docker boot where they run before `eve:update:sde`, and so do the seeders, which read no SDE table today.
- An upgrade from the last tag to the branch. A fresh install is checked, not the step an existing install takes.
- The fields a topic requires, which depend on payloads built at run time.
- Each Help page's list of plugins against how the plugin detects them.
