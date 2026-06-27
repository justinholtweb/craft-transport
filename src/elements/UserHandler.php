<?php

namespace justinholtweb\transport\elements;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;

/**
 * Element handler for users.
 *
 * Never exports passwords, auth secrets, or session data. Users are matched in the
 * target by UID, falling back to email address (a natural key).
 */
class UserHandler extends BaseElementHandler
{
    public function elementType(): string
    {
        return User::class;
    }

    public function packageKey(): string
    {
        return 'users';
    }

    public function query(): ElementQueryInterface
    {
        return User::find()->status(null);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var User $element */
        return [
            'username' => $element->username,
            'email' => $element->email,
            'firstName' => $element->firstName,
            'lastName' => $element->lastName,
            'admin' => $element->admin,
            'preferredLanguage' => $element->getPreferredLanguage(),
            'groups' => array_map(
                static fn($group) => $group->handle,
                $element->getGroups()
            ),
        ];
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        // Reuse an existing user with the same email, otherwise create a new one.
        $email = $attributes['email'] ?? null;
        if ($email) {
            $existing = User::find()->email($email)->status(null)->one();
            if ($existing) {
                return $existing;
            }
        }

        $user = new User();
        $user->username = $attributes['username'] ?? $email;
        $user->email = $email;

        return $user;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var User $element */
        $element->firstName = $attributes['firstName'] ?? $element->firstName;
        $element->lastName = $attributes['lastName'] ?? $element->lastName;

        // Note: preferredLanguage is a stored user preference applied post-save; it is
        // serialized for reference but not reapplied here.

        $groups = [];
        foreach ($attributes['groups'] ?? [] as $handle) {
            $group = Craft::$app->getUserGroups()->getGroupByHandle($handle);
            if ($group) {
                $groups[] = $group;
            }
        }
        if ($groups) {
            $element->setGroups($groups);
        }
    }
}
