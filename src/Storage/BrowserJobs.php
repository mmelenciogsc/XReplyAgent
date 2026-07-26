<?php

declare(strict_types=1);

namespace XReplyAgent\Storage;

use XReplyAgent\Support\Redactor;
use XReplyAgent\Support\Schema;

final class BrowserJobs
{
    /**
     * @return array<string, string>
     */
    public static function tables(): array
    {
        return Schema::tables();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function listJobs(array $filters = [], int $limit = 20): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(100, $limit));

        $sql = "SELECT * FROM {$tables['browser_jobs']}";
        $where = [];
        $params = [];

        $replySetId = (int) ($filters['reply_set_id'] ?? 0);
        if ($replySetId > 0) {
            $where[] = 'reply_set_id = %d';
            $params[] = $replySetId;
        }

        $candidateId = (int) ($filters['candidate_id'] ?? 0);
        if ($candidateId > 0) {
            $where[] = 'candidate_id = %d';
            $params[] = $candidateId;
        }

        $status = sanitize_key((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestJob(int $replySetId = 0): array
    {
        $filters = $replySetId > 0 ? ['reply_set_id' => $replySetId] : [];
        $rows = self::listJobs($filters, 1);
        return $rows[0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function activeJobForReplySet(int $replySetId): array
    {
        $rows = self::listJobs([
            'reply_set_id' => $replySetId,
        ], 10);

        foreach ($rows as $row) {
            if (in_array((string) ($row['status'] ?? ''), ['queued', 'running', 'paused'], true)) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function saveJob(array $data): int
    {
        global $wpdb;
        $tables = self::tables();
        $id = (int) ($data['id'] ?? 0);
        $record = [
            'reply_set_id' => (int) ($data['reply_set_id'] ?? 0),
            'candidate_id' => (int) ($data['candidate_id'] ?? 0),
            'post_id' => (int) ($data['post_id'] ?? 0),
            'job_type' => sanitize_text_field((string) ($data['job_type'] ?? 'publish_monitor')),
            'status' => sanitize_key((string) ($data['status'] ?? 'queued')),
            'phase' => sanitize_key((string) ($data['phase'] ?? 'publish')),
            'current_step' => sanitize_text_field((string) ($data['current_step'] ?? '')),
            'target_url' => !empty($data['target_url']) ? esc_url_raw((string) $data['target_url']) : null,
            'published_url' => !empty($data['published_url']) ? esc_url_raw((string) $data['published_url']) : null,
            'browser_profile_dir' => trim((string) ($data['browser_profile_dir'] ?? '')) !== '' ? sanitize_text_field((string) $data['browser_profile_dir']) : null,
            'browser_state_file' => trim((string) ($data['browser_state_file'] ?? '')) !== '' ? sanitize_text_field((string) $data['browser_state_file']) : null,
            'control_file_path' => trim((string) ($data['control_file_path'] ?? '')) !== '' ? sanitize_text_field((string) $data['control_file_path']) : null,
            'browser_session_name' => sanitize_text_field((string) ($data['browser_session_name'] ?? '')),
            'pid' => (int) ($data['pid'] ?? 0),
            'monitor_cycles' => max(1, (int) ($data['monitor_cycles'] ?? 3)),
            'monitor_interval_seconds' => max(1, (int) ($data['monitor_interval_seconds'] ?? 20)),
            'observed_metrics_json' => self::jsonOrNull($data['observed_metrics'] ?? null),
            'latest_screenshot_url' => !empty($data['latest_screenshot_url']) ? esc_url_raw((string) $data['latest_screenshot_url']) : null,
            'latest_screenshot_path' => trim((string) ($data['latest_screenshot_path'] ?? '')) !== '' ? sanitize_text_field((string) $data['latest_screenshot_path']) : null,
            'log_excerpt' => wp_kses_post((string) ($data['log_excerpt'] ?? '')),
            'error_message' => wp_kses_post((string) ($data['error_message'] ?? '')),
            'started_by' => (int) ($data['started_by'] ?? 0),
            'completed_by' => (int) ($data['completed_by'] ?? 0),
            'started_at' => !empty($data['started_at']) ? sanitize_text_field((string) $data['started_at']) : null,
            'next_check_at' => !empty($data['next_check_at']) ? sanitize_text_field((string) $data['next_check_at']) : null,
            'last_polled_at' => !empty($data['last_polled_at']) ? sanitize_text_field((string) $data['last_polled_at']) : null,
            'completed_at' => !empty($data['completed_at']) ? sanitize_text_field((string) $data['completed_at']) : null,
            'updated_at' => current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $tables['browser_jobs'],
                $record,
                ['id' => $id],
                ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            return $id;
        }

        $wpdb->insert(
            $tables['browser_jobs'],
            $record + ['created_at' => current_time('mysql')],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public static function updateJob(int $jobId, array $data): bool
    {
        if ($jobId <= 0) {
            return false;
        }

        $existing = self::getJob($jobId);
        if ($existing === []) {
            return false;
        }

        $data['id'] = $jobId;
        return self::saveJob(array_merge($existing, $data)) === $jobId;
    }

    public static function setStatus(int $jobId, string $status, string $phase = '', string $step = '', array $extra = []): bool
    {
        $job = self::getJob($jobId);
        if ($job === []) {
            return false;
        }

        $payload = array_merge($job, $extra, [
            'id' => $jobId,
            'status' => $status,
            'phase' => $phase !== '' ? $phase : (string) ($job['phase'] ?? ''),
            'current_step' => $step !== '' ? $step : (string) ($job['current_step'] ?? ''),
            'updated_at' => current_time('mysql'),
        ]);

        return self::saveJob($payload) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getJob(int $jobId): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['browser_jobs']} WHERE id = %d LIMIT 1", $jobId),
            ARRAY_A
        );

        return is_array($row) ? self::hydrateRow($row) : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function saveEvent(array $data): int
    {
        global $wpdb;
        $tables = self::tables();

        $wpdb->insert(
            $tables['browser_events'],
            [
                'job_id' => (int) ($data['job_id'] ?? 0),
                'event_type' => sanitize_key((string) ($data['event_type'] ?? 'event')),
                'phase' => sanitize_key((string) ($data['phase'] ?? '')),
                'step_index' => max(1, (int) ($data['step_index'] ?? 1)),
                'message' => wp_kses_post((string) ($data['message'] ?? '')),
                'data_json' => self::jsonOrNull(Redactor::clean($data['data'] ?? null)),
                'screenshot_path' => trim((string) ($data['screenshot_path'] ?? '')) !== '' ? sanitize_text_field((string) $data['screenshot_path']) : null,
                'screenshot_url' => !empty($data['screenshot_url']) ? esc_url_raw((string) $data['screenshot_url']) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listEvents(int $jobId, int $limit = 20): array
    {
        global $wpdb;
        $tables = self::tables();
        $limit = max(1, min(100, $limit));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['browser_events']} WHERE job_id = %d ORDER BY id DESC LIMIT %d",
                $jobId,
                $limit
            ),
            ARRAY_A
        );

        return self::hydrateRows(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestActive(): array
    {
        global $wpdb;
        $tables = self::tables();
        $row = $wpdb->get_row(
            "SELECT * FROM {$tables['browser_jobs']} WHERE status IN ('queued','running','paused') ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        return is_array($row) ? self::hydrateRow($row) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function latestForReplySet(int $replySetId): array
    {
        return self::latestJob($replySetId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(int $replySetId = 0): array
    {
        $job = $replySetId > 0 ? self::latestJob($replySetId) : self::latestActive();
        $events = $job !== [] ? self::listEvents((int) ($job['id'] ?? 0), 10) : [];

        return [
            'job' => $job,
            'events' => $events,
            'has_active_job' => $job !== [] && in_array((string) ($job['status'] ?? ''), ['queued', 'running', 'paused'], true),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function hydrateRows(array $rows): array
    {
        return array_map([self::class, 'hydrateRow'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateRow(array $row): array
    {
        foreach (['observed_metrics_json', 'data_json'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $decoded = json_decode((string) ($row[$key] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * @param mixed $value
     */
    private static function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
