<?php

namespace justinholtweb\transport\models;

use craft\base\Model;
use justinholtweb\transport\records\ImportHistory;

/**
 * The detailed outcome of an export or import: per-element rows classified as created,
 * updated, skipped or failed, plus the running totals, errors and timing.
 *
 * Reports are built by {@see \justinholtweb\transport\services\Export::run()} and
 * {@see \justinholtweb\transport\services\Import::run()}, persisted on the history
 * record, printed by the console commands, and rendered into completion emails.
 */
class TransportReport extends Model
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_FAILED = 'failed';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_SKIPPED,
        self::ACTION_FAILED,
    ];

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DRY_RUN = 'dry-run';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Upper bound on the per-element rows a report keeps. Totals stay exact; once the
     * cap is hit further rows are dropped and {@see $truncated} flips to true, so a
     * six-figure import can't produce an unbounded history row or email.
     */
    public const MAX_ITEMS = 2000;

    /**
     * Upper bound on the run-level errors a report keeps, for the same reason — and
     * because the history row's error log is a plain TEXT column.
     */
    public const MAX_ERRORS = 200;

    /** @var string One of {@see ImportHistory}'s DIRECTION_* constants. */
    public string $direction = ImportHistory::DIRECTION_IMPORT;

    /** @var string One of the STATUS_* constants. */
    public string $status = self::STATUS_COMPLETED;

    public string $packageName = '';

    /** @var string|null Absolute path to the package (exports, and imports from disk). */
    public ?string $packagePath = null;

    public bool $dryRun = false;

    /** @var int|null The history row this report belongs to, once written. */
    public ?int $historyId = null;

    /** @var int|null The user who started the operation. */
    public ?int $userId = null;

    /** @var float Wall-clock seconds the operation took. */
    public float $duration = 0.0;

    /** @var bool Whether per-element rows were capped at {@see self::MAX_ITEMS}. */
    public bool $truncated = false;

    /**
     * @var array<int, array{action:string,key:string,type:string,uid:string,title:string,detail:string}>
     *      Per-element rows, in the order they were processed.
     */
    public array $items = [];

    /** @var string[] */
    public array $errors = [];

    /** @var int How many errors were dropped once {@see self::MAX_ERRORS} was reached. */
    public int $errorOverflow = 0;

    /** @var array<string, int> Totals keyed by action. */
    private array $counts = [
        self::ACTION_CREATED => 0,
        self::ACTION_UPDATED => 0,
        self::ACTION_SKIPPED => 0,
        self::ACTION_FAILED => 0,
    ];

    /** @var array<string, array<string, int>> Totals keyed by package key, then action. */
    private array $countsByKey = [];

    private float $startedAt = 0.0;

    /**
     * Starts the clock. Call once, before any work.
     */
    public function begin(): void
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Stops the clock and settles the final status.
     */
    public function end(?string $status = null): void
    {
        if ($this->startedAt > 0.0) {
            $this->duration = round(microtime(true) - $this->startedAt, 3);
        }

        $this->status = $status ?? match (true) {
            (bool)$this->errors => self::STATUS_FAILED,
            $this->dryRun => self::STATUS_DRY_RUN,
            default => self::STATUS_COMPLETED,
        };
    }

    /**
     * Records one element's outcome.
     *
     * @param string $action One of the ACTION_* constants.
     * @param array $data The serialized element the outcome belongs to.
     * @param string $detail Why it was skipped or how it failed, when relevant.
     */
    public function record(string $action, array $data = [], string $detail = ''): void
    {
        $key = $data['key'] ?? 'elements';

        $this->counts[$action] = ($this->counts[$action] ?? 0) + 1;
        $this->countsByKey[$key][$action] = ($this->countsByKey[$key][$action] ?? 0) + 1;

        if (count($this->items) >= self::MAX_ITEMS) {
            $this->truncated = true;
            return;
        }

        $this->items[] = [
            'action' => $action,
            'key' => $key,
            'type' => $data['type'] ?? '',
            'uid' => $data['uid'] ?? '',
            'title' => self::titleOf($data),
            'detail' => $detail,
        ];
    }

    /**
     * Records a failure that isn't tied to a single element (validation, a fatal error).
     * Named around the run rather than the model, so it can't be confused with
     * {@see \yii\base\Model::addError()}.
     */
    public function recordError(string $message): void
    {
        if (count($this->errors) >= self::MAX_ERRORS) {
            $this->errorOverflow++;
            return;
        }

        $this->errors[] = $message;
    }

    public function countOf(string $action): int
    {
        return $this->counts[$action] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getCountsByKey(): array
    {
        return $this->countsByKey;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * @return array<int, array> The rows for one action.
     */
    public function itemsFor(string $action): array
    {
        return array_values(array_filter($this->items, static fn(array $i) => $i['action'] === $action));
    }

    /**
     * A one-line summary, e.g. "created 12, updated 3, skipped 1, failed 0".
     */
    public function summary(): string
    {
        $parts = [];
        foreach (self::ACTIONS as $action) {
            $parts[] = sprintf('%s %d', $action, $this->countOf($action));
        }
        return implode(', ', $parts);
    }

    public function isSuccessful(): bool
    {
        return !$this->errors && $this->status !== self::STATUS_FAILED;
    }

    /**
     * The legacy import result shape, kept so existing integrations against
     * {@see \justinholtweb\transport\services\Import::importPackage()} keep working.
     *
     * @return array{status:string,created:int,updated:int,skipped:int,failed:int,errors:string[]}
     */
    public function toLegacyResult(): array
    {
        return [
            'status' => $this->status,
            'created' => $this->countOf(self::ACTION_CREATED),
            'updated' => $this->countOf(self::ACTION_UPDATED),
            'skipped' => $this->countOf(self::ACTION_SKIPPED),
            'failed' => $this->countOf(self::ACTION_FAILED),
            'errors' => $this->errors,
        ];
    }

    /**
     * The JSON-safe form persisted on the history record.
     */
    public function toStorageArray(): array
    {
        return [
            'direction' => $this->direction,
            'status' => $this->status,
            'packageName' => $this->packageName,
            'dryRun' => $this->dryRun,
            'duration' => $this->duration,
            'truncated' => $this->truncated,
            'counts' => $this->counts,
            'countsByKey' => $this->countsByKey,
            'items' => $this->items,
            'errors' => $this->errors,
            'errorOverflow' => $this->errorOverflow,
        ];
    }

    /**
     * Rebuilds a report from {@see toStorageArray()} output.
     */
    public static function fromStorageArray(array $data): self
    {
        $report = new self();
        $report->direction = (string)($data['direction'] ?? ImportHistory::DIRECTION_IMPORT);
        $report->status = (string)($data['status'] ?? self::STATUS_COMPLETED);
        $report->packageName = (string)($data['packageName'] ?? '');
        $report->dryRun = (bool)($data['dryRun'] ?? false);
        $report->duration = (float)($data['duration'] ?? 0);
        $report->truncated = (bool)($data['truncated'] ?? false);
        $report->items = array_values((array)($data['items'] ?? []));
        $report->errors = array_values((array)($data['errors'] ?? []));
        $report->errorOverflow = (int)($data['errorOverflow'] ?? 0);

        foreach (self::ACTIONS as $action) {
            $report->counts[$action] = (int)($data['counts'][$action] ?? 0);
        }
        $report->countsByKey = (array)($data['countsByKey'] ?? []);

        return $report;
    }

    /**
     * The best available human label for a serialized element — its primary-site title,
     * falling back to the first site that has one, then to the UID.
     */
    public static function titleOf(array $data): string
    {
        foreach ((array)($data['sites'] ?? []) as $site) {
            if (!empty($site['title'])) {
                return (string)$site['title'];
            }
        }

        return (string)($data['uid'] ?? '');
    }
}
