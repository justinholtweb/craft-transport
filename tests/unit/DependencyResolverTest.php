<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\services\DependencyResolver;
use PHPUnit\Framework\TestCase;

class DependencyResolverTest extends TestCase
{
    public function testOrdersDependenciesBeforeDependents(): void
    {
        // entry depends on author; child depends on parent.
        $refs = [
            'entry' => ['author'],
            'author' => [],
            'child' => ['parent'],
            'parent' => [],
        ];

        $result = (new DependencyResolver())->resolveFromReferences($refs);
        $order = $result['order'];

        $this->assertLessThan(array_search('entry', $order, true), array_search('author', $order, true));
        $this->assertLessThan(array_search('child', $order, true), array_search('parent', $order, true));
        $this->assertSame([], $result['cycles']);
    }

    public function testExternalReferencesAreIgnored(): void
    {
        // 'a' references 'external' which isn't in the package — no edge, no error.
        $refs = ['a' => ['external']];

        $result = (new DependencyResolver())->resolveFromReferences($refs);

        $this->assertSame(['a'], $result['order']);
        $this->assertSame([], $result['cycles']);
    }

    public function testReportsCycles(): void
    {
        $refs = ['a' => ['b'], 'b' => ['a']];

        $result = (new DependencyResolver())->resolveFromReferences($refs);

        $this->assertEqualsCanonicalizing(['a', 'b'], $result['cycles']);
        $this->assertCount(2, $result['order']);
    }

    public function testSelfReferenceIsIgnored(): void
    {
        $result = (new DependencyResolver())->resolveFromReferences(['a' => ['a']]);

        $this->assertSame(['a'], $result['order']);
        $this->assertSame([], $result['cycles']);
    }
}
