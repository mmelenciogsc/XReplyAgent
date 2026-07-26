<?php

declare(strict_types=1);

use XReplyAgent\Domain\BrowserAutomation;
use XReplyAgent\PublicApp\Controller as PublicController;
use XReplyAgent\Storage\Store;
use XReplyAgent\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$screenMapAdmin = [
    'overview' => 'Overview',
    'walkthroughs' => 'Walkthroughs',
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
$screen = isset($screen) && array_key_exists($screen, $screenMapAdmin) ? $screen : 'overview';
$notice = isset($_GET['xra_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['xra_notice'])) : '';
$settingsNonce = wp_create_nonce('xra_save_settings');
$workflowAction = admin_url('admin-post.php');
$currentReplyCandidates = (array) ($currentCandidates ?? []);
$browserPanel = BrowserAutomation::getJobPanel((int) ($currentReplySet['id'] ?? 0));
$currentBrowserJob = (array) ($browserPanel['job'] ?? []);
$currentBrowserEvents = (array) ($browserPanel['events'] ?? []);
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
?>
<div class="xra-shell-surface xra-shell-surface--admin">
    <header class="xra-workspace-head" aria-labelledby="xra-workspace-title">
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
                <h2 id="xra-workspace-title">XReplyAgent</h2>
            </div>
        </div>
        <div class="xra-workspace-actions">
            <a class="xra-button" href="<?php echo esc_url(PublicController::viewUrl('app')); ?>">Public</a>
            <a class="xra-button xra-button--secondary" href="<?php echo esc_url(PublicController::viewUrl('auth')); ?>">Sign In</a>
            <button class="xra-button xra-button--secondary" type="button" data-xra-fullscreen>Fullscreen</button>
        </div>
    </header>

    <nav class="xra-screen-nav" aria-label="Admin Screens">
        <?php foreach ($screenMapAdmin as $key => $label) : ?>
            <a class="xra-screen-nav__link <?php echo $screen === $key ? 'is-active' : ''; ?>" <?php echo $screen === $key ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url(PublicController::viewUrl('admin', ['xra_screen' => $key])); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($notice !== '') : ?>
        <section class="xra-notice" role="status" aria-live="polite"><?php echo esc_html(ucwords(str_replace('_', ' ', $notice))); ?></section>
    <?php endif; ?>

    <section class="xra-metrics-grid" aria-label="Summary">
        <article class="xra-stat-card"><span>Posts</span><strong><?php echo esc_html((string) ($summary['posts'] ?? 0)); ?></strong></article>
        <article class="xra-stat-card"><span>Analyses</span><strong><?php echo esc_html((string) ($summary['analyses'] ?? 0)); ?></strong></article>
        <article class="xra-stat-card"><span>Reply Sets</span><strong><?php echo esc_html((string) ($summary['reply_sets'] ?? 0)); ?></strong></article>
        <article class="xra-stat-card"><span>Approved</span><strong><?php echo esc_html((string) ($summary['approved_candidates'] ?? 0)); ?></strong></article>
        <article class="xra-stat-card"><span>Published</span><strong><?php echo esc_html((string) ($summary['published_candidates'] ?? 0)); ?></strong></article>
        <article class="xra-stat-card"><span>Metrics</span><strong><?php echo esc_html((string) ($summary['metrics'] ?? 0)); ?></strong></article>
        <article class="xra-stat-card"><span>Audit</span><strong><?php echo esc_html((string) ($summary['audit_events'] ?? 0)); ?></strong></article>
        <article class="xra-stat-card"><span>Errors</span><strong><?php echo esc_html((string) ($summary['error_events'] ?? 0)); ?></strong></article>
    </section>

    <?php if ($screen === 'overview') : ?>
        <section class="xra-grid xra-grid--two xra-grid--overview">
            <article class="xra-surface xra-surface--hero" aria-labelledby="xra-admin-overview-title">
                <div class="xra-section-head">
                    <div><h3 id="xra-admin-overview-title">Overview</h3></div>
                    <span class="xra-chip"><?php echo esc_html((string) ($provider['model'] ?? 'gpt-4o-mini')); ?></span>
                </div>
                <dl class="xra-spec-list">
                    <div><dt>Provider</dt><dd><?php echo esc_html((string) ($provider['provider'] ?? 'mock')); ?></dd></div>
                    <div><dt>Mock Mode</dt><dd><?php echo !empty($provider['mock_mode']) ? 'On' : 'Off'; ?></dd></div>
                    <div><dt>Active Persona</dt><dd><?php echo esc_html((string) ($activePersona['name'] ?? 'Persona')); ?></dd></div>
                    <div><dt>Active Analysis Prompt</dt><dd><?php echo esc_html((string) ($activePrompts['analysis']['name'] ?? 'Analysis Prompt')); ?></dd></div>
                </dl>
            </article>
            <article class="xra-surface" aria-labelledby="xra-admin-queue-title">
                <div class="xra-section-head">
                    <div><h3 id="xra-admin-queue-title">Queue Health</h3></div>
                </div>
                <ul class="xra-list">
                    <li><strong>Review Queue</strong><span><?php echo esc_html((string) count((array) $replySets)); ?></span></li>
                    <li><strong>Recent Errors</strong><span><?php echo esc_html((string) count((array) $errors)); ?></span></li>
                    <li><strong>Recent AI Requests</strong><span><?php echo esc_html((string) count((array) $recentAIRequests)); ?></span></li>
                    <li><strong>Latest Reply Set</strong><span><?php echo esc_html((string) ($currentReplySet['status'] ?? 'none')); ?></span></li>
                    <li><strong>Browser Session</strong><span><?php echo esc_html((string) ($currentBrowserJob['status'] ?? 'idle')); ?></span></li>
                </ul>
            </article>
        </section>
        <section class="xra-grid xra-grid--two xra-grid--control">
            <article class="xra-surface" aria-labelledby="xra-admin-current-post-title">
                <div class="xra-section-head"><div><h3 id="xra-admin-current-post-title">Post</h3></div></div>
                <p class="xra-lede"><?php echo esc_html((string) ($currentPost['post_text'] ?? '')); ?></p>
                <dl class="xra-spec-list">
                    <div><dt>Source</dt><dd><?php echo !empty($currentPost['source_url']) ? $renderLink((string) $currentPost['source_url'], 'Open Source') : esc_html('Browser Capture'); ?></dd></div>
                    <div><dt>Topic</dt><dd><?php echo esc_html((string) ($currentAnalysis['main_topic'] ?? 'Pending')); ?></dd></div>
                    <div><dt>Tone</dt><dd><?php echo esc_html((string) ($currentAnalysis['tone'] ?? 'Pending')); ?></dd></div>
                    <div><dt>Intent</dt><dd><?php echo esc_html((string) ($currentAnalysis['likely_intent'] ?? 'Pending')); ?></dd></div>
                </dl>
            </article>
            <article class="xra-surface" aria-labelledby="xra-admin-current-replies-title">
                <div class="xra-section-head"><div><h3 id="xra-admin-current-replies-title">Replies</h3></div></div>
                <ul class="xra-list">
                    <?php foreach ($currentReplyCandidates as $candidate) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($candidate['approach_label'] ?? 'Reply')); ?></strong>
                            <span><?php echo esc_html((string) ($candidate['reply_text'] ?? '')); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
        </section>
    <?php elseif ($screen === 'walkthroughs') : ?>
        <?php include XRA_DIR . 'views/walkthrough-panel.php'; ?>
    <?php elseif ($screen === 'analyze') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface xra-surface--accent" aria-labelledby="xra-admin-analyze-title">
                <div class="xra-section-head">
                    <div><h3 id="xra-admin-analyze-title">Analyze</h3></div>
                    <span class="xra-chip"><?php echo !empty($provider['mock_mode']) ? 'Mock Mode' : 'Live Provider'; ?></span>
                </div>
                <form class="xra-form" data-xra-query-form data-xra-workflow-form method="post" action="<?php echo esc_url($workflowAction); ?>">
                    <?php wp_nonce_field('xra_run_workflow'); ?>
                    <input type="hidden" name="action" value="xra_run_workflow">
                    <label><span>Post Text</span><textarea name="post_text" rows="8" placeholder="Post text."></textarea></label>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Source URL</span><input type="url" name="source_url" placeholder="https://..."></label>
                        <label><span>Desired Objective</span><input type="text" name="desired_objective" placeholder="Primary objective"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Author Handle</span><input type="text" name="author_handle" placeholder="@handle"></label>
                        <label><span>Author Name</span><input type="text" name="author_name" placeholder="Name"></label>
                    </div>
                    <label><span>Context</span><textarea name="context_text" rows="4" placeholder="Context."></textarea></label>
                    <label><span>Persona</span>
                        <select name="persona_id">
                            <?php foreach ($personas as $persona) : ?>
                                <option value="<?php echo esc_attr((string) ($persona['id'] ?? 0)); ?>" <?php selected((int) ($persona['id'] ?? 0), (int) ($activePersona['id'] ?? 0)); ?>><?php echo esc_html((string) ($persona['name'] ?? 'Persona')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="xra-actions">
                        <button class="xra-button" type="submit">Run Workflow</button>
                        <button class="xra-button xra-button--secondary" type="button" data-xra-fullscreen>Fullscreen</button>
                    </div>
                </form>
            </article>
            <aside class="xra-surface" aria-labelledby="xra-admin-analysis-title">
                <div class="xra-section-head">
                    <div><h3 id="xra-admin-analysis-title">Result</h3></div>
                    <span class="xra-chip"><?php echo esc_html((string) ($provider['model'] ?? 'gpt-4o-mini')); ?></span>
                </div>
                <div class="xra-analysis-panel" data-xra-response role="status" aria-live="polite" aria-atomic="true">
                    <div class="xra-live-region" data-xra-live-region aria-live="polite" aria-atomic="true"></div>
                    <pre class="xra-analysis-plain" data-xra-analysis-output><?php echo esc_html("Topic: " . (string) ($currentAnalysis['main_topic'] ?? 'Pending') . "\nTone: " . (string) ($currentAnalysis['tone'] ?? 'Pending') . "\nReplies: " . (string) count($currentReplyCandidates)); ?></pre>
                </div>
                <div class="xra-card-stack">
                    <?php foreach ($currentReplyCandidates as $candidate) : ?>
                        <article class="xra-mini-stat">
                            <div>
                                <strong><?php echo esc_html((string) ($candidate['approach_label'] ?? 'Reply')); ?></strong>
                                <span><?php echo esc_html((string) ($candidate['short_rationale'] ?? '')); ?></span>
                            </div>
                            <b><?php echo esc_html($scoreLabel($candidate['total_score'] ?? 0)); ?></b>
                        </article>
                    <?php endforeach; ?>
                </div>
            </aside>
        </section>
    <?php elseif ($screen === 'review-queue') : ?>
        <section class="xra-surface" aria-labelledby="xra-review-title">
            <div class="xra-section-head">
                <div><h3 id="xra-review-title">Review Queue</h3></div>
                </div>
            <?php foreach ($replySets as $replySet) : ?>
                <?php $candidates = Store::listReplyCandidates((int) ($replySet['id'] ?? 0)); ?>
                <details class="xra-details" <?php echo ((int) ($replySet['id'] ?? 0) === (int) ($currentReplySet['id'] ?? 0)) ? 'open' : ''; ?>>
                    <summary>
                        <strong><?php echo esc_html((string) ($replySet['main_topic'] ?? 'Reply Set')); ?></strong>
                        <span><?php echo esc_html((string) ($replySet['status'] ?? '')); ?></span>
                    </summary>
                    <div class="xra-details__body">
                        <p class="xra-lede"><?php echo esc_html((string) ($replySet['post_text'] ?? '')); ?></p>
                        <div class="xra-card-stack">
                            <?php foreach ($candidates as $candidate) : ?>
                                <article class="xra-surface xra-surface--compact">
                                    <div class="xra-section-head">
                                        <div>
                                            <h4><?php echo esc_html((string) ($candidate['approach_label'] ?? 'Reply')); ?></h4>
                                        </div>
                                        <span class="xra-chip"><?php echo esc_html($scoreLabel($candidate['total_score'] ?? 0)); ?></span>
                                    </div>
                                    <p><?php echo esc_html((string) ($candidate['reply_text'] ?? '')); ?></p>
                                    <?php
                                    $reviewRationale = trim((string) ($candidate['short_rationale'] ?? ''));
                                    if ($reviewRationale === '' || stripos($reviewRationale, 'fallback candidate') !== false) {
                                        $reviewRationale = 'Ranked reply option.';
                                    }
                                    ?>
                                    <p class="xra-note"><?php echo esc_html($reviewRationale); ?></p>
                                    <form class="xra-form" method="post" action="<?php echo esc_url($workflowAction); ?>">
                                        <?php wp_nonce_field('xra_candidate_action'); ?>
                                        <input type="hidden" name="action" value="xra_candidate_action">
                                        <input type="hidden" name="reply_set_id" value="<?php echo esc_attr((string) ($replySet['id'] ?? 0)); ?>">
                                        <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) ($candidate['id'] ?? 0)); ?>">
                                        <label><span>Edited Text</span><textarea name="edited_text" rows="3"><?php echo esc_textarea((string) ($candidate['edited_text'] ?? $candidate['reply_text'] ?? '')); ?></textarea></label>
                                        <label><span>Reviewer Notes</span><textarea name="notes" rows="2"><?php echo esc_textarea((string) ($candidate['reviewer_notes'] ?? '')); ?></textarea></label>
                                        <div class="xra-actions">
                                            <button class="xra-button xra-button--secondary" type="submit" name="candidate_action" value="save">Save</button>
                                            <button class="xra-button" type="submit" name="candidate_action" value="approve">Approve</button>
                                            <button class="xra-button xra-button--secondary" type="submit" name="candidate_action" value="reject">Reject</button>
                                            <button class="xra-button xra-button--secondary" type="submit" name="candidate_action" value="publish">Mark For Publishing</button>
                                            <button class="xra-button xra-button--secondary" type="button" data-xra-copy data-xra-copy-value="<?php echo esc_attr((string) ($candidate['edited_text'] ?? $candidate['reply_text'] ?? '')); ?>">Copy</button>
                                        </div>
                                    </form>
                                    <?php
                                        $readyForGo = (string) ($candidate['status'] ?? '') === 'for_publishing';
                                        $hasSourceUrl = trim((string) ($replySet['source_url'] ?? '')) !== '';
                                    ?>
                                    <form class="xra-form xra-form--inline" method="post" action="<?php echo esc_url($workflowAction); ?>">
                                        <?php wp_nonce_field('xra_browser_go'); ?>
                                        <input type="hidden" name="action" value="xra_browser_go">
                                        <input type="hidden" name="reply_set_id" value="<?php echo esc_attr((string) ($replySet['id'] ?? 0)); ?>">
                                        <input type="hidden" name="candidate_id" value="<?php echo esc_attr((string) ($candidate['id'] ?? 0)); ?>">
                                        <button class="xra-button" type="submit" <?php disabled(!$readyForGo || !$hasSourceUrl); ?>>GO</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
            <article class="xra-surface xra-browser-panel" data-xra-browser-panel data-reply-set-id="<?php echo esc_attr((string) ($currentReplySet['id'] ?? 0)); ?>">
                <div class="xra-section-head">
                    <div><h3>Browser</h3></div>
                    <span class="xra-chip" data-xra-browser-field="status"><?php echo esc_html((string) ($currentBrowserJob['status'] ?? 'idle')); ?></span>
                </div>
                <?php if ($currentBrowserJob !== []) : ?>
                    <div class="xra-spec-list">
                        <div><dt>Phase</dt><dd data-xra-browser-field="phase"><?php echo esc_html((string) ($currentBrowserJob['phase'] ?? '')); ?></dd></div>
                        <div><dt>Step</dt><dd data-xra-browser-field="step"><?php echo esc_html((string) ($currentBrowserJob['current_step'] ?? '')); ?></dd></div>
                        <div><dt>Target URL</dt><dd data-xra-browser-field="target_url"><?php echo !empty($currentBrowserJob['target_url']) ? $renderLink((string) $currentBrowserJob['target_url'], 'Open Source') : esc_html('Pending'); ?></dd></div>
                        <div><dt>Published URL</dt><dd data-xra-browser-field="published_url"><?php echo !empty($currentBrowserJob['published_url']) ? $renderLink((string) $currentBrowserJob['published_url'], 'Open Reply') : esc_html('Pending'); ?></dd></div>
                        <div><dt>Completed At</dt><dd data-xra-browser-field="completed_at"><?php echo esc_html((string) ($currentBrowserJob['completed_at'] ?? '')); ?></dd></div>
                    </div>
                    <div class="xra-browser-preview">
                        <?php if (!empty($currentBrowserJob['latest_screenshot_url'])) : ?>
                            <img data-xra-browser-screenshot src="<?php echo esc_url((string) ($currentBrowserJob['latest_screenshot_url'] ?? '')); ?>" alt="Latest browser screenshot">
                        <?php else : ?>
                            <div class="xra-browser-placeholder">Waiting for the first screenshot.</div>
                        <?php endif; ?>
                    </div>
                    <ul class="xra-list" data-xra-browser-events>
                        <?php foreach ($currentBrowserEvents as $event) : ?>
                            <li>
                                <strong><?php echo esc_html((string) ($event['event_type'] ?? 'event')); ?></strong>
                                <span><?php echo esc_html((string) ($event['message'] ?? '')); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="xra-actions">
                        <?php if (!empty($browserPanel['can_pause'])) : ?>
                            <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                                <?php wp_nonce_field('xra_browser_job_action'); ?>
                                <input type="hidden" name="action" value="xra_browser_job_action">
                                <input type="hidden" name="browser_job_id" value="<?php echo esc_attr((string) ($currentBrowserJob['id'] ?? 0)); ?>">
                                <input type="hidden" name="reply_set_id" value="<?php echo esc_attr((string) ($currentReplySet['id'] ?? 0)); ?>">
                                <button class="xra-button xra-button--secondary" type="submit" name="browser_job_action" value="pause">Pause</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($browserPanel['can_resume'])) : ?>
                            <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                                <?php wp_nonce_field('xra_browser_job_action'); ?>
                                <input type="hidden" name="action" value="xra_browser_job_action">
                                <input type="hidden" name="browser_job_id" value="<?php echo esc_attr((string) ($currentBrowserJob['id'] ?? 0)); ?>">
                                <input type="hidden" name="reply_set_id" value="<?php echo esc_attr((string) ($currentReplySet['id'] ?? 0)); ?>">
                                <button class="xra-button" type="submit" name="browser_job_action" value="resume">Resume</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($browserPanel['can_stop'])) : ?>
                            <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                                <?php wp_nonce_field('xra_browser_job_action'); ?>
                                <input type="hidden" name="action" value="xra_browser_job_action">
                                <input type="hidden" name="browser_job_id" value="<?php echo esc_attr((string) ($currentBrowserJob['id'] ?? 0)); ?>">
                                <input type="hidden" name="reply_set_id" value="<?php echo esc_attr((string) ($currentReplySet['id'] ?? 0)); ?>">
                                <button class="xra-button xra-button--secondary" type="submit" name="browser_job_action" value="stop">Stop</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <p class="xra-lede"></p>
                <?php endif; ?>
            </article>
        </section>
    <?php elseif ($screen === 'history') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>History</h3></div></div>
                <form class="xra-form xra-form--inline" method="get" action="<?php echo esc_url(PublicController::viewUrl('admin')); ?>">
                    <input type="hidden" name="xra_view" value="admin">
                    <input type="hidden" name="xra_screen" value="history">
                    <label><span>Search</span><input type="search" name="xra_search" value="<?php echo esc_attr((string) ($_GET['xra_search'] ?? '')); ?>"></label>
                    <label><span>Status</span><input type="text" name="xra_status" value="<?php echo esc_attr((string) ($_GET['xra_status'] ?? '')); ?>"></label>
                    <label><span>Persona</span>
                        <select name="xra_persona_id">
                            <option value="0">All</option>
                            <?php foreach ($personas as $persona) : ?>
                                <option value="<?php echo esc_attr((string) ($persona['id'] ?? 0)); ?>" <?php selected((int) ($_GET['xra_persona_id'] ?? 0), (int) ($persona['id'] ?? 0)); ?>><?php echo esc_html((string) ($persona['name'] ?? 'Persona')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="xra-button" type="submit">Filter</button>
                </form>
                <div class="xra-table-wrap">
                    <table class="xra-table">
                        <thead><tr><th scope="col">Post</th><th scope="col">Topic</th><th scope="col">Status</th><th scope="col">Created</th></tr></thead>
                        <tbody>
                            <?php foreach ($posts as $post) : ?>
                                <tr>
                                    <td><?php echo esc_html(mb_substr((string) ($post['post_text'] ?? ''), 0, 90)); ?></td>
                                    <td><?php echo esc_html((string) ($post['main_topic'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($post['status'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($post['created_at'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Recent Reply Sets</h3></div></div>
                <ul class="xra-list">
                    <?php foreach ($recentReplySets as $replySet) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($replySet['main_topic'] ?? 'Reply Set')); ?></strong>
                            <span><?php echo esc_html((string) ($replySet['status'] ?? '')); ?> | <?php echo esc_html((string) ($replySet['candidate_count'] ?? 0)); ?> candidates</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
        </section>
    <?php elseif ($screen === 'analytics') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Analytics</h3></div></div>
                <dl class="xra-spec-list">
                    <div><dt>Impressions</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['impressions'] ?? 0)); ?></dd></div>
                    <div><dt>Likes</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['likes'] ?? 0)); ?></dd></div>
                    <div><dt>Replies</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['replies_received'] ?? 0)); ?></dd></div>
                    <div><dt>Profile Visits</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['profile_visits'] ?? 0)); ?></dd></div>
                    <div><dt>Follows</dt><dd><?php echo esc_html((string) ($metricsSummary['totals']['follows'] ?? 0)); ?></dd></div>
                </dl>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Top Patterns</h3></div></div>
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
                <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                    <?php wp_nonce_field('xra_save_metric'); ?>
                    <input type="hidden" name="action" value="xra_save_metric">
                    <label><span>Audience Category</span><input type="text" name="audience_category" placeholder="Primary Audience"></label>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Impressions</span><input type="number" name="impressions" min="0" value="0"></label>
                        <label><span>Likes</span><input type="number" name="likes" min="0" value="0"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Replies</span><input type="number" name="replies_received" min="0" value="0"></label>
                        <label><span>Reposts</span><input type="number" name="reposts" min="0" value="0"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Bookmarks</span><input type="number" name="bookmarks" min="0" value="0"></label>
                        <label><span>Profile Visits</span><input type="number" name="profile_visits" min="0" value="0"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Follows</span><input type="number" name="follows" min="0" value="0"></label>
                        <label><span>Measurement Datetime</span><input type="datetime-local" name="measurement_datetime"></label>
                    </div>
                    <label><span>Notes</span><textarea name="notes" rows="3"></textarea></label>
                    <div class="xra-actions"><button class="xra-button" type="submit">Save Metric</button></div>
                </form>
            </article>
        </section>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Recent Metrics</h3></div></div>
                <ul class="xra-list">
                    <?php foreach ($metrics as $metric) : ?>
                        <li><strong><?php echo esc_html((string) ($metric['audience_category'] ?? 'Audience')); ?></strong><span><?php echo esc_html((string) ($metric['impressions'] ?? 0)); ?> impressions</span></li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Recent AI Requests</h3></div></div>
                <ul class="xra-list">
                    <?php foreach ($recentAIRequests as $request) : ?>
                        <li><strong><?php echo esc_html((string) ($request['stage'] ?? '')); ?></strong><span><?php echo esc_html((string) ($request['provider'] ?? '')); ?> | <?php echo esc_html((string) ($request['status'] ?? '')); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </article>
        </section>
        <div class="xra-actions">
            <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                <?php wp_nonce_field('xra_export_csv'); ?>
                <input type="hidden" name="action" value="xra_export_csv">
                <input type="hidden" name="type" value="posts">
                <button class="xra-button" type="submit">Export CSV</button>
            </form>
        </div>
    <?php elseif ($screen === 'personas') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Editor</h3></div></div>
                <form class="xra-form" method="post" action="<?php echo esc_url($workflowAction); ?>">
                    <?php wp_nonce_field('xra_save_persona'); ?>
                    <input type="hidden" name="action" value="xra_save_persona">
                    <input type="hidden" name="id" value="<?php echo esc_attr((string) ($activePersona['id'] ?? 0)); ?>">
                    <label><span>Slug</span><input type="text" name="slug" value="<?php echo esc_attr((string) ($activePersona['slug'] ?? '')); ?>"></label>
                    <label><span>Name</span><input type="text" name="name" value="<?php echo esc_attr((string) ($activePersona['name'] ?? '')); ?>"></label>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Tone</span><input type="text" name="tone" value="<?php echo esc_attr((string) ($activePersona['tone'] ?? 'measured')); ?>"></label>
                        <label><span>Voice</span><input type="text" name="voice" value="<?php echo esc_attr((string) ($activePersona['voice'] ?? 'deadpan')); ?>"></label>
                    </div>
                    <label><span>Description</span><textarea name="description" rows="2"><?php echo esc_textarea((string) ($activePersona['description'] ?? '')); ?></textarea></label>
                    <label><span>Behavior Guidance</span><textarea name="instructions" rows="6"><?php echo esc_textarea((string) ($activePersona['instructions'] ?? '')); ?></textarea></label>
                    <label><span>Guardrails JSON</span><textarea name="guardrails_json" rows="3"><?php echo esc_textarea(is_array($activePersona['guardrails_json'] ?? null) ? wp_json_encode($activePersona['guardrails_json']) : (string) ($activePersona['guardrails_json'] ?? '')); ?></textarea></label>
                    <label><input type="checkbox" name="active" value="1" <?php checked((int) ($activePersona['active'] ?? 0), 1); ?>> Active Persona</label>
                    <div class="xra-actions"><button class="xra-button" type="submit">Save Persona</button></div>
                </form>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Personas</h3></div></div>
                <?php foreach ($personas as $persona) : ?>
                    <article class="xra-mini-stat">
                        <div>
                            <strong><?php echo esc_html((string) ($persona['name'] ?? 'Persona')); ?></strong>
                            <span><?php echo esc_html((string) ($persona['tone'] ?? '')); ?> | <?php echo !empty($persona['active']) ? 'Active' : 'Inactive'; ?></span>
                        </div>
                        <div class="xra-actions">
                            <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                                <?php wp_nonce_field('xra_persona_action'); ?>
                                <input type="hidden" name="action" value="xra_persona_action">
                                <input type="hidden" name="persona_id" value="<?php echo esc_attr((string) ($persona['id'] ?? 0)); ?>">
                                <button class="xra-button xra-button--secondary" type="submit" name="persona_action" value="activate">Activate</button>
                                <button class="xra-button xra-button--secondary" type="submit" name="persona_action" value="duplicate">Duplicate</button>
                                <button class="xra-button xra-button--secondary" type="submit" name="persona_action" value="delete">Delete</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </article>
        </section>
    <?php elseif ($screen === 'prompts') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Prompts</h3></div></div>
                <form class="xra-form" method="post" action="<?php echo esc_url($workflowAction); ?>">
                    <?php wp_nonce_field('xra_save_prompt'); ?>
                    <input type="hidden" name="action" value="xra_save_prompt">
                    <label><span>Stage</span>
                        <select name="stage">
                            <?php foreach (['analysis' => 'Analysis', 'generation' => 'Generation', 'scoring' => 'Scoring', 'recommendations' => 'Recommendations'] as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>Slug</span><input type="text" name="slug" value=""></label>
                    <label><span>Name</span><input type="text" name="name" value=""></label>
                    <label><span>Description</span><textarea name="description" rows="2"></textarea></label>
                    <label><span>System Prompt</span><textarea name="system_prompt" rows="5"></textarea></label>
                    <label><span>User Prompt</span><textarea name="user_prompt" rows="5"></textarea></label>
                    <label><span>Response Schema</span><textarea name="response_schema" rows="8"></textarea></label>
                    <label><input type="checkbox" name="active" value="1" checked> Active</label>
                    <label><input type="checkbox" name="draft" value="1"> Draft</label>
                    <div class="xra-actions"><button class="xra-button" type="submit">Save Prompt</button></div>
                </form>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Prompts</h3></div></div>
                <?php foreach ($prompts as $prompt) : ?>
                    <article class="xra-mini-stat">
                        <div>
                            <strong><?php echo esc_html((string) ($prompt['name'] ?? 'Prompt')); ?></strong>
                            <span><?php echo esc_html((string) ($prompt['stage'] ?? '')); ?> | <?php echo !empty($prompt['active']) ? 'Active' : 'Inactive'; ?></span>
                        </div>
                        <div class="xra-actions">
                            <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                                <?php wp_nonce_field('xra_prompt_action'); ?>
                                <input type="hidden" name="action" value="xra_prompt_action">
                                <input type="hidden" name="prompt_id" value="<?php echo esc_attr((string) ($prompt['id'] ?? 0)); ?>">
                                <button class="xra-button xra-button--secondary" type="submit" name="prompt_action" value="activate">Activate</button>
                                <button class="xra-button xra-button--secondary" type="submit" name="prompt_action" value="duplicate">Duplicate</button>
                                <button class="xra-button xra-button--secondary" type="submit" name="prompt_action" value="delete">Delete</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </article>
        </section>
    <?php elseif ($screen === 'settings') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Settings</h3></div></div>
                <form class="xra-form" method="post" action="<?php echo esc_url($workflowAction); ?>">
                    <?php wp_nonce_field('xra_save_settings'); ?>
                    <input type="hidden" name="action" value="xra_save_settings">
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Provider</span><input type="text" name="<?php echo esc_attr(Settings::OPTION_PROVIDER); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_PROVIDER, 'openai')); ?>"></label>
                        <label><span>Model</span><input type="text" name="<?php echo esc_attr(Settings::OPTION_MODEL); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_MODEL, 'gpt-4o-mini')); ?>"></label>
                    </div>
                    <label><span>Endpoint</span><input type="url" name="<?php echo esc_attr(Settings::OPTION_ENDPOINT); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_ENDPOINT, 'https://api.openai.com/v1/responses')); ?>"></label>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>API Key</span><input type="password" name="<?php echo esc_attr(Settings::OPTION_API_KEY); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_API_KEY, '')); ?>"></label>
                        <label><span>API Key File</span><input type="text" name="<?php echo esc_attr(Settings::OPTION_API_KEY_FILE); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_API_KEY_FILE, '/path/to/private-api-key.txt')); ?>"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Temperature</span><input type="number" step="0.1" min="0" max="2" name="<?php echo esc_attr(Settings::OPTION_TEMPERATURE); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_TEMPERATURE, '0.2')); ?>"></label>
                        <label><span>Daily AI Limit</span><input type="number" min="1" name="<?php echo esc_attr(Settings::OPTION_DAILY_LIMIT); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_DAILY_LIMIT, '100')); ?>"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Max Post Characters</span><input type="number" min="500" name="<?php echo esc_attr(Settings::OPTION_MAX_POST_CHARS); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_MAX_POST_CHARS, '8000')); ?>"></label>
                        <label><span>Max Reply Characters</span><input type="number" min="80" name="<?php echo esc_attr(Settings::OPTION_MAX_REPLY_CHARS); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_MAX_REPLY_CHARS, '280')); ?>"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Retention Days</span><input type="number" min="1" name="<?php echo esc_attr(Settings::OPTION_RETENTION_DAYS); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_RETENTION_DAYS, '365')); ?>"></label>
                        <label><span>Cost Per 1K Prompt Tokens</span><input type="number" step="0.0001" min="0" name="<?php echo esc_attr(Settings::OPTION_COST_PROMPT); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_COST_PROMPT, '0')); ?>"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Cost Per 1K Completion Tokens</span><input type="number" step="0.0001" min="0" name="<?php echo esc_attr(Settings::OPTION_COST_COMPLETION); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_COST_COMPLETION, '0')); ?>"></label>
                        <label><span>Default Tone</span><input type="text" name="<?php echo esc_attr(Settings::OPTION_DEFAULT_TONE); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_DEFAULT_TONE, 'measured')); ?>"></label>
                    </div>
                    <label><input type="hidden" name="<?php echo esc_attr(Settings::OPTION_MOCK_MODE); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_MOCK_MODE); ?>" value="1" <?php checked((string) get_option(Settings::OPTION_MOCK_MODE, '0'), '1'); ?>> Mock Mode</label>
                    <label><input type="hidden" name="<?php echo esc_attr(Settings::OPTION_RETAIN_ON_UNINSTALL); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_RETAIN_ON_UNINSTALL); ?>" value="1" <?php checked((string) get_option(Settings::OPTION_RETAIN_ON_UNINSTALL, '1'), '1'); ?>> Retain Data On Uninstall</label>
                    <label><input type="hidden" name="<?php echo esc_attr(Settings::OPTION_PUBLIC_ACCESS); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_PUBLIC_ACCESS); ?>" value="1" <?php checked((string) get_option(Settings::OPTION_PUBLIC_ACCESS, '1'), '1'); ?>> Public Access</label>
                    <label><input type="hidden" name="<?php echo esc_attr(Settings::OPTION_BROWSER_ENABLED); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION_BROWSER_ENABLED); ?>" value="1" <?php checked((string) get_option(Settings::OPTION_BROWSER_ENABLED, '1'), '1'); ?>> Browser Automation Enabled</label>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Browser Profile Directory</span><input type="text" name="<?php echo esc_attr(Settings::OPTION_BROWSER_PROFILE_DIR); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_BROWSER_PROFILE_DIR, '')); ?>" placeholder="/home/marx/..."></label>
                        <label><span>Browser Storage State</span><input type="text" name="<?php echo esc_attr(Settings::OPTION_BROWSER_STORAGE_STATE); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_BROWSER_STORAGE_STATE, '')); ?>" placeholder="/home/marx/.../state.json"></label>
                    </div>
                    <div class="xra-grid xra-grid--two xra-grid--compact">
                        <label><span>Monitor Interval Seconds</span><input type="number" min="5" name="<?php echo esc_attr(Settings::OPTION_BROWSER_MONITOR_INTERVAL); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_BROWSER_MONITOR_INTERVAL, '20')); ?>"></label>
                        <label><span>Monitor Cycles</span><input type="number" min="1" name="<?php echo esc_attr(Settings::OPTION_BROWSER_MONITOR_CYCLES); ?>" value="<?php echo esc_attr((string) get_option(Settings::OPTION_BROWSER_MONITOR_CYCLES, '3')); ?>"></label>
                    </div>
                    <div class="xra-actions">
                        <button class="xra-button" type="submit">Save Settings</button>
                        <button class="xra-button xra-button--secondary" type="button" data-xra-test-connection>Refresh Status</button>
                    </div>
                </form>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Tools</h3></div></div>
                <div class="xra-actions">
                    <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                        <?php wp_nonce_field('xra_seed_demo'); ?>
                        <input type="hidden" name="action" value="xra_seed_demo">
                        <button class="xra-button xra-button--secondary" type="submit">Load Starter Content</button>
                    </form>
                    <form method="post" action="<?php echo esc_url($workflowAction); ?>">
                        <?php wp_nonce_field('xra_reset_data'); ?>
                        <input type="hidden" name="action" value="xra_reset_data">
                        <button class="xra-button xra-button--secondary" type="submit">Reset Workspace</button>
                    </form>
                </div>
                <div class="xra-analysis-panel" data-xra-connection-status aria-live="polite" role="status">
                    <pre class="xra-analysis-plain">Ready.</pre>
                </div>
            </article>
        </section>
    <?php elseif ($screen === 'audit') : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Audit Log</h3></div></div>
                <ul class="xra-list">
                    <?php foreach ($audit as $entry) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($entry['action'] ?? '')); ?></strong>
                            <span><?php echo esc_html((string) ($entry['object_type'] ?? '')); ?> | <?php echo esc_html((string) ($entry['message'] ?? '')); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Recent AI Requests</h3></div></div>
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
    <?php else : ?>
        <section class="xra-grid xra-grid--two">
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Error Log</h3></div></div>
                <ul class="xra-list">
                    <?php foreach ($errors as $entry) : ?>
                        <li>
                            <strong><?php echo esc_html((string) ($entry['error_code'] ?? '')); ?></strong>
                            <span><?php echo esc_html((string) ($entry['message'] ?? '')); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <article class="xra-surface">
                <div class="xra-section-head"><div><h3>Recent AI Requests</h3></div></div>
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
