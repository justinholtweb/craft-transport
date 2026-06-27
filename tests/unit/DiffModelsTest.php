<?php

namespace justinholtweb\transport\tests\unit;

use justinholtweb\transport\models\DiffEntry;
use justinholtweb\transport\models\DiffResult;
use PHPUnit\Framework\TestCase;

class DiffModelsTest extends TestCase
{
    public function testDiffEntryPathAndIsChange(): void
    {
        $entry = new DiffEntry(['site' => 'default', 'field' => 'body', 'status' => DiffEntry::STATUS_CHANGED]);
        $this->assertSame('default.body', $entry->path());
        $this->assertTrue($entry->isChange());

        $unchanged = new DiffEntry(['status' => DiffEntry::STATUS_UNCHANGED]);
        $this->assertFalse($unchanged->isChange());
    }

    public function testDiffResultChangesAndCount(): void
    {
        $result = new DiffResult([
            'action' => DiffResult::ACTION_UPDATE,
            'entries' => [
                new DiffEntry(['field' => 'a', 'status' => DiffEntry::STATUS_CHANGED]),
                new DiffEntry(['field' => 'b', 'status' => DiffEntry::STATUS_UNCHANGED]),
                new DiffEntry(['field' => 'c', 'status' => DiffEntry::STATUS_ADDED]),
            ],
        ]);

        $this->assertSame(2, $result->changeCount());
        $changedFields = array_map(static fn(DiffEntry $e) => $e->field, $result->changes());
        $this->assertEqualsCanonicalizing(['a', 'c'], $changedFields);
    }

    public function testUnchangedResultHasNoChanges(): void
    {
        $result = new DiffResult([
            'action' => DiffResult::ACTION_UNCHANGED,
            'entries' => [new DiffEntry(['status' => DiffEntry::STATUS_UNCHANGED])],
        ]);

        $this->assertSame(0, $result->changeCount());
        $this->assertSame([], $result->changes());
    }
}
