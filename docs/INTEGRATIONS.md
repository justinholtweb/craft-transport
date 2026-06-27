# Plugin integrations

Transport works with any element and field out of the box for self-contained values, and
adds dedicated handlers where a plugin embeds environment-local element IDs that must be
made portable. Handlers are registered only when the host plugin is installed.

| Plugin | Status | Notes |
|---|---|---|
| **Craft core** (entries, categories, tags, users, assets, addresses, globals) | ✅ Supported & tested | Built-in element handlers. |
| **Matrix** | ✅ Supported & tested | Nested entries serialized recursively. |
| **Relations** (Entries/Assets/Categories/Tags/Users) | ✅ Supported & tested | IDs ↔ UIDs. |
| **Verbb Hyper** | ✅ Supported & tested | Element links (entry/category/asset/user/product/variant) made portable. |
| **Freelink** (justinholtweb) | ✅ Supported & tested | Reads the relations-backed link target and rewrites it as a UID. |
| **Freenav** (justinholtweb) | ✅ No handler needed | The field value is a menu *handle* (already portable). Migrating menu/node definitions is a separate concern, like sections. |
| **Asset files** | ✅ Supported & tested | Real file bytes bundled and recreated in the target volume. (Craft re-encodes EXIF-oriented JPEGs on upload, so bytes may differ; image content is preserved.) |
| **Craft Commerce 5** (products, variants) | ✅ Supported & tested | Product type, variants (SKU, base price, dimensions, tax/shipping category, custom fields). Inventory levels, catalog pricing, and orders are out of scope. |
| **Google Maps** (doublesecretagency) | ✅ Supported & tested | Address field; strips environment-specific owner keys, keeps the address data. |
| **SEOMatic** (nystudio107) | ✅ Supported & tested | SeoSettings field rewrites SEO/OG/Twitter image asset ids to portable UIDs and back. |
| **Verbb Navigation** | ✅ Supported & tested | Node elements migrate with their element links (→ UIDs), custom URLs, and nested structure; the nav is resolved by handle (it lives in project config). The Navigation *field* needs no handler (stores a nav handle). |
| **Neo** (spicyweb) | ⚠️ Conditional, experimental | Block-based handler; not yet exercised against a live Neo install. |
| **Super Table** (verbb) | ⚠️ Conditional, experimental | Block-based handler; not yet exercised against a live Super Table install. |
| **Formie** (verbb) | ✅ Supported & tested | Form definitions migrate via Formie's own export/import; submissions migrate with their field values (form resolved by handle, imported first). Submission values that reference elements rely on the relevant field handler. |
| **Solspace Calendar** | ✅ Supported & tested | Events migrate with dates, recurrence rules (freq/interval/byDay/etc.), and author; the calendar is resolved by handle. Canonical events only (not expanded occurrences). |

## Adding your own

Any plugin not listed here can be supported without changing Transport — see
[EXTENDING.md](EXTENDING.md). If a field's value is self-contained (no element IDs), it
already works via the generic handler; only fields that embed element references need a
custom handler.
