<?php

namespace justinholtweb\transport\records;

use craft\db\ActiveRecord;
use craft\helpers\Json;
use craft\records\User;
use yii\db\ActiveQueryInterface;

/**
 * Import/export history record.
 *
 * @property int $id
 * @property string $packageName
 * @property string $direction
 * @property string $status
 * @property array|null $elementCounts
 * @property string|null $errorLog
 * @property int|null $snapshotId
 * @property int|null $userId
 */
class ImportHistory extends ActiveRecord
{
    public const TABLE = '{{%transport_history}}';

    public const DIRECTION_EXPORT = 'export';
    public const DIRECTION_IMPORT = 'import';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    /**
     * Returns element counts as an array regardless of how the driver returns the
     * JSON column (MySQL hands back a string).
     */
    public function getCountsArray(): array
    {
        $value = $this->elementCounts;
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }
        return is_array($value) ? $value : [];
    }

    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }

    public function getSnapshot(): ActiveQueryInterface
    {
        return $this->hasOne(ElementSnapshot::class, ['id' => 'snapshotId']);
    }
}
