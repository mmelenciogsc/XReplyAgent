<?php

declare(strict_types=1);

use XReplyAgent\PublicApp\Controller as PublicController;
use XReplyAgent\Storage\Store;
use XReplyAgent\Support\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

$screenMapPublic = [
    'overview' => 'Overview',
    'walkthroughs' => 'Walkthroughs',
    'analyze' => 'Analyze',
    'history' => 'History',
    'analytics' => 'Analytics',
];
$notice = isset($_GET['xra_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['xra_notice'])) : '';
$analyzeAction = admin_url('admin-post.php');
$loginUrl = PublicController::viewUrl('auth');
$adminUrl = PublicController::viewUrl('admin');
$overviewCards = [
    ['key' => 'posts', 'label' => 'Posts', 'value' => (string) ($summary['posts'] ?? 0)],
    ['key' => 'analyses', 'label' => 'Analyses', 'value' => (string) ($summary['analyses'] ?? 0)],
    ['key' => 'reply_sets', 'label' => 'Reply Sets', 'value' => (string) ($summary['reply_sets'] ?? 0)],
    ['key' => 'published_candidates', 'label' => 'Published', 'value' => (string) ($summary['published_candidates'] ?? 0)],
    ['key' => 'approved_candidates', 'label' => 'Approved', 'value' => (string) ($summary['approved_candidates'] ?? 0)],
    ['key' => 'metrics', 'label' => 'Metrics', 'value' => (string) ($summary['metrics'] ?? 0)],
];
$sourceUrl = trim((string) ($currentPost['source_url'] ?? ''));
$renderLink = static function (string $url, string $label): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    return sprintf(
        '<a class="xra-inline-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
        esc_url($url),
        esc_html($label)
    );
};
$scoreLabel = static fn (mixed $score): string => Store::displayScoreLabel((float) $score);
$displayReplySet = $currentReplySet;
$displayCandidates = $currentCandidates;
if ($displayCandidates === []) {
    foreach ($recentReplySets as $recentReplySet) {
        $recentReplyCandidates = Store::listReplyCandidates((int) ($recentReplySet['id'] ?? 0));
        if ($recentReplyCandidates !== []) {
            $displayReplySet = $recentReplySet;
            $displayCandidates = $recentReplyCandidates;
            break;
        }
    }
}
$recentPostLinks = [];
foreach (array_values((array) $recentPosts) as $index => $recentPost) {
    $recentPostId = (int) ($recentPost['id'] ?? 0);
    if ($recentPostId <= 0) {
        continue;
    }

    $recentReplySetId = (int) ($recentPost['reply_set_id'] ?? 0);
    $recentTopic = trim((string) ($recentPost['main_topic'] ?? ''));
    if ($recentTopic === '') {
        $recentTopic = trim(mb_substr((string) ($recentPost['post_text'] ?? ''), 0, 42));
    }

    $recentPostLinks[] = [
        'index' => $index + 1,
        'post_id' => $recentPostId,
        'label' => $recentTopic !== '' ? $recentTopic : 'Post ' . (string) ($index + 1),
        'url' => PublicController::viewUrl('app', array_filter([
            'xra_screen' => 'overview',
            'xra_post_id' => $recentPostId,
            'xra_reply_set_id' => $recentReplySetId > 0 ? $recentReplySetId : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '')),
        'has_replies' => $recentReplySetId > 0,
    ];
}
?>
<div class="xra-shell-surface xra-shell-surface--public">
    <nav class="xra-screen-nav xra-screen-nav--public" aria-label="Public Screens">
        <?php foreach ($screenMapPublic as $key => $label) : ?>
            <a class="xra-screen-nav__link <?php echo $screen === $key ? 'is-active' : ''; ?>" <?php echo $screen === $key ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url(PublicController::viewUrl('app', ['xra_screen' => $key])); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
        <a class="xra-screen-nav__link" href="<?php echo esc_url($loginUrl); ?>">Sign In</a>
        <?php if (Capabilities::canAccessAdminShell()) : ?>
            <a class="xra-screen-nav__link" href="<?php echo esc_url($adminUrl); ?>">Admin</a>
        <?php endif; ?>
    </nav>

    <?php if ($recentPostLinks !== []) : ?>
        <p class="xra-sr-only" id="xra-recent-posts-hint">Use these links to open another post and its replies.</p>
        <nav class="xra-jump-links" aria-label="Recent Posts" aria-describedby="xra-recent-posts-hint">
            <?php foreach ($recentPostLinks as $recentPostLink) : ?>
                <a
                    class="xra-chip xra-jump-links__link <?php echo ((int) ($currentPost['id'] ?? 0) === (int) ($recentPostLink['post_id'] ?? 0)) ? 'is-active' : ''; ?>"
                    href="<?php echo esc_url((string) ($recentPostLink['url'] ?? '')); ?>"
                    aria-label="<?php echo esc_attr('Open post ' . (string) ($recentPostLink['index'] ?? '') . ': ' . (string) ($recentPostLink['label'] ?? '')); ?>"
                >
                    <?php echo esc_html((string) ($recentPostLink['index'] ?? '') . '. ' . (string) ($recentPostLink['label'] ?? '')); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($notice !== '') : ?>
        <section class="xra-notice" role="status" aria-live="polite"><?php echo esc_html(ucwords(str_replace('_', ' ', $notice))); ?></section>
    <?php endif; ?>

    <?php if ($screen === 'overview') : ?>
        <section class="xra-hero-card" aria-labelledby="xra-public-overview-title">
                <div class="xra-section-head">
                    <div>
                        <h2 id="xra-public-overview-title">Overview</h2>
                    </div>
                <div class="xra-actions">
                    <a class="xra-button" href="<?php echo esc_url(PublicController::viewUrl('app', ['xra_screen' => 'analyze'])); ?>">Analyze</a>
                    <button class="xra-button xra-button--secondary" type="button" data-xra-fullscreen>Fullscreen</button>
                </div>
            </div>
            <div class="xra-metrics-grid" aria-label="Overview summary">
                <?php foreach ($overviewCards as $card) : ?>
                    <article class="xra-stat-card">
                        <span><?php echo esc_html($card['label']); ?></span>
                        <strong data-xra-stat="<?php echo esc_attr((string) ($card['key'] ?? sanitize_key($card['label']))); ?>"><?php echo esc_html($card['value']); ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="xra-grid xra-grid--two xra-grid--overview">
            <article class="xra-surface" aria-labelledby="xra-public-current-title">
                <div class="xra-section-head">
                    <div><h2 id="xra-public-current-title">Post</h2></div>
                    <span class="xra-chip"><?php echo esc_html((string) ($currentPost['status'] ?? 'draft')); ?></span>
                </div>
                <?php if ($currentPost !== []) : ?>
                    <p class="xra-lede"><?php echo esc_html((string) ($currentPost['post_text'] ?? '')); ?></p>
                    <dl class="xra-spec-list">
                        <div><dt>Source</dt><dd><?php echo $sourceUrl !== '' ? $renderLink($sourceUrl, 'Open Source') : esc_html('Browser Capture'); ?></dd></div>
                        <div><dt>Objective</dt><dd><?php echo esc_html((string) ($currentPost['desired_objective'] ?? '')); ?></dd></div>
                        <div><dt>Topic</dt><dd><?php echo esc_html((string) ($currentAnalysis['main_topic'] ?? 'Pending')); ?></dd></div>
                        <div><dt>Persona</dt><dd><?php echo esc_html((string) ($currentAnalysis['persona_name'] ?? ($activePersona['name'] ?? 'Persona'))); ?></dd></div>
                    </dl>
                <?php else : ?>
                    <p class="xra-note"></p>
                <?php endif; ?>
            </article>

            <article class="xra-surface" aria-labelledby="xra-public-recommendation-title">
                <div class="xra-section-head">
                    <div><h2 id="xra-public-recommendation-title">Replies</h2></div>
                    <span class="xra-chip"><?php echo esc_html((string) count((array) $currentCandidates)); ?> Candidates</span>
                </div>
                <p class="xra-lede"><?php echo esc_html((string) ($displayReplySet['recommendations_json']['summary'] ?? '')); ?></p>
                <div class="xra-card-stack xra-reply-stack">
                    <?php foreach ((array) ($displayCandidates ?? []) as $candidate) : ?>
                        <?php
                        $candidateRationale = trim((string) ($candidate['short_rationale'] ?? ''));
                        if ($candidateRationale === '' || stripos($candidateRationale, 'fallback candidate') !== false) {
                            $candidateRationale = 'Ranked reply option.';
                        }
                        ?>
                        <article class="xra-mini-stat xra-reply-card">
                            <div class="xra-reply-copy">
                                <strong><?php echo esc_html((string) ($candidate['approach_label'] ?? 'Reply')); ?></strong>
                                <span><?php echo esc_html($candidateRationale); ?></span>
                                <p class="xra-reply-text"><?php echo esc_html((string) ($candidate['reply_text'] ?? '')); ?></p>
                            </div>
                            <b><?php echo esc_html($scoreLabel($candidate['total_score'] ?? 0)); ?></b>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>
    <?php elseif ($screen === 'walkthroughs') : ?>
        <?php include XRA_DIR . 'views/walkthrough-panel.php'; ?>
    <?php elseif ($screen === 'analyze') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface xra-surface--accent" aria-labelledby="xra-analyze-title">
                <div class="xra-section-head">
                    <div>
                        <h2 id="xra-analyze-title">Analyze</h2>
                    </div>
                    <button class="xra-button xra-button--secondary" type="button" data-xra-fullscreen>Fullscreen</button>
                </div>
                <form class="xra-form" data-xra-query-form data-xra-workflow-form method="post" action="<?php echo esc_url($analyzeAction); ?>">
                    <?php wp_nonce_field('xra_run_workflow'); ?>
                    <input type="hidden" name="action" value="xra_run_workflow">
                    <label>
                        <span>Post Text</span>
                        <textarea name="post_text" rows="8" placeholder="Post text."></textarea>
                    </label>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label>
                            <span>Source URL</span>
                            <input type="url" name="source_url" placeholder="https://...">
                        </label>
                        <label>
                            <span>Desired Objective</span>
                            <input type="text" name="desired_objective" placeholder="Primary objective">
                        </label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label>
                            <span>Author Handle</span>
                            <input type="text" name="author_handle" placeholder="@handle">
                        </label>
                        <label>
                            <span>Author Name</span>
                            <input type="text" name="author_name" placeholder="Name">
                        </label>
                    </div>
                    <label>
                        <span>Context</span>
                        <textarea name="context_text" rows="4" placeholder="Context."></textarea>
                    </label>
                    <label>
                        <span>Persona</span>
                        <select name="persona_id">
                            <?php foreach ($personas as $persona) : ?>
                                <option value="<?php echo esc_attr((string) ($persona['id'] ?? 0)); ?>" <?php selected((int) ($persona['id'] ?? 0), (int) ($activePersona['id'] ?? 0)); ?>><?php echo esc_html((string) ($persona['name'] ?? 'Persona')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="xra-actions">
                        <button class="xra-button" type="submit">Run Workflow</button>
                    </div>
                </form>
            </article>
            <aside class="xra-surface" aria-labelledby="xra-analysis-output-title">
                <div class="xra-section-head">
                    <div><h2 id="xra-analysis-output-title">Result</h2></div>
                    <span class="xra-chip"><?php echo esc_html((string) ($provider['model'] ?? 'gpt-4o-mini')); ?></span>
                </div>
                <div class="xra-card-stack xra-reply-stack">
                    <?php foreach ($currentCandidates as $candidate) : ?>
                        <?php
                        $candidateRationale = trim((string) ($candidate['short_rationale'] ?? ''));
                        if ($candidateRationale === '' || stripos($candidateRationale, 'fallback candidate') !== false) {
                            $candidateRationale = 'Ranked reply option.';
                        }
                        ?>
                        <article class="xra-mini-stat xra-reply-card">
                            <div class="xra-reply-copy">
                                <strong><?php echo esc_html((string) ($candidate['approach_label'] ?? 'Reply')); ?></strong>
                                <span><?php echo esc_html($candidateRationale); ?></span>
                                <p class="xra-reply-text"><?php echo esc_html((string) ($candidate['reply_text'] ?? '')); ?></p>
                            </div>
                            <b><?php echo esc_html($scoreLabel($candidate['total_score'] ?? 0)); ?></b>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="xra-analysis-panel" data-xra-response role="status" aria-live="polite" aria-atomic="true">
                    <div class="xra-live-region" data-xra-live-region aria-live="polite" aria-atomic="true"></div>
                    <pre class="xra-analysis-plain" data-xra-analysis-output><?php
                        echo esc_html(
                            "Main Topic: " . (string) ($currentAnalysis['main_topic'] ?? 'Pending') . "\n" .
                            "Tone: " . (string) ($currentAnalysis['tone'] ?? 'Pending') . "\n" .
                            "Sentiment: " . (string) ($currentAnalysis['sentiment'] ?? 'Pending') . "\n" .
                            "Likely Intent: " . (string) ($currentAnalysis['likely_intent'] ?? 'Pending') . "\n" .
                            "Recommended Approach: " . (string) ($currentAnalysis['recommended_reply_approach'] ?? 'Pending') . "\n" .
                            "Reply Candidates: " . (string) count($currentCandidates)
                        );
                    ?></pre>
                </div>
            </aside>
        </section>
    <?php elseif ($screen === 'history') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface" aria-labelledby="xra-history-title">
                <div class="xra-section-head">
                    <div><h2 id="xra-history-title">History</h2></div>
                </div>
                <form class="xra-form xra-form--inline" method="get" action="<?php echo esc_url(PublicController::viewUrl('app')); ?>">
                    <input type="hidden" name="xra_view" value="app">
                    <input type="hidden" name="xra_screen" value="history">
                    <label><span>Search</span><input type="search" name="xra_search" value="<?php echo esc_attr((string) ($_GET['xra_search'] ?? '')); ?>" placeholder="Search posts"></label>
                    <label><span>Status</span><input type="text" name="xra_status" value="<?php echo esc_attr((string) ($_GET['xra_status'] ?? '')); ?>" placeholder="Status"></label>
                    <button class="xra-button" type="submit">Filter</button>
                </form>
                <div class="xra-table-wrap">
                    <table class="xra-table">
                        <thead><tr><th scope="col">Post</th><th scope="col">Topic</th><th scope="col">Status</th><th scope="col">Updated</th></tr></thead>
                        <tbody>
                        <?php foreach ($posts as $post) : ?>
                            <tr>
                                <td><?php echo esc_html(mb_substr((string) ($post['post_text'] ?? ''), 0, 90)); ?></td>
                                <td><?php echo esc_html((string) ($post['main_topic'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($post['status'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($post['updated_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="xra-surface" aria-labelledby="xra-recent-replies-title">
                <div class="xra-section-head">
                    <div><h2 id="xra-recent-replies-title">Reply History</h2></div>
                </div>
                <?php foreach ($replySets as $replySet) : ?>
                    <details class="xra-details" <?php echo $replySet === $currentReplySet ? 'open' : ''; ?>>
                        <summary>
                            <strong><?php echo esc_html((string) ($replySet['main_topic'] ?? 'Reply Set')); ?></strong>
                            <span><?php echo esc_html((string) ($replySet['status'] ?? '')); ?></span>
                        </summary>
                        <div class="xra-details__body">
                            <p><?php echo esc_html((string) ($replySet['post_text'] ?? '')); ?></p>
                            <p><?php echo esc_html((string) ($replySet['persona_name'] ?? '')); ?></p>
                        </div>
                    </details>
                <?php endforeach; ?>
            </article>
        </section>
    <?php else : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface" aria-labelledby="xra-analytics-title">
                <div class="xra-section-head">
                    <div><h2 id="xra-analytics-title">Analytics</h2></div>
                    <span class="xra-chip"><?php echo esc_html((string) ($metricsSummary['count'] ?? 0)); ?> metrics</span>
                </div>
                <dl class="xra-spec-list">
                    <div><dt>Impressions</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['impressions'] ?? 0)); ?></dd></div>
                    <div><dt>Likes</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['likes'] ?? 0)); ?></dd></div>
                    <div><dt>Replies</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['replies_received'] ?? 0)); ?></dd></div>
                    <div><dt>Bookmarks</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['bookmarks'] ?? 0)); ?></dd></div>
                </dl>
            </article>
            <article class="xra-surface" aria-labelledby="xra-topics-title">
                <div class="xra-section-head">
                    <div><h2 id="xra-topics-title">Patterns</h2></div>
                </div>
                <div class="xra-card-stack">
                    <?php foreach ([
                        'Top Topics' => (array) ($analytics['top_topics'] ?? []),
                        'Top Tones' => (array) ($analytics['top_tones'] ?? []),
                        'Top Personas' => (array) ($analytics['top_personas'] ?? []),
                    ] as $label => $items) : ?>
                        <article class="xra-mini-stat">
                            <div>
                                <strong><?php echo esc_html($label); ?></strong>
                                <span><?php echo esc_html(implode(', ', array_map(static fn (array $entry): string => (string) ($entry['label'] ?? '') . ' (' . (string) ($entry['count'] ?? 0) . ')', $items))); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="xra-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('xra_export_csv'); ?>
                        <input type="hidden" name="action" value="xra_export_csv">
                        <input type="hidden" name="type" value="posts">
                        <button class="xra-button" type="submit">Export CSV</button>
                    </form>
                </div>
            </article>
        </section>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface" aria-labelledby="xra-recent-metrics-title">
                <div class="xra-section-head"><div><h2 id="xra-recent-metrics-title">Metrics</h2></div></div>
                <ul class="xra-list">
                    <?php foreach ($metrics as $metric) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($metric['audience_category'] ?? 'Audience')); ?></strong>
                            <span><?php echo esc_html((string) ($metric['impressions'] ?? 0)); ?> impressions</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <article class="xra-surface" aria-labelledby="xra-ai-request-title">
                <div class="xra-section-head"><div><h2 id="xra-ai-request-title">Requests</h2></div></div>
                <ul class="xra-list">
                    <?php foreach ($recentAIRequests as $request) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($request['stage'] ?? '')); ?></strong>
                            <span><?php echo esc_html((string) ($request['provider'] ?? '')); ?> | <?php echo esc_html((string) ($request['status'] ?? '')); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
        </section>
    <?php endif; ?>
</div>
