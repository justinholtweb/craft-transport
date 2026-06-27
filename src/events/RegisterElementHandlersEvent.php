<?php

namespace justinholtweb\transport\events;

use yii\base\Event;

/**
 * Raised so third parties can register custom element handlers.
 *
 * Listeners append fully-qualified {@see \justinholtweb\transport\elements\ElementHandlerInterface}
 * class names (or instances) to {@see $handlers}.
 */
class RegisterElementHandlersEvent extends Event
{
    /**
     * @var array List of element handler class names or instances, keyed by element
     *            type class name. e.g. ['craft\elements\Entry' => EntryHandler::class].
     */
    public array $handlers = [];
}
