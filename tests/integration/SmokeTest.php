<?php

declare(strict_types=1);

namespace justinholtweb\transport\tests\integration;

use Craft;
use craft\test\TestCase;
use justinholtweb\transport\Plugin;

final class SmokeTest extends TestCase
{
    public function testCraftIsInstalledAndTransportIsLoaded(): void
    {
        self::assertTrue(Craft::$app->getIsInstalled());
        self::assertInstanceOf(Plugin::class, Plugin::getInstance());
    }

    public function testInstallMigrationCreatedTables(): void
    {
        $db = Craft::$app->getDb();
        self::assertTrue($db->tableExists('{{%transport_history}}'));
        self::assertTrue($db->tableExists('{{%transport_snapshots}}'));
    }
}
