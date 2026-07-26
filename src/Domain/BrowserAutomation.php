<?php

declare(strict_types=1);

namespace XReplyAgent\Domain;

use XReplyAgent\Storage\BrowserJobs;
use XReplyAgent\Storage\Store;
use XReplyAgent\Support\Settings;

final class BrowserAutomation
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function startPublishAndMonitor(int $replySetId, int $candidateId, array $overrides = []): array
    {
        $replySet = Store::getReplySet($replySetId);
        if ($replySet === []) {
            return ['ok' => false, 'error' => 'Reply set not found.'];
        }

        $candidate = Store::getReplyCandidate($candidateId);
        if ($candidate === []) {
            return ['ok' => false, 'error' => 'Reply candidate not found.'];
        }

        $candidateStatus = (string) ($candidate['status'] ?? '');
        if (!in_array($candidateStatus, ['for_publishing', 'approved'], true)) {
            return ['ok' => false, 'error' => 'Mark the reply for publishing before starting.'];
        }

        $post = Store::getPost((int) ($replySet['post_id'] ?? 0));
        $targetUrl = trim((string) ($overrides['target_url'] ?? ($post['source_url'] ?? '')));
        if ($targetUrl === '') {
            return ['ok' => false, 'error' => 'A source URL is required for browser publishing.'];
        }

        $settings = self::settings();
        if (empty($settings['enabled'])) {
            return ['ok' => false, 'error' => 'Browser automation is disabled in settings.'];
        }

        $paths = self::artifactPaths($replySetId, $candidateId);
        self::ensureDirectory($paths['dir']);

        $jobId = BrowserJobs::saveJob([
            'reply_set_id' => $replySetId,
            'candidate_id' => $candidateId,
            'post_id' => (int) ($replySet['post_id'] ?? 0),
            'job_type' => 'publish_monitor',
            'status' => 'queued',
            'phase' => 'publish',
            'current_step' => 'Queued',
            'target_url' => $targetUrl,
            'browser_profile_dir' => (string) ($settings['profile_dir'] ?? ''),
            'browser_state_file' => (string) ($settings['state_file'] ?? ''),
            'browser_session_name' => 'xra-job-' . $replySetId . '-' . $candidateId,
            'pid' => 0,
            'monitor_cycles' => (int) ($settings['monitor_cycles'] ?? 3),
            'monitor_interval_seconds' => (int) ($settings['monitor_interval_seconds'] ?? 20),
            'observed_metrics' => [],
            'latest_screenshot_url' => '',
            'latest_screenshot_path' => '',
            'log_excerpt' => 'Queued for browser publishing.',
            'error_message' => '',
            'started_by' => get_current_user_id(),
            'completed_by' => 0,
            'started_at' => null,
            'next_check_at' => null,
            'last_polled_at' => null,
            'completed_at' => null,
            'control_file_path' => $paths['control'],
        ]);

        self::writeControlFile($paths['control'], [
            'job_id' => $jobId,
            'status' => 'queued',
            'phase' => 'publish',
            'step' => 'Queued',
        ]);

        BrowserJobs::saveEvent([
            'job_id' => $jobId,
            'event_type' => 'queued',
            'phase' => 'publish',
            'step_index' => 1,
            'message' => 'Browser publish job queued.',
            'data' => [
                'target_url' => $targetUrl,
                'reply_set_id' => $replySetId,
                'candidate_id' => $candidateId,
            ],
        ]);

        Store::saveReplySet([
            'id' => $replySetId,
            'post_id' => (int) ($replySet['post_id'] ?? 0),
            'analysis_id' => (int) ($replySet['analysis_id'] ?? 0),
            'persona_id' => (int) ($replySet['persona_id'] ?? 0),
            'analysis_prompt_version_id' => (int) ($replySet['analysis_prompt_version_id'] ?? 0),
            'generation_prompt_version_id' => (int) ($replySet['generation_prompt_version_id'] ?? 0),
            'scoring_prompt_version_id' => (int) ($replySet['scoring_prompt_version_id'] ?? 0),
            'recommendation_prompt_version_id' => (int) ($replySet['recommendation_prompt_version_id'] ?? 0),
            'status' => 'publish_ready',
            'selected_candidate_id' => $candidateId,
            'reviewer_user_id' => get_current_user_id(),
            'reviewer_notes' => (string) ($replySet['reviewer_notes'] ?? ''),
            'browser_status' => 'queued',
            'publish_job_id' => $jobId,
            'monitoring_job_id' => $jobId,
            'rank_summary' => $replySet['rank_summary_json'] ?? [],
            'recommendations' => $replySet['recommendations_json'] ?? [],
        ]);

        Store::saveAudit(
            'browser_job_queued',
            'browser_job',
            $jobId,
            'Browser publish job queued.',
            [
                'reply_set_id' => $replySetId,
                'candidate_id' => $candidateId,
                'target_url' => $targetUrl,
            ],
            'info',
            get_current_user_id()
        );

        $pid = self::spawnWorker($jobId);
        BrowserJobs::updateJob($jobId, [
            'pid' => $pid,
            'browser_session_name' => 'xra-job-' . $replySetId . '-' . $candidateId,
            'log_excerpt' => $pid > 0 ? 'Worker spawned.' : 'Worker spawn failed.',
        ]);

        if ($pid <= 0) {
            BrowserJobs::updateJob($jobId, [
                'status' => 'failed',
                'phase' => 'done',
                'current_step' => 'Failed',
                'error_message' => 'Unable to start the browser worker.',
                'completed_at' => current_time('mysql'),
            ]);
            self::writeControlFile($paths['control'], [
                'job_id' => $jobId,
                'status' => 'failed',
                'phase' => 'done',
                'step' => 'Failed',
                'message' => 'Unable to start the browser worker.',
            ]);
            return ['ok' => false, 'error' => 'Unable to start the browser worker.'];
        }

        return [
            'ok' => true,
            'job_id' => $jobId,
            'pid' => $pid,
            'job' => BrowserJobs::getJob($jobId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pauseJob(int $jobId): array
    {
        return self::transitionJob($jobId, 'paused', 'Paused', 'paused');
    }

    /**
     * @return array<string, mixed>
     */
    public static function resumeJob(int $jobId): array
    {
        return self::transitionJob($jobId, 'running', 'Monitoring', 'running');
    }

    /**
     * @return array<string, mixed>
     */
    public static function stopJob(int $jobId): array
    {
        return self::transitionJob($jobId, 'stopped', 'Stopped', 'stopped');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getJobPanel(int $replySetId = 0): array
    {
        $job = $replySetId > 0 ? BrowserJobs::latestForReplySet($replySetId) : BrowserJobs::latestActive();
        $events = $job !== [] ? BrowserJobs::listEvents((int) ($job['id'] ?? 0), 8) : [];

        return [
            'job' => $job,
            'events' => $events,
            'has_job' => $job !== [],
            'can_pause' => in_array((string) ($job['status'] ?? ''), ['queued', 'running'], true),
            'can_resume' => (string) ($job['status'] ?? '') === 'paused',
            'can_stop' => in_array((string) ($job['status'] ?? ''), ['queued', 'running', 'paused'], true),
        ];
    }

    public static function workerMain(int $jobId): void
    {
        $job = BrowserJobs::getJob($jobId);
        if ($job === []) {
            return;
        }

        $replySetId = (int) ($job['reply_set_id'] ?? 0);
        $candidateId = (int) ($job['candidate_id'] ?? 0);
        $paths = self::artifactPaths($replySetId, $candidateId);
        self::ensureDirectory($paths['dir']);
        if (trim((string) ($job['control_file_path'] ?? '')) === '') {
            BrowserJobs::updateJob($jobId, ['control_file_path' => $paths['control']]);
        }

        self::writeControlFile($paths['control'], [
            'job_id' => $jobId,
            'status' => 'running',
            'phase' => 'publish',
            'step' => 'Opening Browser',
        ]);

        BrowserJobs::setStatus($jobId, 'running', 'publish', 'Opening Browser', [
            'started_at' => current_time('mysql'),
            'control_file_path' => $paths['control'],
        ]);
        BrowserJobs::saveEvent([
            'job_id' => $jobId,
            'event_type' => 'running',
            'phase' => 'publish',
            'step_index' => 1,
            'message' => 'Browser worker started.',
            'data' => [
                'reply_set_id' => $replySetId,
                'candidate_id' => $candidateId,
            ],
        ]);

        $scriptPath = self::scriptPath();
        if (!is_readable($scriptPath)) {
            self::failJob($jobId, 'Browser workflow script not found.', $paths['control']);
            return;
        }

        $job = BrowserJobs::getJob($jobId);
        $candidate = Store::getReplyCandidate($candidateId);
        $replySet = Store::getReplySet($replySetId);
        $post = Store::getPost((int) ($replySet['post_id'] ?? 0));
        $replyText = trim((string) ($candidate['edited_text'] ?? $candidate['reply_text'] ?? ''));
        $targetUrl = trim((string) ($job['target_url'] ?? ($post['source_url'] ?? '')));
        if ($targetUrl === '') {
            self::failJob($jobId, 'Source URL is required for browser publishing.', $paths['control']);
            return;
        }

        $env = [
            'XRA_JOB_ID' => (string) $jobId,
            'XRA_TARGET_URL' => $targetUrl,
            'XRA_REPLY_TEXT' => $replyText,
            'XRA_BROWSER_OUTPUT_DIR' => $paths['dir'],
            'XRA_CONTROL_FILE' => $paths['control'],
            'XRA_MONITOR_CYCLES' => (string) max(1, (int) ($job['monitor_cycles'] ?? 3)),
            'XRA_MONITOR_INTERVAL_SECONDS' => (string) max(1, (int) ($job['monitor_interval_seconds'] ?? 20)),
            'XRA_BROWSER_SELECTORS' => (string) get_option(Settings::OPTION_BROWSER_PUBLISH_SELECTORS, (string) Settings::defaults()[Settings::OPTION_BROWSER_PUBLISH_SELECTORS]),
        ];

        $stdout = self::runPlaywright($scriptPath, $env);
        $result = self::parseResult($stdout);

        if (!empty($result['ok'])) {
            self::finishJob($jobId, $result, $paths['control']);
            return;
        }

        $error = (string) ($result['error'] ?? trim(self::tailText($stdout, 1200)) ?: 'Browser publish failed.');
        self::failJob($jobId, $error, $paths['control'], $stdout);
    }

    /**
     * @return array<string, mixed>
     */
    private static function transitionJob(int $jobId, string $status, string $step, string $controlStatus): array
    {
        $job = BrowserJobs::getJob($jobId);
        if ($job === []) {
            return ['ok' => false, 'error' => 'Browser job not found.'];
        }

        $paths = self::artifactPaths((int) ($job['reply_set_id'] ?? 0), (int) ($job['candidate_id'] ?? 0));
        self::ensureDirectory($paths['dir']);
        BrowserJobs::updateJob($jobId, [
            'status' => $status,
            'phase' => $status === 'stopped' ? 'done' : (string) ($job['phase'] ?? 'publish'),
            'current_step' => $step,
            'last_polled_at' => current_time('mysql'),
            'control_file_path' => $paths['control'],
        ]);
        self::writeControlFile($paths['control'], [
            'job_id' => $jobId,
            'status' => $controlStatus,
            'phase' => (string) ($job['phase'] ?? 'publish'),
            'step' => $step,
        ]);

        BrowserJobs::saveEvent([
            'job_id' => $jobId,
            'event_type' => sanitize_key($status),
            'phase' => (string) ($job['phase'] ?? 'publish'),
            'step_index' => 0,
            'message' => ucfirst($status) . ' requested.',
            'data' => ['status' => $status],
        ]);

        return ['ok' => true, 'job' => BrowserJobs::getJob($jobId)];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function finishJob(int $jobId, array $result, string $controlFile): void
    {
        $job = BrowserJobs::getJob($jobId);
        $replySetId = (int) ($job['reply_set_id'] ?? 0);
        $candidateId = (int) ($job['candidate_id'] ?? 0);
        $finalStatus = (string) ($result['status'] ?? 'completed');
        if (!in_array($finalStatus, ['completed', 'stopped'], true)) {
            $finalStatus = 'completed';
        }
        $publishedUrl = trim((string) ($result['published_url'] ?? ($result['publish']['url'] ?? '')));
        $didPublish = $publishedUrl !== '';
        $observedMetrics = [];
        $screenshotUrl = '';
        $screenshotPath = '';

        $monitoring = (array) ($result['monitoring'] ?? []);
        foreach ($monitoring as $sample) {
            if (!is_array($sample)) {
                continue;
            }

            $metrics = (array) ($sample['metrics'] ?? []);
            $sampleScreenshotPath = trim((string) ($sample['screenshot_path'] ?? ''));
            $sampleScreenshotUrl = $sampleScreenshotPath !== '' ? self::pathToUrl($sampleScreenshotPath) : '';
            if ($metrics !== []) {
                $observedMetrics = $metrics;
                Store::saveMetric([
                    'post_id' => (int) ($job['post_id'] ?? 0),
                    'reply_set_id' => $replySetId,
                    'candidate_id' => $candidateId,
                    'impressions' => (int) ($metrics['impressions'] ?? 0),
                    'likes' => (int) ($metrics['likes'] ?? 0),
                    'replies_received' => (int) ($metrics['replies_received'] ?? 0),
                    'reposts' => (int) ($metrics['reposts'] ?? 0),
                    'bookmarks' => (int) ($metrics['bookmarks'] ?? 0),
                    'profile_visits' => (int) ($metrics['profile_visits'] ?? 0),
                    'follows' => (int) ($metrics['follows'] ?? 0),
                    'publication_datetime' => current_time('mysql'),
                    'measurement_datetime' => current_time('mysql'),
                    'audience_category' => 'browser observation',
                    'notes' => 'Live browser observation',
                ]);
            }

            $screenshotUrl = $sampleScreenshotUrl !== '' ? $sampleScreenshotUrl : (string) ($sample['screenshot_url'] ?? $screenshotUrl);
            $screenshotPath = $sampleScreenshotPath !== '' ? $sampleScreenshotPath : (string) ($sample['screenshot_path'] ?? $screenshotPath);
            BrowserJobs::saveEvent([
                'job_id' => $jobId,
                'event_type' => 'monitor_sample',
                'phase' => 'monitor',
                'step_index' => max(1, (int) ($sample['cycle'] ?? 1)),
                'message' => 'Monitoring sample captured.',
                'data' => $sample,
                'screenshot_path' => $screenshotPath,
                'screenshot_url' => $screenshotUrl,
            ]);
        }

        BrowserJobs::updateJob($jobId, [
            'status' => $finalStatus,
            'phase' => 'done',
            'current_step' => $finalStatus === 'stopped' ? ($didPublish ? 'Stopped After Publish' : 'Stopped Before Publish') : 'Completed',
            'published_url' => $publishedUrl !== '' ? $publishedUrl : null,
            'observed_metrics' => $observedMetrics,
            'latest_screenshot_url' => $screenshotUrl,
            'latest_screenshot_path' => $screenshotPath,
            'completed_by' => get_current_user_id(),
            'completed_at' => current_time('mysql'),
            'log_excerpt' => 'Browser publish completed.',
            'control_file_path' => $controlFile,
        ]);

        if ($replySetId > 0 && $candidateId > 0) {
            $replySet = Store::getReplySet($replySetId);
            Store::saveReplySet([
                'id' => $replySetId,
                'post_id' => (int) ($job['post_id'] ?? 0),
                'analysis_id' => (int) ($replySet['analysis_id'] ?? 0),
                'persona_id' => (int) ($replySet['persona_id'] ?? 0),
                'analysis_prompt_version_id' => (int) ($replySet['analysis_prompt_version_id'] ?? 0),
                'generation_prompt_version_id' => (int) ($replySet['generation_prompt_version_id'] ?? 0),
                'scoring_prompt_version_id' => (int) ($replySet['scoring_prompt_version_id'] ?? 0),
                'recommendation_prompt_version_id' => (int) ($replySet['recommendation_prompt_version_id'] ?? 0),
                'status' => $didPublish ? 'published' : 'publish_stopped',
                'selected_candidate_id' => $candidateId,
                'reviewer_user_id' => get_current_user_id(),
                'published_at' => $didPublish ? current_time('mysql') : '',
                'published_url' => $publishedUrl,
                'publish_job_id' => $jobId,
                'monitoring_job_id' => $jobId,
                'browser_status' => $finalStatus,
            ]);
            if ($didPublish) {
                Store::setCandidateStatus($candidateId, 'published', get_current_user_id(), 'Published via browser automation.');
            } else {
                Store::setCandidateStatus($candidateId, 'for_publishing', get_current_user_id(), 'Browser publish stopped before completion.');
            }
        }

        BrowserJobs::saveEvent([
            'job_id' => $jobId,
            'event_type' => $finalStatus,
            'phase' => 'done',
            'step_index' => 999,
            'message' => $finalStatus === 'stopped' ? 'Browser publish job stopped.' : 'Browser publish job completed.',
            'data' => [
                'published_url' => $publishedUrl,
                'observed_metrics' => $observedMetrics,
            ],
            'screenshot_path' => $screenshotPath,
            'screenshot_url' => $screenshotUrl,
        ]);

        self::writeControlFile($controlFile, [
            'job_id' => $jobId,
            'status' => $finalStatus,
            'phase' => 'done',
            'step' => $finalStatus === 'stopped' ? 'Stopped' : 'Completed',
            'published_url' => $publishedUrl,
        ]);

        Store::saveAudit(
            $finalStatus === 'stopped' ? 'browser_job_stopped' : 'browser_job_completed',
            'browser_job',
            $jobId,
            $finalStatus === 'stopped' ? 'Browser publish job stopped.' : 'Browser publish job completed.',
            [
                'reply_set_id' => $replySetId,
                'candidate_id' => $candidateId,
                'published_url' => $publishedUrl,
            ],
            'info',
            get_current_user_id()
        );
    }

    private static function failJob(int $jobId, string $message, string $controlFile, string $stdout = ''): void
    {
        $job = BrowserJobs::getJob($jobId);
        BrowserJobs::updateJob($jobId, [
            'status' => 'failed',
            'phase' => 'done',
            'current_step' => 'Failed',
            'error_message' => $message,
            'log_excerpt' => self::tailText($stdout, 1200),
            'completed_at' => current_time('mysql'),
            'control_file_path' => $controlFile,
        ]);
        BrowserJobs::saveEvent([
            'job_id' => $jobId,
            'event_type' => 'failed',
            'phase' => 'done',
            'step_index' => 999,
            'message' => $message,
            'data' => ['stdout' => self::tailText($stdout, 1200)],
        ]);
        self::writeControlFile($controlFile, [
            'job_id' => $jobId,
            'status' => 'failed',
            'phase' => 'done',
            'step' => 'Failed',
            'message' => $message,
        ]);
        if ($job !== []) {
            Store::saveReplySet([
                'id' => (int) ($job['reply_set_id'] ?? 0),
                'post_id' => (int) ($job['post_id'] ?? 0),
                'analysis_id' => (int) (Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['analysis_id'] ?? 0),
                'persona_id' => (int) (Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['persona_id'] ?? 0),
                'analysis_prompt_version_id' => (int) (Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['analysis_prompt_version_id'] ?? 0),
                'generation_prompt_version_id' => (int) (Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['generation_prompt_version_id'] ?? 0),
                'scoring_prompt_version_id' => (int) (Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['scoring_prompt_version_id'] ?? 0),
                'recommendation_prompt_version_id' => (int) (Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['recommendation_prompt_version_id'] ?? 0),
                'status' => 'publish_failed',
                'selected_candidate_id' => (int) ($job['candidate_id'] ?? 0),
                'browser_status' => 'failed',
                'publish_job_id' => $jobId,
                'monitoring_job_id' => $jobId,
                'rank_summary' => Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['rank_summary_json'] ?? [],
                'recommendations' => Store::getReplySet((int) ($job['reply_set_id'] ?? 0))['recommendations_json'] ?? [],
            ]);
        }
        Store::saveErrorLog('browser_automation', 'browser_job_failed', $message, [
            'job_id' => $jobId,
        ], 'error', get_current_user_id());
    }

    /**
     * @return array<string, string|int>
     */
    private static function settings(): array
    {
        return [
            'enabled' => Settings::bool(Settings::OPTION_BROWSER_ENABLED),
            'profile_dir' => Settings::get(Settings::OPTION_BROWSER_PROFILE_DIR),
            'state_file' => Settings::get(Settings::OPTION_BROWSER_STORAGE_STATE),
            'monitor_interval_seconds' => max(5, Settings::int(Settings::OPTION_BROWSER_MONITOR_INTERVAL)),
            'monitor_cycles' => max(1, Settings::int(Settings::OPTION_BROWSER_MONITOR_CYCLES)),
        ];
    }

    /**
     * @return array{dir: string, control: string}
     */
    private static function artifactPaths(int $replySetId, int $candidateId): array
    {
        $upload = wp_upload_dir();
        $baseDir = isset($upload['basedir']) ? (string) $upload['basedir'] : sys_get_temp_dir();
        $dir = trailingslashit($baseDir) . 'xreplyagent/browser/reply-set-' . $replySetId . '/candidate-' . $candidateId;
        return [
            'dir' => $dir,
            'control' => trailingslashit($dir) . 'control.json',
        ];
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            wp_mkdir_p($path);
        }
    }

    private static function pathToUrl(string $path): string
    {
        $upload = wp_upload_dir();
        $baseDir = isset($upload['basedir']) ? trailingslashit((string) $upload['basedir']) : '';
        $baseUrl = isset($upload['baseurl']) ? trailingslashit((string) $upload['baseurl']) : '';
        if ($baseDir !== '' && $baseUrl !== '' && str_starts_with($path, $baseDir)) {
            return $baseUrl . ltrim(substr($path, strlen($baseDir)), '/');
        }

        return '';
    }

    private static function writeControlFile(string $path, array $payload): void
    {
        self::ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    private static function scriptPath(): string
    {
        return dirname(__DIR__, 2) . '/bin/browser-workflow.js';
    }

    private static function spawnWorker(int $jobId): int
    {
        $job = BrowserJobs::getJob($jobId);
        $php = self::phpBinary();
        $worker = dirname(__DIR__, 2) . '/bin/browser-worker.php';
        $paths = self::artifactPaths((int) ($job['reply_set_id'] ?? $jobId), (int) ($job['candidate_id'] ?? $jobId));
        self::ensureDirectory($paths['dir']);
        $logPath = trailingslashit($paths['dir']) . 'worker.log';

        $cmd = sprintf(
            'nohup %s %s --job-id=%d > %s 2>&1 & echo $!',
            escapeshellarg($php),
            escapeshellarg($worker),
            $jobId,
            escapeshellarg($logPath)
        );

        $output = trim((string) shell_exec($cmd));
        return (int) preg_replace('/[^0-9]/', '', $output);
    }

    private static function phpBinary(): string
    {
        $candidates = array_filter([
            defined('PHP_BINARY') ? PHP_BINARY : '',
            PHP_BINDIR . '/php',
            'php',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return 'php';
    }

    /**
     * @param array<string, string> $env
     */
    private static function runPlaywright(string $scriptPath, array $env): string
    {
        $node = self::nodeBinary();
        $command = sprintf('%s %s', escapeshellarg($node), escapeshellarg($scriptPath));
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env['NODE_PATH'] = self::nodeModulesPath();
        $env['XRA_CHROMIUM_PATH'] = self::chromiumBinary();

        $process = proc_open($command, $descriptorSpec, $pipes, null, $env);
        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($stdout . "\n" . $stderr);
    }

    private static function nodeBinary(): string
    {
        $candidates = array_filter([
            trim((string) shell_exec('command -v node 2>/dev/null')),
            trim((string) shell_exec('command -v nodejs 2>/dev/null')),
            '/usr/bin/node',
            '/usr/bin/nodejs',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'node';
    }

    private static function chromiumBinary(): string
    {
        $candidates = array_filter([
            trim((string) shell_exec('command -v chromium 2>/dev/null')),
            trim((string) shell_exec('command -v chromium-browser 2>/dev/null')),
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'chromium';
    }

    private static function nodeModulesPath(): string
    {
        $root = trim((string) shell_exec('npm root -g 2>/dev/null'));
        return $root !== '' ? $root : '/usr/lib/node_modules';
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseResult(string $output): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return ['ok' => false, 'error' => 'Empty browser output.'];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/(\{(?:.|\n)*\})\s*$/', $trimmed, $matches)) {
            $decoded = json_decode((string) $matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['ok' => false, 'error' => 'Unable to parse browser output.', 'raw' => $trimmed];
    }

    /**
     * @return array<string, int>
     */
    public static function parseMetrics(string $text): array
    {
        $metrics = [];
        foreach ([
            'impressions' => '/([\d,]+)\s+(?:views?|impressions?)/i',
            'likes' => '/([\d,]+)\s+likes?/i',
            'replies_received' => '/([\d,]+)\s+replies?/i',
            'reposts' => '/([\d,]+)\s+reposts?/i',
            'bookmarks' => '/([\d,]+)\s+bookmarks?/i',
            'profile_visits' => '/([\d,]+)\s+profile visits?/i',
            'follows' => '/([\d,]+)\s+follows?/i',
        ] as $field => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $metrics[$field] = (int) str_replace(',', '', (string) $matches[1]);
            }
        }

        return $metrics;
    }

    private static function tailText(string $text, int $limit): string
    {
        $limit = max(80, $limit);
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, -$limit);
    }
}
