# Citizen Edge

A Drupal package for the media file lifecycle: editors can see where media
is used, replace a file while keeping its URL, and delete a file so it
actually disappears — including from the hosting platform's edge cache,
which is what historically made replaced and deleted files "come back."

Acquia and Pantheon both cache files at the edge for about a year by default
(`max-age=31536000` on Acquia from core's `.htaccess`, `max-age=31622400` on
Pantheon), so without purging, a replaced or deleted file keeps serving at
its old URL long after Drupal did the right thing.

## Renamed from citizen_media_files (3.0.0)

Versions before 3.0.0 shipped as `citizen_media_files` (Composer package
`electriccitizen/citizen_media_files`). Drupal treats the new name as a
different module, so a site already running the old one migrates like this:

```bash
composer remove electriccitizen/citizen_media_files
composer require electriccitizen/citizen_edge
drush pm:uninstall citizen_media_files_standard citizen_media_files -y
drush en citizen_edge citizen_edge_standard -y   # standard only if it was on
drush cex -y
```

Uninstalling the old module strips its usage-count field from every view
first (so no view is deleted by the config dependency cascade), and enabling
the new one re-adds it under the new plugin id `citizen_edge_usage_count`.
Settings keys moved from `citizen_media_files_*` to `citizen_edge_*`, the
retry queue is `citizen_edge_edge_purge`, drush commands are `ce:*` (the old
`cmf:*` aliases still work), and the `replace media files` permission keeps
its name.

## What it bundles

Requiring this one package pulls in and (on enable) installs:

- **entity_usage** — tracks where each media entity is used. Media with 0
  usage on the current published version is safe to delete.
- **media_entity_file_replace** — "Replace file" on the media edit form. The
  overwrite option keeps the same filename and URL (no `-0`/`-1` suffixes).
- **media_file_delete** — deletes the underlying file when a media entity is
  deleted.
- **Edge purging (this module)** — when a file is replaced or deleted, its
  URL (and every image style derivative URL) is purged from the platform
  edge cache, host-detected at runtime, with a retry queue for failures.

Plus drush tooling (`ce:setup`, `ce:purge`, `ce:edge-test`) and a
query-free Views field for a per-row usage count.

## Requirements

- Drupal `^10.3 || ^11` with the `media` and `file` core modules.
- **Acquia with Platform CDN (Fastly)**: nothing extra. The module purges
  files through the Fastly API using the Platform CDN credentials Acquia
  already injects (`$settings['acquia_service_credentials']['platform_cdn']`),
  so it does not depend on the `acquia_purge` URL purger for files. This is
  what lets it work behind a WAF (see "How purging works").
- **Acquia without Platform CDN (Varnish only)**: `drupal/purge` and
  `drupal/acquia_purge`, with the Varnish (Cloud) purger configured (it
  supports `url` invalidations). This module registers its own purge queuer
  and the site's purge processors (late runtime / cron) drain the queue. The
  purge modules are deliberately NOT hard dependencies, so the package
  installs cleanly on non-Acquia sites; if purge is missing on such a host,
  every file change logs a loud warning instead of purging.
- **Pantheon**: nothing extra — the platform provides
  `pantheon_clear_edge_paths()`, which this module calls directly.
- **Cloudways**: there is nothing to purge, by design. Cloudways serves
  static files nginx-direct — bypassing Varnish and Apache — with no
  server-side cache (vendor-confirmed). Disk changes are publicly visible
  instantly; the only stale-file exposure is downstream TTL (browsers, any
  CDN in front). The remedy is the panel setting Server Settings > Advanced >
  Nginx > Static Cache Expiry (default 1 year, server-global). The module
  detects Cloudways from its filesystem layout and logs an informational
  no-op on file changes; the chained Cloudflare purge still applies where
  configured.
- **Anything else** (local, unknown hosts): no purging; the module logs what
  it would have purged.

## Installing

```bash
composer require electriccitizen/citizen_edge
drush en citizen_edge -y
drush cex -y   # commit the config the setup produced
```

Enable dependency patching once in the site's root `composer.json` so the
contrib patch this module declares (see below) applies automatically:

```json
"extra": {
    "enable-patching": true
}
```

Then **read the `citizen_edge` log channel** (or run
`drush ce:setup --dry-run` beforehand). The setup adapts to the site and
reports every decision — what it configured and what it deliberately left
for a human.

## What setup configures — and what it refuses to touch

The setup routine applies a baseline only into a vacuum. Existing site
choices are never overwritten; each skipped piece is reported instead.

- **entity_usage settings**: track node/paragraph/block_content sources
  against media/node targets across the entity_reference, entity_embed,
  media_embed, link, linkit, html_link, and dynamic_entity_reference
  plugins; usage tab on media and nodes; delete warning on media. Applied
  only when stored config is byte-identical to entity_usage's shipped
  defaults. Any deviation means the site configured it, and it is left
  alone.
- **Replace widget**: enabled on the form display of every file-backed media
  type, unless the stored display config explicitly hides it.
- **Usage count column**: added to the media admin view via this module's
  own non-SQL views field (per-row count + "View Usage" link). Skipped when
  the view already has a usage column or does not exist. Never retrofits
  views aggregation onto an existing view — that breaks views whose fields
  do not aggregate.
- **Permissions**: `access entity usage statistics` and `delete any file`
  granted to a `site_manager`, `site_admin`, or `manager` role when one
  exists; otherwise a warning says what to grant manually. Note that
  `delete any file` is core's restrict-access permission over every file
  entity, not only media-backed ones, so confirm the role that receives it
  is the right one on each site. The `administer media file delete`
  settings permission is deliberately not granted.
- **One unconditional opinion**: media_file_delete's "also delete the file"
  checkbox defaults to checked, because an unchecked default quietly
  recreates the orphaned-file problem this package exists to solve. Editors
  can still uncheck it per delete.

### Re-running and previewing setup

`hook_install()` runs the setup once. The same routine is available any time,
idempotently, as a drush command — for reviewing what a site will get before
enabling, and for re-aligning a site after a media type or role is added:

```bash
drush ce:setup --dry-run      # report decisions, save nothing
drush ce:setup                # apply the baseline
drush ce:setup --standard     # also apply the standard (submodule enabled)
```

Every decision prints as a table and, when applied, also logs to the
`citizen_edge` channel.

### Why the setup is code, not a Recipe

Drupal Recipes were evaluated for this. A recipe's config actions need
concrete config names: `core.entity_form_display.media.<type>.default`
requires knowing each site's media type IDs, and `user.role.<id>:
grantPermissions` requires knowing which role is the manager. Those differ
on every site this package targets, which is exactly the variability the
adaptive setup exists to absorb. A recipe also cannot merge columns into an
existing media view without custom code. The reviewability a recipe would
offer is provided instead by `ce:setup --dry-run`, which prints the exact
decisions for the site in front of you. If a fleet standardises on fixed
media type and role names, a thin recipe wrapping this module becomes
straightforward, and the setup routine is written so that can happen without
changing the runtime.

## Optional: standard enforcement (`citizen_edge_standard`)

The base module adapts and defers to existing config. Enabling
`citizen_edge_standard` is the opt-in to a fixed standard, and it
deliberately OVERRIDES existing configuration:

- Forces the baseline entity_usage settings.
- Forces the Replace file widget onto every file-backed media type.
- Forces Media File Delete to delete the file with the media entity.
- Applies a permission matrix: `site_manager` gets delete/replace/batch
  powers (a `replace media files` permission, defined here, gates the
  replace widget); `editor` gets usage statistics and loses delete/replace.
  Custom roles that already hold delete permissions are treated as
  manager-tier (client-configured), never revoked, and granted the working
  set. On sites without a `site_manager` role the matrix is NOT applied —
  only the grandfathering pass runs, with a warning telling you what to set
  up manually. Anonymous or authenticated holding delete permissions is
  warned about, never touched.
- Merges the standard COLUMNS into the site's existing media view — bulk
  ops, Thumbnail, Media name, Provider, Image size, Usage count, Author,
  Changed, Actions — while leaving the site's filters, sorts, pager, style,
  and aggregation settings untouched, and adds media admin CSS.
  Site-conditional columns prune where the field is absent (Image size needs
  `field_media_image`). Never enables views aggregation. The rollback for
  the merge is the site's exported config in git (a copy of the previous
  view is also kept in state under
  `citizen_edge_standard.previous_view`, but state does not survive a
  database refresh; git does).

```bash
drush ce:setup --standard --dry-run   # preview
drush en citizen_edge_standard -y
drush cex -y
```

## Settings

```php
// Force or disable host detection (default: AH_SITE_ENVIRONMENT => acquia,
// PANTHEON_ENVIRONMENT => pantheon, Cloudways filesystem layout => cloudways,
// otherwise none/log-only). Set 'none' on hosts that set a platform variable
// but where no purge stack can be installed.
$settings['citizen_edge_host'] = 'acquia'; // 'pantheon' | 'cloudways' | 'none'

// Public base URLs to purge. Varnish/CDN cache one object per scheme+host,
// so multi-domain sites must purge every public domain, and CLI-generated
// URLs (cron, drush) would otherwise purge http:// variants that miss the
// cached https:// object. Without this setting, the generated host is kept
// and the scheme is forced to https.
$settings['citizen_edge_base_urls'] = [
  'https://www.example.org',
  'https://exampleprod.prod.acquia-sites.com',
];

// Per-request cap on inline deferred purge URLs (default 500). A request
// that defers more than this (bulk delete, migration) hands everything to
// the cron retry queue instead of firing hundreds of API calls at terminate.
$settings['citizen_edge_inline_purge_limit'] = 500;

// Optional: override Acquia Platform CDN (Fastly) credentials. On Acquia,
// these are read automatically from the platform's own
// $settings['acquia_service_credentials']['platform_cdn'], so you normally
// set nothing. Provide them explicitly only for a Fastly service that is not
// delivered through Acquia's platform config, keeping the token out of
// committed code. See the Acquia Platform CDN section below.
$settings['citizen_edge_fastly'] = [
  'service_id' => '<fastly service id>',
  'token' => getenv('FASTLY_API_TOKEN'),
];

// Optional Cloudflare layer — see below.
$settings['citizen_edge_cloudflare'] = [
  'zone_id' => '<zone id>',
  'api_token' => getenv('CLOUDFLARE_API_TOKEN'),
];
```

## How purging works

- **Triggers**: `hook_file_update()` (covers Media Entity File Replace's
  overwrite, which re-saves the file entity) and `hook_file_delete()`
  (covers Media File Delete). Public-scheme permanent files only. A fresh
  upload's temporary-to-permanent save is skipped: nothing has ever been
  served at that URL with different contents, so there is nothing to purge.
- **Images**: every image style's derivative URL is purged, without checking
  whether the derivative exists on disk. On network filesystems each
  `file_exists()` is a slow remote stat, and at 100+ styles that added ~8
  measured seconds INSIDE the editor's save request. Purging a URL the edge
  never cached is a no-op, so blind purging trades bounded purge-queue noise
  for seconds of editor-facing latency.
- **Deferral**: Acquia Platform CDN (Fastly), Pantheon, and Cloudflare purges
  are network calls, so they are collected during the request, deduplicated,
  and executed at kernel terminate, after the response has been sent. Acquia
  *without* Platform CDN stays inline because its purge is only a local queue
  add; the purge module's processors do the network work and carry their own
  retry semantics.
- **Retry**: a Fastly, Pantheon, or Cloudflare purge that fails is placed on
  the core queue `citizen_edge_edge_purge` and retried by cron, up to five
  attempts, then logged as an error with the URLs so it can be purged by
  hand. Drain on demand with `drush queue:run citizen_edge_edge_purge`.
- **Acquia Platform CDN (Fastly)**: file URLs are purged through the Fastly
  API (`POST api.fastly.com/purge/<url>`) using the site's Platform CDN
  credentials, not through `acquia_purge`'s URL purger. That purger sends an
  HTTP `PURGE` to the file URL, which a WAF in front of the domain (e.g.
  Radware Bot Manager, common on government sites) challenges before it
  reaches Fastly, so files never clear. The API call goes to `api.fastly.com`
  and never touches the public domain, so no WAF intercepts it — the same
  channel Acquia's own documented tag and domain purges use. Purges are hard,
  so a deleted file's URL returns to a 404 at once.
- **Pantheon** paths are sent with their query strings. The Global CDN keys
  derivative URLs on `?itok=` and `pantheon_clear_edge_paths()` honors it
  (verified on a live environment: a purged derivative reset to age 0 while
  an untouched control URL kept its age). Chunked 25 paths per call.
- Every purge, skip, queueing, and failure logs to the `citizen_edge`
  channel. Silence is never a failure mode.

## Cloudflare layer (optional, chained)

Sites behind a Cloudflare zone you control have a third cache: Cloudflare
caches files at its edge honoring the origin's cache-control. When
configured, the module purges Cloudflare BY URL in addition to the host
purge, on every replace and delete, with the same retry queue. The token
needs only Zone > Cache Purge. Unconfigured sites skip this silently. Never
configure it against a Cloudflare account that belongs to someone else.

## Editor workflow (train content teams on this)

1. Check usage before deleting: the media list's "Usage count" column or the
   Usage tab. Zero usage on the published version means safe.
2. Replace: media edit form, "Replace file", keep the overwrite box checked,
   and the replacement file type must match the original.
3. Delete: deleting the media entity deletes the file too (checkbox defaults
   on).
4. Sensitive removals additionally need a Google Search Console removal
   request.

### What the safety nets do not see

Two cases slip past both entity_usage and Media File Delete's file-usage
guard, and editors should know it:

- **A file linked by bare URL in body text** (`/sites/default/files/x.pdf`
  pasted as a link rather than inserted as media). Core file usage does not
  track it, and entity_usage tracks the media entity, not the file. Deleting
  the media 404s that link with no warning. Adding `file` to entity_usage's
  target types makes such links visible on the file's usage tab, but adds no
  guard on delete, because Media File Delete consults core file usage only.
- **Files on plain file or image fields with no media entity.** Not media, so
  none of this applies; deletion follows core's rules.

## What purging cannot fix

Browser caches that already hold a file (served with a year-long max-age
before this module was installed), search engine indexes (use a removal
request for sensitive documents), and third-party archives. Consider
shortening the file max-age in `.htaccess` alongside this module so browsers
re-check files at a sane interval.

## Drush commands

- **`drush ce:setup [--dry-run] [--standard]`** — apply or preview the site
  setup; see above.
- **`drush ce:purge <target>`** — purge a file's URLs from the edge on
  demand. Takes a fid (includes image style derivatives), a `public://` URI,
  a `/sites/default/files/...` path, or a full URL. The support tool for
  "this file is still stale" — including files that went stale through
  paths the hooks do not see (SFTP replacement, migrations, pre-rollout
  objects).
- **`drush ce:edge-test [--base-url=...] [--auth=user:pass]`** — the
  rollout verifier: creates a throwaway file, warms the edge, proves
  stale-serve after a disk-only overwrite, triggers the module purge and
  asserts fresh content, deletes and asserts the URL is gone. Self-cleaning;
  drains the purge queue inline on Acquia. Reports INCONCLUSIVE on
  environments that do not edge-cache files instead of a false verdict. Run
  once per host with `--base-url` on genuinely multi-host sites. A curl from
  a real client machine remains the gold-standard final check.
- **`drush queue:run citizen_edge_edge_purge`** — drain the retry
  queue now instead of waiting for cron.

## Known edge-layer limits

The module purges the HOSTING platform's edge (Acquia Varnish/Platform CDN,
Pantheon Global CDN) and, optionally, a Cloudflare zone you control. Any
other CDN stacked in front of the site is a separate cache this module
cannot reach.

## Required contrib patch: slow replace-saves (media_entity_file_replace)

media_entity_file_replace's submit handler calls `image_path_flush()`
unconditionally — even when the replaced file is a document. Core's
`ImageStyle::flush()` then stats a would-be derivative for EVERY image style
on the site, and on network filesystems each stat is a slow remote call.
Measured on one production site with 126 image styles: 7.96s for the flush
alone, turning a ~1.1s document replace-save into ~9-10s.

Fix: filed upstream as
[#3619311](https://www.drupal.org/project/media_entity_file_replace/issues/3619311)
(skips the flush for non-image files; image replaces still flush,
correctly). This module DECLARES the patch in its own `composer.json` by its
public drupal.org URL, so Composer-consuming sites only need
`"enable-patching": true` in the root `composer.json` (cweagans/composer-patches
v1). The patch — and any future patch this module ships — then applies
automatically when the module is installed or updated.

Sites that vendor the module by hand instead of consuming it via Composer
never see the module's declaration and must wire the patch in their root
`composer.json` themselves: copy
`patches/media_entity_file_replace-skip-flush-non-images.patch` into the
site's own `patches/` directory and add a root `extra.patches` entry pointing
at it. Do NOT reference the patch inside this module's install path from a
site's root `composer.json`: on a fresh `composer install` the patched
package can install before this module exists on disk, the patch file is
missing at apply time, and `composer-exit-on-patch-failure` aborts the
whole build.

If upstream merges #3619311 and cuts a release, drop the declaration and
bump the constraint instead.

Image replaces on style-heavy NFS sites remain slower than document
replaces — that cost is core's flush design. A site with 100+ image styles
should audit them; it helps every save path, not just this module's.

## Development

Kernel tests cover URL collection, deferral, dedupe, the new-upload skip,
the inline limit, the retry queue, and every branch of the setup routines
(fresh site, dry run, idempotency, respected site choices, the permission
matrix with and without `site_manager`, the view merge). From a site root
with `drupal/core-dev` installed:

```bash
SIMPLETEST_DB=mysql://user:pass@host/db vendor/bin/phpunit -c web/core \
  web/modules/contrib/citizen_edge/tests
```

Coding standards: `phpcs --standard=Drupal,DrupalPractice` clean.

Changes land in this repository first and reach sites through Composer.
Site repositories should never carry local edits to the module.
