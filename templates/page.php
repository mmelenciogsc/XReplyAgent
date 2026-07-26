<?php

declare(strict_types=1);

use XReplyAgent\AI\ProviderFactory;
use XReplyAgent\PublicApp\Controller as PublicController;
use XReplyAgent\Storage\Store;
use XReplyAgent\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$view = PublicController::activeView();
$screen = '';
if (isset($_GET['xra_force_screen'])) {
    $screen = sanitize_key(wp_unslash((string) $_GET['xra_force_screen']));
}
if ($screen === '' && isset($_GET['xra_screen'])) {
    $screen = sanitize_key(wp_unslash((string) $_GET['xra_screen']));
}
if ($screen === '' && isset($_GET['screen'])) {
    $screen = sanitize_key(wp_unslash((string) $_GET['screen']));
}
if ($screen === '') {
    $requestedPath = strtolower(trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'));
    $route = trim(PublicController::routeSlug(), '/');
    if ($requestedPath !== '' && str_starts_with($requestedPath, $route)) {
        $tail = trim(substr($requestedPath, strlen($route)), '/');
        if ($tail !== '') {
            $parts = array_values(array_filter(explode('/', $tail), static fn (string $part): bool => $part !== ''));
            $first = sanitize_key((string) ($parts[0] ?? ''));
            $second = sanitize_key((string) ($parts[1] ?? ''));
            if (in_array($first, ['overview', 'analyze', 'review-queue', 'history', 'analytics', 'personas', 'prompts', 'settings', 'audit', 'error-log'], true)) {
                $screen = $first;
            } elseif (in_array($first, ['app', 'admin'], true) && $second !== '') {
                $screen = $second;
            }
        }
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
$screen = $screen !== '' ? $screen : 'overview';

$summary = Store::summary();
$analytics = Store::analyticsSummary();
$metricsSummary = Store::metricsSummary();
$provider = ProviderFactory::settings();
$personas = Store::listPersonas(50);
$prompts = Store::listPrompts(100);
$posts = Store::listPosts([
    'search' => (string) ($_GET['xra_search'] ?? ''),
    'status' => (string) ($_GET['xra_status'] ?? ''),
    'tone' => (string) ($_GET['xra_tone'] ?? ''),
    'intent' => (string) ($_GET['xra_intent'] ?? ''),
    'persona_id' => (int) ($_GET['xra_persona_id'] ?? 0),
    'from' => (string) ($_GET['xra_from'] ?? ''),
    'to' => (string) ($_GET['xra_to'] ?? ''),
], 100);
$replySets = Store::listReplySets([
    'search' => (string) ($_GET['xra_search'] ?? ''),
    'status' => (string) ($_GET['xra_status'] ?? ''),
    'persona_id' => (int) ($_GET['xra_persona_id'] ?? 0),
], 100);
$metrics = Store::listMetrics(50);
$audit = Store::listAudit(50);
$errors = Store::listErrors(50);
$recentPosts = Store::listPosts([], 6);
$recentAnalyses = Store::listAnalyses(6);
$recentReplySets = Store::listReplySets([], 6);
$recentAIRequests = Store::listAIRequests(8);
$currentPostId = (int) ($_GET['xra_post_id'] ?? 0);
$currentReplySetId = (int) ($_GET['xra_reply_set_id'] ?? 0);
$currentPost = $currentPostId > 0 ? Store::getPost($currentPostId) : Store::latestPost();
if ($currentPost === []) {
    $currentPost = Store::latestPost();
}

$currentReplySet = $currentReplySetId > 0 ? Store::getReplySet($currentReplySetId) : Store::latestReplySet();
if ($currentReplySet === []) {
    $currentReplySet = Store::latestReplySet();
}

$currentAnalysis = [];
if ($currentReplySet !== []) {
    $currentAnalysis = Store::getAnalysis((int) ($currentReplySet['analysis_id'] ?? 0));
}
if ($currentAnalysis === [] && $currentPost !== []) {
    $analysisRowId = (int) ($currentPost['analysis_row_id'] ?? 0);
    if ($analysisRowId > 0) {
        $currentAnalysis = Store::getAnalysis($analysisRowId);
    }
}

$currentCandidates = $currentReplySet !== [] ? Store::listReplyCandidates((int) ($currentReplySet['id'] ?? 0)) : [];
$activePersona = Store::activePersona();
$activePrompts = Store::activePrompts();
$footerText = (string) get_option(Settings::OPTION_BRAND_LABEL, 'XReplyAgent');
$notice = isset($_GET['xra_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['xra_notice'])) : '';
$screenMap = [
    'overview' => 'Overview',
    'analyze' => 'Analyze',
    'review-queue' => 'Review Queue',
    'history' => 'History',
    'analytics' => 'Analytics',
    'personas' => 'Personas',
    'prompts' => 'Prompts',
    'settings' => 'Settings',
    'audit' => 'Audit',
    'error-log' => 'Error Log',
];
?>
<!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body class="xra-body xra-view-<?php echo esc_attr($view); ?>">
<?php wp_body_open(); ?>
<a class="xra-skip-link" href="#xra-main">Skip To Content</a>
<div class="xra-app" data-xra-app data-xra-view="<?php echo esc_attr($view); ?>" data-xra-screen="<?php echo esc_attr($screen); ?>">
    <header class="xra-frame" aria-label="XReplyAgent Header">
        <div class="xra-brand-block">
            <div class="xra-brand-mark">
                <img
                    class="xra-brand-mark__img"
                    src="<?php echo esc_url(XRA_URL . 'assets/images/xreplyagent-logo.svg'); ?>"
                    alt="XReplyAgent robot mask logo"
                    width="72"
                    height="72"
                    decoding="async"
                >
            </div>
            <div class="xra-brand-copy">
                <h1>XReplyAgent</h1>
            </div>
        </div>
    </header>
    <main class="xra-shell" id="xra-main" tabindex="-1">
        <?php if ($view === 'auth') : ?>
            <?php include XRA_DIR . 'views/auth-shell.php'; ?>
        <?php elseif ($view === 'admin') : ?>
            <?php include XRA_DIR . 'views/admin-shell.php'; ?>
        <?php else : ?>
            <?php include XRA_DIR . 'views/public-shell.php'; ?>
        <?php endif; ?>
    </main>
    <footer class="xra-footer" aria-label="Application Footer">
        <span class="xra-footer-brand">XReplyAgent</span>
        <span class="xra-footer-meta">
            <a href="mailto:mmelencio@theoriamedical.com">mmelencio</a>
            <span><?php echo esc_html($footerText !== '' ? $footerText : 'XReplyAgent'); ?></span>
        </span>
    </footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
