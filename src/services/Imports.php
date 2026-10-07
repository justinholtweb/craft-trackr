<?php

namespace justinholtweb\trackr\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\trackr\models\Shipment;
use justinholtweb\trackr\Plugin;
use Throwable;

/**
 * CSV import.
 *
 * Dropshippers, 3PLs and label services all hand back a spreadsheet, and no two of them agree on
 * what the columns are called. Import maps headers through an alias table rather than demanding
 * an exact format, previews everything before it writes, and puts every row through
 * `Shipments::record()` so a re-uploaded file updates rather than duplicates.
 */
class Imports extends Component
{
    /**
     * Header aliases, in the order they are tried. Comparison is on the header with everything
     * but letters and numbers removed, so "Tracking Number", "tracking_number" and
     * "TRACKING-NUMBER" are the same column.
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'orderNumber' => [
            'ordernumber', 'order', 'orderid', 'orderno', 'ordernr', 'reference', 'orderreference',
            'ordernumberreference', 'order#', 'invoice', 'invoicenumber',
        ],
        'trackingNumber' => [
            'trackingnumber', 'tracking', 'trackingno', 'trackingcode', 'trackingid', 'awb',
            'awbnumber', 'waybill', 'consignment', 'consignmentnumber', 'shipmentid', 'barcode',
        ],
        'provider' => [
            'provider', 'carrier', 'shippingprovider', 'shippingcarrier', 'courier', 'shippingcompany',
            'trackingprovider', 'shipper', 'carriername',
        ],
        'shipDate' => [
            'shipdate', 'date', 'shippeddate', 'dateshipped', 'shippingdate', 'dispatchdate', 'senddate',
        ],
        'service' => [
            'service', 'shippingservice', 'shippingmethod', 'method', 'servicelevel', 'shipmethod',
        ],
        'status' => ['status', 'shipmentstatus', 'trackingstatus', 'deliverystatus'],
        'note' => ['note', 'notes', 'comment', 'comments', 'remarks'],
        'trackingUrl' => ['trackingurl', 'trackinglink', 'url', 'link'],
        'lineItems' => ['lineitems', 'items', 'itemjson', 'lineitemjson'],
    ];

    /**
     * Read a CSV into mapped rows.
     *
     * @return array{headers: string[], map: array<string, int>, rows: array<int, array<string, string>>, error: string|null}
     */
    public function parse(string $path, int $maxRows = 5000): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['headers' => [], 'map' => [], 'rows' => [], 'error' => Craft::t('trackr', 'That file could not be read.')];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ['headers' => [], 'map' => [], 'rows' => [], 'error' => Craft::t('trackr', 'That file could not be opened.')];
        }

        try {
            $first = fgets($handle);

            if ($first === false) {
                return ['headers' => [], 'map' => [], 'rows' => [], 'error' => Craft::t('trackr', 'That file is empty.')];
            }

            $delimiter = $this->detectDelimiter($first);
            rewind($handle);

            $headerRow = fgetcsv($handle, 0, $delimiter);

            if ($headerRow === false || $headerRow === [null]) {
                return ['headers' => [], 'map' => [], 'rows' => [], 'error' => Craft::t('trackr', 'That file has no header row.')];
            }

            // Excel writes a UTF-8 BOM, which otherwise becomes part of the first header's name.
            $headerRow[0] = preg_replace('/^\x{FEFF}/u', '', (string)$headerRow[0]);

            $headers = array_map(static fn($h) => trim((string)$h), $headerRow);
            $map = $this->mapHeaders($headers);

            if (!isset($map['orderNumber'])) {
                return [
                    'headers' => $headers,
                    'map' => $map,
                    'rows' => [],
                    'error' => Craft::t('trackr', 'No order number column. Name one of the columns “Order Number”.'),
                ];
            }

            $rows = [];
            $lineNumber = 1;

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($values === [null]) {
                    continue;
                }

                $row = ['_line' => $lineNumber];

                foreach ($map as $field => $index) {
                    $row[$field] = isset($values[$index]) ? trim((string)$values[$index]) : '';
                }

                // A trailing blank line is not an error worth reporting.
                if (($row['orderNumber'] ?? '') === '' && ($row['trackingNumber'] ?? '') === '') {
                    continue;
                }

                $rows[] = $row;

                if (count($rows) >= $maxRows) {
                    break;
                }
            }

            return ['headers' => $headers, 'map' => $map, 'rows' => $rows, 'error' => null];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Resolve every row against real orders and providers without writing anything.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array{total: int, ready: int, problems: int}, error: string|null}
     */
    public function preview(string $path, int $maxRows = 5000): array
    {
        $parsed = $this->parse($path, $maxRows);

        if ($parsed['error'] !== null) {
            return ['rows' => [], 'summary' => ['total' => 0, 'ready' => 0, 'problems' => 0], 'error' => $parsed['error']];
        }

        $rows = [];
        $ready = 0;

        foreach ($parsed['rows'] as $row) {
            $resolved = $this->resolveRow($row);

            if ($resolved['errors'] === []) {
                $ready++;
            }

            $rows[] = $resolved;
        }

        return [
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'ready' => $ready,
                'problems' => count($rows) - $ready,
            ],
            'error' => null,
        ];
    }

    /**
     * Import a CSV.
     *
     * @return array{imported: int, updated: int, failed: int, skipped: int, rows: array<int, array<string, mixed>>, error: string|null}
     */
    public function import(string $path, bool $notify = false, int $maxRows = 5000): array
    {
        $preview = $this->preview($path, $maxRows);

        if ($preview['error'] !== null) {
            return ['imported' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'rows' => [], 'error' => $preview['error']];
        }

        $plugin = Plugin::getInstance();
        $shipments = $plugin->getShipments();

        $imported = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;
        $rows = [];

        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== []) {
                $failed++;
                $rows[] = $row;
                continue;
            }

            $order = $shipments->getOrderById((int)$row['orderId']);

            if ($order === null) {
                $row['errors'][] = Craft::t('trackr', 'Order disappeared between preview and import.');
                $failed++;
                $rows[] = $row;
                continue;
            }

            $result = $shipments->record($order, [
                'provider' => $row['provider'],
                'trackingNumber' => $row['trackingNumber'],
                'trackingUrl' => $row['trackingUrl'],
                'service' => $row['service'],
                'shipDate' => $row['shipDate'],
                'status' => $row['status'],
                'note' => $row['note'],
                'items' => $row['items'],
                'source' => Shipment::SOURCE_CSV,
                'notify' => $notify ?: null,
            ]);

            if ($result['shipment'] === null) {
                $row['errors'] = array_merge($row['errors'], array_map('strval', $result['errors']));
                $failed++;
            } elseif ($result['isNew']) {
                $imported++;
                $row['result'] = 'imported';
            } else {
                $updated++;
                $row['result'] = 'updated';
            }

            $rows[] = $row;
        }

        $plugin->getLog()->write('import.csv', Craft::t('trackr', '{imported} added, {updated} updated, {failed} failed', [
            'imported' => $imported,
            'updated' => $updated,
            'failed' => $failed,
        ]), [
            'level' => $failed > 0 ? 'warning' : 'info',
            'source' => Shipment::SOURCE_CSV,
            'message' => basename($path),
        ]);

        return [
            'imported' => $imported,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
            'rows' => $rows,
            'error' => null,
        ];
    }

    /**
     * Import every CSV sitting in the watched directory (Pro).
     *
     * Files are moved out of the way once read, so a directory that a supplier keeps dropping
     * into is not re-imported on every run.
     *
     * @return array{files: int, imported: int, updated: int, failed: int, messages: string[]}
     */
    public function watch(?string $path = null, bool $notify = false): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $result = ['files' => 0, 'imported' => 0, 'updated' => 0, 'failed' => 0, 'messages' => []];

        if (!$plugin->isPro()) {
            $result['messages'][] = Craft::t('trackr', 'Watched-folder import is a Pro feature.');

            return $result;
        }

        $dir = $path !== null && $path !== '' ? $path : $settings->getParsedWatchPath();

        if ($dir === '' || !is_dir($dir)) {
            $result['messages'][] = Craft::t('trackr', 'No watched folder is configured.');

            return $result;
        }

        $files = glob(rtrim($dir, '/') . '/*.{csv,CSV,txt,TXT}', GLOB_BRACE) ?: [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $result['files']++;
            $outcome = $this->import($file, $notify);

            if ($outcome['error'] !== null) {
                $result['messages'][] = basename($file) . ': ' . $outcome['error'];
                continue;
            }

            $result['imported'] += $outcome['imported'];
            $result['updated'] += $outcome['updated'];
            $result['failed'] += $outcome['failed'];
            $result['messages'][] = sprintf(
                '%s: %d added, %d updated, %d failed',
                basename($file),
                $outcome['imported'],
                $outcome['updated'],
                $outcome['failed']
            );

            $this->retireFile($file, $settings->importArchiveProcessed);
        }

        return $result;
    }

    /**
     * A sample CSV, so the merchant can see the shape rather than read about it.
     */
    public function sampleCsv(): string
    {
        return implode("\n", [
            'Order Number,Tracking Number,Carrier,Ship Date,Service,Status,Note',
            'CT-1042,1Z999AA10123456784,UPS,2026-08-14,Ground,in_transit,',
            'CT-1043,9400111899223197428490,USPS,2026-08-14,Priority Mail,in_transit,Left with neighbour',
            'CT-1044,7712345678,DHL Express,2026-08-15,Express Worldwide,delivered,',
        ]) . "\n";
    }

    /**
     * Store an uploaded file where the commit step can find it again, without letting the
     * uploader choose the name or the extension.
     */
    public function stashUpload(string $tempPath): ?string
    {
        try {
            $dir = Craft::$app->getPath()->getTempPath() . '/trackr';
            FileHelper::createDirectory($dir);

            $target = $dir . '/' . StringHelper::UUID() . '.csv';

            if (!copy($tempPath, $target)) {
                return null;
            }

            return basename($target, '.csv');
        } catch (Throwable $e) {
            Craft::error('Trackr could not stash an upload: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * Resolve a stash token back to a path. The token is a UUID Trackr generated, so nothing a
     * caller sends can walk out of the temp directory.
     */
    public function stashedPath(string $token): ?string
    {
        if (!StringHelper::isUUID($token)) {
            return null;
        }

        $path = Craft::$app->getPath()->getTempPath() . '/trackr/' . $token . '.csv';

        return is_file($path) ? $path : null;
    }

    public function discardStash(string $token): void
    {
        $path = $this->stashedPath($token);

        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Turn one CSV row into something `Shipments::record()` can take, or into a list of reasons
     * why it cannot.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function resolveRow(array $row): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $errors = [];
        $warnings = [];

        $orderNumber = trim((string)($row['orderNumber'] ?? ''));
        $trackingNumber = trim((string)($row['trackingNumber'] ?? ''));
        $providerValue = trim((string)($row['provider'] ?? ''));

        $order = $orderNumber !== '' ? $this->findOrderFor($orderNumber, $settings->csvOrderNumberSource) : null;

        if ($order === null) {
            $errors[] = Craft::t('trackr', 'No order matches “{number}”.', ['number' => $orderNumber ?: '—']);
        }

        if ($trackingNumber === '' && $providerValue === '') {
            $errors[] = Craft::t('trackr', 'Neither a tracking number nor a carrier.');
        }

        $provider = $plugin->getProviders()->resolve($providerValue);

        if ($provider === null && $settings->autoDetectProvider) {
            $provider = $plugin->getProviders()->detect($trackingNumber);
        }

        if ($provider === null && $providerValue !== '') {
            // Not fatal: the tracking number is still worth recording, it just will not link.
            $warnings[] = Craft::t('trackr', 'Unknown carrier “{carrier}” — recorded without a tracking link.', [
                'carrier' => $providerValue,
            ]);
        }

        $status = strtolower(str_replace([' ', '-'], '_', trim((string)($row['status'] ?? ''))));

        if ($status !== '' && !isset(Shipment::statuses()[$status])) {
            $status = '';
        }

        $items = [];
        $rawItems = trim((string)($row['lineItems'] ?? ''));

        if ($rawItems !== '') {
            $decoded = Json::decodeIfJson($rawItems);
            $items = is_array($decoded) ? $decoded : [];
        }

        return [
            '_line' => $row['_line'] ?? null,
            'orderNumber' => $orderNumber,
            'orderId' => $order?->id,
            'orderReference' => $order?->reference,
            'trackingNumber' => $trackingNumber,
            'provider' => $provider->handle ?? $providerValue,
            'providerLabel' => $provider->name ?? $providerValue,
            'trackingUrl' => trim((string)($row['trackingUrl'] ?? '')),
            'service' => trim((string)($row['service'] ?? '')),
            'shipDate' => trim((string)($row['shipDate'] ?? '')),
            'status' => $status !== '' ? $status : $settings->defaultShipmentStatus,
            'note' => trim((string)($row['note'] ?? '')),
            'items' => $items,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, string[]>
     */
    public function getAliases(): array
    {
        $aliases = self::ALIASES;

        foreach (Plugin::getInstance()->getSettings()->csvColumnAliases as $field => $extra) {
            if (!isset($aliases[$field])) {
                continue;
            }

            foreach ((array)$extra as $alias) {
                $aliases[$field][] = $this->normalizeHeader((string)$alias);
            }
        }

        return $aliases;
    }

    /**
     * @param string[] $headers
     * @return array<string, int>
     */
    private function mapHeaders(array $headers): array
    {
        $aliases = $this->getAliases();
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);

            if ($normalized === '') {
                continue;
            }

            foreach ($aliases as $field => $candidates) {
                if (isset($map[$field])) {
                    continue;
                }

                if (in_array($normalized, $candidates, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower((string)preg_replace('/[^a-zA-Z0-9#]+/', '', $header));
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            "\t" => substr_count($line, "\t"),
            '|' => substr_count($line, '|'),
        ];

        arsort($counts);
        $delimiter = array_key_first($counts);

        return $counts[$delimiter] > 0 ? $delimiter : ',';
    }

    private function findOrderFor(string $number, string $source): ?\craft\commerce\elements\Order
    {
        $tracking = Plugin::getInstance()->getTracking();

        if ($source === 'auto') {
            return $tracking->findOrder($number);
        }

        $query = \craft\commerce\elements\Order::find()->status(null)->isCompleted(true);

        $order = match ($source) {
            'reference' => $query->reference($number)->one(),
            'number' => $query->number($number)->one(),
            'shortNumber' => $query->shortNumber(strtolower(substr($number, 0, 7)))->one(),
            'id' => ctype_digit($number) ? $query->id((int)$number)->one() : null,
            default => null,
        };

        return $order instanceof \craft\commerce\elements\Order ? $order : null;
    }

    private function retireFile(string $file, bool $archive): void
    {
        try {
            if (!$archive) {
                @unlink($file);

                return;
            }

            $processedDir = dirname($file) . '/processed';
            FileHelper::createDirectory($processedDir);

            @rename($file, $processedDir . '/' . date('Ymd-His') . '-' . basename($file));
        } catch (Throwable $e) {
            Craft::error('Trackr could not retire an imported file: ' . $e->getMessage(), __METHOD__);
        }
    }
}
