<?php

declare(strict_types=1);

namespace XReplyAgent;

use XReplyAgent\Admin\Controller as AdminController;
use XReplyAgent\Domain\Maintenance;
use XReplyAgent\Domain\Seeder;
use XReplyAgent\PublicApp\Controller as PublicController;
use XReplyAgent\Rest\Controller as RestController;
use XReplyAgent\Support\Capabilities;
use XReplyAgent\Support\Schema;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->boot();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        Capabilities::install();
        Schema::install();
        Seeder::seed();
        PublicController::ensurePage();
        self::scheduleCleanup();
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('xra_daily_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'xra_daily_cleanup');
        }
    }

    private function boot(): void
    {
        add_action('init', [Schema::class, 'ensure'], 0);
        add_action('init', [Capabilities::class, 'sync'], 1);
        add_action('init', [PublicController::class, 'registerShortcode']);
        add_action('init', [PublicController::class, 'ensurePage']);
        add_action('init', [self::class, 'scheduleCleanup'], 10);
        add_action('xra_daily_cleanup', [Maintenance::class, 'cleanup']);
        add_filter('document_title_parts', [PublicController::class, 'documentTitleParts'], 99);
        add_filter('pre_get_document_title', [PublicController::class, 'preGetDocumentTitle'], 99);
        add_action('wp_head', [PublicController::class, 'printHeadMeta'], 1);
        add_filter('template_include', [PublicController::class, 'templateInclude'], 99);
        add_action('template_redirect', [PublicController::class, 'templateRedirect'], 0);
        add_action('wp_enqueue_scripts', [PublicController::class, 'enqueueAssets']);
        add_action('admin_post_nopriv_xra_auth_login', [PublicController::class, 'handleAuthLogin']);
        add_action('admin_post_xra_auth_login', [PublicController::class, 'handleAuthLogin']);
        add_action('admin_post_nopriv_xra_auth_register', [PublicController::class, 'handleAuthRegister']);
        add_action('admin_post_xra_auth_register', [PublicController::class, 'handleAuthRegister']);
        add_action('admin_menu', [AdminController::class, 'registerMenu']);
        add_action('admin_init', [AdminController::class, 'registerSettings']);
        add_action('admin_post_xra_run_workflow', [AdminController::class, 'handleWorkflow']);
        add_action('admin_post_xra_save_settings', [AdminController::class, 'handleSettingsSave']);
        add_action('admin_post_xra_save_persona', [AdminController::class, 'handlePersonaSave']);
        add_action('admin_post_xra_persona_action', [AdminController::class, 'handlePersonaAction']);
        add_action('admin_post_xra_save_prompt', [AdminController::class, 'handlePromptSave']);
        add_action('admin_post_xra_prompt_action', [AdminController::class, 'handlePromptAction']);
        add_action('admin_post_xra_save_metric', [AdminController::class, 'handleMetricSave']);
        add_action('admin_post_xra_candidate_action', [AdminController::class, 'handleCandidateAction']);
        add_action('admin_post_xra_publish_candidate', [AdminController::class, 'handlePublishCandidate']);
        add_action('admin_post_xra_browser_go', [AdminController::class, 'handleBrowserGo']);
        add_action('admin_post_xra_browser_job_action', [AdminController::class, 'handleBrowserJobAction']);
        add_action('admin_post_xra_seed_demo', [AdminController::class, 'handleSeed']);
        add_action('admin_post_xra_reset_data', [AdminController::class, 'handleReset']);
        add_action('admin_post_xra_export_csv', [AdminController::class, 'handleExport']);
        add_action('rest_api_init', [RestController::class, 'registerRoutes']);
    }

    public static function scheduleCleanup(): void
    {
        if (!wp_next_scheduled('xra_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'xra_daily_cleanup');
        }
    }
}
