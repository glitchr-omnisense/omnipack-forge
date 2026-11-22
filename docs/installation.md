---
title: "Installation"
order: 10
---

# Installation

## Requirements

The core - software, versions and their builds per platform, downloads, each version's
documentation, the publish API and `forge:artifact:attach`, a WebGL build played in its page -
needs:

- PHP 8.4 (omnisense/omnibase 7.0 asks it), `ext-zip`;
- omnisense/omnibase 7.0 or later, omnibridge/symfony 3.0 or later, omnipack/admin 2;
- doctrine/doctrine-bundle and doctrine/orm (2.15 or 3);
- Symfony 6, 7 or 8 (config, dependency-injection, doctrine-bridge, form, http-kernel, lock,
  security-bundle).

It presumes no provider: no payment, no repository, no registry. The rest switches on when its
package is installed (`Base\Forge\Feature`), each in `suggest`:

| Package | Switches on |
|---|---|
| omnipack/marketplace (+ an omnitrade/&lt;provider&gt; for one-click purchases) | licences sold and paid downloads, the shop, support hours, quotes, invoices of hours, client projects and their board, reviews, pipelines. Without it every software is free and no checkout route exists |
| git/git-bundle (with `ext-git2`) | releases from the repositories' tags and their archives, the Composer repository, the repositories' `docs/` folders and history in the viewer. Without it the builds are attached |
| omnisense/omnihub (with git/git-bundle) | the Unity registry (`/upm`) and the hub clients' manifest, catalogue and device flow |
| omnisense/omnistate with a register provider (omnistate/annuaire-entreprises) | the SIRET check of the quote form, through omnipack/marketplace's company register |
| omnidocs/markdown, else league/commonmark | each version's documentation rendered (omnidocs: callouts, code frames, headings); without either it shows as Markdown |
| symfony/http-client, symfony/yaml | the pipelines' runs read from GitHub Actions |

A feature whose package is installed can still be turned off (`forge.features`,
[Configuration](configuration.md)): its services, routes and entities are then left out as if
the package were missing.

## The bundle

```bash
composer require omnipack/forge
# and what you want of the rest, for example:
composer require omnipack/marketplace git/git-bundle omnidocs/markdown
```

```php
// config/bundles.php: the core's bundle first, then the ones the forge stands on
Base\BaseBundle::class => ['all' => true],
Base\Admin\AdminBundle::class => ['all' => true],
// only with the optional packages:
// Omnistate\Bridge\Symfony\OmnistateBundle::class => ['all' => true],
// Base\Marketplace\MarketplaceBundle::class => ['all' => true],
// Git\GitBundle::class => ['all' => true],
Base\Forge\ForgeBundle::class => ['all' => true],
```

## The routes

```yaml
# config/routes.yaml: the routes of the features that are on
forge:
    resource: .
    type: forge
```

`Base\Forge\Routing\ForgeRouteLoader` reads each controller's attributes and leaves out those of
the features that are off. An application that installs every feature may keep the 2.x import of
the whole folder (`resource: "@ForgeBundle/src/Controller"`, `type: attribute`).

The core's addresses:

| Route | Path |
|---|---|
| `forge_downloads` | `/telechargements` |
| `forge_software` | `/telechargements/{slug}`: the software, its versions, their builds and documentation |
| `forge_download` | `/telechargements/{slug}/{version}?format=<platform>`: a signed link to the build (`latest` for the newest) |
| `forge_release_docs` | `/telechargements/{slug}/{version}/docs/{page}`: a version's documentation |
| `forge_play` | `/play/{slug}/{version}/`: a WebGL build |
| `forge_api_software_index`, `forge_api_software` | `GET /api/software`, `GET /api/software/{slug}`: the versions as JSON |
| `forge_api_release_publish` | `POST /api/software/{slug}/releases/{version}`: a build published ([Games and applications](games.md#the-publish-api)) |
| `forge_admin_release_builds` | `/admin/forge/release/{id}/builds`: the builds and the documentation of a version, in the back office |

## The database

The core's tables are `forge_release`, `forge_artifact`, `forge_download`, the licences' (`forge_license`,
`forge_license_seat`, `forge_license_activation`, which a download refers to) and the software's (a thread of
omnibase). The features add theirs. Generate the migration from the mapping:

```bash
bin/console doctrine:migrations:diff
```

## The configuration

Every key is optional ([Configuration reference](configuration.md)); the marketplace's keys
(`support_store`, `software_store`, `hourly_rate`, `invoicing`...) and the hub's are read only
with their feature:

```yaml
# config/packages/forge.yaml
forge:
    storage_dir: '%kernel.project_dir%/var/storage/forge'          # release archives, never under public/
    repositories_dir: '%kernel.project_dir%/var/storage/repositories'
    accel_prefix: '/_protected/forge/'   # nginx internal location; empty: PHP streams the file
    download_ttl: 600                    # seconds a signed link stays valid
    support_store: support               # marketplace store selling hour packs and quotes
    software_store: logiciels            # marketplace store selling licence offers
    hourly_rate: 6000                    # default rate of a quote line, in cents
    low_balance_minutes: 120             # below this, forge:hours:low-balance reminds the client
    contact_email: '%env(MAILER_CONTACT)%'   # where quote requests are mailed
    publish_token: '%env(FORGE_PUBLISH_TOKEN)%'   # opens the publish API; empty: closed
    # features: { marketplace: false }   # a feature turned off although its package is installed
```

With git/git-bundle, to open each client their own repositories in its viewer:

```yaml
# config/packages/git.yaml
git:
    access_role: ROLE_USER
    repository_attribute: GIT_VIEW       # Base\Forge\Security\Voter\GitRepositoryVoter
```

Every project and piece of software with a repository shows up in the viewer
through `Base\Forge\Git\ForgeRepositoryProvider`.

## nginx

The archives are handed out through an `internal` location:

```nginx
location /_protected/forge/ {
    internal;
    alias /srv/app/var/storage/forge/;
}
```

## How a purchase is delivered

With omnipack/marketplace, its `Checkout::confirm()` dispatches `OrderPaidEvent`, and
`Base\Forge\EventSubscriber\OrderPaidSubscriber` delivers the order:

| Product | Delivered |
|---|---|
| `HourPack` | minutes times quantity credited to the buyer's ledger |
| `LicenseOffer` | one `License` per unit (seats, duration and updates taken from the offer) |
| a quote's own `HourPack` | its hours credited, the quote marked paid |

Delivery is idempotent per order reference. Bank transfers (the `manual`
gateway) stay pending until someone in the back office posts to
`forge_admin_order_paid`, which confirms the order.

Licence keys (`glk_...`) are generated by their owner from
`/compte/licences` and shown once; only their SHA-256 is stored.

## The cron

| Command | Suggested | Feature |
|---|---|---|
| `forge:release:sync` | hourly | git |
| `forge:license:expire` | daily | marketplace |
| `forge:hours:low-balance` | weekly | marketplace |

## The templates

They extend the site's `layout1.html.twig`, use `forge-*` classes for the
site to style, and are overridden in `templates/bundles/ForgeBundle/` - the
core's under `client/downloads/` (`index`, `software`, `docs`, `_game`). Two
Twig functions tell them what is there: `forge_feature('marketplace')` (a
feature is on) and `forge_release_docs(release)` (a version has its
documentation).
