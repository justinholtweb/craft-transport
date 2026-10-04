<?php

namespace justinholtweb\transport\records;

use craft\db\ActiveRecord;
use craft\helpers\Json;
use craft\records\User;
use justinholtweb\transport\models\TransportReport;
use yii\db\ActiveQueryInterface;

/**
 * Import/export history record.
 *
 * @property int $id
 * @property string $packageName
 * @property string $direction
 * @property string $status
 * @property array|string|null $elementCounts
 * @property array|string|null $report
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

    /**
     * Stores the detailed run report as JSON.
     *
     * Named around the model rather than the column because `$record->report` resolves
     * to the raw attribute — the same reason {@see getCountsArray()} exists.
     */
    public function setRunReport(?TransportReport $report): void
    {
        $this->report = $report === null ? null : Json::encode($report->toStorageArray());
    }

    /**
     * Rebuilds the detailed run report, or null for rows written before reports existed.
     */
    public function getRunReport(): ?TransportReport
    {
        $value = $this->report;
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) && $value ? TransportReport::fromStorageArray($value) : null;
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
