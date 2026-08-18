<?php

namespace justinholtweb\trackr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\trackr\Plugin;
use yii\console\ExitCode;

/**
 * Log housekeeping.
 */
class LogController extends Controller
{
    /**
     * Days to keep, overriding the configured retention.
     */
    public int $days = 0;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return $actionID === 'prune' ? array_merge($options, ['days']) : $options;
    }

    /**
     * Delete log rows past the retention window. Meant for cron.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days > 0 ? $this->days : null);

        $this->stdout("$deleted entries pruned.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete every log row.
     */
    public function actionClear(): int
    {
        if (!$this->confirm('Delete the entire Trackr log?')) {
            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("$deleted entries deleted.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
