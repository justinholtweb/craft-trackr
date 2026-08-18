<?php

namespace justinholtweb\trackr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\trackr\Plugin;
use yii\console\ExitCode;

/**
 * CSV import from the command line — the half of the feature that belongs in cron.
 */
class ImportController extends Controller
{
    /**
     * Show what would happen without writing anything.
     */
    public bool $dryRun = false;

    /**
     * Email customers as shipments are recorded.
     */
    public bool $notify = false;

    /**
     * Directory to poll, overriding the configured one.
     */
    public string $path = '';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'csv' => array_merge($options, ['dryRun', 'notify']),
            'watch' => array_merge($options, ['path', 'notify']),
            default => $options,
        };
    }

    /**
     * Import one CSV file.
     *
     * trackr/import/csv /path/to/tracking.csv --dry-run
     */
    public function actionCsv(string $file): int
    {
        $plugin = Plugin::getInstance();

        if (!is_file($file)) {
            $this->stderr("No such file: $file\n", Console::FG_RED);

            return ExitCode::NOINPUT;
        }

        if ($this->dryRun) {
            $preview = $plugin->getImports()->preview($file);

            if ($preview['error'] !== null) {
                $this->stderr($preview['error'] . "\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            foreach ($preview['rows'] as $row) {
                $problems = array_merge($row['errors'], $row['warnings']);

                $this->stdout(sprintf(
                    "  %s %-16s %-22s %s\n",
                    $row['errors'] === [] ? '✓' : '✗',
                    substr((string)$row['orderNumber'], 0, 16),
                    substr((string)$row['trackingNumber'], 0, 22),
                    $problems !== [] ? implode(' ', $problems) : ''
                ), $row['errors'] === [] ? Console::FG_GREEN : Console::FG_RED);
            }

            $this->stdout(sprintf(
                "\n%d rows: %d ready, %d with problems.\n",
                $preview['summary']['total'],
                $preview['summary']['ready'],
                $preview['summary']['problems']
            ));

            return $preview['summary']['problems'] > 0 ? ExitCode::DATAERR : ExitCode::OK;
        }

        $result = $plugin->getImports()->import($file, $this->notify);

        if ($result['error'] !== null) {
            $this->stderr($result['error'] . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout(sprintf(
            "%d added, %d updated, %d failed.\n",
            $result['imported'],
            $result['updated'],
            $result['failed']
        ), $result['failed'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        foreach ($result['rows'] as $row) {
            if ($row['errors'] !== []) {
                $this->stderr('  line ' . ($row['_line'] ?? '?') . ': ' . implode(' ', $row['errors']) . "\n", Console::FG_RED);
            }
        }

        return $result['failed'] > 0 ? ExitCode::DATAERR : ExitCode::OK;
    }

    /**
     * Import every CSV in the watched folder, then move them aside (Pro). Meant for cron.
     */
    public function actionWatch(): int
    {
        $result = Plugin::getInstance()->getImports()->watch($this->path ?: null, $this->notify);

        foreach ($result['messages'] as $message) {
            $this->stdout('  ' . $message . "\n");
        }

        $this->stdout(sprintf(
            "%d file(s): %d added, %d updated, %d failed.\n",
            $result['files'],
            $result['imported'],
            $result['updated'],
            $result['failed']
        ), $result['failed'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Print a sample CSV.
     */
    public function actionSample(): int
    {
        $this->stdout(Plugin::getInstance()->getImports()->sampleCsv());

        return ExitCode::OK;
    }
}
