<?php
// Generates synthetic credentials only for a loopback HTTP regression test.
if (PHP_SAPI !== 'cli' || !defined('AUTHOR_CHAT_SYNTHETIC_TESTS') || AUTHOR_CHAT_SYNTHETIC_TESTS !== true ||
    !defined('AUTHOR_CHAT_INTEGRATION_ALLOWED') || AUTHOR_CHAT_INTEGRATION_ALLOWED !== true ||
    DB_HOST !== 'tests-mysql' || get_option('siteurl') !== 'http://localhost:8929' ||
    !defined('WP_DEBUG') || WP_DEBUG !== true || !has_filter('pre_wp_mail', '__return_false')) { exit(2); }
if ((int)get_option('author_chat_settings_access_all_users') !== 1) { throw new Exception('HTTP fixture requires the test site default access setting'); }
$fixture = array('base' => get_option('siteurl'), 'users' => array());
foreach (array('owner' => 'administrator', 'member' => 'subscriber', 'outsider' => 'administrator') as $name => $role) {
    $login = 'acppreview211_'.$name.'_'.bin2hex(random_bytes(5));
    $password = wp_generate_password(30, true, false);
    $id = wp_insert_user(array('user_login' => $login, 'user_pass' => $password, 'role' => $role));
    if (is_wp_error($id)) { throw new Exception('Could not create synthetic user'); }
    $fixture['users'][$name] = array('login' => $login, 'password' => $password, 'id' => $id);
}
$fixture['cases'] = require __DIR__.'/message-cases.php';
echo wp_json_encode($fixture, JSON_INVALID_UTF8_SUBSTITUTE);
