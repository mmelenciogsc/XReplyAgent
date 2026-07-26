<?php

declare(strict_types=1);

use XReplyAgent\PublicApp\Controller as PublicController;

if (!defined('ABSPATH')) {
    exit;
}

$notice = isset($_GET['xra_notice']) ? sanitize_key(wp_unslash((string) $_GET['xra_notice'])) : '';
$noticeMap = [
    'sign_in_failed' => 'Sign in failed.',
    'create_account_failed' => 'Account creation failed.',
    'registration_closed' => 'Registration is closed.',
];
$currentUser = wp_get_current_user();
$signedIn = is_user_logged_in() && $currentUser instanceof WP_User;
?>
<section class="xra-auth-shell" aria-labelledby="xra-auth-title">
    <div class="xra-auth-grid">
        <article class="xra-surface xra-surface--hero xra-auth-panel xra-auth-panel--intro">
            <div class="xra-section-head">
                <div>
                    <h2 id="xra-auth-title"><?php echo $signedIn ? 'Account' : 'Sign In'; ?></h2>
                </div>
            </div>

            <?php if ($notice !== '') : ?>
                <p class="xra-notice xra-auth-notice" role="status" aria-live="polite"><?php echo esc_html($noticeMap[$notice] ?? ucwords(str_replace('_', ' ', $notice))); ?></p>
            <?php endif; ?>

            <?php if ($signedIn) : ?>
                <dl class="xra-spec-list xra-auth-specs">
                    <div><dt>User</dt><dd><?php echo esc_html($currentUser->display_name ?: $currentUser->user_login); ?></dd></div>
                    <div><dt>Role</dt><dd><?php echo esc_html(implode(', ', (array) $currentUser->roles)); ?></dd></div>
                </dl>
                <div class="xra-actions">
                    <a class="xra-button" href="<?php echo esc_url(PublicController::viewUrl('admin')); ?>">Workspace</a>
                    <a class="xra-button xra-button--secondary" href="<?php echo esc_url(wp_logout_url(PublicController::viewUrl('auth'))); ?>">Sign Out</a>
                </div>
            <?php else : ?>
                <div class="xra-auth-copy"></div>
            <?php endif; ?>
        </article>

        <?php if (!$signedIn) : ?>
            <article class="xra-surface xra-auth-panel" aria-labelledby="xra-login-title">
                <div class="xra-section-head">
                    <div>
                        <h3 id="xra-login-title">Sign In</h3>
                    </div>
                </div>
                <form class="xra-form xra-auth-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('xra_auth_login'); ?>
                    <input type="hidden" name="action" value="xra_auth_login">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr(PublicController::viewUrl('app')); ?>">
                    <label>
                        <span>Username Or Email</span>
                        <input type="text" name="identifier" autocomplete="username" placeholder="Username Or Email">
                    </label>
                    <label>
                        <span>Password</span>
                        <input type="password" name="password" autocomplete="current-password" placeholder="Password">
                    </label>
                    <label class="xra-inline-field">
                        <input type="checkbox" name="remember" value="1">
                        <span>Remember Me</span>
                    </label>
                    <div class="xra-actions">
                        <button class="xra-button" type="submit">Sign In</button>
                    </div>
                </form>
            </article>

            <article class="xra-surface xra-auth-panel" aria-labelledby="xra-register-title">
                <div class="xra-section-head">
                    <div>
                        <h3 id="xra-register-title">Create Account</h3>
                    </div>
                </div>
                <?php if (get_option('users_can_register')) : ?>
                    <form class="xra-form xra-auth-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('xra_auth_register'); ?>
                        <input type="hidden" name="action" value="xra_auth_register">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr(PublicController::viewUrl('app')); ?>">
                        <label>
                            <span>Username</span>
                            <input type="text" name="username" autocomplete="username" placeholder="Username">
                        </label>
                        <label>
                            <span>Email</span>
                            <input type="email" name="email" autocomplete="email" placeholder="Email">
                        </label>
                        <label>
                            <span>Password</span>
                            <input type="password" name="password" autocomplete="new-password" placeholder="Password">
                        </label>
                        <div class="xra-actions">
                            <button class="xra-button" type="submit">Create Account</button>
                        </div>
                    </form>
                <?php else : ?>
                    <p class="xra-note"></p>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </div>
</section>
