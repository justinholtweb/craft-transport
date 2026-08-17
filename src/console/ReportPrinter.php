<?php

namespace justinholtweb\transport\console;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\records\ImportHistory;

/**
 * Prints a {@see TransportReport} to the terminal: totals, a per-type breakdown, the
 * elements behind each outcome, and any errors.
 */
class ReportPrinter
{
    private const COLORS = [
        TransportReport::ACTION_CREATED => Console::FG_GREEN,
        TransportReport::ACTION_UPDATED => Console::FG_YELLOW,
        TransportReport::ACTION_SKIPPED => Console::FG_GREY,
        TransportReport::ACTION_FAILED => Console::FG_RED,
    ];

    /**
     * @param int $detailLimit How many elements to list per outcome. Pass a large value
     *                         (as `--verbose` does) to list everything the report kept.
     */
    public function __construct(
        private readonly Controller $controller,
        private readonly int $detailLimit = 20,
    ) {
    }

    public function print(TransportReport $report): void
    {
        $this->heading($report);
        $this->totals($report);
        $this->byType($report);

        foreach (TransportReport::ACTIONS as $action) {
            $this->details($report, $action);
        }

        if ($report->truncated) {
            $this->controller->stdout(
                "Per-element detail was capped at " . TransportReport::MAX_ITEMS . " rows; totals above are complete.\n",
                Console::FG_GREY
            );
        }

        $this->errors($report);
    }

    private function heading(TransportReport $report): void
    {
        $this->controller->stdout("\n" . str_repeat('─', 60) . "\n");
        $this->controller->stdout(sprintf(
            "%s report — %s%s · %.1fs\n",
            $report->direction === ImportHistory::DIRECTION_EXPORT ? 'Export' : 'Import',
            $report->status,
            $report->dryRun ? ' (dry run — nothing saved)' : '',
            $report->duration
        ), $report->isSuccessful() ? Console::FG_GREEN : Console::FG_RED);

        if ($report->packageName !== '') {
            $this->controller->stdout("Package: {$report->packageName}\n", Console::FG_GREY);
        }

        $this->controller->stdout("\n");
    }

    private function totals(TransportReport $report): void
    {
        foreach (TransportReport::ACTIONS as $action) {
            $this->controller->stdout(sprintf("  %-10s %5d\n", $this->label($report, $action), $report->countOf($action)),
                self::COLORS[$action]);
        }

        $this->controller->stdout("\n");
    }

    private function byType(TransportReport $report): void
    {
        $byKey = $report->getCountsByKey();
        if (!$byKey) {
            return;
        }

        $this->controller->stdout("By type\n");

        foreach ($byKey as $key => $counts) {
            $parts = [];
            foreach (TransportReport::ACTIONS as $action) {
                if (!empty($counts[$action])) {
                    $parts[] = strtolower($this->label($report, $action)) . ' ' . $counts[$action];
                }
            }
            $this->controller->stdout(sprintf("  %-16s %s\n", $key, implode(', ', $parts)));
        }

        $this->controller->stdout("\n");
    }

    private function details(TransportReport $report, string $action): void
    {
        $items = $report->itemsFor($action);
        if (!$items) {
            return;
        }

        $this->controller->stdout(
            sprintf("%s (%d)\n", $this->label($report, $action), $report->countOf($action)),
            self::COLORS[$action]
        );

        foreach (array_slice($items, 0, $this->detailLimit) as $item) {
            $this->controller->stdout(sprintf(
                "  · %s (%s)%s\n",
                $item['title'] !== '' ? $item['title'] : $item['uid'],
                $item['key'],
                $item['detail'] !== '' ? ' — ' . $item['detail'] : ''
            ));
        }

        if (count($items) > $this->detailLimit) {
            $this->controller->stdout(sprintf(
                "  …and %d more (run with --verbose to list them all)\n",
                count($items) - $this->detailLimit
            ), Console::FG_GREY);
        }

        $this->controller->stdout("\n");
    }

    private function errors(TransportReport $report): void
    {
        if (!$report->errors) {
            return;
        }

        $this->controller->stdout("Errors\n", Console::FG_RED);
        foreach ($report->errors as $error) {
            $this->controller->stderr("  - $error\n", Console::FG_RED);
        }
        if ($report->errorOverflow > 0) {
            $this->controller->stderr("  …and {$report->errorOverflow} more\n", Console::FG_GREY);
        }
        $this->controller->stdout("\n");
    }

    /**
     * Exports "add" nothing to the target, so their created rows read as "Exported".
     */
    private function label(TransportReport $report, string $action): string
    {
        if ($action === TransportReport::ACTION_CREATED && $report->direction === ImportHistory::DIRECTION_EXPORT) {
            return 'Exported';
        }

        return ucfirst($action === TransportReport::ACTION_CREATED ? 'added' : $action);
    }
}
