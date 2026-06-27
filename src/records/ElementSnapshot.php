<?php

namespace justinholtweb\transport\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Pre-import snapshot record. Stores the prior state of affected elements
 * so an import can be rolled back.
 *
 * @property int $id
 * @property int $historyId
 * @property string|null $elementData Compressed JSON of pre-import element state.
 */
class ElementSnapshot extends ActiveRecord
{
    public const TABLE = '{{%transport_snapshots}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    public function getHistory(): ActiveQueryInterface
    {
        return $this->hasOne(ImportHistory::class, ['id' => 'historyId']);
    }
}
