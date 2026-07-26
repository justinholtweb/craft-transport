<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use justinholtweb\transport\models\TransportPackage;

/**
 * Pre-flight import validation: confirms the target has the schema a package needs and
 * warns about content that would be skipped.
 */
final class ValidationServiceTest extends TransportTestCase
{
    private function package(array $elements): TransportPackage
    {
        return new TransportPackage([
            'manifest' => ['version' => TransportPackage::FORMAT_VERSION],
            'elements' => $elements,
        ]);
    }

    public function testValidPackageAgainstExistingSchemaHasNoErrors(): void
    {
        $group = $this->categoryGroup();

        $package = $this->package([
            'categories' => [[
                'uid' => 'v-1',
                'type' => \craft\elements\Category::class,
                'key' => 'categories',
                'attributes' => ['group' => $group->handle],
                'sites' => [$this->primarySiteHandle() => ['title' => 'A']],
            ]],
        ]);

        $result = $this->plugin()->validation->validate($package);

        self::assertSame([], $result['errors']);
    }

    public function testMissingSectionIsAnError(): void
    {
        $package = $this->package([
            'entries' => [[
                'uid' => 'v-2',
                'type' => \craft\elements\Entry::class,
                'key' => 'entries',
                'attributes' => ['section' => 'nonexistentSection', 'type' => 'article'],
                'sites' => [$this->primarySiteHandle() => ['title' => 'A']],
            ]],
        ]);

        $result = $this->plugin()->validation->validate($package);

        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('nonexistentSection', implode(' ', $result['errors']));
    }

    public function testMissingCategoryGroupIsAnError(): void
    {
        $package = $this->package([
            'categories' => [[
                'uid' => 'v-3',
                'type' => \craft\elements\Category::class,
                'key' => 'categories',
                'attributes' => ['group' => 'ghostGroup'],
                'sites' => [$this->primarySiteHandle() => ['title' => 'A']],
            ]],
        ]);

        $result = $this->plugin()->validation->validate($package);

        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('ghostGroup', implode(' ', $result['errors']));
    }

    public function testMissingEntryTypeInExistingSectionIsAnError(): void
    {
        $section = $this->section();

        $package = $this->package([
            'entries' => [[
                'uid' => 'v-4',
                'type' => \craft\elements\Entry::class,
                'key' => 'entries',
                'attributes' => ['section' => $section->handle, 'type' => 'ghostType'],
                'sites' => [$this->primarySiteHandle() => ['title' => 'A']],
            ]],
        ]);

        $result = $this->plugin()->validation->validate($package);

        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('ghostType', implode(' ', $result['errors']));
    }

    public function testUnknownSiteProducesAWarningNotAnError(): void
    {
        $package = $this->package([
            'categories' => [[
                'uid' => 'v-5',
                'type' => \craft\elements\Category::class,
                'key' => 'categories',
                'attributes' => ['group' => $this->categoryGroup()->handle],
                'sites' => ['nonexistentSite' => ['title' => 'A']],
            ]],
        ]);

        $result = $this->plugin()->validation->validate($package);

        self::assertSame([], $result['errors']);
        self::assertNotEmpty($result['warnings']);
        self::assertStringContainsString('nonexistentSite', implode(' ', $result['warnings']));
    }

    public function testMissingVolumeIsAnError(): void
    {
        $package = $this->package([
            'assets' => [[
                'uid' => 'v-6',
                'type' => \craft\elements\Asset::class,
                'key' => 'assets',
                'attributes' => ['volume' => 'ghostVolume'],
                'sites' => [$this->primarySiteHandle() => ['title' => 'file.jpg']],
            ]],
        ]);

        $result = $this->plugin()->validation->validate($package);

        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('ghostVolume', implode(' ', $result['errors']));
    }

    public function testEmptyPackageValidatesCleanly(): void
    {
        $result = $this->plugin()->validation->validate($this->package([]));

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['warnings']);
    }
}
