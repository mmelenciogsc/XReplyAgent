<?php

declare(strict_types=1);

namespace XReplyAgent\Support;

final class Schema
{
    public const VERSION = '3.2.0';

    /**
     * @return array<string, string>
     */
    public static function tables(): array
    {
        global $wpdb;

        return [
            'posts' => $wpdb->prefix . 'xra_posts',
            'analyses' => $wpdb->prefix . 'xra_analyses',
            'reply_sets' => $wpdb->prefix . 'xra_reply_sets',
            'reply_candidates' => $wpdb->prefix . 'xra_reply_candidates',
            'personas' => $wpdb->prefix . 'xra_personas',
            'prompt_versions' => $wpdb->prefix . 'xra_prompt_versions',
            'performance_metrics' => $wpdb->prefix . 'xra_performance_metrics',
            'ai_requests' => $wpdb->prefix . 'xra_ai_requests',
            'browser_jobs' => $wpdb->prefix . 'xra_browser_jobs',
            'browser_events' => $wpdb->prefix . 'xra_browser_events',
            'audit_log' => $wpdb->prefix . 'xra_audit_log',
            'error_log' => $wpdb->prefix . 'xra_error_log',
        ];
    }

    public static function ensure(): void
    {
        if ((string) get_option('xra_schema_version', '') !== self::VERSION) {
            self::install();
            update_option('xra_schema_version', self::VERSION, false);
        }
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $tables = self::tables();

        $definitions = [
            "CREATE TABLE {$tables['posts']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                platform varchar(40) NOT NULL DEFAULT 'x',
                external_post_id varchar(191) NOT NULL DEFAULT '',
                source_url longtext NULL,
                content_hash char(64) NOT NULL DEFAULT '',
                post_text longtext NOT NULL,
                context_text longtext NULL,
                desired_objective varchar(191) NOT NULL DEFAULT '',
                author_handle varchar(191) NOT NULL DEFAULT '',
                author_name varchar(191) NOT NULL DEFAULT '',
                language varchar(20) NOT NULL DEFAULT 'en',
                status varchar(40) NOT NULL DEFAULT 'draft',
                duplicate_of_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                metadata_json longtext NULL,
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY content_hash (content_hash),
                KEY external_post_id (external_post_id),
                KEY status (status),
                KEY created_at (created_at)
            ) $charset",
            "CREATE TABLE {$tables['analyses']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                persona_id bigint(20) unsigned NOT NULL DEFAULT 0,
                prompt_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                ai_request_id bigint(20) unsigned NOT NULL DEFAULT 0,
                main_topic varchar(191) NOT NULL DEFAULT '',
                secondary_topics_json longtext NULL,
                tone varchar(80) NOT NULL DEFAULT '',
                sentiment varchar(80) NOT NULL DEFAULT '',
                likely_intent varchar(80) NOT NULL DEFAULT '',
                important_context_json longtext NULL,
                conversation_angles_json longtext NULL,
                factual_claims_requiring_caution_json longtext NULL,
                safety_or_reputation_risks_json longtext NULL,
                recommended_reply_approach longtext NULL,
                confidence_notes longtext NULL,
                raw_output_json longtext NULL,
                repair_attempted tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY post_id (post_id),
                KEY persona_id (persona_id),
                KEY prompt_version_id (prompt_version_id),
                KEY main_topic (main_topic)
            ) $charset",
            "CREATE TABLE {$tables['reply_sets']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                analysis_id bigint(20) unsigned NOT NULL DEFAULT 0,
                persona_id bigint(20) unsigned NOT NULL DEFAULT 0,
                analysis_prompt_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                generation_prompt_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                scoring_prompt_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                recommendation_prompt_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                ai_request_id bigint(20) unsigned NOT NULL DEFAULT 0,
                rank_summary_json longtext NULL,
                recommendations_json longtext NULL,
                status varchar(40) NOT NULL DEFAULT 'generated',
                selected_candidate_id bigint(20) unsigned NOT NULL DEFAULT 0,
                reviewer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                reviewer_notes longtext NULL,
                published_at datetime NULL,
                published_url longtext NULL,
                publish_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
                monitoring_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
                browser_status varchar(40) NOT NULL DEFAULT 'idle',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY post_id (post_id),
                KEY analysis_id (analysis_id),
                KEY status (status)
            ) $charset",
            "CREATE TABLE {$tables['reply_candidates']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                reply_set_id bigint(20) unsigned NOT NULL DEFAULT 0,
                candidate_index tinyint(3) unsigned NOT NULL DEFAULT 0,
                approach_label varchar(120) NOT NULL DEFAULT '',
                reply_text longtext NOT NULL,
                short_rationale longtext NULL,
                relevance_score decimal(7,4) NOT NULL DEFAULT 0.0000,
                naturalness_score decimal(7,4) NOT NULL DEFAULT 0.0000,
                originality_score decimal(7,4) NOT NULL DEFAULT 0.0000,
                tone_match_score decimal(7,4) NOT NULL DEFAULT 0.0000,
                conversation_potential_score decimal(7,4) NOT NULL DEFAULT 0.0000,
                risk_score decimal(7,4) NOT NULL DEFAULT 0.0000,
                total_score decimal(7,4) NOT NULL DEFAULT 0.0000,
                character_count int(11) NOT NULL DEFAULT 0,
                status varchar(40) NOT NULL DEFAULT 'draft',
                edited_text longtext NULL,
                reviewer_notes longtext NULL,
                similarity_group varchar(64) NOT NULL DEFAULT '',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY reply_set_id (reply_set_id),
                KEY candidate_index (candidate_index),
                KEY status (status)
            ) $charset",
            "CREATE TABLE {$tables['personas']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                slug varchar(191) NOT NULL DEFAULT '',
                version varchar(50) NOT NULL DEFAULT '1.0.0',
                name varchar(191) NOT NULL DEFAULT '',
                description longtext NULL,
                tone varchar(80) NOT NULL DEFAULT 'restrained',
                voice varchar(80) NOT NULL DEFAULT 'deadpan',
                instructions longtext NOT NULL,
                guardrails_json longtext NULL,
                active tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY slug (slug),
                KEY active (active)
            ) $charset",
            "CREATE TABLE {$tables['prompt_versions']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                stage varchar(40) NOT NULL DEFAULT '',
                slug varchar(191) NOT NULL DEFAULT '',
                version varchar(50) NOT NULL DEFAULT '1.0.0',
                name varchar(191) NOT NULL DEFAULT '',
                description longtext NULL,
                system_prompt longtext NOT NULL,
                user_prompt longtext NOT NULL,
                response_schema longtext NOT NULL,
                active tinyint(1) NOT NULL DEFAULT 0,
                draft tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY stage (stage),
                KEY slug (slug),
                KEY active (active)
            ) $charset",
            "CREATE TABLE {$tables['performance_metrics']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                reply_set_id bigint(20) unsigned NOT NULL DEFAULT 0,
                candidate_id bigint(20) unsigned NOT NULL DEFAULT 0,
                impressions bigint(20) NOT NULL DEFAULT 0,
                likes bigint(20) NOT NULL DEFAULT 0,
                replies_received bigint(20) NOT NULL DEFAULT 0,
                reposts bigint(20) NOT NULL DEFAULT 0,
                bookmarks bigint(20) NOT NULL DEFAULT 0,
                profile_visits bigint(20) NOT NULL DEFAULT 0,
                follows bigint(20) NOT NULL DEFAULT 0,
                publication_datetime datetime NULL,
                measurement_datetime datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                audience_category varchar(120) NOT NULL DEFAULT '',
                notes longtext NULL,
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY post_id (post_id),
                KEY reply_set_id (reply_set_id),
                KEY candidate_id (candidate_id),
                KEY measurement_datetime (measurement_datetime)
            ) $charset",
            "CREATE TABLE {$tables['ai_requests']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                stage varchar(40) NOT NULL DEFAULT '',
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                analysis_id bigint(20) unsigned NOT NULL DEFAULT 0,
                reply_set_id bigint(20) unsigned NOT NULL DEFAULT 0,
                prompt_version_id bigint(20) unsigned NOT NULL DEFAULT 0,
                persona_id bigint(20) unsigned NOT NULL DEFAULT 0,
                provider varchar(80) NOT NULL DEFAULT 'mock',
                model varchar(120) NOT NULL DEFAULT '',
                request_json longtext NULL,
                response_json longtext NULL,
                status varchar(40) NOT NULL DEFAULT 'ok',
                repair_attempted tinyint(1) NOT NULL DEFAULT 0,
                latency_ms int(11) NOT NULL DEFAULT 0,
                error_message longtext NULL,
                error_category varchar(80) NOT NULL DEFAULT '',
                prompt_tokens int(11) NOT NULL DEFAULT 0,
                completion_tokens int(11) NOT NULL DEFAULT 0,
                total_tokens int(11) NOT NULL DEFAULT 0,
                cost_estimate decimal(12,6) NOT NULL DEFAULT 0.000000,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY stage (stage),
                KEY post_id (post_id),
                KEY analysis_id (analysis_id),
                KEY reply_set_id (reply_set_id),
                KEY provider (provider)
            ) $charset",
            "CREATE TABLE {$tables['browser_jobs']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                reply_set_id bigint(20) unsigned NOT NULL DEFAULT 0,
                candidate_id bigint(20) unsigned NOT NULL DEFAULT 0,
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                job_type varchar(40) NOT NULL DEFAULT 'publish_monitor',
                status varchar(40) NOT NULL DEFAULT 'queued',
                phase varchar(40) NOT NULL DEFAULT 'publish',
                current_step varchar(120) NOT NULL DEFAULT '',
                target_url longtext NULL,
                published_url longtext NULL,
                browser_profile_dir longtext NULL,
                browser_state_file longtext NULL,
                control_file_path longtext NULL,
                browser_session_name varchar(120) NOT NULL DEFAULT '',
                pid bigint(20) unsigned NOT NULL DEFAULT 0,
                monitor_cycles int(11) NOT NULL DEFAULT 3,
                monitor_interval_seconds int(11) NOT NULL DEFAULT 20,
                observed_metrics_json longtext NULL,
                latest_screenshot_url longtext NULL,
                latest_screenshot_path longtext NULL,
                log_excerpt longtext NULL,
                error_message longtext NULL,
                started_by bigint(20) unsigned NOT NULL DEFAULT 0,
                completed_by bigint(20) unsigned NOT NULL DEFAULT 0,
                started_at datetime NULL,
                next_check_at datetime NULL,
                last_polled_at datetime NULL,
                completed_at datetime NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY reply_set_id (reply_set_id),
                KEY candidate_id (candidate_id),
                KEY status (status),
                KEY phase (phase),
                KEY created_at (created_at)
            ) $charset",
            "CREATE TABLE {$tables['browser_events']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_id bigint(20) unsigned NOT NULL DEFAULT 0,
                event_type varchar(80) NOT NULL DEFAULT '',
                phase varchar(40) NOT NULL DEFAULT '',
                step_index int(11) NOT NULL DEFAULT 0,
                message longtext NULL,
                data_json longtext NULL,
                screenshot_path longtext NULL,
                screenshot_url longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY job_id (job_id),
                KEY event_type (event_type),
                KEY created_at (created_at)
            ) $charset",
            "CREATE TABLE {$tables['audit_log']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
                action varchar(120) NOT NULL DEFAULT '',
                object_type varchar(80) NOT NULL DEFAULT '',
                object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                severity varchar(20) NOT NULL DEFAULT 'info',
                message longtext NULL,
                context_json longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY action (action),
                KEY object_type (object_type),
                KEY created_at (created_at)
            ) $charset",
            "CREATE TABLE {$tables['error_log']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
                source varchar(120) NOT NULL DEFAULT '',
                error_code varchar(120) NOT NULL DEFAULT '',
                severity varchar(20) NOT NULL DEFAULT 'error',
                message longtext NULL,
                context_json longtext NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY source (source),
                KEY error_code (error_code),
                KEY created_at (created_at)
            ) $charset",
        ];

        foreach ($definitions as $statement) {
            dbDelta($statement);
        }
    }
}
