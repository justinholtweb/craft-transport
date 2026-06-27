<?php

namespace justinholtweb\transport\elements\thirdparty;

use Craft;
use Carbon\Carbon;
use craft\base\ElementInterface;
use craft\elements\User;
use justinholtweb\transport\elements\BaseElementHandler;
use justinholtweb\transport\helpers\IdentityHelper;
use Solspace\Calendar\Calendar;
use Solspace\Calendar\Elements\Event;

/**
 * Element handler for Solspace Calendar events, including recurrence rules.
 *
 * The calendar is resolved by handle (it must exist in the target). Only registered
 * when solspace/craft-calendar is installed.
 */
class CalendarEventHandler extends BaseElementHandler
{
    private const RECURRENCE = ['rrule', 'freq', 'interval', 'count', 'byMonth', 'byYearDay', 'byMonthDay', 'byDay'];

    public function elementType(): string
    {
        return Event::class;
    }

    public function packageKey(): string
    {
        return 'events';
    }

    public function query(): \craft\elements\db\ElementQueryInterface
    {
        // Export canonical events, not their expanded recurrence occurrences.
        return Event::find()->status(null)->setLoadOccurrences(false);
    }

    public function serializeAttributes(ElementInterface $element): array
    {
        /** @var Event $element */
        $author = $element->authorId ? Craft::$app->getElements()->getElementById((int)$element->authorId, User::class) : null;

        $attributes = [
            'calendar' => $element->getCalendar()->handle,
            'author' => $author?->uid,
            'startDate' => $element->startDate?->format(DATE_ATOM),
            'endDate' => $element->endDate?->format(DATE_ATOM),
            'allDay' => $element->allDay,
            'until' => $element->until?->format(DATE_ATOM),
        ];

        foreach (self::RECURRENCE as $key) {
            $attributes[$key] = $element->$key;
        }

        return $attributes;
    }

    public function makeElement(array $attributes): ?ElementInterface
    {
        $calendar = isset($attributes['calendar'])
            ? Calendar::getInstance()->calendars->getCalendarByHandle($attributes['calendar'])
            : null;

        if (!$calendar) {
            return null;
        }

        return Event::create(null, $calendar->id);
    }

    public function applyAttributes(array $attributes, ElementInterface $element): void
    {
        /** @var Event $element */
        // Calendar's event logic relies on Carbon date instances.
        if (!empty($attributes['startDate'])) {
            $element->startDate = new Carbon($attributes['startDate']);
        }
        if (!empty($attributes['endDate'])) {
            $element->endDate = new Carbon($attributes['endDate']);
        }
        if (!empty($attributes['until'])) {
            $element->until = new Carbon($attributes['until']);
        }
        $element->allDay = (bool)($attributes['allDay'] ?? false);

        foreach (self::RECURRENCE as $key) {
            if (array_key_exists($key, $attributes)) {
                $element->$key = $attributes[$key];
            }
        }

        if (!empty($attributes['author'])) {
            $element->authorId = IdentityHelper::resolveId($attributes['author'], User::class);
        }
    }

    public function collectReferences(ElementInterface $element): array
    {
        /** @var Event $element */
        if ($element->authorId) {
            $author = Craft::$app->getElements()->getElementById((int)$element->authorId, User::class);
            if ($author) {
                return [$author->uid];
            }
        }
        return [];
    }
}
