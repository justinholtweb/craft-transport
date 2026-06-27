# Extending Transport

Transport resolves every element and custom field through pluggable handler
registries. You can add support for your own element types or third-party field types
without modifying Transport.

## Concepts

- **Portable identity.** A package never contains environment-local element IDs. Every
  reference is stored as a UID and resolved back to a local ID on import. Your handlers
  must follow this rule.
- **Field handlers** convert a single custom field's value to/from the portable
  representation (`justinholtweb\transport\fields\FieldHandlerInterface`).
- **Element handlers** convert a whole element type — its type-specific attributes and
  how to recreate it (`justinholtweb\transport\elements\ElementHandlerInterface`).

## Registering a field handler

```php
use justinholtweb\transport\services\FieldRegistry;
use justinholtweb\transport\events\RegisterFieldHandlersEvent;
use yii\base\Event;

Event::on(
    FieldRegistry::class,
    FieldRegistry::EVENT_REGISTER_FIELD_HANDLERS,
    function (RegisterFieldHandlersEvent $event) {
        // Earlier entries win, so prepend to take precedence over the built-ins.
        array_unshift($event->handlers, MyFieldHandler::class);
    }
);
```

A field handler declares which fields it handles and (de)serializes their values. The
contract:

```php
interface FieldHandlerInterface
{
    public function canHandle(FieldInterface $field): bool;
    public function serialize(ElementInterface $element, FieldInterface $field): mixed;
    public function normalize(mixed $data, FieldInterface $field, ?ElementInterface $element): mixed;
    public function collectReferences(ElementInterface $element, FieldInterface $field): array;
}
```

`collectReferences()` returns the UIDs the field value depends on, so the dependency
resolver imports those elements first.

### Worked example: a link field that references elements

The bundled `HyperFieldHandler` is a good template. Verbb Hyper stores element links as
a local element id; the handler rewrites that id to a `{uid, type}` reference on export
and resolves it back on import:

```php
public function serialize(ElementInterface $element, FieldInterface $field): mixed
{
    $serialized = $field->serializeValue($element->getFieldValue($field->handle), $element);
    foreach ($serialized as &$link) {
        if ($this->isElementLink($link)) {
            $linked = Craft::$app->getElements()->getElementById((int)$link['linkValue'][0], ...);
            $link['linkValue'] = ['_uid' => $linked->uid, '_type' => get_class($linked)];
        }
    }
    return $serialized;
}
```

For scalar / self-contained fields you don't need a handler at all — the generic
`BaseFieldHandler` delegates to Craft's own (de)serialization, which is correct for
text, number, dropdown, date, money, and similar fields (including Google Maps address
fields, which store self-contained JSON).

## Registering an element handler

```php
use justinholtweb\transport\services\ElementRegistry;
use justinholtweb\transport\events\RegisterElementHandlersEvent;

Event::on(
    ElementRegistry::class,
    ElementRegistry::EVENT_REGISTER_ELEMENT_HANDLERS,
    function (RegisterElementHandlersEvent $event) {
        $event->handlers[] = MyElementHandler::class;
    }
);
```

Extend `BaseElementHandler` and implement the type-specific pieces:

```php
class MyElementHandler extends BaseElementHandler
{
    public function elementType(): string { return MyElement::class; }
    public function packageKey(): string { return 'myelements'; }   // package filename + UI bucket
    public function query(): ElementQueryInterface { return MyElement::find()->status(null); }

    public function serializeAttributes(ElementInterface $element): array { /* handles, parents, owners as UIDs */ }
    public function makeElement(array $attributes): ?ElementInterface  { /* build a new, unsaved element */ }
    public function applyAttributes(array $attributes, ElementInterface $element): void { /* apply on create/update */ }
    public function collectReferences(ElementInterface $element): array { /* attribute UID refs */ }
}
```

The serializer owns the common envelope (uid, per-site title/slug/enabled, custom field
values); your handler only deals with what's specific to the type — a section + entry
type for entries, a group for categories, a volume + folder for assets, an owner for
nested elements, and so on.

## Conditional registration

Only register a handler when its host plugin is present, so Transport has no hard
dependency:

```php
if (class_exists(\some\plugin\fields\TheField::class)) {
    array_unshift($event->handlers, TheFieldHandler::class);
}
```

This is how Transport registers its own Commerce, Hyper, Neo, and Super Table handlers.

## Lifecycle events

Hook into export and import runs to inspect, adjust, or cancel them, or to react after
they finish.

| Event | Class | Cancel? |
|---|---|---|
| `Export::EVENT_BEFORE_EXPORT` | `BeforeExportEvent` (`config`) | yes (`$event->isValid = false`) |
| `Export::EVENT_AFTER_EXPORT` | `AfterExportEvent` (`config`, `path`, `elements`) | — |
| `Import::EVENT_BEFORE_IMPORT` | `BeforeImportEvent` (`package`, `dryRun`) | yes (`$event->isValid = false`) |
| `Import::EVENT_AFTER_IMPORT` | `AfterImportEvent` (`package`, `result`, `dryRun`) | — |

```php
use justinholtweb\transport\services\Import;
use justinholtweb\transport\events\AfterImportEvent;
use yii\base\Event;

Event::on(
    Import::class,
    Import::EVENT_AFTER_IMPORT,
    function (AfterImportEvent $event) {
        if ($event->result['status'] === 'completed') {
            // e.g. warm caches, ping a webhook, reindex search…
        }
    }
);
```

Cancelling a before-event stops the run: a cancelled export returns an empty path; a
cancelled import returns a result with `status` of `cancelled`.
