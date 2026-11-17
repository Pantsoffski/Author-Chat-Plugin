<?php
// Package upgrade checks, restricted to known disposable Author Chat test sites.
if (PHP_SAPI !== 'cli' || !defined('AUTHOR_CHAT_SYNTHETIC_TESTS') || AUTHOR_CHAT_SYNTHETIC_TESTS !== true ||
    !defined('AUTHOR_CHAT_INTEGRATION_ALLOWED') || AUTHOR_CHAT_INTEGRATION_ALLOWED !== true ||
    DB_HOST !== 'tests-mysql' || !in_array((int) wp_parse_url(get_option('siteurl'), PHP_URL_PORT), array(8909,8919,8929), true)) {
    exit(2);
}
function acppreview211_snapshot() {
    global $wpdb;
    $snapshot = array();
    foreach (array('author_chat' => 'id', 'author_chat_room_participants' => 'id', 'author_chat_rooms' => 'room_id') as $suffix => $key) {
        $snapshot[$suffix] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY %i', $wpdb->prefix.$suffix, $key), ARRAY_A);
    }
    $snapshot['options'] = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name",
        $wpdb->esc_like('author_chat_').'%', $wpdb->esc_like('acppauthorchat_').'%'
    ), ARRAY_A);
    return $snapshot;
}
$version = get_plugin_data(WP_PLUGIN_DIR.'/author-chat/acppauthorchat-author-chat.php')['Version'];
if (($args[0] ?? '') === 'seed') {
    if ($version !== '2.1.0' || get_option('acppreview211_snapshot') !== false) { throw new Exception('Expected an unseeded 2.1.0 test site'); }
    wp_set_current_user(1);
    $room = acppauthorchat_create_room(bin2hex(random_bytes(16)));
    if (is_wp_error($room)) { throw new Exception('Could not create synthetic historical private room'); }
    foreach (array(0, $room['chat_room_id']) as $room_id) {
        $legacy = "<svg onload=alert(1)>Legacy 2.1.0 😀</svg>\nhttps://example.test/a%20b%2Fc?q=%25";
        $sent = acppauthorchat_store_message($room_id, $legacy);
        if (is_wp_error($sent) || $sent['row']['content'] !== $legacy) { throw new Exception('Historical fixture must retain original HTML'); }
    }
    update_option('acppreview211_snapshot', acppreview211_snapshot(), false);
    echo "PASS: 2.1.0 historical HTML, Unicode, private rooms and options snapshotted\n";
} else {
    $before = get_option('acppreview211_snapshot');
    if ($version !== '2.1.1' || get_option('author_chat_db_version') !== '2.0' || !is_array($before) || $before !== acppreview211_snapshot()) {
        throw new Exception('2.1.0 -> 2.1.1 changed historical rows/options or failed version checks');
    }
    echo "PASS: installed 2.1.1 ZIP preserves every historical message, room ID, membership and option; schema stays 2.0\n";
}
