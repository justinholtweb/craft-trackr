<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Request as WebRequest;
use DateTime;
use justinholtweb\trackr\db\Table;
use justinholtweb\trackr\models\LogEntry;
use justinholtweb\trackr\Plugin;
use Throwable;

/**
 * Trackr's activity log: what was recorded, by whom, and what went wrong.
 *
 * Logging is never allowed to break the thing it is logging — every write is wrapped, because a
 * log row failing to insert must not lose a tracking number.
 */
class Log extends Component
{
    /**
     * @param array{orderId?: int|null, level?: string, payload?: string|null, source?: string|null, message?: string|null} $options
     */
    public function write(string $action, string $summary, array $options = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => substr($action, 0, 32),
                'level' => $options['level'] ?? 'info',
                'orderId' => $options['orderId'] ?? null,
                'summary' => substr($summary, 0, 255),
                'message' => $options['message'] ?? null,
                'payload' => $settings->logPayloads ? ($options['payload'] ?? null) : null,
                'source' => $options['source'] ?? null,
                'ip' => $this->clientIp(),
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();
        } catch (Throwable $e) {
            Craft::error('Trackr could not write a log row: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $query = $this->buildQuery($filters)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        return array_map(static fn(array $row) => new LogEntry($row), $query->all());
    }

    public function getEntryCount(array $filters = []): int
    {
        return (int)$this->buildQuery($filters)->count('[[id]]');
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = (new Query())->from(Table::LOG)->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * Drop rows past the retention window. Returns how many went.
     */
    public function prune(?int $days = null): int
    {
        $days ??= Plugin::getInstance()->getSettings()->getEffectiveLogRetentionDays();

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        try {
            return (int)Craft::$app->getDb()->createCommand()
                ->delete(Table::LOG, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
                ->execute();
        } catch (Throwable $e) {
            Craft::error('Trackr could not prune the log: ' . $e->getMessage(), __METHOD__);

            return 0;
        }
    }

    public function clear(): int
    {
        try {
            return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function buildQuery(array $filters): Query
    {
        $query = (new Query())->from(Table::LOG);

        if (($filters['level'] ?? '') !== '') {
            $query->andWhere(['level' => $filters['level']]);
        }

        if (($filters['action'] ?? '') !== '') {
            $query->andWhere(['action' => $filters['action']]);
        }

        if (!empty($filters['orderId'])) {
            $query->andWhere(['orderId' => (int)$filters['orderId']]);
        }

        $search = trim((string)($filters['search'] ?? ''));

        if ($search !== '') {
            $query->andWhere(['like', 'summary', '%' . $search . '%', false]);
        }

        return $query;
    }

    /**
     * Console requests have no client, so anything that may run from a command has to type-check
     * before reaching for web-only request methods.
     */
    private function clientIp(): ?string
    {
        $request = Craft::$app->getRequest();

        return $request instanceof WebRequest ? $request->getUserIP() : null;
    }
}
