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
- **Recognises content you already have** — when a package element's UID isn't in the
  target, Transport matches the element that already exists by its natural key (a single's
  section, a slug within its section or group, an asset's filename, a user's email) and
  updates it, instead of duplicating it or failing on a URI clash.
- **Automatic dependency ordering** — a topological sort imports each element after the
  elements it depends on (structure parents, authors, relations), with cycle detection.
- **Field-level diff & selective merge** — review each changed field side by side and
  accept or reject it individually before importing.
- **Asset file transfer** — bundles real files from any volume and recreates them in the
  target.
- **Multi-site** — per-site title/slug/enabled/field values, with optional site mapping.
- **Snapshot-based rollback** — every import is snapshotted first; roll it back with one
  click. Rollbacks are themselves reversible.
- **Queued exports & imports** — control panel runs go to Craft's queue, so large jobs
  can't hit a request timeout, and email the person who started them when they finish.
- **Detailed reports** — every run records what was added, updated, skipped and failed,
  element by element, in the control panel, the console, and the completion email.
- **CLI** — script exports, imports, history, and rollbacks, with live progress.
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

### Updating

```bash
composer update justinholtweb/craft-transport
php craft up
```

Craft records Transport's schema version in your project config when its migrations run,
and normally writes that out to `config/project/` as the command exits. If the environment
has *pending project config changes* when you update, though, `craft up` applies config
before writing the files, stops on the version mismatch, and returns early — so the new
version never lands in the files:

```
Transport is installed with schema version of 1.1.0 while 1.0.0 was expected.
```

The database is already migrated at that point; only the config files are behind. Check
what's pending:

```bash
php craft project-config/diff
```

If none of it matters, `php craft project-config/write` regenerates the files with the new
version. If you need those changes applied, set the version in
`config/project/project.yaml` so the two agree:

```yaml
plugins:
  transport:
    schemaVersion: 1.1.0
```

then run `php craft up` again. Either way, commit the updated project config so other
environments don't hit the same mismatch.

## Exporting

**Control panel:** *Transport → Export*. Choose the site, the element types to include,
optionally a section, and whether to bundle asset files. Submitting queues the export —
it runs in the background so a large site can't time out. When it finishes, download the
package from *Transport → History*, and (if you asked to be notified) you'll get an email
with the full report.

**CLI:** console runs are always synchronous — never queued — and stream their progress
to the terminal, finishing with a detailed report:

```bash
craft transport/export --types=entries,categories,assets --site=default --output=content.zip
craft transport/export --section=blog --output=blog.zip
craft transport/export --all --output=everything.zip --verbose   # list every element
craft transport/export --all --output=everything.zip --quiet     # report only
```

## Importing

**Control panel:** *Transport → Import* runs a four-step wizard:

1. **Upload** the package.
2. **Configure** — review every element with its action (Add / Update / Unchanged) and
   select which to import. Pre-flight validation flags missing sections, entry types,
   groups, or volumes.
3. **Preview** — see field-level changes (current vs. incoming) and uncheck any field to
   keep the target's current value. Choose whether to be emailed when it finishes, or tick
   *Dry run* to simulate it.
4. **Run** — real imports are queued and run in the background; their report appears in
   *Transport → History* when they finish. Dry runs report back immediately.

**CLI:** console runs are always synchronous — never queued — and stream their progress
to the terminal, finishing with a detailed report:

```bash
craft transport/import content.zip --dry-run   # simulate, report what would change
craft transport/import content.zip             # import
craft transport/import content.zip --verbose   # list every element as it is processed
```

## History & rollback

*Transport → History* lists every import and export, and is where queued exports are
downloaded from. Open a run to see its full report — the elements added, updated, skipped
and failed, broken down by type — along with any errors. Completed imports can be **rolled back** with one click — Transport
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
- **Match existing content on import** — recognise elements that already exist here under
  a different UID and update them. Turn off for strict UID-only identity.
- **Notify on completion** — whether the "email me when it finishes" option starts switched
  on for exports and imports.
- **Additional notification emails** — addresses copied on every completion email.
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
- **"Could not generate a unique URI based on the URI format"** — the target already has
  that content under a different UID (most often a single). Transport now updates it in
  place; if you've turned *Match existing content on import* off, turn it back on, or give
  the two environments matching UIDs.
- **An import went wrong** — roll it back from *Transport → History*.
- **A queued export or import never finishes** — Craft's queue needs a runner. Check
  *Utilities → Queue Manager*, and make sure the queue is being run (Craft's web-based
  runner, or `craft queue/listen` under a process manager).
- **No completion email arrived** — Transport uses Craft's mailer; test it under
  *Settings → Email*. Notification failures are logged to `storage/logs/transport.log`
  and never fail the run itself.

## License

Proprietary (Craft License). A valid license must be purchased through the Craft Plugin
Store for each production install.
