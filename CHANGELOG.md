# Release Notes for Transport

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
