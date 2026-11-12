<?php
/*
 * Plugin Name: Author Chat
 * Plugin URI: https://github.com/Pantsoffski/Author-Chat-Plugin
 * Description: Plugin that gives your authors an easy way to communicate through back-end UI (admin panel).
 * Author: Piotr Pesta
 * Version: 2.1.0
 * Author URI: https://github.com/Pantsoffski
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Text Domain: author-chat
 * Domain Path: /lang
 */

if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/acppauthorchat-process.php';
require_once __DIR__ . '/acppauthorchat-schema.php';
require_once __DIR__ . '/acppauthorchat-options.php';

// Global Vars
global $acppauthorchat_version;

$acppauthorchat_version = '2.1.0';

global $acppauthorchat_db_version;
$acppauthorchat_db_version = '2.0';

add_action('admin_menu', 'acppauthorchat_setup_menu');
add_action('wp_dashboard_setup', 'acppauthorchat_wp_dashboard');
add_action('admin_enqueue_scripts', 'acppauthorchat_scripts_admin_chat');
add_action('admin_init', 'acppauthorchat_register_settings');
add_action('admin_post_acppauthorchat_prune', 'acppauthorchat_handle_prune');
register_activation_hook(__FILE__, 'acppauthorchat_activate');
register_deactivation_hook( __FILE__, 'acppauthorchat_deactivate' );
add_action('plugins_loaded', 'acppauthorchat_update_db_check');
add_action('wp_initialize_site', 'acppauthorchat_initialize_site', 200);
add_action('plugins_loaded', 'acppauthorchat_load_textdomain');
// Render outside #wpfooter, which WordPress hides on narrow admin screens.
add_action('admin_footer', 'acppauthorchat_chat_on_top');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'acppauthorchat_plugin_action_links');
add_action('admin_notices', 'acppauthorchat_schema_notice');

// Load Localization
function acppauthorchat_load_textdomain() {
    load_plugin_textdomain('author-chat', false, dirname(plugin_basename(__FILE__)) . '/lang/');
}

function acppauthorchat_update_db_check() {
    if (!acppauthorchat_registry_ready()) { acppauthorchat_activate(); }
}

/**
 * Install/upgrade per-site schema and add missing defaults without rewriting data.
 * @param bool $network_wide Whether WordPress requested network activation.
 * @return true|WP_Error
 */
function acppauthorchat_activate($network_wide = false) {
    if (is_multisite() && $network_wide) {
        $offset = 0;
        $failure = null;
        do {
            $sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $offset));
            foreach ($sites as $site_id) {
                switch_to_blog((int) $site_id);
                try {
                    $result = acppauthorchat_activate(false);
                    if (is_wp_error($result)) { $failure = $result; }
                } finally { restore_current_blog(); }
            }
            $offset += count($sites);
        } while (count($sites) === 100);
        return $failure ? $failure : true;
    }
    $result = acppauthorchat_install_schema();
    if (is_wp_error($result)) { return $result; }
    add_option('author_chat_settings', 30);
    add_option('author_chat_settings_access_all_users', 1);
    add_option('author_chat_settings_access_author', 0);
    add_option('author_chat_settings_access_contributor', 0);
    add_option('author_chat_settings_access_editor', 0);
    add_option('author_chat_settings_access_subscriber', 0);
    add_option('author_chat_settings_interval', 2);
    add_option('author_chat_settings_name', 0);
    add_option('author_chat_settings_show_my_name', 0);
    add_option('author_chat_settings_weekdays', 1);
    add_option('author_chat_settings_window', 0);
    return true;
}

// Deactivate Author Chat
function acppauthorchat_deactivate() {
    // Deactivation retains historical settings and all chat data.
}

// Enqueue JavaScript & CSS files
function acppauthorchat_scripts_admin_chat() {
    if (!acppauthorchat_can_access() || (get_current_screen()->base !== 'dashboard' && get_option('author_chat_settings_window') != 1)) {
        return;
    }
    global $acppauthorchat_version;
    $script_version = $acppauthorchat_version . '.' . filemtime(__DIR__ . '/acppauthorchat_chat.js');
    wp_enqueue_script('acppauthorchat-author-chat-script', plugins_url('acppauthorchat_chat.js', __FILE__), array('jquery', 'wp-i18n', 'jquery-ui-autocomplete'), $script_version, true);
	wp_localize_script('acppauthorchat-author-chat-script', 'acppauthorchat_ajax', array(
         'url' => admin_url('admin-ajax.php'),
         'action' => 'acppauthorchat',
         'nonce' => wp_create_nonce('ajax-nonce')
     ));
    wp_enqueue_style('acppauthorchat-author-chat-style', plugins_url('acppauthorchat_author_chat_style.css', __FILE__), array(), $acppauthorchat_version . '.' . filemtime(__DIR__ . '/acppauthorchat_author_chat_style.css'));
    wp_set_script_translations('acppauthorchat-author-chat-script', 'author-chat', __DIR__ . '/lang');

    // set acppauthorchat_config variables for send to the JS
    $current_user = wp_get_current_user();
    $values = array
        (
        'user_id' => $current_user->ID,
        'today' => __('Today', 'author-chat'),
        'yesterday' => __('Yesterday', 'author-chat'),
        'sunday' => __('Sunday', 'author-chat'),
        'monday' => __('Monday', 'author-chat'),
        'tuesday' => __('Tuesday', 'author-chat'),
        'wednesday' => __('Wednesday', 'author-chat'),
        'thursday' => __('Thursday', 'author-chat'),
        'friday' => __('Friday', 'author-chat'),
        'saturday' => __('Saturday', 'author-chat'),
        'set_interval' => max(1, min(10, (int) get_option('author_chat_settings_interval', 2))),
        'set_show_my_name' => (int) (get_option('author_chat_settings_show_my_name') == 1),
        'set_weekdays' => (int) (get_option('author_chat_settings_weekdays') == 1)
    );
    wp_localize_script('acppauthorchat-author-chat-script', 'acppauthorchat_config', $values);
}

function acppauthorchat_setup_menu() {

    $optionsTitle = __('Author Chat Options', 'author-chat');
    $pluginName = __('Author Chat', 'author-chat');
    add_menu_page($optionsTitle, $pluginName, 'manage_options', 'acset', 'acppauthorchat_settings_page', 'dashicons-format-chat');
}

function acppauthorchat_wp_dashboard() {
    if (!acppauthorchat_can_access()) {
        return;
    }
    $pluginName = __('Author Chat', 'author-chat');
    wp_add_dashboard_widget('author-chat-widget', $pluginName, 'acppauthorchat_author_chat');
}

function acppauthorchat_register_settings() {
    $flags = array('access_all_users', 'access_author', 'access_contributor', 'access_editor', 'access_subscriber', 'name', 'show_my_name', 'weekdays', 'window');
    foreach ($flags as $flag) {
        register_setting('author_chat_settings_group', 'author_chat_settings_' . $flag, array('type' => 'integer', 'sanitize_callback' => 'acppauthorchat_sanitize_flag', 'default' => 0));
    }
    register_setting('author_chat_settings_group', 'author_chat_settings', array('type' => 'integer', 'sanitize_callback' => 'acppauthorchat_sanitize_days'));
    register_setting('author_chat_settings_group', 'author_chat_settings_interval', array('type' => 'integer', 'sanitize_callback' => 'acppauthorchat_sanitize_interval'));
}

function acppauthorchat_sanitize_flag($value) {
    return is_scalar($value) && (string) $value === '1' ? 1 : 0;
}

function acppauthorchat_sanitize_days($value) {
    $days = filter_var($value, FILTER_VALIDATE_INT);
    return false === $days ? 30 : max(1, min(36500, $days));
}

function acppauthorchat_sanitize_interval($value) {
    $seconds = filter_var($value, FILTER_VALIDATE_INT);
    return false === $seconds ? 2 : max(1, min(10, $seconds));
}

function acppauthorchat_plugin_action_links($links) { //Add settings link to plugins page
    $action_links = array(
        'settings' => '<a href="' . esc_url(admin_url('admin.php?page=acset')) . '">' . esc_html__('Settings', 'author-chat') . '</a>',
    );

    return array_merge($action_links, $links);
}

function acppauthorchat_author_chat() {
    if (!acppauthorchat_can_access()) { return; }
    ?>
    <div id="author-chat" class="wp-exclude-emoji">
        <div id="ac-rooms-add-btn-wrapper" aria-label="<?php esc_attr_e('Chat rooms', 'author-chat'); ?>"><span id="ac-rooms"></span><span id="ac-private-conversation"></span></div>
        <div id="ac-search-user"></div><div id="ac-room-users-list"></div>
        <div class="ac-wrap"><div id="author-chat-area" tabindex="0" aria-label="<?php esc_attr_e('Messages', 'author-chat'); ?>"><ul aria-live="polite" aria-relevant="additions"></ul></div></div>
        <form class="ac-text-form">
            <label class="screen-reader-text" for="acppauthorchat-message"><?php esc_html_e('Your message', 'author-chat'); ?></label>
            <textarea id="acppauthorchat-message" class="ac-textarea" maxlength="1000" placeholder="<?php esc_attr_e('Your message...', 'author-chat'); ?>"></textarea>
        </form>
        <p class="ac-request-status wp-exclude-emoji" role="status"></p><p class="ac-schema-status" role="status"></p><div class="ac-outbox" aria-live="polite"></div>
        <audio id="author-chat-sound" preload="none"><source src="<?php echo esc_url(plugins_url('notifyauthorchat.wav', __FILE__)); ?>" type="audio/wav"></audio>
    </div>
    <?php
}

function acppauthorchat_chat_on_top() {
    if (!acppauthorchat_can_access() || get_option('author_chat_settings_window') != 1 || get_current_screen()->base === 'dashboard') { return; }
    ?>
    <aside id="acppauthorchat-floating" aria-label="<?php esc_attr_e('Author Chat', 'author-chat'); ?>">
        <button type="button" id="acppauthorchat-toggle" aria-expanded="false" aria-controls="acppauthorchat-panel"><?php esc_html_e('Author Chat', 'author-chat'); ?> <span id="author-chat-count" aria-label="<?php esc_attr_e('Unread messages', 'author-chat'); ?>"></span></button>
        <div id="acppauthorchat-panel" hidden><?php acppauthorchat_author_chat(); ?></div>
    </aside>
    <?php
}

function acppauthorchat_clean_up_chat_history() {
    // Legacy automatic entry point stays disabled; cleanup requires the manual admin action.
    return new WP_Error('author_chat_cleanup_disabled', __('Automatic chat cleanup is disabled.', 'author-chat'));
}

function acppauthorchat_clean_up_database() {
    // Never honor a stale delete checkbox while rendering the dashboard.
    return new WP_Error('author_chat_cleanup_disabled', __('Chat deletion is disabled.', 'author-chat'));
}
