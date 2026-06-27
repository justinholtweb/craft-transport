<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Address;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use justinholtweb\transport\helpers\IdentityHelper;

/**
 * Element handler for addresses.
 *
 * Addresses are nested elements owned by another element (typically a user). The owner
 * is referenced by UID and must exist in (or be imported into) the target.
 */
class AddressHandler extends BaseElementHandler
{
    /** Standard addressing attributes carried verbatim. */
    private const FIELDS = [
        'countryCode', 'administrativeArea', 'locality', 'dependentLocality',
        'postalCode', 'sortingCode', 'addressLine1', 'addressLine2', 'addressLine3',
        'organization', 'organizationTaxId', 'latitude', 'longitude', 'fullName',
    ];

    public function elementType(): string
    {
        return Address::class;
    }

    public function packageKey(): string
    {
        return 'addresses';
    }

    public function query(): ElementQueryInterface
    {
        return Address::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Address $element */
        $owner = $element->getOwner();

        $attributes = [
            'owner' => $owner?->uid,
            'ownerType' => $owner ? $owner::class : null,
        ];

        foreach (self::FIELDS as $name) {
            $attributes[$name] = $element->$name ?? null;
        }

        return $attributes;
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $ownerUid = $attributes['owner'] ?? null;
        $ownerType = $attributes['ownerType'] ?? User::class;
        if (!$ownerUid) {
            return null;
        }

        $ownerId = IdentityHelper::resolveId($ownerUid, $ownerType);
        if ($ownerId === null) {
            return null;
        }

        $address = new Address();
        $address->setOwnerId($ownerId);
        $address->countryCode = $attributes['countryCode'] ?? 'US';

        return $address;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Address $element */
        foreach (self::FIELDS as $name) {
            if (array_key_exists($name, $attributes) && $attributes[$name] !== null) {
                $element->$name = $attributes[$name];
            }
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Address $element */
        return $element->getOwner() ? [$element->getOwner()->uid] : [];
    }
}
