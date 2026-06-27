# Craft Transport — Implementation Plan

## Overview

**Craft Transport** is a paid Craft CMS 5 plugin for safely migrating content between environments. It improves on existing solutions (Migration Assistant, Zen) with automatic dependency resolution, field-level conflict review, queue-based export *and* import, Commerce/multi-site support, and zero-risk rollback.

---

## Competitive Analysis Summary

| Capability | Migration Assistant | Zen | **Craft Transport** |
|---|---|---|---|
| Export format | PHP migration files | ZIP (JSON + assets) | **ZIP (JSON + assets)** |
| Dependency resolution | Manual ordering | Auto (limited) | **Full auto with DAG** |
| Conflict review | None | Element-level accept/reject | **Field-level selective merge** |
| Queue support | Import only (via Craft) | Import only | **Both export & import** |
| Asset file transfer | No (must pre-exist) | Yes (local only) | **Yes (all volumes)** |
| Commerce support | No | Products/Variants | **Full Commerce** |
| Multi-site | Buggy | Yes | **Yes, with propagation awareness** |
| Rollback/undo | Craft migration rollback | No | **Built-in snapshot restore** |
| CLI support | Via Craft CLI | No | **Full CLI commands** |
| Third-party fields | 5 | 9 | **Extensible registry** |
| Diff/preview | No | Side-by-side | **Side-by-side + field-level diff** |

---

## Architecture

### Plugin Identity

- **Handle**: `craft-transport`
- **Namespace**: `justinholtweb\crafttransport`
- **Composer**: `justinholtweb/craft-transport`
- **License**: Proprietary (Craft License)
- **Single paid edition** (no free tier)
- **Requires**: `craftcms/cms ^5.3.0`, `php ^8.2`

### Directory Structure

```
craft-transport/
├── composer.json
├── LICENSE.md
├── CHANGELOG.md
├── src/
│   ├── Plugin.php                          # Main plugin class
│   ├── models/
│   │   ├── Settings.php                    # Plugin settings
│   │   ├── ExportConfig.php                # Export configuration model
│   │   ├── ImportConfig.php                # Import configuration model
│   │   ├── TransportPackage.php            # Represents a ZIP package
│   │   ├── ElementSnapshot.php             # Pre-import backup snapshot
│   │   ├── DiffResult.php                  # Diff between source/target
│   │   ├── DiffEntry.php                   # Single field-level diff
│   │   ├── DependencyGraph.php             # DAG for element ordering
│   │   └── ImportAction.php                # Per-element import decision
│   │
│   ├── controllers/
│   │   ├── ExportController.php            # Export UI + API
│   │   ├── ImportController.php            # Import wizard (upload/configure/preview/run)
│   │   ├── HistoryController.php           # View past imports, rollback
│   │   └── SettingsController.php          # Plugin settings
│   │
│   ├── services/
│   │   ├── Export.php                       # Orchestrates full export flow
│   │   ├── Import.php                       # Orchestrates full import flow
│   │   ├── Serializer.php                  # Element → portable array
│   │   ├── Normalizer.php                  # Portable array → element
│   │   ├── DependencyResolver.php          # Builds DAG, topological sort
│   │   ├── Differ.php                      # Field-level diffing engine
│   │   ├── Merger.php                      # Applies selective merge decisions
│   │   ├── Snapshotter.php                 # Pre-import backup + rollback
│   │   ├── PackageManager.php              # ZIP creation/extraction
│   │   ├── AssetTransfer.php               # Asset file bundling/extraction
│   │   ├── ElementRegistry.php             # Maps element types → handlers
│   │   ├── FieldRegistry.php               # Maps field types → handlers
│   │   └── ValidationService.php           # Pre-flight checks
│   │
│   ├── elements/                           # Element type handlers
│   │   ├── BaseElementHandler.php          # Abstract: serialize/normalize contract
│   │   ├── EntryHandler.php
│   │   ├── CategoryHandler.php
│   │   ├── TagHandler.php
│   │   ├── UserHandler.php
│   │   ├── AssetHandler.php
│   │   ├── GlobalSetHandler.php
│   │   ├── AddressHandler.php
│   │   └── commerce/
│   │       ├── ProductHandler.php
│   │       └── VariantHandler.php
│   │
│   ├── fields/                             # Field type handlers
│   │   ├── BaseFieldHandler.php            # Generic fallback
│   │   ├── RelationFieldHandler.php        # Entries/Assets/Users/Categories/Tags
│   │   ├── MatrixFieldHandler.php          # Nested entries
│   │   ├── TableFieldHandler.php
│   │   ├── LinkFieldHandler.php
│   │   ├── CkEditorFieldHandler.php
│   │   └── thirdparty/
│   │       ├── SuperTableFieldHandler.php
│   │       ├── NeoFieldHandler.php
│   │       ├── HyperFieldHandler.php
│   │       ├── SeomaticFieldHandler.php
│   │       └── NavigationFieldHandler.php
│   │
│   ├── queue/
│   │   ├── ExportJob.php                   # Batched export job
│   │   └── ImportJob.php                   # Batched import job
│   │
│   ├── console/
│   │   └── controllers/
│   │       └── TransportController.php     # CLI: export, import, rollback
│   │
│   ├── records/
│   │   ├── ImportHistory.php               # Tracks past imports
│   │   └── ElementSnapshot.php             # Snapshot data for rollback
│   │
│   ├── events/
│   │   ├── BeforeExportEvent.php
│   │   ├── AfterExportEvent.php
│   │   ├── BeforeImportEvent.php
│   │   ├── AfterImportEvent.php
│   │   ├── RegisterElementHandlersEvent.php
│   │   └── RegisterFieldHandlersEvent.php
│   │
│   ├── helpers/
│   │   ├── IdentityHelper.php              # UID/handle/slug resolution
│   │   ├── SiteHelper.php                  # Multi-site utilities
│   │   └── CommerceHelper.php              # Commerce-specific utilities
│   │
│   ├── migrations/
│   │   └── Install.php                     # Create plugin tables
│   │
│   ├── templates/                          # CP Twig templates
│   │   ├── export/
│   │   │   └── index.twig                  # Export configuration screen
│   │   ├── import/
│   │   │   ├── upload.twig                 # Step 1: Upload ZIP
│   │   │   ├── configure.twig              # Step 2: Review elements
│   │   │   ├── preview.twig               # Step 3: Field-level diff
│   │   │   └── run.twig                    # Step 4: Execute + progress
│   │   ├── history/
│   │   │   └── index.twig                  # Import history + rollback
│   │   └── _settings.twig
│   │
│   ├── web/assets/                         # CP CSS/JS bundles
│   │   └── transport/
│   │       ├── TransportAsset.php
│   │       ├── css/
│   │       └── js/
│   │
│   └── translations/
│       └── en/
│           └── craft-transport.php
```

---

## Core Features — Phased Implementation

### Phase 1: Foundation (Weeks 1–3)

**Goal**: Plugin skeleton, serialization engine, basic export/import of entries.

1. **Plugin scaffolding**
   - `composer.json` with Craft Plugin Store metadata
   - `Plugin.php` with service registration, CP nav, permissions
   - `Install.php` migration for `transport_history` and `transport_snapshots` tables
   - Settings model (temp directory, max package size, log verbosity)

2. **Serialization engine**
   - `Serializer` service: element → portable JSON using UIDs + handles (never raw IDs)
   - `Normalizer` service: portable JSON → element, resolving UIDs to local IDs
   - `IdentityHelper`: UID-based matching with fallback to handle+slug for Singles
   - Handle all field translation methods (not translatable, per-site, per-group, per-language)

3. **Field handler registry**
   - `FieldRegistry` with `EVENT_REGISTER_FIELD_HANDLERS` for extensibility
   - `BaseFieldHandler`: generic serialize/normalize for scalar fields
   - `RelationFieldHandler`: convert related element IDs ↔ UID references
   - `MatrixFieldHandler`: recursive serialization of nested entries with owner tracking

4. **Element handler registry**
   - `ElementRegistry` with `EVENT_REGISTER_ELEMENT_HANDLERS`
   - `EntryHandler`: sections, entry types, authors, parent/child, drafts exclusion
   - Basic export controller + template (select section → select entries → export)

5. **Package format**
   - ZIP containing: `manifest.json` (metadata, Craft version, plugin versions, site config) + `elements/` directory with one JSON file per element type + `assets/` directory for files
   - `PackageManager` service: create/extract/validate ZIP

### Phase 2: Full Element Coverage + Dependencies (Weeks 4–6)

6. **Remaining element handlers**
   - `CategoryHandler`, `TagHandler`, `GlobalSetHandler`, `UserHandler` (excluding passwords/sensitive data), `AssetHandler`, `AddressHandler`

7. **Dependency resolver**
   - `DependencyGraph` model: directed acyclic graph of element references
   - `DependencyResolver` service: walk serialized elements, extract all UID references from relation fields, Matrix owners, parent entries, structure positions
   - Topological sort for correct import ordering
   - Circular dependency detection with clear error messaging

8. **Asset file transfer**
   - Bundle actual asset files into ZIP (from any volume type, not just local)
   - On import: create asset files in target volume before creating Asset elements
   - Configurable: include files or metadata-only (for large media libraries)

9. **Multi-site support**
   - Export per-site field values respecting translation method
   - Site mapping UI: source site handle → target site handle (for handle mismatches)
   - Propagation-aware import: respect target section's propagation settings
   - Handle "Single Site Only" entries correctly

### Phase 3: Diffing, Preview & Conflict Resolution (Weeks 7–9)

10. **Diff engine**
    - `Differ` service: deep recursive comparison of serialized element data
    - Field-level granularity: each field gets its own diff status (added/changed/removed/unchanged)
    - Special handling for relation fields (show human-readable titles, not UIDs)
    - Matrix diff: block-level add/change/remove with nested field diffs

11. **Import wizard UI**
    - **Step 1 — Upload**: drag-and-drop ZIP upload with validation (Craft version check, schema compatibility check, duplicate detection)
    - **Step 2 — Configure**: tabbed element list showing action per element (Add/Update/Skip). Counts by type. Checkbox select/deselect.
    - **Step 3 — Preview**: expandable side-by-side diff view. Field-level accept/reject toggles. Visual indicators (green=add, yellow=change, red=remove). Rich preview for assets (thumbnail), relations (linked titles), Matrix (nested block tree).
    - **Step 4 — Execute**: progress bar via queue job, real-time status updates, error log display.

12. **Selective merge**
    - `Merger` service: applies user's per-field accept/reject decisions
    - "Accept all" / "Reject all" bulk actions
    - Conflict markers for fields changed on both source and target since last sync

### Phase 4: Safety, History & Rollback (Weeks 10–11)

13. **Pre-import snapshots**
    - `Snapshotter` service: before any import, serialize all affected elements' current state
    - Store in `transport_snapshots` table (compressed JSON)
    - Configurable retention (default: 30 days / last 20 imports)

14. **Rollback**
    - One-click rollback from import history screen
    - Restores element state from snapshot
    - Rollback is itself snapshot-protected (rollback of a rollback)

15. **Import history**
    - `transport_history` table: timestamp, user, package name, element counts, status, errors
    - CP screen: filterable list with details view
    - Per-import error log with element-level detail

16. **Validation & safety**
    - `ValidationService`: pre-flight checks before import
      - Schema compatibility (required sections, fields, entry types exist)
      - Site configuration match
      - Commerce plugin version compatibility
      - Disk space check for asset imports
    - Dry-run mode: simulate import without saving, report what would change
    - Never overwrite without explicit user confirmation
    - Transaction-wrapped imports: all-or-nothing per element batch

### Phase 5: Commerce & Third-Party Support (Weeks 12–14)

17. **Craft Commerce support**
    - `ProductHandler`: products with their variants, product types
    - `VariantHandler`: price, SKU, stock, dimensions, custom fields
    - Handle tax/shipping category references
    - Multi-store awareness
    - Exclude orders (too transactional/ephemeral for content migration)

18. **Third-party field handlers**
    - `SuperTableFieldHandler`
    - `NeoFieldHandler` (with level/nesting support)
    - `HyperFieldHandler` (Verbb Hyper links)
    - `SeomaticFieldHandler`
    - `NavigationFieldHandler` (Verbb Navigation)
    - Each conditionally loaded only when the host plugin is installed

19. **Third-party element support via events**
    - Document `EVENT_REGISTER_ELEMENT_HANDLERS` and `EVENT_REGISTER_FIELD_HANDLERS`
    - Provide `BaseElementHandler` and `BaseFieldHandler` as extension points
    - Example integration in docs

### Phase 6: CLI, Queue & Polish (Weeks 15–17)

20. **CLI commands**
    ```
    craft transport/export --section=blog --site=default --output=export.zip
    craft transport/export --all --since="2024-01-01"
    craft transport/import path/to/export.zip --dry-run
    craft transport/import path/to/export.zip --auto-accept
    craft transport/history
    craft transport/rollback <import-id>
    ```

21. **Queue-based export**
    - `ExportJob`: batched processing for large datasets (prevents memory exhaustion and timeouts — a known Zen weakness)
    - Progress reporting in CP toolbar
    - Export completion notification

22. **Queue-based import**
    - `ImportJob`: batched element processing with progress
    - Respects dependency ordering within batches
    - Per-element error capture (continues remaining elements on non-fatal errors)

23. **Logging & error handling**
    - Dedicated log target: `storage/logs/transport.log`
    - Structured log entries: element type, UID, action, status, error detail
    - CP-visible error summaries on import completion
    - Graceful degradation: skip unsupported field types with warnings instead of failing

24. **CP polish**
    - Craft-native UI patterns (using Craft's CP CSS framework)
    - Accessible table/form markup
    - Keyboard navigation in diff view
    - Toast notifications for async operations
    - Permission controls: `transport:export`, `transport:import`, `transport:rollback`

### Phase 7: Testing & Documentation (Weeks 18–19)

25. **Testing**
    - Unit tests: serializer/normalizer for each element and field type
    - Integration tests: round-trip export→import with fixture data
    - Multi-site test scenarios
    - Commerce test scenarios
    - Dependency resolution edge cases (circular refs, missing refs)
    - Rollback verification

26. **Documentation**
    - Plugin Store listing (description, screenshots, feature list)
    - User guide: export workflow, import wizard, CLI usage, rollback
    - Developer guide: extending with custom element/field handlers
    - Troubleshooting: common errors and resolutions

---

## Package Format Spec

```
transport-package.zip
├── manifest.json           # Package metadata
│   ├── version             # Package format version
│   ├── craftVersion        # Source Craft version
│   ├── pluginVersions      # Source plugin versions (commerce, etc.)
│   ├── sites               # Source site configuration
│   ├── exportedAt          # ISO 8601 timestamp
│   ├── exportedBy          # Username
│   └── elementCounts       # Summary counts by type
├── elements/
│   ├── entries.json        # All exported entries
│   ├── categories.json
│   ├── tags.json
│   ├── users.json
│   ├── globals.json
│   ├── assets.json         # Asset metadata
│   ├── products.json       # Commerce products
│   └── variants.json       # Commerce variants
└── files/                  # Actual asset files
    └── {volumeHandle}/
        └── {folderPath}/
            └── filename.ext
```

---

## Key Design Decisions

1. **UID-based identity** (not slugs/handles) — UIDs are globally unique and survive renames. Fall back to section+slug only for Singles which have different UIDs across environments.

2. **ZIP format over PHP migrations** — Portable, inspectable, version-independent. Users can examine the JSON. PHP migration files are fragile across Craft versions.

3. **Field-level merge** (not just element-level) — The biggest UX improvement over Zen. When an entry has 15 fields and only 2 changed, let the user accept/reject per field.

4. **Queue for both export and import** — Zen's synchronous exports cause timeouts on large sites. Both operations should be non-blocking.

5. **Snapshot-based rollback** — Neither competitor offers reliable undo. This is a key selling point for safety-conscious teams.

6. **Conditional Commerce/third-party loading** — Only register handlers when the host plugin is detected. No hard dependencies.

7. **No schema migration** — Craft's Project Config handles schema. Transport handles content only. Clear separation of concerns. Pre-flight validation ensures schema compatibility.

---

## Database Tables

### `transport_history`

| Column | Type | Description |
|---|---|---|
| id | int PK | Auto-increment |
| uid | char(36) | Craft UID |
| packageName | varchar(255) | Original ZIP filename |
| direction | enum(export,import) | Operation type |
| status | enum(pending,running,completed,failed,rolled_back) | Current status |
| elementCounts | json | `{entries: 5, assets: 2, ...}` |
| errorLog | text | JSON array of error entries |
| snapshotId | int FK nullable | Link to snapshot (imports only) |
| userId | int FK | Who ran it |
| dateCreated | datetime | |
| dateUpdated | datetime | |

### `transport_snapshots`

| Column | Type | Description |
|---|---|---|
| id | int PK | Auto-increment |
| uid | char(36) | Craft UID |
| historyId | int FK | Link to import history |
| elementData | longtext | Compressed JSON of pre-import state |
| dateCreated | datetime | |
