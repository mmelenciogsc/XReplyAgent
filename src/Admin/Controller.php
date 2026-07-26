<?php

declare(strict_types=1);

namespace XReplyAgent\Admin;

use XReplyAgent\Domain\BrowserAutomation;
use XReplyAgent\Domain\Exporter;
use XReplyAgent\Domain\Workflow;
use XReplyAgent\PublicApp\Controller as PublicController;
use XReplyAgent\Storage\Store;
use XReplyAgent\Support\Capabilities;
use XReplyAgent\Support\Settings;

final class Controller
{
    public static function registerMenu(): void
    {
        $screens = [
            'Overview' => 'overview',
            'Analyze' => 'analyze',
            'Review Queue' => 'review-queue',
            'History' => 'history',
            'Analytics' => 'analytics',
            'Personas' => 'personas',
            'Prompts' => 'prompts',
            'Settings' => 'settings',
            'Audit' => 'audit',
            'Error Log' => 'error-log',
        ];

        add_menu_page(
            'XReplyAgent',
            'XReplyAgent',
            Capabilities::USE_APP,
            'xreplyagent',
            [self::class, 'renderLauncher'],
            'dashicons-format-chat',
            58
        );

        foreach ($screens as $label => $screen) {
            add_submenu_page(
                'xreplyagent',
                $label,
                $label,
                Capabilities::USE_APP,
                'xreplyagent-' . $screen,
                [self::class, 'renderLauncher']
            );
        }
    }

    public static function registerSettings(): void
    {
        foreach (array_keys(Settings::defaults()) as $option) {
            register_setting('xra_settings', $option, [
                'sanitize_callback' => [self::class, 'sanitizeOption'],
                'default' => Settings::defaults()[$option] ?? '',
            ]);
        }
    }

    public static function sanitizeOption(mixed $value): string
    {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    public static function renderLauncher(): void
    {
        if (!current_user_can(Capabilities::USE_APP)) {
            wp_die('Unauthorized', 403);
        }

        $screen = self::requestedScreen();
        $url = PublicController::viewUrl('admin', ['xra_screen' => $screen]);

        echo '<div class="wrap xra-wp-launcher">';
        echo '<h1>XReplyAgent</h1>';
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Open Workspace</a></p>';
        echo '<p>The workspace opens in its own shell.</p>';
        echo '</div>';
    }

    public static function handleWorkflow(): void
    {
        self::assertUseApp();
        check_admin_referer('xra_run_workflow');
        $result = Workflow::processSubmission(self::payloadFromPost());
        self::redirect('analyze', !empty($result['ok']) ? 'analysis_saved' : 'analysis_failed', [
            'xra_post_id' => (int) ($result['post_id'] ?? 0),
            'xra_reply_set_id' => (int) ($result['reply_set_id'] ?? 0),
        ]);
    }

    public static function handleSettingsSave(): void
    {
        self::assertManageSettings();
        check_admin_referer('xra_save_settings');

        foreach (Settings::defaults() as $option => $default) {
            if (in_array($option, [Settings::OPTION_MOCK_MODE, Settings::OPTION_RETAIN_ON_UNINSTALL, Settings::OPTION_PUBLIC_ACCESS, Settings::OPTION_PUBLIC_QUERIES], true)) {
                $value = !empty($_POST[$option]) ? '1' : '0';
            } else {
                $value = self::sanitizeOption($_POST[$option] ?? $default);
            }

            update_option($option, $value, false);
        }

        Store::saveAudit('save_settings', 'settings', 0, 'Settings saved.', [], 'info', get_current_user_id());
        self::redirect('settings', 'settings_saved');
    }

    public static function handlePersonaSave(): void
    {
        self::assertManagePersonas();
        check_admin_referer('xra_save_persona');

        $id = Store::savePersona([
            'id' => (int) ($_POST['id'] ?? 0),
            'slug' => (string) ($_POST['slug'] ?? ''),
            'version' => (string) ($_POST['version'] ?? '1.0.0'),
            'name' => (string) ($_POST['name'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'tone' => (string) ($_POST['tone'] ?? ''),
            'voice' => (string) ($_POST['voice'] ?? ''),
            'instructions' => (string) ($_POST['instructions'] ?? ''),
            'guardrails_json' => (string) ($_POST['guardrails_json'] ?? ''),
            'active' => !empty($_POST['active']),
        ]);
        Store::saveAudit('save_persona', 'persona', $id, 'Persona saved.', [], 'info', get_current_user_id());
        self::redirect('personas', 'persona_saved');
    }

    public static function handlePersonaAction(): void
    {
        self::assertManagePersonas();
        check_admin_referer('xra_persona_action');
        $action = sanitize_key((string) ($_POST['persona_action'] ?? ''));
        $persona_id = (int) ($_POST['persona_id'] ?? 0);

        if ($action === 'activate') {
            Store::setPersonaActive($persona_id);
        } elseif ($action === 'duplicate') {
            Store::duplicatePersona($persona_id);
        } elseif ($action === 'delete') {
            Store::deletePersona($persona_id);
        }

        Store::saveAudit($action, 'persona', $persona_id, 'Persona action processed.', [], 'info', get_current_user_id());
        self::redirect('personas', 'persona_updated');
    }

    public static function handlePromptSave(): void
    {
        self::assertManageSettings();
        check_admin_referer('xra_save_prompt');

        $id = Store::savePromptVersion([
            'id' => (int) ($_POST['id'] ?? 0),
            'slug' => (string) ($_POST['slug'] ?? ''),
            'stage' => (string) ($_POST['stage'] ?? 'analysis'),
            'version' => (string) ($_POST['version'] ?? '1.0.0'),
            'name' => (string) ($_POST['name'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'system_prompt' => (string) ($_POST['system_prompt'] ?? ''),
            'user_prompt' => (string) ($_POST['user_prompt'] ?? ''),
            'response_schema' => (string) ($_POST['response_schema'] ?? ''),
            'active' => !empty($_POST['active']),
            'draft' => !empty($_POST['draft']),
        ]);
        Store::saveAudit('save_prompt', 'prompt_version', $id, 'Prompt saved.', ['stage' => (string) ($_POST['stage'] ?? 'analysis')], 'info', get_current_user_id());
        self::redirect('prompts', 'prompt_saved');
    }

    public static function handlePromptAction(): void
    {
        self::assertManageSettings();
        check_admin_referer('xra_prompt_action');
        $action = sanitize_key((string) ($_POST['prompt_action'] ?? ''));
        $prompt_id = (int) ($_POST['prompt_id'] ?? 0);

        if ($action === 'activate') {
            Store::setPromptActive($prompt_id);
        } elseif ($action === 'duplicate') {
            Store::duplicatePromptVersion($prompt_id);
        } elseif ($action === 'delete') {
            Store::deletePromptVersion($prompt_id);
        }

        Store::saveAudit($action, 'prompt_version', $prompt_id, 'Prompt action processed.', [], 'info', get_current_user_id());
        self::redirect('prompts', 'prompt_updated');
    }

    public static function handleMetricSave(): void
    {
        self::assertUseApp();
        check_admin_referer('xra_save_metric');
        $result = Workflow::recordPerformanceMetric([
            'post_id' => (int) ($_POST['post_id'] ?? 0),
            'reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
            'candidate_id' => (int) ($_POST['candidate_id'] ?? 0),
            'impressions' => (int) ($_POST['impressions'] ?? 0),
            'likes' => (int) ($_POST['likes'] ?? 0),
            'replies_received' => (int) ($_POST['replies_received'] ?? 0),
            'reposts' => (int) ($_POST['reposts'] ?? 0),
            'bookmarks' => (int) ($_POST['bookmarks'] ?? 0),
            'profile_visits' => (int) ($_POST['profile_visits'] ?? 0),
            'follows' => (int) ($_POST['follows'] ?? 0),
            'publication_datetime' => (string) ($_POST['publication_datetime'] ?? ''),
            'measurement_datetime' => (string) ($_POST['measurement_datetime'] ?? ''),
            'audience_category' => (string) ($_POST['audience_category'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''),
        ]);
        self::redirect('analytics', !empty($result['ok']) ? 'metric_saved' : 'metric_failed');
    }

    public static function handleCandidateAction(): void
    {
        self::assertReviewReplies();
        check_admin_referer('xra_candidate_action');
        $result = Workflow::reviewReplySet([
            'reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
            'candidate_id' => (int) ($_POST['candidate_id'] ?? 0),
            'action' => (string) ($_POST['candidate_action'] ?? 'save'),
            'reply_text' => (string) ($_POST['reply_text'] ?? ''),
            'edited_text' => (string) ($_POST['edited_text'] ?? ''),
            'approach_label' => (string) ($_POST['approach_label'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''),
        ]);
        self::redirect('review-queue', !empty($result['ok']) ? 'candidate_saved' : 'candidate_failed', [
            'xra_reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
        ]);
    }

    public static function handlePublishCandidate(): void
    {
        self::assertReviewReplies();
        check_admin_referer('xra_publish_candidate');
        $result = Workflow::reviewReplySet([
            'reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
            'candidate_id' => (int) ($_POST['candidate_id'] ?? 0),
            'action' => 'publish',
            'notes' => (string) ($_POST['notes'] ?? ''),
        ]);
        self::redirect('review-queue', !empty($result['ok']) ? 'candidate_queued' : 'candidate_failed', [
            'xra_reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
        ]);
    }

    public static function handleBrowserGo(): void
    {
        self::assertReviewReplies();
        check_admin_referer('xra_browser_go');
        $result = Workflow::startBrowserPublish([
            'reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
            'candidate_id' => (int) ($_POST['candidate_id'] ?? 0),
        ]);
        self::redirect('review-queue', !empty($result['ok']) ? 'browser_job_queued' : 'browser_job_failed', [
            'xra_reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
        ]);
    }

    public static function handleBrowserJobAction(): void
    {
        self::assertReviewReplies();
        check_admin_referer('xra_browser_job_action');

        $jobId = (int) ($_POST['browser_job_id'] ?? 0);
        $jobAction = sanitize_key((string) ($_POST['browser_job_action'] ?? ''));
        $result = match ($jobAction) {
            'pause' => BrowserAutomation::pauseJob($jobId),
            'resume' => BrowserAutomation::resumeJob($jobId),
            'stop' => BrowserAutomation::stopJob($jobId),
            default => ['ok' => false, 'error' => 'Unknown browser action.'],
        };

        self::redirect('review-queue', !empty($result['ok']) ? 'browser_job_updated' : 'browser_job_failed', [
            'xra_reply_set_id' => (int) ($_POST['reply_set_id'] ?? 0),
        ]);
    }

    public static function handleSeed(): void
    {
        self::assertUseApp();
        check_admin_referer('xra_seed_demo');
        Workflow::seedDemo();
        self::redirect('tools', 'seeded');
    }

    public static function handleReset(): void
    {
        self::assertManageSettings();
        check_admin_referer('xra_reset_data');
        Workflow::resetDemo(true);
        self::redirect('tools', 'reset');
    }

    public static function handleExport(): void
    {
        self::assertUseApp();
        check_admin_referer('xra_export_csv');

        $type = sanitize_key((string) ($_POST['type'] ?? 'posts'));
        $csv = Exporter::csv($type);
        $filename = 'xreplyagent-' . $type . '-' . gmdate('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $csv;
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadFromPost(): array
    {
        return [
            'post_text' => (string) ($_POST['post_text'] ?? ''),
            'source_url' => (string) ($_POST['source_url'] ?? ''),
            'context_text' => (string) ($_POST['context_text'] ?? ''),
            'desired_objective' => (string) ($_POST['desired_objective'] ?? ''),
            'persona_id' => (int) ($_POST['persona_id'] ?? 0),
            'author_handle' => (string) ($_POST['author_handle'] ?? ''),
            'author_name' => (string) ($_POST['author_name'] ?? ''),
        ];
    }

    private static function assertUseApp(): void
    {
        if (!current_user_can(Capabilities::USE_APP) && !Capabilities::canUseApp()) {
            wp_die('Unauthorized', 403);
        }
    }

    private static function assertReviewReplies(): void
    {
        if (!current_user_can(Capabilities::REVIEW_REPLIES) && !Capabilities::canReviewReplies()) {
            wp_die('Unauthorized', 403);
        }
    }

    private static function assertManagePersonas(): void
    {
        if (!current_user_can(Capabilities::MANAGE_PERSONAS) && !Capabilities::canManagePersonas()) {
            wp_die('Unauthorized', 403);
        }
    }

    private static function assertManageSettings(): void
    {
        if (!current_user_can(Capabilities::MANAGE_SETTINGS) && !Capabilities::canManageSettings()) {
            wp_die('Unauthorized', 403);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function redirect(string $screen, string $notice, array $extra = []): void
    {
        $target = wp_get_referer() ?: PublicController::viewUrl('admin', ['xra_screen' => $screen]);
        wp_safe_redirect(
            add_query_arg(
                array_merge(
                    [
                        'xra_notice' => $notice,
                        'xra_screen' => $screen,
                    ],
                    $extra
                ),
                $target
            )
        );
        exit;
    }

    private static function requestedScreen(): string
    {
        $screen = isset($_GET['xra_force_screen']) ? sanitize_key(wp_unslash((string) $_GET['xra_force_screen'])) : '';
        if ($screen !== '') {
            return $screen;
        }

        $screen = isset($_GET['xra_screen']) ? sanitize_key(wp_unslash((string) $_GET['xra_screen'])) : '';
        if ($screen === '' && isset($_GET['screen'])) {
            $screen = sanitize_key(wp_unslash((string) $_GET['screen']));
        }
        if ($screen === '') {
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            if ($query !== '') {
                parse_str($query, $params);
                if (isset($params['xra_screen'])) {
                    $screen = sanitize_key(wp_unslash((string) $params['xra_screen']));
                } elseif (isset($params['screen'])) {
                    $screen = sanitize_key(wp_unslash((string) $params['screen']));
                }
            }
        }

        return $screen !== '' ? $screen : 'overview';
    }
}
