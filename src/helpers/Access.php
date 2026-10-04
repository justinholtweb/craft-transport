<?php

namespace justinholtweb\transport\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;

/**
 * Whether the person behind an import, export or rollback may touch a given element.
 *
 * The Transport permissions say who may *run* a migration; what it reads and writes is still
 * bounded by the person's own Craft permissions — a section they can't edit, a volume they can't
 * upload to, a user account they can't manage. Admins and console runs (no user: whoever has the
 * server has everything anyway) are not limited.
 */
abstract class Access
{
    /**
     * The user a run is acting for, or null for an unrestricted run.
     */
    public static function actor(?int $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        $user = User::find()->id($userId)->status(null)->one();

        // A run credited to a user who no longer exists gets no access, not full access.
        return $user ?? new User(['id' => 0, 'admin' => false]);
    }

    public static function canView(ElementInterface $element, ?User $user): bool
    {
        return $user === null || $user->admin || Craft::$app->getElements()->canView($element, $user);
    }

    public static function canSave(ElementInterface $element, ?User $user): bool
    {
        if ($user === null || $user->admin) {
            return true;
        }

        // Craft lets anyone with editUsers save an admin's account; its own screens then refuse
        // the edit. An import has no such second gate.
        if ($element instanceof User && $element->id && $element->admin) {
            return false;
        }

        return Craft::$app->getElements()->canSave($element, $user);
    }

    public static function canDelete(ElementInterface $element, ?User $user): bool
    {
        if ($user === null || $user->admin) {
            return true;
        }

        if ($element instanceof User && $element->admin) {
            return false;
        }

        return Craft::$app->getElements()->canDelete($element, $user);
    }
}
