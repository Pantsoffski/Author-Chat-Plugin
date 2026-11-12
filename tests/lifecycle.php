<?php
/** Real WP-CLI deactivate/delete/reinstall checks on disposable release installations. */
if (PHP_SAPI !== 'cli' || !defined('AUTHOR_CHAT_SYNTHETIC_TESTS') || AUTHOR_CHAT_SYNTHETIC_TESTS !== true ||
    !defined('AUTHOR_CHAT_INTEGRATION_ALLOWED') || AUTHOR_CHAT_INTEGRATION_ALLOWED !== true || DB_HOST !== 'tests-mysql' ||
    !in_array((int) wp_parse_url(get_option('siteurl'), PHP_URL_PORT), array(8909, 8919), true)) { exit(2); }

function ac_lifecycle_assert($condition, $message) { if (!$condition) { throw new Exception($message); } }
function ac_lifecycle_rows($table) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $table), ARRAY_A);
}
function ac_lifecycle_tables() {
    global $wpdb;
    return array_map(function ($suffix) use ($wpdb) { return $wpdb->prefix.$suffix; }, array('author_chat','author_chat_room_participants','author_chat_rooms'));
}
function ac_lifecycle_core_snapshot() {
    global $wpdb;
    $hashes = array();
    foreach (array($wpdb->users,$wpdb->usermeta,$wpdb->posts,$wpdb->postmeta,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->comments,$wpdb->commentmeta) as $table) {
        $rows = ac_lifecycle_rows($table);
        // Physical scan order may change; compare the same multiset of rows.
        $rows = array_map('wp_json_encode', $rows); sort($rows);
        $hashes[$table] = hash('sha256', implode("\n", $rows));
    }
    return $hashes;
}
$mode = $args[0] ?? '';
if ($mode === 'seed') {
    if (is_multisite()) {
        $was_installing = wp_installing();
        try {
            $blog = wpmu_create_blog('localhost', '/lifecycle-'.bin2hex(random_bytes(4)).'/', 'Lifecycle synthetic', 1);
        } finally { wp_installing($was_installing); }
        ac_lifecycle_assert(!is_wp_error($blog), 'New blog failed');
    }
    $sites = is_multisite() ? get_sites(array('fields'=>'ids','number'=>0,'network_id'=>0)) : array(get_current_blog_id());
    update_site_option('ac_lifecycle_sites', $sites);
    update_site_option('author_chat_db_version', 'legacy-network-version');
    set_site_transient('author_chat_lifecycle', 'owned', DAY_IN_SECONDS);
    set_site_transient('ac_lifecycle_keep', 'unrelated', DAY_IN_SECONDS);
    foreach ($sites as $site_id) {
        if (is_multisite()) { switch_to_blog((int)$site_id); }
        try {
            ac_lifecycle_assert(acppauthorchat_activate() === true, 'Schema install failed');
            global $wpdb;
            $wpdb->insert($wpdb->prefix.'author_chat', array('user_id'=>1,'nickname'=>'Synthetic','content'=>'Lifecycle message','chat_room_id'=>0,'date'=>'2026-01-01 00:00:00'));
            update_option('author_chat_settings_val', 'legacy');
            update_option('author_chat_settings_future_legacy', 'owned');
            update_option('ac_lifecycle_keep', 'unrelated');
            update_option('author_chatting_keep', 'similar but unrelated');
            set_transient('author_chat_lifecycle', 'owned', DAY_IN_SECONDS);
            set_transient('ac_lifecycle_keep', 'unrelated', DAY_IN_SECONDS);
            wp_schedule_single_event(time()+DAY_IN_SECONDS,'author_chat_lifecycle');
            wp_schedule_single_event(time()+DAY_IN_SECONDS,'ac_lifecycle_keep');
            update_option('ac_lifecycle_snapshot', array(
                'plugin'=>array_map('ac_lifecycle_rows',ac_lifecycle_tables()),
                'core'=>ac_lifecycle_core_snapshot()
            ));
        } finally { if (is_multisite()) { restore_current_blog(); } }
    }
    echo "PASS: lifecycle fixtures seeded on ".count($sites)." sites\n";
    return;
}
foreach (get_site_option('ac_lifecycle_sites') as $site_id) {
    if (is_multisite()) { switch_to_blog((int)$site_id); }
    try {
        global $wpdb;
        $snapshot = get_option('ac_lifecycle_snapshot');
        ac_lifecycle_assert(ac_lifecycle_core_snapshot() === $snapshot['core'], 'Unrelated WordPress rows changed');
        ac_lifecycle_assert(get_option('ac_lifecycle_keep') === 'unrelated' && get_option('author_chatting_keep') === 'similar but unrelated' && get_transient('ac_lifecycle_keep') === 'unrelated' && wp_next_scheduled('ac_lifecycle_keep'), 'Unrelated options/transient/cron changed');
        if ($mode === 'retained') {
            ac_lifecycle_assert(array_map('ac_lifecycle_rows', ac_lifecycle_tables()) === $snapshot['plugin'], 'Update/deactivation changed chat records');
            ac_lifecycle_assert(get_option('author_chat_settings_val') === 'legacy', 'Legacy setting lost');
        } elseif ($mode === 'removed') {
            foreach (ac_lifecycle_tables() as $table) {
                ac_lifecycle_assert(!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))), 'Table left after uninstall');
            }
            foreach (array('author_chat_', 'acppauthorchat_', '_transient_author_chat_', '_transient_timeout_author_chat_') as $prefix) {
                ac_lifecycle_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix).'%')) === 0, 'Owned options left after uninstall');
            }
            ac_lifecycle_assert(!wp_next_scheduled('author_chat_lifecycle'), 'Owned cron left');
        } elseif ($mode === 'reinstalled') {
            foreach (ac_lifecycle_tables() as $table) { ac_lifecycle_assert(ac_lifecycle_rows($table) === array(), 'Reinstall is not empty'); }
            ac_lifecycle_assert(get_option('author_chat_db_version') === '2.0' && (int)get_option('author_chat_settings') === 30 && get_option('author_chat_settings_val') === false, 'Reinstall defaults incorrect');
        } else { throw new Exception('Unknown lifecycle mode'); }
    } finally { if (is_multisite()) { restore_current_blog(); } }
}
if ($mode === 'removed') {
    ac_lifecycle_assert(get_site_option('author_chat_db_version') === false && get_site_transient('author_chat_lifecycle') === false, 'Network leftovers');
    ac_lifecycle_assert(get_site_transient('ac_lifecycle_keep') === 'unrelated', 'Unrelated network transient removed');
}
echo "PASS: lifecycle $mode; unrelated WordPress data retained\n";
