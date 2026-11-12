<?php
// Disposable release-test installations only. Never execute on a real site.
if (PHP_SAPI !== 'cli' || !defined('AUTHOR_CHAT_SYNTHETIC_TESTS') || AUTHOR_CHAT_SYNTHETIC_TESTS !== true ||
    !defined('AUTHOR_CHAT_INTEGRATION_ALLOWED') || AUTHOR_CHAT_INTEGRATION_ALLOWED !== true ||
    DB_HOST !== 'tests-mysql' || !in_array((int) wp_parse_url(get_option('siteurl'), PHP_URL_PORT), array(8909,8919), true)) { exit(2); }
global $wpdb;
$m = $wpdb->prefix.'author_chat'; $p = $wpdb->prefix.'author_chat_room_participants';
if (($args[0] ?? '') === 'seed') {
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($m)))) { throw new Exception('Refusing to overwrite an existing fixture'); }
    foreach (array(
        "CREATE TABLE $m (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id bigint NOT NULL,nickname tinytext NOT NULL,content text NOT NULL,chat_room_id bigint NOT NULL DEFAULT 0,date datetime NOT NULL)",
        "CREATE TABLE $p (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id bigint NOT NULL,chat_room_id bigint NOT NULL)",
        "INSERT INTO $m VALUES(7,1,'Synthetic','Legacy 2.0.3 history',900,'2026-01-01 12:00:00')",
        "INSERT INTO $p VALUES(10,1,800),(11,1,800)"
    ) as $sql) { if ($wpdb->query($sql) === false) { throw new Exception($wpdb->last_error); } }
    update_option('author_chat_db_version','1.2'); update_option('author_chat_settings',77);
    update_option('author_chat_settings_val','synthetic-legacy-value'); update_option('author_chat_settings_window',1);
    update_option('acppauthorchat_release_fixture', array($wpdb->get_results("SELECT * FROM $m ORDER BY id", ARRAY_A),$wpdb->get_results("SELECT * FROM $p ORDER BY id", ARRAY_A)));
    echo "PASS: synthetic 2.0.3 schema/options/history seeded before package activation\n";
} else {
    $before=get_option('acppauthorchat_release_fixture');
    if ($before !== array($wpdb->get_results("SELECT * FROM $m ORDER BY id", ARRAY_A),$wpdb->get_results("SELECT * FROM $p ORDER BY id", ARRAY_A)) ||
        get_option('author_chat_db_version') !== '2.0' || (int)get_option('author_chat_settings') !== 77 || get_option('author_chat_settings_val') !== 'synthetic-legacy-value' ||
        get_plugin_data(WP_PLUGIN_DIR.'/author-chat/acppauthorchat-author-chat.php')['Version'] !== '2.1.0') { throw new Exception('Package upgrade changed historical data/settings or wrong version'); }
    echo "PASS: installed ZIP migrated schema 1.2 -> 2.0; original IDs, duplicate memberships, history and settings preserved\n";
}
