<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\services\Merger;
use PHPUnit\Framework\TestCase;

class MergerTest extends TestCase
{
    private function incoming(): array
    {
        return [
            'uid' => 'abc',
            'sites' => [
                'default' => [
                    'title' => 'Incoming Title',
                    'slug' => 'incoming',
                    'fields' => ['body' => 'incoming body', 'summary' => 'incoming summary'],
                ],
            ],
        ];
    }

    private function current(): array
    {
        return [
            'sites' => [
                'default' => [
                    'title' => 'Current Title',
                    'slug' => 'current',
                    'fields' => ['body' => 'current body', 'summary' => 'current summary'],
                ],
            ],
        ];
    }

    public function testRejectedFieldKeepsCurrentValue(): void
    {
        $merged = (new Merger())->apply($this->incoming(), ['default.body'], $this->current());

        $this->assertSame('current body', $merged['sites']['default']['fields']['body']);
        // Non-rejected field keeps the incoming value.
        $this->assertSame('incoming summary', $merged['sites']['default']['fields']['summary']);
    }

    public function testRejectedTitleKeepsCurrentValue(): void
    {
        $merged = (new Merger())->apply($this->incoming(), ['default.title'], $this->current());

        $this->assertSame('Current Title', $merged['sites']['default']['title']);
    }

    public function testRejectedFieldOnNewElementIsDropped(): void
    {
        // No current element (an "add"): rejecting a field removes it so it isn't set.
        $merged = (new Merger())->apply($this->incoming(), ['default.body'], null);

        $this->assertArrayNotHasKey('body', $merged['sites']['default']['fields']);
        $this->assertArrayHasKey('summary', $merged['sites']['default']['fields']);
    }

    public function testNoDecisionsLeavesPayloadUnchanged(): void
    {
        $incoming = $this->incoming();
        $merged = (new Merger())->apply($incoming, [], $this->current());

        $this->assertSame($incoming, $merged);
    }
}
