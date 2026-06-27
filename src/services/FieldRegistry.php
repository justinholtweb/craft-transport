<?php

namespace justinholtweb\transport\services;

use craft\base\FieldInterface;
use justinholtweb\transport\events\RegisterFieldHandlersEvent;
use justinholtweb\transport\fields\BaseFieldHandler;
use justinholtweb\transport\fields\FieldHandlerInterface;
use justinholtweb\transport\fields\MatrixFieldHandler;
use justinholtweb\transport\fields\RelationFieldHandler;
use justinholtweb\transport\fields\thirdparty\FreeLinkFieldHandler;
use justinholtweb\transport\fields\thirdparty\GoogleMapsFieldHandler;
use justinholtweb\transport\fields\thirdparty\HyperFieldHandler;
use justinholtweb\transport\fields\thirdparty\NeoFieldHandler;
use justinholtweb\transport\fields\thirdparty\SeoSettingsFieldHandler;
use justinholtweb\transport\fields\thirdparty\SuperTableFieldHandler;
use yii\base\Component;

/**
 * Resolves the appropriate {@see FieldHandlerInterface} for any custom field.
 *
 * Third parties may register additional handlers via {@see self::EVENT_REGISTER_FIELD_HANDLERS}.
 * Registered handlers take precedence over the built-in ones, which in turn take
 * precedence over the universal {@see BaseFieldHandler} fallback.
 */
class FieldRegistry extends Component
{
    public const EVENT_REGISTER_FIELD_HANDLERS = 'registerFieldHandlers';

    /** @var FieldHandlerInterface[] */
    private array $handlers;

    private BaseFieldHandler $fallback;

    public function init(): void
    {
        parent::init();
        $this->fallback = new BaseFieldHandler();
        $this->handlers = $this->loadHandlers();
    }

    /**
     * Returns the first handler that can handle the given field, or the base fallback.
     */
    public function getHandler(FieldInterface $field): FieldHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($field)) {
                return $handler;
            }
        }

        return $this->fallback;
    }

    /**
     * @return FieldHandlerInterface[]
     */
    private function loadHandlers(): array
    {
        $classes = [
            RelationFieldHandler::class,
            MatrixFieldHandler::class,
        ];

        // Third-party field handlers, registered only when their host plugin's field
        // class is present. Earlier entries take precedence, so these are prepended
        // ahead of the generic relation/Matrix handlers.
        $conditional = [
            \verbb\hyper\fields\HyperField::class => HyperFieldHandler::class,
            \justinholtweb\freelink\fields\FreeLinkField::class => FreeLinkFieldHandler::class,
            \doublesecretagency\googlemaps\fields\AddressField::class => GoogleMapsFieldHandler::class,
            \nystudio107\seomatic\fields\SeoSettings::class => SeoSettingsFieldHandler::class,
            \verbb\supertable\fields\SuperTableField::class => SuperTableFieldHandler::class,
            \benf\neo\Field::class => NeoFieldHandler::class,
        ];
        foreach ($conditional as $hostClass => $handler) {
            if (class_exists($hostClass)) {
                array_unshift($classes, $handler);
            }
        }

        $event = new RegisterFieldHandlersEvent([
            'handlers' => $classes,
        ]);
        $this->trigger(self::EVENT_REGISTER_FIELD_HANDLERS, $event);

        $handlers = [];
        foreach ($event->handlers as $handler) {
            $instance = is_string($handler) ? new $handler() : $handler;
            if ($instance instanceof FieldHandlerInterface) {
                $handlers[] = $instance;
            }
        }

        return $handlers;
    }
}
