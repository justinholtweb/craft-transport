<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\services\Merger;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases for the selective-merge engine beyond the happy path covered by
 * {@see MergerTest}.
 */
class MergerAdvancedTest extends TestCase
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
                'de' => [
                    'title' => 'Eingang',
                    'slug' => 'eingang',
                    'fields' => ['body' => 'eingang body'],
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
                'de' => [
                    'title' => 'Aktuell',
                    'slug' => 'aktuell',
                    'fields' => ['body' => 'aktuell body'],
                ],
            ],
        ];
    }

    public function testRejectionsAreScopedPerSite(): void
    {
        // Reject the German body only; the default site's body must be untouched.
        $merged = (new Merger())->apply($this->incoming(), ['de.body'], $this->current());

        $this->assertSame('aktuell body', $merged['sites']['de']['fields']['body']);
        $this->assertSame('incoming body', $merged['sites']['default']['fields']['body']);
    }

    public function testMultipleRejectedPathsAcrossSitesAndPseudoFields(): void
    {
        $merged = (new Merger())->apply(
            $this->incoming(),
            ['default.title', 'de.body', 'default.summary'],
            $this->current()
        );

        $this->assertSame('Current Title', $merged['sites']['default']['title']);
        $this->assertSame('current summary', $merged['sites']['default']['fields']['summary']);
        $this->assertSame('aktuell body', $merged['sites']['de']['fields']['body']);
        // Unmentioned fields keep the incoming value.
        $this->assertSame('incoming body', $merged['sites']['default']['fields']['body']);
        $this->assertSame('Eingang', $merged['sites']['de']['title']);
    }

    public function testMalformedPathWithoutDotIsIgnored(): void
    {
        $incoming = $this->incoming();
        $merged = (new Merger())->apply($incoming, ['boguspath'], $this->current());

        $this->assertSame($incoming, $merged);
    }

    public function testRejectingFieldAbsentFromCurrentDropsItOnUpdate(): void
    {
        // The target has no "summary" in German; rejecting de.summary should remove the
        // incoming German summary rather than invent a value. (There is none incoming
        // either, so this is a no-op that must not create keys.)
        $incoming = $this->incoming();
        $merged = (new Merger())->apply($incoming, ['de.summary'], $this->current());

        $this->assertArrayNotHasKey('summary', $merged['sites']['de']['fields']);
    }

    public function testRejectingSlugOnNewElementDropsIt(): void
    {
        $merged = (new Merger())->apply($this->incoming(), ['default.slug'], null);

        $this->assertArrayNotHasKey('slug', $merged['sites']['default']);
        // Title (not rejected) survives.
        $this->assertSame('Incoming Title', $merged['sites']['default']['title']);
    }

    public function testRejectedPathForUnknownSiteIsHarmless(): void
    {
        $incoming = $this->incoming();
        // "fr" isn't present in the payload; applying should neither error nor mutate.
        $merged = (new Merger())->apply($incoming, ['fr.title'], $this->current());

        $this->assertArrayNotHasKey('fr', $merged['sites']);
        $this->assertSame($incoming['sites']['default'], $merged['sites']['default']);
    }

    public function testFieldPathWithDotsInHandleUsesFirstSeparator(): void
    {
        // explode(..., 2) means only the first dot splits site from field; a field
        // handle can therefore itself contain a dot.
        $incoming = [
            'sites' => ['default' => ['fields' => ['a.b' => 'incoming']]],
        ];
        $current = [
            'sites' => ['default' => ['fields' => ['a.b' => 'current']]],
        ];

        $merged = (new Merger())->apply($incoming, ['default.a.b'], $current);

        $this->assertSame('current', $merged['sites']['default']['fields']['a.b']);
    }
}
