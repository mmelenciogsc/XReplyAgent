<?php

declare(strict_types=1);

namespace XReplyAgent\Rest;

use XReplyAgent\Domain\Workflow;
use XReplyAgent\Domain\BrowserAutomation;
use XReplyAgent\Storage\Store;
use XReplyAgent\Support\Capabilities;

final class Controller
{
    public static function registerRoutes(): void
    {
        register_rest_route('xreplyagent/v1', '/health', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'health'],
        ]);

        register_rest_route('xreplyagent/v1', '/workflow', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'permissionPublic'],
            'callback' => [self::class, 'workflow'],
        ]);

        register_rest_route('xreplyagent/v1', '/analyze', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'permissionPublic'],
            'callback' => [self::class, 'workflow'],
        ]);

        register_rest_route('xreplyagent/v1', '/review', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'permissionReview'],
            'callback' => [self::class, 'review'],
        ]);

        register_rest_route('xreplyagent/v1', '/analytics', [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'permissionAnalytics'],
            'callback' => [self::class, 'analytics'],
        ]);

        register_rest_route('xreplyagent/v1', '/history', [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'permissionPublic'],
            'callback' => [self::class, 'history'],
        ]);

        register_rest_route('xreplyagent/v1', '/metrics', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'permissionPublic'],
            'callback' => [self::class, 'metric'],
        ]);

        register_rest_route('xreplyagent/v1', '/browser-jobs', [
            'methods' => 'GET',
            'permission_callback' => [self::class, 'permissionReview'],
            'callback' => [self::class, 'browserJobs'],
        ]);

        register_rest_route('xreplyagent/v1', '/seed', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'permissionPublic'],
            'callback' => [self::class, 'seed'],
        ]);

        register_rest_route('xreplyagent/v1', '/reset', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'permissionSettings'],
            'callback' => [self::class, 'reset'],
        ]);
    }

    public static function health(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'ok' => true,
            'schema_version' => (string) get_option('xra_schema_version', ''),
            'provider' => \XReplyAgent\AI\ProviderFactory::settings(),
            'summary' => Store::summary(),
            'analytics' => Store::analyticsSummary(),
        ], 200);
    }

    public static function workflow(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = is_array($request->get_json_params()) ? $request->get_json_params() : [];
        $result = Workflow::processSubmission($payload);
        return new \WP_REST_Response($result, !empty($result['ok']) ? 200 : 400);
    }

    public static function review(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = is_array($request->get_json_params()) ? $request->get_json_params() : [];
        $result = Workflow::reviewReplySet($payload);
        return new \WP_REST_Response($result, !empty($result['ok']) ? 200 : 400);
    }

    public static function analytics(): \WP_REST_Response
    {
        return new \WP_REST_Response(Workflow::analyticsOverview(), 200);
    }

    public static function history(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = Workflow::history([
            'post_id' => (int) ($request->get_param('post_id') ?? 0),
            'analysis_id' => (int) ($request->get_param('analysis_id') ?? 0),
        ]);

        return new \WP_REST_Response($result, 200);
    }

    public static function metric(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = is_array($request->get_json_params()) ? $request->get_json_params() : [];
        $result = Workflow::recordPerformanceMetric($payload);
        return new \WP_REST_Response($result, !empty($result['ok']) ? 200 : 400);
    }

    public static function browserJobs(\WP_REST_Request $request): \WP_REST_Response
    {
        $replySetId = (int) ($request->get_param('reply_set_id') ?? 0);
        return new \WP_REST_Response(BrowserAutomation::getJobPanel($replySetId), 200);
    }

    public static function seed(): \WP_REST_Response
    {
        return new \WP_REST_Response(Workflow::seedDemo(), 200);
    }

    public static function reset(): \WP_REST_Response
    {
        return new \WP_REST_Response(Workflow::resetDemo(true), 200);
    }

    public static function permissionPublic(\WP_REST_Request $request): bool
    {
        return self::validNonce($request) || Capabilities::canUseApp();
    }

    public static function permissionReview(\WP_REST_Request $request): bool
    {
        return self::validNonce($request) || Capabilities::canReviewReplies();
    }

    public static function permissionAnalytics(\WP_REST_Request $request): bool
    {
        return self::validNonce($request) || Capabilities::canViewAnalytics();
    }

    public static function permissionSettings(\WP_REST_Request $request): bool
    {
        return self::validNonce($request) || Capabilities::canManageSettings();
    }

    private static function validNonce(\WP_REST_Request $request): bool
    {
        $nonce = (string) $request->get_header('x-xra-nonce');
        if ($nonce === '') {
            $nonce = (string) $request->get_param('_xra_nonce');
        }

        return $nonce !== '' && wp_verify_nonce($nonce, 'xra_public');
    }
}
