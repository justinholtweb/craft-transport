# Transport

Safely migrate content between Craft CMS 5 environments — with automatic dependency
resolution, field-level conflict review, asset transfer, multi-site support, and
snapshot-based rollback.

Transport exports the content you choose into a portable, inspectable `.zip` package and
imports it into another environment, resolving every reference by UID so nothing breaks
when IDs differ between sites.

## Features

- **Portable packages** — a `.zip` of JSON (`manifest.json` + per-type element files) plus
  bundled asset files. Human-readable and version-independent.
- **UID-based identity** — references (relations, authors, parents, Matrix/Hyper links)
  are stored as UIDs and resolved to local IDs on import. No fragile ID mapping.
- **Automatic dependency ordering** — a topological sort imports each element after the
  elements it depends on (structure parents, authors, relations), with cycle detection.
- **Field-level diff & selective merge** — review each changed field side by side and
  accept or reject it individually before importing.
- **Asset file transfer** — bundles real files from any volume and recreates them in the
  target.
- **Multi-site** — per-site title/slug/enabled/field values, with optional site mapping.
- **Snapshot-based rollback** — every import is snapshotted first; roll it back with one
  click. Rollbacks are themselves reversible.
- **CLI** — script exports, imports, history, and rollbacks.
- **Extensible** — register handlers for custom element and field types. Built-in
  conditional support for Commerce, Verbb Hyper, Neo, and Super Table.

## Requirements

- Craft CMS 5.3+
- PHP 8.2+
- `ext-zip`

## Installation

```bash
composer require justinholtweb/craft-transport
php craft plugin/install transport
```

## Exporting

**Control panel:** *Transport → Export*. Choose the site, the element types to include,
optionally a section, and whether to bundle asset files. Submit to download the package.

**CLI:**

```bash
craft transport/export --types=entries,categories,assets --site=default --output=content.zip
craft transport/export --section=blog --output=blog.zip
craft transport/export --all --output=everything.zip
```

## Importing

**Control panel:** *Transport → Import* runs a four-step wizard:

1. **Upload** the package.
2. **Configure** — review every element with its action (Add / Update / Unchanged) and
   select which to import. Pre-flight validation flags missing sections, entry types,
   groups, or volumes.
3. **Preview** — see field-level changes (current vs. incoming) and uncheck any field to
   keep the target's current value. Optionally run as a dry run or in the background.
4. **Run** — import and review the results.

**CLI:**

```bash
craft transport/import content.zip --dry-run   # simulate, report what would change
craft transport/import content.zip             # import
```

## History & rollback

*Transport → History* lists every import and export. Open an import to see its details
and error log. Completed imports can be **rolled back** with one click — Transport
restores updated elements to their prior state and deletes elements the import created.
Rollbacks are snapshot-protected, so they can be undone too.

```bash
craft transport/history
craft transport/rollback 42
```

## Settings

- **Temp path** — where packages are staged (`@storage/transport` by default).
- **Max package size** — upload limit for import.
- **Include asset files** — bundle files by default, or export metadata only.
- **Snapshot retention** — how long / how many import snapshots to keep.
- **Log level** — verbosity of `storage/logs/transport.log`.

## What Transport does not do

Transport moves **content**, not **schema**. Sections, fields, entry types, volumes, and
global sets are managed by Craft's Project Config. Transport's pre-flight validation
checks that the required schema already exists in the target before importing.

Orders and other transactional Commerce data are intentionally excluded.

## Extending

See [docs/EXTENDING.md](docs/EXTENDING.md) for registering custom element and field
handlers via `EVENT_REGISTER_ELEMENT_HANDLERS` and `EVENT_REGISTER_FIELD_HANDLERS`.

## Troubleshooting

- **"Missing section / entry type / group / volume"** — the target is missing schema the
  package needs. Deploy your Project Config first, then import.
- **A field didn't import** — unsupported field types are skipped with a warning in
  `storage/logs/transport.log` rather than failing the whole import.
- **An import went wrong** — roll it back from *Transport → History*.

## License

Proprietary (Craft License). A valid license must be purchased through the Craft Plugin
Store for each production install.
