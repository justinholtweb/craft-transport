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
| **Google Maps** (doublesecretagency) | ✅ No handler needed | Address fields store self-contained JSON. |
| **Craft Commerce** (products, variants) | ⚠️ Conditional, experimental | Handlers ship but have not been exercised against a live Commerce install. Orders are intentionally excluded. |
| **Neo** (spicyweb) | ⚠️ Conditional, experimental | Block-based handler; not yet exercised against a live Neo install. |
| **Super Table** (verbb) | ⚠️ Conditional, experimental | Block-based handler; not yet exercised against a live Super Table install. |
| **Verbb Navigation** | ⛔ Planned | Like Freenav, the field references a navigation by handle. Migrating the navigations + nodes (nodes can link to elements) is an element/structure migration best built with the plugin installed for testing. |
| **Formie** (verbb) | ⛔ Planned | Forms and submissions are elements; migrating form *definitions* and/or submissions is a larger effort. A field that references a form would carry the form handle/UID. |
| **Freeform** (solspace) | ⛔ Planned | Similar to Formie — forms/submissions are a separate element-migration effort. |

## Adding your own

Any plugin not listed here can be supported without changing Transport — see
[EXTENDING.md](EXTENDING.md). If a field's value is self-contained (no element IDs), it
already works via the generic handler; only fields that embed element references need a
custom handler.
