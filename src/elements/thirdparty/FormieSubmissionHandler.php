<?php

namespace justinholtweb\transport\elements\thirdparty;

use craft\base\ElementInterface;
use justinholtweb\transport\elements\BaseElementHandler;
use verbb\formie\elements\Submission;
use verbb\formie\Formie;

/**
 * Element handler for Formie submissions (data).
 *
 * A submission belongs to a form (resolved by handle) and carries the form's custom
 * field values, which ride through the normal field-handler pipeline. Only registered
 * when verbb/formie is installed.
 */
class FormieSubmissionHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return Submission::class;
    }

    public function packageKey(): string
    {
        return 'submissions';
    }

    public function query(): \craft\elements\db\ElementQueryInterface
    {
        return Submission::find()->status(null)->isIncomplete(null)->isSpam(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Submission $element */
        $form = $element->getForm();

        // Formie fields aren't standard Craft custom fields, so capture their values
        // from the form's field definitions rather than the generic field layout.
        $values = [];
        foreach ($form?->getFields() ?? [] as $field) {
            $values[$field->handle] = $field->serializeValue($element->getFieldValue($field->handle), $element);
        }

        return [
            'form' => $form?->handle,
            'status' => $element->getStatus(),
            'isSpam' => $element->isSpam,
            'isIncomplete' => $element->isIncomplete,
            'fieldValues' => $values,
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $form = isset($attributes['form'])
            ? Formie::$plugin->getForms()->getFormByHandle($attributes['form'])
            : null;

        if (!$form) {
            return null;
        }

        $submission = new Submission();
        $submission->setForm($form);

        return $submission;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Submission $element */
        $element->isSpam = (bool)($attributes['isSpam'] ?? false);
        $element->isIncomplete = (bool)($attributes['isIncomplete'] ?? false);

        if (!empty($attributes['status'])) {
            $status = Formie::$plugin->getStatuses()->getStatusByHandle($attributes['status']);
            if ($status) {
                $element->statusId = $status->id;
            }
        }

        $form = $element->getForm();
        foreach ($attributes['fieldValues'] ?? [] as $handle => $value) {
            $field = $form?->getFieldByHandle($handle);
            if ($field) {
                $element->setFieldValue($handle, $field->normalizeValue($value, $element));
            }
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Submission $element */
        // Reference the form so it's imported before its submissions.
        return $element->getForm() ? [$element->getForm()->uid] : [];
    }
}
