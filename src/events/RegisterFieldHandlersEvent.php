<?php

namespace justinholtweb\transport\events;

use yii\base\Event;

/**
 * Raised so third parties can register custom field handlers.
 *
 * Listeners append fully-qualified {@see \justinholtweb\transport\fields\FieldHandlerInterface}
 * class names (or instances) to {@see $handlers}.
 */
class RegisterFieldHandlersEvent extends Event
{
    /**
     * @var array List of field handler class names or instances. Earlier entries
     *            take precedence when more than one handler can handle a field.
     */
    public array $handlers = [];
}
