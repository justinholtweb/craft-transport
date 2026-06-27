<?php

namespace justinholtweb\transport\services;

use craft\base\ElementInterface;
use justinholtweb\transport\models\DependencyGraph;
use justinholtweb\transport\Plugin;
use yii\base\Component;

/**
 * Builds a dependency graph from a set of elements and produces a safe import order.
 *
 * Only references that point at other elements *within the same package* create edges;
 * references to elements expected to already exist in the target are left to resolve at
 * import time.
 */
class DependencyResolver extends Component
{
    /**
     * Computes an import order for a set of live elements.
     *
     * @param ElementInterface[] $elements
     * @return array{order: string[], cycles: string[]} Ordered UIDs and any cyclic UIDs.
     */
    public function resolve(array $elements): array
    {
        $serializer = Plugin::getInstance()->serializer;

        $referencesByUid = [];
        foreach ($elements as $element) {
            $referencesByUid[$element->uid] = $serializer->collectReferences($element);
        }

        return $this->resolveFromReferences($referencesByUid);
    }

    /**
     * Computes an import order from a precomputed UID => referenced-UIDs map.
     *
     * @param array<string, string[]> $referencesByUid
     * @return array{order: string[], cycles: string[]}
     */
    public function resolveFromReferences(array $referencesByUid): array
    {
        $graph = new DependencyGraph();
        $known = array_fill_keys(array_keys($referencesByUid), true);

        foreach (array_keys($referencesByUid) as $uid) {
            $graph->addNode($uid);
        }

        foreach ($referencesByUid as $uid => $refs) {
            foreach ($refs as $ref) {
                if ($ref !== $uid && isset($known[$ref])) {
                    // $uid depends on $ref, so $ref must come first.
                    $graph->addDependency($uid, $ref);
                }
            }
        }

        return [
            'order' => $graph->topologicalSort(),
            'cycles' => $graph->cycleNodes(),
        ];
    }
}
