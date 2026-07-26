<?php

declare(strict_types=1);

namespace XReplyAgent\Domain;

use XReplyAgent\Storage\Store;

final class Exporter
{
    public static function csv(string $type): string
    {
        $rows = Store::exportRows($type);
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }

        if ($rows === []) {
            fputcsv($fh, ['empty']);
        } else {
            $header = array_keys((array) $rows[0]);
            fputcsv($fh, $header);
            foreach ($rows as $row) {
                $line = [];
                foreach ($header as $key) {
                    $value = $row[$key] ?? '';
                    $line[] = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                fputcsv($fh, $line);
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return is_string($csv) ? $csv : '';
    }
}
