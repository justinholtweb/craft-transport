<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

/**
 * The dependency resolver operating on live elements (as opposed to the pure-array
 * unit tests), confirming reference collection and ordering agree with real Craft data.
 */
final class DependencyResolverLiveTest extends TransportTestCase
{
    public function testResolvesParentBeforeChildFromLiveElements(): void
    {
        $parent = $this->makeCategory('LiveParent', 'dr-parent');
        $child = $this->makeCategory('LiveChild', 'dr-child', $parent->id);

        $resolution = $this->plugin()->dependencies->resolve([$child, $parent]);
        $order = $resolution['order'];

        self::assertLessThan(
            array_search($child->uid, $order, true),
            array_search($parent->uid, $order, true)
        );
        self::assertSame([], $resolution['cycles']);
    }

    public function testExternalReferencesDoNotCreateEdges(): void
    {
        // A lone child whose parent is NOT in the set: no edge, single-node order.
        $parent = $this->makeCategory('OutParent', 'dr-out-parent');
        $child = $this->makeCategory('OutChild', 'dr-out-child', $parent->id);

        $resolution = $this->plugin()->dependencies->resolve([$child]);

        self::assertSame([$child->uid], $resolution['order']);
        self::assertSame([], $resolution['cycles']);
    }

    public function testEmptySetResolvesToEmptyOrder(): void
    {
        $resolution = $this->plugin()->dependencies->resolve([]);

        self::assertSame([], $resolution['order']);
        self::assertSame([], $resolution['cycles']);
    }
}
