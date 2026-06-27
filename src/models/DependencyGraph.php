<?php

namespace justinholtweb\transport\models;

use craft\base\Model;

/**
 * A directed acyclic graph of element UID dependencies, used to order imports so that
 * an element is always created/updated after the elements it references.
 *
 * An edge A → B means "A depends on B", so B must be imported before A.
 */
class DependencyGraph extends Model
{
    /** @var array<string, true> Node set (UID => true). */
    private array $nodes = [];

    /** @var array<string, array<string, true>> dependency UID => set of dependent UIDs. */
    private array $dependents = [];

    /** @var array<string, int> Number of unmet dependencies per node. */
    private array $indegree = [];

    public function addNode(string $uid): void
    {
        if (!isset($this->nodes[$uid])) {
            $this->nodes[$uid] = true;
            $this->indegree[$uid] ??= 0;
        }
    }

    /**
     * Records that $dependent depends on $dependency (so $dependency sorts first).
     * Duplicate edges are ignored.
     */
    public function addDependency(string $dependent, string $dependency): void
    {
        $this->addNode($dependent);
        $this->addNode($dependency);

        if (isset($this->dependents[$dependency][$dependent])) {
            return;
        }

        $this->dependents[$dependency][$dependent] = true;
        $this->indegree[$dependent]++;
    }

    /**
     * @return string[] All node UIDs.
     */
    public function nodes(): array
    {
        return array_keys($this->nodes);
    }

    /**
     * Kahn's algorithm. Returns UIDs ordered so dependencies precede dependents.
     * Nodes caught in a cycle are appended at the end (see {@see cycleNodes()}).
     *
     * @return string[]
     */
    public function topologicalSort(): array
    {
        $indegree = $this->indegree;
        $queue = [];
        foreach ($indegree as $uid => $deg) {
            if ($deg === 0) {
                $queue[] = $uid;
            }
        }

        $order = [];
        while ($queue) {
            $uid = array_shift($queue);
            $order[] = $uid;

            foreach (array_keys($this->dependents[$uid] ?? []) as $dependent) {
                if (--$indegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Any nodes never reaching indegree 0 are part of a cycle; append them so they
        // are still imported (callers should surface a warning).
        foreach ($indegree as $uid => $deg) {
            if ($deg > 0 && !in_array($uid, $order, true)) {
                $order[] = $uid;
            }
        }

        return $order;
    }

    /**
     * Returns UIDs that participate in at least one cycle (never reach indegree 0).
     *
     * @return string[]
     */
    public function cycleNodes(): array
    {
        $indegree = $this->indegree;
        $queue = array_keys(array_filter($indegree, static fn($d) => $d === 0));

        while ($queue) {
            $uid = array_shift($queue);
            foreach (array_keys($this->dependents[$uid] ?? []) as $dependent) {
                if (--$indegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        return array_keys(array_filter($indegree, static fn($d) => $d > 0));
    }

    public function hasCycles(): bool
    {
        return $this->cycleNodes() !== [];
    }
}
