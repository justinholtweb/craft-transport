<?php

namespace justinholtweb\transport\elements\thirdparty;

use craft\base\ElementInterface;
use justinholtweb\transport\elements\BaseElementHandler;
use verbb\formie\elements\Form;
use verbb\formie\helpers\ImportExportHelper;

/**
 * Element handler for Formie forms (definitions).
 *
 * Delegates to Formie's own export/import (`ImportExportHelper`) so the full form
 * config — pages, rows, fields, settings, notifications — is captured and rebuilt
 * faithfully. Only registered when verbb/formie is installed.
 */
class FormieFormHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Form::class;
    }

    public function packageKey(): string
    {
        return 'forms';
    }

    public function query(): \craft\elements\db\ElementQueryInterface
    {
        return Form::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Form $element */
        return [
            'config' => ImportExportHelper::generateFormExport($element),
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        // A bare form; the full config is applied (in place) in applyAttributes so the
        // same path handles both create and update.
        return new Form();
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Form $element */
        $config = $attributes['config'] ?? null;
        if (is_array($config)) {
            ImportExportHelper::createFormFromImport($config, $element);
        }
    }
}
