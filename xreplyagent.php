<?php
// phpcs:ignoreFile -- Plugin bootstrap: headers and registration live in the same entrypoint file.

/**
 * Plugin Name: XReplyAgent
 * Description: Self-contained reply agent plugin with prompt versions, personas, audit trails, metrics, and manual publication controls.
 * Version: 0.1.0
 * Author: OpenAI Codex
 * Requires PHP: 8.2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('XRA_VERSION', '0.1.0');
define('XRA_FILE', __FILE__);
define('XRA_DIR', plugin_dir_path(__FILE__));
define('XRA_URL', plugin_dir_url(__FILE__));

require_once XRA_DIR . 'src/Support/Autoloader.php';

\XReplyAgent\Support\Autoloader::register('XReplyAgent\\', XRA_DIR . 'src/');

register_activation_hook(__FILE__, [\XReplyAgent\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\XReplyAgent\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \XReplyAgent\Plugin::instance();
});

\XReplyAgent\Plugin::instance();
