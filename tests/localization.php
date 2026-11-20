<?php

// Run with wp eval-file in the isolated wp-env tests-cli container.
if (PHP_SAPI !== 'cli' || !defined('AUTHOR_CHAT_INTEGRATION_ALLOWED') || AUTHOR_CHAT_INTEGRATION_ALLOWED !== true) {
    exit(2);
}
require_once ABSPATH . 'wp-admin/includes/admin.php';

function acppauthorchat_expect_localization($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

$admin = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
acppauthorchat_expect_localization(!empty($admin), 'The test site needs an administrator.');
wp_set_current_user((int) $admin[0]);
set_current_screen('dashboard');

$handle = 'acppauthorchat-author-chat-script';
$domain = 'author-chat';
$json_path = WP_PLUGIN_DIR . '/author-chat/lang/author-chat-pl_PL-' . $handle . '.json';
$loaded_path = null;
add_filter('load_script_translation_file', function ($file) use ($handle, &$loaded_path) {
    if (is_string($file) && basename($file) === 'author-chat-pl_PL-' . $handle . '.json') {
        $loaded_path = $file;
    }
    return $file;
}, 10, 1);

acppauthorchat_expect_localization(switch_to_locale('pl_PL') || determine_locale() === 'pl_PL', 'Install the pl_PL core language pack in the test environment.');
try {
    acppauthorchat_expect_localization(__('Author Chat settings', $domain) === 'Ustawienia czatu autorów', 'The Polish gettext catalog failed to load.');
    acppauthorchat_scripts_admin_chat();
    $script = wp_scripts()->registered[$handle];
    acppauthorchat_expect_localization($script->textdomain === $domain && in_array('wp-date', $script->deps, true), 'The chat must register its text domain and WordPress date dependency.');
    $data = json_decode(load_script_textdomain($handle, $domain, $script->translations_path), true, 512, JSON_THROW_ON_ERROR);
    acppauthorchat_expect_localization($loaded_path === $json_path && $data['locale_data']['messages']['Main room'][0] === 'Pokój główny', 'WordPress must resolve the local Polish JSON for the registered chat handle.');

    $timestamp = strtotime('2026-11-12 00:30:00 UTC');
    foreach (array('j F Y', 'd. F Y', 'F jS, Y') as $format) {
        $november = acppauthorchat_declined_date_formats($format)[11];
        $preview = (new DateTimeImmutable('@' . $timestamp))->format($november);
        acppauthorchat_expect_localization($preview === wp_date($format, $timestamp, new DateTimeZone('UTC')), "Incorrect Polish month declension for {$format}.");
    }
    acppauthorchat_expect_localization(acppauthorchat_declined_date_formats('F Y') === array(), 'Standalone month names must keep their nominative form.');

    $_GET['acppauthorchat_nonce'] = wp_create_nonce('acppauthorchat_prune_result');
    foreach (array(0 => 'starych wiadomości', 1 => 'starą wiadomość', 2 => 'stare wiadomości',
                   5 => 'starych wiadomości', 12 => 'starych wiadomości', 22 => 'stare wiadomości') as $count => $form) {
        $_GET['acppauthorchat_result'] = (string) $count;
        ob_start();
        acppauthorchat_settings_page();
        $html = ob_get_clean();
        acppauthorchat_expect_localization(str_contains($html, "Usunięto {$count} {$form}."), "Incorrect Polish cleanup message for {$count}.");
    }
} finally {
    restore_previous_locale();
}

acppauthorchat_expect_localization(switch_to_locale('en_US') || determine_locale() === 'en_US', 'Could not use the base locale.');
try {
    acppauthorchat_expect_localization(__('Author Chat settings', $domain) === 'Author Chat settings', 'The English source must be the PHP fallback.');
    acppauthorchat_expect_localization(load_script_textdomain($handle, $domain, $script->translations_path) === false, 'English should use the source JS strings.');
} finally {
    restore_previous_locale();
}
echo "PASS: local MO and JSON, English fallback, WordPress date formats and month declension, and Polish cleanup notices for 0, 1, 2, 5, 12, 22.\n";
