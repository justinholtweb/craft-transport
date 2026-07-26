<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\models\DependencyGraph;
use PHPUnit\Framework\TestCase;

/**
 * Deeper coverage of the topological sort: fan-out/fan-in, partial cycles, duplicate
 * edges, and ordering guarantees the resolver relies on.
 */
class DependencyGraphAdvancedTest extends TestCase
{
    /** Asserts $before appears earlier than $after in $order. */
    private function assertBefore(string $before, string $after, array $order): void
    {
        $this->assertLessThan(
            array_search($after, $order, true),
            array_search($before, $order, true),
            "$before must precede $after"
        );
    }

    public function testDiamondDependencyOrdersRootFirstAndLeafLast(): void
    {
        // d depends on b and c; b and c each depend on a.
        $graph = new DependencyGraph();
        $graph->addDependency('b', 'a');
        $graph->addDependency('c', 'a');
        $graph->addDependency('d', 'b');
        $graph->addDependency('d', 'c');

        $order = $graph->topologicalSort();

        $this->assertBefore('a', 'b', $order);
        $this->assertBefore('a', 'c', $order);
        $this->assertBefore('b', 'd', $order);
        $this->assertBefore('c', 'd', $order);
        $this->assertFalse($graph->hasCycles());
        $this->assertCount(4, $order);
    }

    public function testDuplicateEdgesDoNotInflateIndegree(): void
    {
        $graph = new DependencyGraph();
        $graph->addDependency('child', 'parent');
        $graph->addDependency('child', 'parent');
        $graph->addDependency('child', 'parent');

        // If duplicate edges leaked into indegree, Kahn's algorithm would strand 'child'
        // as a false cycle.
        $this->assertSame(['parent', 'child'], $graph->topologicalSort());
        $this->assertFalse($graph->hasCycles());
    }

    public function testPartialCycleLeavesAcyclicNodesOrderedAndFlagsOnlyTheCycle(): void
    {
        $graph = new DependencyGraph();
        // Clean chain:
        $graph->addDependency('b', 'a');
        // Separate 2-node cycle:
        $graph->addDependency('x', 'y');
        $graph->addDependency('y', 'x');

        $order = $graph->topologicalSort();

        $this->assertBefore('a', 'b', $order);
        $this->assertEqualsCanonicalizing(['x', 'y'], $graph->cycleNodes());
        // Every node still appears exactly once.
        $this->assertCount(4, $order);
        $this->assertEqualsCanonicalizing(['a', 'b', 'x', 'y'], $order);
    }

    public function testAddNodeIsIdempotent(): void
    {
        $graph = new DependencyGraph();
        $graph->addNode('a');
        $graph->addNode('a');

        $this->assertSame(['a'], $graph->nodes());
        $this->assertSame(['a'], $graph->topologicalSort());
    }

    public function testFanOutSharedDependencyComesFirst(): void
    {
        // A single author with many entries: author must sort before all of them.
        $graph = new DependencyGraph();
        foreach (['e1', 'e2', 'e3', 'e4'] as $entry) {
            $graph->addDependency($entry, 'author');
        }

        $order = $graph->topologicalSort();

        $this->assertSame('author', $order[0]);
        $this->assertCount(5, $order);
    }

    public function testCycleNodesIsEmptyForAcyclicGraph(): void
    {
        $graph = new DependencyGraph();
        $graph->addDependency('b', 'a');
        $graph->addDependency('c', 'b');

        $this->assertSame([], $graph->cycleNodes());
    }

    public function testThreeNodeCycleIsFullyReported(): void
    {
        $graph = new DependencyGraph();
        $graph->addDependency('a', 'b');
        $graph->addDependency('b', 'c');
        $graph->addDependency('c', 'a');

        $this->assertTrue($graph->hasCycles());
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $graph->cycleNodes());
    }
}
