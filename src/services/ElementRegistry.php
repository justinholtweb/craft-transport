<?php

namespace justinholtweb\transport\services;

use justinholtweb\transport\elements\AddressHandler;
use justinholtweb\transport\elements\AssetHandler;
use justinholtweb\transport\elements\CategoryHandler;
use justinholtweb\transport\elements\ElementHandlerInterface;
use justinholtweb\transport\elements\EntryHandler;
use justinholtweb\transport\elements\GlobalSetHandler;
use justinholtweb\transport\elements\TagHandler;
use justinholtweb\transport\elements\UserHandler;
use justinholtweb\transport\events\RegisterElementHandlersEvent;
use yii\base\Component;

/**
 * Resolves the {@see ElementHandlerInterface} responsible for a given element type.
 *
 * Third parties may register handlers for custom element types via
 * {@see self::EVENT_REGISTER_ELEMENT_HANDLERS}.
 */
class ElementRegistry extends Component
{
    public const EVENT_REGISTER_ELEMENT_HANDLERS = 'registerElementHandlers';

    /** @var array<string, ElementHandlerInterface> Keyed by element class. */
    private array $byType;

    /** @var array<string, ElementHandlerInterface> Keyed by package key. */
    private array $byKey;

    public function init(): void
    {
        parent::init();
        $this->loadHandlers();
    }

    public function getHandlerForType(string $elementType): ?ElementHandlerInterface
    {
        return $this->byType[$elementType] ?? null;
    }

    public function getHandlerForKey(string $packageKey): ?ElementHandlerInterface
    {
        return $this->byKey[$packageKey] ?? null;
    }

    /**
     * @return ElementHandlerInterface[]
     */
    public function all(): array
    {
        return array_values($this->byType);
    }

    private function loadHandlers(): void
    {
        $handlers = [
            EntryHandler::class,
            CategoryHandler::class,
            TagHandler::class,
            GlobalSetHandler::class,
            UserHandler::class,
            AssetHandler::class,
            AddressHandler::class,
        ];

        // Commerce element handlers, only when craftcms/commerce is installed.
        if (class_exists(\craft\commerce\elements\Product::class)) {
            $handlers[] = \justinholtweb\transport\elements\commerce\ProductHandler::class;
            $handlers[] = \justinholtweb\transport\elements\commerce\VariantHandler::class;
        }

        $event = new RegisterElementHandlersEvent([
            'handlers' => $handlers,
        ]);
        $this->trigger(self::EVENT_REGISTER_ELEMENT_HANDLERS, $event);

        $this->byType = [];
        $this->byKey = [];

        foreach ($event->handlers as $handler) {
            $instance = is_string($handler) ? new $handler() : $handler;
            if ($instance instanceof ElementHandlerInterface) {
                $this->byType[$instance->elementType()] = $instance;
                $this->byKey[$instance->packageKey()] = $instance;
            }
        }
    }
}
