# Release Notes for Transport

## 5.1.2 — 2026-10-04

### Fixed
- User group memberships were exported but never applied. An imported user arrived in no groups,
  and an updated one kept whatever they had. Imports now set them, and rollbacks restore them.
  Admins and console runs set exactly the groups the package names. Anyone else changes only the
  groups they may assign users to (Craft's *Assign users to “…”* permissions); memberships in
  other groups stay as they were, and the report says which. A group that doesn't exist here is
  skipped and reported.

## 5.1.1 — 2026-10-03

### Security
- An asset's filename in a package was used as given when staging its file, so a package
  with a name like `../../web/x.php` could write a file anywhere the web server could, even
  on a dry run. Staged files now use only the base name, sanitized like an upload, in a
  directory of their own. A name whose extension isn't in `allowedFileExtensions` is refused
  before anything is written.
- The export package name accepted paths, so a package could be written outside the temp
  directory. It is now a filename: letters, numbers, dots, dashes and underscores.
- *Transport → History* and its run reports opened for any control panel user. They now
  need one of the Transport permissions.
- Anyone with the export permission could download any package, including one an admin
  exported. Now only the exporter or an admin can.
- The Transport permissions were the only check. An export read every section, volume and
  user account. An import or rollback wrote to all of them, including admin accounts. Each
  is now bounded by the person's own Craft permissions; see *Permissions* in the README.
  Admins and console runs are unchanged.
- The *Max package size* setting wasn't enforced. Uploads larger than it are now refused,
  as are packages that unpack to more than 20 times it, or that aren't zips.

### Changed
- The import screens use Craft's own form fields and notices instead of hand-built markup
  and hard-coded colours.

> {note} If people who aren't admins run imports, check their section, volume and user
> permissions first. An import that includes anything they couldn't save by hand now fails
> as a whole: its report lists each refused element, and nothing from it is kept.

## 5.1.0 — 2026-08-17

> {note} Control panel exports and imports now run on Craft's queue, so make sure a queue
> runner is active in each environment. Exports no longer download straight from the
> Export screen — queue one and download the finished package from *Transport → History*.

> {note} Imports now update content that already exists in the target under a different
> UID rather than adding a second copy of it. Turn this off under *Settings → Match
> existing content on import* if you rely on strict UID-only identity.

> {warning} This release adds a database column, so Transport's schema version moves from
> 1.0.0 to 1.1.0. Craft records that in your project config for you when the migration runs
> — but if the environment also has pending project config changes, `craft up` applies
> config before it writes the config files back out, stops on the version mismatch
> (“Transport is installed with schema version of 1.1.0 while 1.0.0 was expected”), and
> returns before the new version reaches `config/project/`. Your database is already
> migrated at that point; only the config files are behind. Run `php craft
> project-config/diff` to see what's pending: if none of it matters, `php craft
> project-config/write` regenerates the files with the new version; if you need those
> changes applied, set `plugins.transport.schemaVersion: 1.1.0` in
> `config/project/project.yaml` so the versions match, then run `php craft up` again.

### Added
- Control panel exports and imports now run as queue jobs, so large runs can't hit a
  request timeout. Queued exports are downloaded from *Transport → History* when they
  finish; queued imports report back there too.
- Optional completion emails: tick "email me when it finishes" on the export or import
  screen to get the full report at your account's address. Extra recipients and the
  default state of that option are configurable in the plugin settings.
- Detailed run reports (`TransportReport`) covering every element added, updated, skipped
  and failed — with the reason for each skip and failure, a per-element-type breakdown,
  and run duration. Reports are stored on the history row, shown on the history detail
  screen, printed by the console commands, and included in completion emails.
- `--verbose` and `--quiet` options on `transport/export` and `transport/import`.
- `Export::run()` and `Import::run()`, which accept a `ProgressInterface` and return a
  `TransportReport`. `AfterExportEvent` and `AfterImportEvent` now carry that report.

### Changed
- Console exports and imports never queue: they run inline and stream live progress —
  a progress bar per stage plus the closing report — so you can watch them work.
- The import wizard's "run in the background" checkbox is gone; real imports always are.
  Dry runs still run inline and report back immediately.
- The export screen no longer streams the package straight back as a download, since the
  export now runs on the queue.

### Fixed
- Content that already exists in the target under a different UID — a single created
  independently in each environment, content seeded before Transport was installed — is
  now recognised and **updated** instead of imported as a second copy. Previously such an
  element was treated as new, which duplicated it or, where Craft couldn't generate a
  unique URI for it (singles, and any section whose URI format has no `{slug}` token),
  failed the whole import with "Could not generate a unique URI based on the URI format."
  Matching uses each element type's natural key: a single's section, a slug within its
  section or group, an asset's filename in its folder, a user's email, a product's slug
  within its type, a variant's SKU. Turn it off under *Settings → Match existing content
  on import* for strict UID-only identity.
- Every site of a multi-site element now writes to the one element the import identified,
  instead of being re-matched per site — which could create a duplicate when a site's
  slug differed.
- Pre-import snapshots resolve elements the same way the import does, so rolling back an
  import that updated pre-existing content restores it rather than deleting it.
- The import preview no longer shows "Add" for an element the import will actually
  update; matched elements are flagged as such in the wizard.
- Imports now report elements that matched no site in the target as skipped, with the
  reason, instead of silently counting them.

## 5.0.3 — 2026-07-26

### Added
- Comprehensive automated test coverage: a PHPUnit unit suite (dependency
  resolution, selective merge, and the package/diff/settings/config models) and a
  Craft-booted Codeception integration suite exercising the full export → import
  pipeline — serialization, relation/Matrix field portability, dependency ordering,
  selective merge, snapshot/rollback, asset file transfer, and pre-flight validation.

## 5.0.2 — 2026-06-28

### Changed
- Updated plugin icon and icon mask.

## 5.0.1 — 2026-06-27

### Added
Correct Craft license.

## 5.0.0 — 2026-06-27

### Added

- Initial Phase 1 foundation: plugin scaffolding, settings, and the export/import database layer.
- Serialization engine that converts elements to a portable, UID-based JSON representation.
- Field handler registry (`EVENT_REGISTER_FIELD_HANDLERS`) with handlers for scalar, relation, and Matrix fields.
- Element handler registry (`EVENT_REGISTER_ELEMENT_HANDLERS`) with an entry handler.
- ZIP package format (`manifest.json` + `elements/` + `files/`) via `PackageManager`.
- Control panel export screen for selecting and exporting entries.

#### Phase 2 — full element coverage, dependencies, assets, multi-site

- Element handlers for categories, tags, global sets, users, assets, and addresses.
- Dependency resolver: builds a `DependencyGraph` of UID references and topologically
  sorts the import so each element is created after the elements it references
  (structure parents, authors, relations), with cycle detection.
- Asset file transfer: bundles asset files into the package from any volume and
  recreates them in the target volume on import (`AssetTransfer`).
- Multi-site serialization: per-site title/slug/enabled/field-values, with a per-site
  import save loop and optional source→target site handle mapping.
- Export screen now offers element-type selection.

#### Phase 3 — diffing, preview & conflict resolution

- Diff engine (`Differ`): field-level, per-site comparison of each package element
  against its target counterpart, classified add/update/unchanged with human-readable
  values (relation titles, Matrix block counts).
- Import wizard: upload → configure (per-element actions + selection) → preview
  (field-level diff with per-field apply/reject toggles) → run.
- Selective merge (`Merger`): rejected fields keep the target's current value; accepted
  fields apply the incoming value. Import accepts a selected-UID set and per-field
  decisions.

#### Phase 4 — safety, history & rollback

- Pre-import snapshots (`Snapshotter`): captures the prior state of every affected
  element before import (compressed JSON in `transport_snapshots`), recording updates
  with their full prior data and creations as deletable.
- One-click rollback from the History screen: restores updated elements to their prior
  state and deletes elements the import created. Rollback is itself snapshot-protected.
- Import history: real imports are recorded up front (status `running`) and updated to
  `completed`/`failed`, with a per-import detail view and error log.
- Pre-flight validation (`ValidationService`): checks the target has the required
  sections, entry types, category/tag groups and volumes, that referenced sites exist,
  and that there is disk space for bundled files. Blocking errors stop the import.

#### Phase 5 — Commerce & third-party support

- Verbb Hyper field handler: rewrites element-link targets (entry/category/asset/user/
  product/variant) to portable UID references and back.
- Craft Commerce product + variant element handlers (variants serialized inline),
  registered only when Commerce is installed.
- Neo and Super Table field handlers (block-based, recursive), registered only when the
  host plugin is installed.
- All third-party handlers load conditionally — Transport keeps no hard dependencies.
- Developer documentation (`docs/EXTENDING.md`) for adding custom element/field handlers
  via `EVENT_REGISTER_ELEMENT_HANDLERS` / `EVENT_REGISTER_FIELD_HANDLERS`.

#### Phase 6 — CLI, queue & logging

- Console commands: `transport/export` (`--section`, `--site`, `--types`, `--all`,
  `--output`, `--metadata-only`), `transport/import <path>` (`--dry-run`),
  `transport/history`, and `transport/rollback <id>`.
- Background queue jobs (`ExportJob`, `ImportJob`); the import wizard can run large
  imports in the background.
- Dedicated `storage/logs/transport.log` target with structured import summaries.

#### Phase 7 — testing & documentation

- README with feature overview, install, export/import workflows, CLI, and rollback.
- Export/import lifecycle events: `Export::EVENT_BEFORE_EXPORT` / `EVENT_AFTER_EXPORT`
  and `Import::EVENT_BEFORE_IMPORT` / `EVENT_AFTER_IMPORT` (before-events can cancel).
- Test suites: unit tests (dependency graph/resolver, selective merge, package + diff
  models) and integration tests that round-trip the full export/import pipeline against
  a live Craft app (UID identity, recreation, dependency ordering, merge, rollback).

#### More integrations

- Freelink field handler (justinholtweb): rewrites relations-backed element links to
  portable UID references and restores them on import.
- Asset file transfer verified end to end against a live volume (bundle + recreate).
- Craft Commerce 5 product/variant migration verified (product type, SKU, base price,
  dimensions, tax/shipping category, custom fields).
- Google Maps Address field handler: strips environment-specific owner keys.
- SEOMatic SeoSettings field handler: rewrites SEO/OG/Twitter image asset ids to UIDs.
- Verbb Navigation node migration: element links, custom URLs, and nested structure.
- Formie support: form definitions (via Formie's export/import) and submissions with
  their field values.
- Solspace Calendar events: dates, recurrence rules, and author (canonical events).
- SEOMatic SeoSettings image asset references made portable.
- Documented integration status for all supported and planned plugins
  (`docs/INTEGRATIONS.md`).
