<?php

declare(strict_types=1);

namespace XReplyAgent\PublicApp;

use XReplyAgent\Support\Capabilities;
use XReplyAgent\Support\View;

final class Controller
{
    public static function shortcodeName(): string
    {
        return 'xreplyagent_app';
    }

    public static function routeSlug(): string
    {
        return 'apps/xreplyagent';
    }

    public static function routePath(): string
    {
        return '/' . self::routeSlug() . '/';
    }

    public static function routeUrl(): string
    {
        return home_url(self::routePath());
    }

    public static function viewUrl(string $view = 'app', array $args = []): string
    {
        $url = self::routeUrl();
        if ($view === '') {
            $view = 'app';
        }

        $screen = '';
        if (isset($args['xra_screen'])) {
            $screen = sanitize_key((string) $args['xra_screen']);
            unset($args['xra_screen']);
        } elseif (isset($args['screen'])) {
            $screen = sanitize_key((string) $args['screen']);
            unset($args['screen']);
        }

        $path = '';
        if ($view === 'auth') {
            $path = 'auth/';
        } elseif ($view === 'admin' || $view === 'app') {
            $path = $view . '/';
            if ($screen !== '') {
                $path .= $screen . '/';
            }
        } else {
            $path = trim($view, '/') . '/';
            if ($screen !== '') {
                $path .= $screen . '/';
            }
        }

        $url = trailingslashit($url) . ltrim($path, '/');
        $args['xra_view'] = $view;
        if ($screen !== '') {
            $args['xra_screen'] = $screen;
        }

        if ($args !== []) {
            $url = add_query_arg($args, $url);
        }

        return $url;
    }

    public static function displayTitle(): string
    {
        $view = self::activeView();
        return match ($view) {
            'auth' => 'XReplyAgent - Sign In',
            'admin' => 'XReplyAgent - Workspace',
            default => 'XReplyAgent',
        };
    }

    public static function documentTitleParts(array $parts): array
    {
        if (!self::isRequest()) {
            return $parts;
        }

        $parts['title'] = self::displayTitle();
        $parts['tagline'] = 'Controlled AI Reply Workbench';
        return $parts;
    }

    public static function preGetDocumentTitle(string $title): string
    {
        return self::isRequest() ? self::displayTitle() : $title;
    }

    public static function printHeadMeta(): void
    {
        if (!self::isRequest()) {
            return;
        }

        $description = match (self::activeView()) {
            'auth' => 'Sign in to continue in XReplyAgent.',
            'admin' => 'Manage prompts, personas, reviews, metrics, and publication steps in XReplyAgent.',
            default => 'Analyze posts, generate replies, and review results in XReplyAgent.',
        };

        printf(
            '<meta name="application-name" content="%s">' . "\n",
            esc_attr('XReplyAgent')
        );
        printf(
            '<meta name="description" content="%s">' . "\n",
            esc_attr($description)
        );
        printf(
            '<meta name="theme-color" content="%s">' . "\n",
            esc_attr('#0b0f16')
        );
    }

    public static function handleAuthLogin(): void
    {
        self::processAuthNonce('xra_auth_login');

        $identifier = sanitize_text_field(wp_unslash((string) ($_POST['identifier'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);
        $redirectTo = self::authRedirectTarget();

        if ($identifier === '' || $password === '') {
            self::redirectAuth('sign_in_failed', $redirectTo);
        }

        $login = $identifier;
        if (is_email($identifier)) {
            $user = get_user_by('email', $identifier);
            if ($user instanceof \WP_User) {
                $login = (string) $user->user_login;
            }
        }

        $user = wp_signon([
            'user_login' => $login,
            'user_password' => $password,
            'remember' => $remember,
        ], is_ssl());

        if (is_wp_error($user)) {
            self::redirectAuth('sign_in_failed', $redirectTo);
        }

        $target = Capabilities::canAccessAdminShell()
            ? self::viewUrl('admin')
            : self::viewUrl('app');
        wp_safe_redirect($target);
        exit;
    }

    public static function handleAuthRegister(): void
    {
        self::processAuthNonce('xra_auth_register');

        if (!get_option('users_can_register')) {
            self::redirectAuth('registration_closed', self::authRedirectTarget());
        }

        $username = sanitize_user(wp_unslash((string) ($_POST['username'] ?? '')), true);
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $redirectTo = self::authRedirectTarget();

        if ($username === '' || $email === '' || $password === '' || !is_email($email) || strlen($password) < 8) {
            self::redirectAuth('create_account_failed', $redirectTo);
        }

        if (username_exists($username) || email_exists($email)) {
            self::redirectAuth('create_account_failed', $redirectTo);
        }

        $userId = wp_insert_user([
            'user_login' => $username,
            'user_pass' => $password,
            'user_email' => $email,
            'role' => get_option('default_role', 'subscriber'),
            'display_name' => $username,
        ]);

        if (is_wp_error($userId)) {
            self::redirectAuth('create_account_failed', $redirectTo);
        }

        $user = get_user_by('id', (int) $userId);
        if ($user instanceof \WP_User) {
            wp_set_current_user((int) $user->ID);
            wp_set_auth_cookie((int) $user->ID, true, is_ssl());
        }

        wp_safe_redirect(self::viewUrl('app'));
        exit;
    }

    public static function requestedView(): string
    {
        $view = self::requestedRawView();
        if ($view === '') {
            $view = 'app';
        }

        if (str_contains($view, '-')) {
            $parts = explode('-', $view, 2);
            $view = sanitize_key((string) $parts[0]);
        }

        return in_array($view, ['app', 'auth', 'admin'], true) ? $view : 'app';
    }

    private static function requestedRawView(): string
    {
        $view = isset($_GET['xra_view']) ? sanitize_key(wp_unslash((string) $_GET['xra_view'])) : '';
        $pathInfo = self::routeStateFromPath();
        if ($pathInfo['view'] !== '') {
            $view = $pathInfo['view'];
        }
        if ($view === '') {
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            if ($query !== '') {
                parse_str($query, $params);
                if (isset($params['xra_view'])) {
                    $view = sanitize_key(wp_unslash((string) $params['xra_view']));
                }
            }
        }

        return $view;
    }

    private static function authRedirectTarget(): string
    {
        $target = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_POST['redirect_to'])) : self::viewUrl('app');
        return $target !== '' ? $target : self::viewUrl('app');
    }

    private static function processAuthNonce(string $action): void
    {
        check_admin_referer($action);
    }

    private static function redirectAuth(string $notice, string $target): void
    {
        wp_safe_redirect(add_query_arg([
            'xra_view' => 'auth',
            'xra_notice' => $notice,
        ], $target));
        exit;
    }

    public static function activeView(): string
    {
        $view = self::requestedView();
        if ($view === 'admin' && !Capabilities::canAccessAdminShell()) {
            return 'auth';
        }

        return $view;
    }

    public static function registerShortcode(): void
    {
        add_shortcode(self::shortcodeName(), [self::class, 'shortcode']);
    }

    public static function shortcode(): string
    {
        ob_start();
        self::render();
        return (string) ob_get_clean();
    }

    public static function render(): void
    {
        View::render(XRA_DIR . 'templates/page.php', [
            'view' => self::activeView(),
            'screen' => self::requestedScreen(),
        ]);
    }

    private static function requestedScreen(): string
    {
        $screen = isset($_GET['xra_force_screen']) ? sanitize_key(wp_unslash((string) $_GET['xra_force_screen'])) : '';
        if ($screen !== '') {
            return $screen;
        }

        $pathInfo = self::routeStateFromPath();
        if ($pathInfo['screen'] !== '') {
            return $pathInfo['screen'];
        }

        $screen = isset($_GET['xra_screen']) ? sanitize_key(wp_unslash((string) $_GET['xra_screen'])) : '';
        if ($screen === '' && isset($_GET['screen'])) {
            $screen = sanitize_key(wp_unslash((string) $_GET['screen']));
        }
        if ($screen === '') {
            $routeView = self::requestedRawView();
            if ($routeView !== '' && str_contains($routeView, '-')) {
                $parts = explode('-', $routeView, 2);
                $screen = sanitize_key((string) $parts[1]);
            }
        }
        if ($screen === '') {
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            if ($query !== '') {
                parse_str($query, $params);
                if (isset($params['xra_screen'])) {
                    $screen = sanitize_key(wp_unslash((string) $params['xra_screen']));
                } elseif (isset($params['screen'])) {
                    $screen = sanitize_key(wp_unslash((string) $params['screen']));
                } elseif (isset($params['xra_view']) && str_contains((string) $params['xra_view'], '-')) {
                    $parts = explode('-', sanitize_key((string) $params['xra_view']), 2);
                    $screen = sanitize_key((string) $parts[1]);
                }
            }
        }

        return $screen !== '' ? $screen : 'overview';
    }

    public static function ensurePage(): void
    {
        $existing = get_page_by_path(self::routeSlug(), OBJECT, 'page');
        if ($existing instanceof \WP_Post) {
            return;
        }

        $parentId = 0;
        $parent = get_page_by_path('apps', OBJECT, 'page');
        if ($parent instanceof \WP_Post) {
            $parentId = (int) $parent->ID;
        } else {
            $parentId = (int) wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Apps',
                'post_name' => 'apps',
                'post_content' => '',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ]);
        }

        $childSlug = 'xreplyagent';
        if ($parentId > 0) {
            wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'XReplyAgent',
                'post_name' => $childSlug,
                'post_parent' => $parentId,
                'post_content' => '[' . self::shortcodeName() . ']',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ]);
            return;
        }

        wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'XReplyAgent',
            'post_name' => $childSlug,
            'post_parent' => 0,
            'post_content' => '[' . self::shortcodeName() . ']',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ]);
    }

    public static function isRequest(): bool
    {
        if (self::requestedView() !== 'app') {
            return true;
        }

        $request_path = strtolower(trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'));
        return str_starts_with($request_path, self::routeSlug()) || $request_path === trim(self::routePath(), '/');
    }

    /**
     * @return array{view: string, screen: string}
     */
    private static function routeStateFromPath(): array
    {
        $path = strtolower(trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'));
        $route = trim(self::routeSlug(), '/');
        if ($path === '' || !str_starts_with($path, $route)) {
            return ['view' => '', 'screen' => ''];
        }

        $tail = trim(substr($path, strlen($route)), '/');
        if ($tail === '') {
            return ['view' => '', 'screen' => ''];
        }

        $parts = array_values(array_filter(explode('/', $tail), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return ['view' => '', 'screen' => ''];
        }

        $first = sanitize_key((string) ($parts[0] ?? ''));
        $second = sanitize_key((string) ($parts[1] ?? ''));

        $view = '';
        $screen = '';
        if (in_array($first, ['app', 'admin', 'auth'], true)) {
            $view = $first;
            $screen = $second;
        } elseif (in_array($first, ['overview', 'walkthroughs', 'analyze', 'review-queue', 'history', 'analytics', 'personas', 'prompts', 'settings', 'audit', 'error-log'], true)) {
            $view = 'app';
            $screen = $first;
        }

        return ['view' => $view, 'screen' => $screen];
    }

    public static function templateInclude(string $template): string
    {
        if (!self::isRequest()) {
            return $template;
        }

        $path = XRA_DIR . 'templates/page.php';
        return is_readable($path) ? $path : $template;
    }

    public static function templateRedirect(): void
    {
        if (!self::isRequest()) {
            return;
        }

        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
            $wp_query->is_singular = true;
        }

        status_header(200);
        nocache_headers();
    }

    public static function enqueueAssets(): void
    {
        if (!self::isRequest() && !self::hasShortcode()) {
            return;
        }

        wp_enqueue_style(
            'xra-app',
            XRA_URL . 'assets/css/xreplyagent.css',
            [],
            filemtime(XRA_DIR . 'assets/css/xreplyagent.css') ?: XRA_VERSION
        );

        wp_enqueue_script(
            'xra-app',
            XRA_URL . 'assets/js/xreplyagent.js',
            [],
            filemtime(XRA_DIR . 'assets/js/xreplyagent.js') ?: XRA_VERSION,
            true
        );

        wp_localize_script(
            'xra-app',
            'XReplyAgentApp',
            [
                'restUrl' => esc_url_raw(rest_url('xreplyagent/v1')),
                'nonce' => wp_create_nonce('xra_public'),
                'publicUrl' => self::viewUrl('app'),
                'adminUrl' => self::viewUrl('admin'),
                'authUrl' => self::viewUrl('auth'),
                'canReview' => Capabilities::canReviewReplies(),
                'canSettings' => Capabilities::canManageSettings(),
            ]
        );
    }

    private static function hasShortcode(): bool
    {
        if (!function_exists('get_post_field') || !function_exists('get_the_ID')) {
            return false;
        }

        $content = (string) get_post_field('post_content', (int) get_the_ID());
        return has_shortcode($content, self::shortcodeName());
    }
}
