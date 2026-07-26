<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\models\ExportConfig;
use PHPUnit\Framework\TestCase;

/**
 * Validation and defaults for the ExportConfig model.
 */
class ExportConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new ExportConfig();

        $this->assertSame(['entries'], $config->packageKeys);
        $this->assertSame([], $config->elementIds);
        $this->assertNull($config->section);
        $this->assertNull($config->site);
        $this->assertTrue($config->includeAssetFiles);
        $this->assertNull($config->packageName);
    }

    public function testDefaultsValidate(): void
    {
        $this->assertTrue((new ExportConfig())->validate());
    }

    public function testValidFullConfigValidates(): void
    {
        $config = new ExportConfig();
        $config->packageKeys = ['entries', 'categories', 'assets'];
        $config->elementIds = [1, 2, 3];
        $config->section = 'news';
        $config->site = 'default';
        $config->includeAssetFiles = false;
        $config->packageName = 'my-package';

        $this->assertTrue($config->validate());
    }

    public function testNonIntegerElementIdsAreRejected(): void
    {
        $config = new ExportConfig();
        $config->elementIds = ['not-an-int'];

        $this->assertFalse($config->validate(['elementIds']));
        $this->assertArrayHasKey('elementIds', $config->getErrors());
    }

    public function testNumericStringElementIdsValidate(): void
    {
        // Yii's integer validator accepts numeric strings.
        $config = new ExportConfig();
        $config->elementIds = ['12', '34'];

        $this->assertTrue($config->validate(['elementIds']));
    }
}
