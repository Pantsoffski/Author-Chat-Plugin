<?php
// Loaded only by the isolated wp-env mapping. No production integrations.
if (!defined('ABSPATH')) { exit; }
if (defined('AUTHOR_CHAT_SYNTHETIC_TESTS') && AUTHOR_CHAT_SYNTHETIC_TESTS === true) {
    $browser_policy = function () {
        if (!headers_sent()) {
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self'; connect-src 'self'; form-action 'self'; frame-src 'none'; object-src 'none'; base-uri 'self'");
        }
    };
    add_action('send_headers', $browser_policy);
    add_action('admin_init', $browser_policy);
    add_filter('pre_http_request', function () {
        return new WP_Error('author_chat_test_network_blocked', 'Outbound HTTP is disabled in synthetic tests.');
    }, PHP_INT_MAX);
    add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);
}
