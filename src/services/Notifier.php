<?php

namespace justinholtweb\transport\services;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\transport\models\TransportReport;
use justinholtweb\transport\Plugin;
use justinholtweb\transport\records\ImportHistory;
use Throwable;
use yii\base\Component;

/**
 * Emails the outcome of a queued export or import to whoever started it.
 *
 * Notification is best-effort: a mail failure is logged and swallowed, never allowed to
 * fail the job whose work already succeeded.
 */
class Notifier extends Component
{
    /**
     * Emails the given report to its initiating user plus any addresses configured in
     * the plugin settings.
     *
     * @return bool Whether a message was sent.
     */
    public function reportFinished(TransportReport $report): bool
    {
        $recipients = $this->recipients($report);
        if (!$recipients) {
            Craft::info('No notification recipients for report; skipping email.', 'transport');
            return false;
        }

        try {
            $message = Craft::$app->getMailer()->compose()
                ->setTo($recipients)
                ->setSubject($this->subject($report))
                ->setTextBody($this->textBody($report))
                ->setHtmlBody($this->htmlBody($report));

            $sent = Craft::$app->getMailer()->send($message);

            Craft::info(sprintf(
                'Completion email for %s %s (%s) %s to: %s',
                $report->direction,
                $report->packageName,
                $report->status,
                $sent ? 'sent' : 'failed to send',
                implode(', ', $recipients)
            ), 'transport');

            return $sent;
        } catch (Throwable $e) {
            Craft::warning('Could not send Transport completion email: ' . $e->getMessage(), 'transport');
            return false;
        }
    }

    /**
     * @return string[] Deduplicated recipient addresses.
     */
    private function recipients(TransportReport $report): array
    {
        $emails = [];

        if ($report->userId !== null) {
            $user = Craft::$app->getUsers()->getUserById($report->userId);
            if ($user?->email) {
                $emails[] = $user->email;
            }
        }

        $configured = Plugin::getInstance()->getSettings()->notificationEmails;
        foreach (preg_split('/[,\s]+/', (string)App::parseEnv($configured), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $email) {
            $emails[] = $email;
        }

        return array_values(array_unique(array_filter($emails)));
    }

    private function subject(TransportReport $report): string
    {
        return Craft::t('transport', 'Transport {direction} {status}: {package}', [
            'direction' => $report->direction === ImportHistory::DIRECTION_EXPORT ? 'export' : 'import',
            'status' => $report->status,
            'package' => $report->packageName ?: 'package',
        ]);
    }

    private function htmlBody(TransportReport $report): string
    {
        $view = Craft::$app->getView();

        return $view->renderTemplate('transport/_email/report', $this->variables($report), View::TEMPLATE_MODE_CP);
    }

    /**
     * A plain-text equivalent, so the message is readable without HTML.
     */
    private function textBody(TransportReport $report): string
    {
        $lines = [
            sprintf('%s %s: %s', ucfirst($report->direction), $report->status, $report->packageName),
            $report->summary(),
            sprintf('Took %.1fs.', $report->duration),
        ];

        if ($report->dryRun) {
            $lines[] = 'Dry run — nothing was saved.';
        }

        foreach ($report->errors as $error) {
            $lines[] = "Error: $error";
        }

        $url = $this->historyUrl($report);
        if ($url !== null) {
            $lines[] = "Details: $url";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(TransportReport $report): array
    {
        return [
            'report' => $report,
            'historyUrl' => $this->historyUrl($report),
            'systemName' => Craft::$app->getSystemName(),
            'language' => Craft::$app->language,
        ];
    }

    private function historyUrl(TransportReport $report): ?string
    {
        return $report->historyId === null
            ? null
            : UrlHelper::cpUrl('transport/history/' . $report->historyId);
    }
}
