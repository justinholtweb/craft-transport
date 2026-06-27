<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\models\DependencyGraph;
use PHPUnit\Framework\TestCase;

class DependencyGraphTest extends TestCase
{
    public function testDependenciesSortBeforeDependents(): void
    {
        $graph = new DependencyGraph();
        // child depends on parent; entry depends on author.
        $graph->addDependency('child', 'parent');
        $graph->addDependency('entry', 'author');

        $order = $graph->topologicalSort();

        $this->assertLessThan(
            array_search('child', $order, true),
            array_search('parent', $order, true),
            'parent must come before child'
        );
        $this->assertLessThan(
            array_search('entry', $order, true),
            array_search('author', $order, true),
            'author must come before entry'
        );
    }

    public function testChainIsFullyOrdered(): void
    {
        $graph = new DependencyGraph();
        $graph->addDependency('c', 'b');
        $graph->addDependency('b', 'a');

        $this->assertSame(['a', 'b', 'c'], $graph->topologicalSort());
        $this->assertFalse($graph->hasCycles());
    }

    public function testCycleIsDetectedAndNodesStillReturned(): void
    {
        $graph = new DependencyGraph();
        $graph->addDependency('a', 'b');
        $graph->addDependency('b', 'a');

        $this->assertTrue($graph->hasCycles());
        $this->assertEqualsCanonicalizing(['a', 'b'], $graph->cycleNodes());
        // Cyclic nodes are still included so they get imported.
        $this->assertCount(2, $graph->topologicalSort());
    }

    public function testIsolatedNodesAreIncluded(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode('lonely');

        $this->assertSame(['lonely'], $graph->topologicalSort());
    }
}
