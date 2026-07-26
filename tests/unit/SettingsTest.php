<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Validation and defaults for the plugin Settings model.
 */
class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame('@storage/transport', $settings->tempPath);
        $this->assertSame(512, $settings->maxPackageSize);
        $this->assertTrue($settings->includeAssetFiles);
        $this->assertSame(30, $settings->snapshotRetentionDays);
        $this->assertSame(20, $settings->snapshotRetentionCount);
        $this->assertSame('info', $settings->logLevel);
    }

    public function testDefaultsValidate(): void
    {
        $this->assertTrue((new Settings())->validate());
    }

    public function testTempPathIsRequired(): void
    {
        $settings = new Settings();
        $settings->tempPath = '';

        $this->assertFalse($settings->validate(['tempPath']));
        $this->assertArrayHasKey('tempPath', $settings->getErrors());
    }

    public function testLogLevelIsRequired(): void
    {
        $settings = new Settings();
        $settings->logLevel = '';

        $this->assertFalse($settings->validate(['logLevel']));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function logLevelProvider(): array
    {
        return [
            'error' => ['error', true],
            'warning' => ['warning', true],
            'info' => ['info', true],
            'debug' => ['debug', true],
            'unknown' => ['verbose', false],
            'uppercase is rejected' => ['INFO', false],
        ];
    }

    /**
     * @dataProvider logLevelProvider
     */
    public function testLogLevelRange(string $level, bool $expectedValid): void
    {
        $settings = new Settings();
        $settings->logLevel = $level;

        $this->assertSame($expectedValid, $settings->validate(['logLevel']));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function nonNegativeIntProvider(): array
    {
        return [
            'negative' => [-1, false],
            'zero allowed' => [0, true],
            'positive' => [42, true],
        ];
    }

    /**
     * @dataProvider nonNegativeIntProvider
     */
    public function testMaxPackageSizeMustBeNonNegative(int $value, bool $expectedValid): void
    {
        $settings = new Settings();
        $settings->maxPackageSize = $value;

        $this->assertSame($expectedValid, $settings->validate(['maxPackageSize']));
    }

    /**
     * @dataProvider nonNegativeIntProvider
     */
    public function testSnapshotRetentionDaysMustBeNonNegative(int $value, bool $expectedValid): void
    {
        $settings = new Settings();
        $settings->snapshotRetentionDays = $value;

        $this->assertSame($expectedValid, $settings->validate(['snapshotRetentionDays']));
    }

    /**
     * @dataProvider nonNegativeIntProvider
     */
    public function testSnapshotRetentionCountMustBeNonNegative(int $value, bool $expectedValid): void
    {
        $settings = new Settings();
        $settings->snapshotRetentionCount = $value;

        $this->assertSame($expectedValid, $settings->validate(['snapshotRetentionCount']));
    }
}
