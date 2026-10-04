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

    /**
     * A user is identified by their email address, falling back to their username.
     */
    public function matchExisting(array $data, ?int $siteId = null): ?ElementInterface
    {
        $attributes = $data['attributes'] ?? [];

        foreach ([['email', $attributes['email'] ?? null], ['username', $attributes['username'] ?? null]] as [$param, $value]) {
            if (!$value) {
                continue;
            }

            $existing = User::find()->$param($value)->status(null)->one();
            if ($existing) {
                return $existing;
            }
        }

        return null;
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var User $element */
        $element->firstName = $attributes['firstName'] ?? $element->firstName;
        $element->lastName = $attributes['lastName'] ?? $element->lastName;

        // Note: preferredLanguage is a stored user preference applied post-save; it is
        // serialized for reference but not reapplied here.
        //
        // Group memberships aren't part of the element save at all — Craft stores them through
        // Users::assignUserToGroups() — so they are applied after it, by syncGroups().
    }

    /**
     * Gives a saved user the group memberships the package says they have.
     *
     * Before 5.1.2 groups were exported but never applied: an imported user arrived in no groups
     * at all, and an updated one kept whatever they had.
     *
     * Who may change what follows Craft's own user screen. Admins and console runs (no actor) set
     * the package's groups exactly. Anyone else changes only the groups they hold
     * `assignUserGroup:<uid>` for; memberships in the rest stay as they were, in either direction.
     * A group the package names that doesn't exist here is skipped and reported.
     *
     * @return string[] Notes for the report: groups skipped, and why.
     */
    public static function syncGroups(User $user, array $data, ?User $actor): array
    {
        $attributes = $data['attributes'] ?? [];

        // A package that says nothing about groups leaves them alone.
        if (!$user->id || !array_key_exists('groups', $attributes) || !is_array($attributes['groups'])) {
            return [];
        }

        $groupsService = Craft::$app->getUserGroups();
        $notes = [];
        $wanted = [];

        foreach ($attributes['groups'] as $handle) {
            $group = is_string($handle) ? $groupsService->getGroupByHandle($handle) : null;
            if ($group === null) {
                $notes[] = sprintf('User group "%s" doesn’t exist here, so it was skipped.', is_string($handle) ? $handle : '?');
                continue;
            }
            $wanted[(int)$group->id] = $group;
        }

        $current = [];
        foreach ($groupsService->getGroupsByUserId((int)$user->id) as $group) {
            $current[(int)$group->id] = $group;
        }

        if ($actor === null || $actor->admin) {
            $final = array_keys($wanted);
        } else {
            $assignable = static fn($group): bool => $actor->can('assignUserGroup:' . $group->uid);
            $final = [];

            foreach ($current as $id => $group) {
                if (!$assignable($group)) {
                    $final[] = $id;
                }
            }

            foreach ($wanted as $id => $group) {
                if ($assignable($group)) {
                    $final[] = $id;
                } elseif (!isset($current[$id])) {
                    $notes[] = sprintf('You can’t assign users to "%s", so this user wasn’t added to it.', $group->name);
                }
            }

            foreach ($current as $id => $group) {
                if (!$assignable($group) && !isset($wanted[$id])) {
                    $notes[] = sprintf('You can’t assign users to "%s", so this user was left in it.', $group->name);
                }
            }
        }

        $final = array_values(array_unique($final));
        sort($final);
        $have = array_keys($current);
        sort($have);

        if ($final !== $have) {
            Craft::$app->getUsers()->assignUserToGroups((int)$user->id, $final);
        }

        return $notes;
    }
}
