<?php
// Run ONLY in wp-env tests-cli: wp eval-file wp-content/plugins/author-chat/tests/integration.php
// Additive fixtures are retained for inspection; no DROP/TRUNCATE/reset/uninstall.
if (PHP_SAPI !== 'cli' || !defined('ABSPATH') || !defined('AUTHOR_CHAT_SYNTHETIC_TESTS') || AUTHOR_CHAT_SYNTHETIC_TESTS !== true ||
    !defined('AUTHOR_CHAT_INTEGRATION_ALLOWED') || AUTHOR_CHAT_INTEGRATION_ALLOWED !== true ||
    !in_array(wp_parse_url(get_option('siteurl'), PHP_URL_HOST), array('localhost', '127.0.0.1'), true) ||
    !preg_match('/^tests-mysql(:\d+)?$/D', DB_HOST)) {
    fwrite(STDERR, "NOT TESTED: isolated wp-env context is required.\n"); exit(2);
}
require_once WP_PLUGIN_DIR . '/author-chat/acppauthorchat-author-chat.php';

function ac_test_assert($value, $message) { if (!$value) { throw new Exception($message); } }
function ac_test_sql($sql) {
    global $wpdb;
    $result = $wpdb->query($sql);
    if ($result === false) { throw new Exception('Synthetic SQL failed: ' . $wpdb->last_error); }
    return $result;
}
function ac_test_cache() {
    wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
    foreach (array('author_chat_db_version', 'author_chat_settings', 'author_chat_settings_access_all_users') as $key) { wp_cache_delete($key, 'options'); }
}
function ac_test_context($prefix, $create = true) {
    global $wpdb;
    static $site_url = null;
    if ($site_url === null) { $site_url = get_option('siteurl'); }
    ac_test_assert((bool) preg_match('/^actest_[a-f0-9]{12}_[a-z0-9]+_$/D', $prefix), 'Unsafe fixture prefix');
    $wpdb->prefix = $prefix;
    // Keep the synthetic site's core users/usermeta. Isolate plugin options too.
    $wpdb->options = $prefix . 'options';
    if ($create) {
        ac_test_sql("CREATE TABLE IF NOT EXISTS {$wpdb->options} (
            option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL,
            option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'off',
            PRIMARY KEY(option_id), UNIQUE KEY option_name(option_name)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate());
    }
    ac_test_cache();
    if ($create) {
        // Redirect validation needs a real synthetic site origin, even with isolated options.
        add_option('siteurl', $site_url);
        add_option('home', $site_url);
    }
}
function ac_test_history($prefix) {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$prefix}author_chat ORDER BY id", ARRAY_A);
}
function ac_test_legacy($prefix, $owner, $member, $partial = false) {
    ac_test_context($prefix);
    ac_test_sql("CREATE TABLE {$prefix}author_chat (
        id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint NOT NULL,
        nickname tinytext NOT NULL, content text NOT NULL, chat_room_id bigint NOT NULL DEFAULT 0, date datetime NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ac_test_sql("INSERT INTO {$prefix}author_chat VALUES (7,$owner,'Synthetic','orphan',900,'2026-01-01 12:00:00')");
    if (!$partial) {
        ac_test_sql("CREATE TABLE {$prefix}author_chat_room_participants (
            id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint NOT NULL, chat_room_id bigint NOT NULL
        ) ENGINE=InnoDB");
        ac_test_sql("INSERT INTO {$prefix}author_chat_room_participants VALUES (10,$owner,800),(11,$owner,800),(12,$member,800)");
    }
    update_option('author_chat_db_version', '1.2');
    update_option('author_chat_settings', 77);
}

global $wpdb;
$original_prefix = $wpdb->prefix; $original_options = $wpdb->options;
try {
    if (isset($args[0]) && $args[0] === 'worker') {
        $user = (int) $args[2]; wp_set_current_user($user);
        ac_test_context($args[1], false);
        $room = acppauthorchat_create_room($args[3]);
        ac_test_assert(!is_wp_error($room), 'Worker room creation failed');
        echo json_encode($room) . "\n"; return;
    }
    echo 'Runtime: PHP ' . PHP_VERSION . ', WordPress ' . get_bloginfo('version') . ', DB ' . $wpdb->get_var('SELECT VERSION()') . "\n";
    require_once ABSPATH . WPINC . '/pomo/mo.php';
    require_once ABSPATH . WPINC . '/pomo/po.php';
    foreach (glob(WP_PLUGIN_DIR . '/author-chat/lang/*.mo') as $catalog) {
        $mo = new MO();
        ac_test_assert($mo->import_from_file($catalog), 'WordPress rejected catalog: '.basename($catalog));
        $po = new PO();
        ac_test_assert($po->import_from_file(substr($catalog, 0, -3).'.po'), 'PO catalog rejected');
        foreach ($po->entries as $entry) {
            ac_test_assert($mo->translate($entry->singular) === $po->translate($entry->singular), 'Compiled translation differs from PO');
        }
    }
    echo "PASS: bundled MO catalogs load in WordPress and preserve existing translations\n";
    $run = bin2hex(random_bytes(6));
    $owner_password = wp_generate_password();
    $owner = wp_insert_user(array('user_login' => 'actest_owner_' . $run, 'user_pass' => $owner_password, 'role' => 'administrator'));
    $member = wp_insert_user(array('user_login' => 'actest_member_' . $run, 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
    $stranger = wp_insert_user(array('user_login' => 'actest_stranger_' . $run, 'user_pass' => wp_generate_password(), 'role' => 'author'));
    ac_test_assert(!is_wp_error($owner) && !is_wp_error($member) && !is_wp_error($stranger), 'Synthetic user creation failed');
    wp_set_current_user($owner);
    $base = 'actest_' . $run . '_';

    ac_test_context($base . 'fresh_');
    ac_test_assert(acppauthorchat_activate() === true && acppauthorchat_registry_ready(), 'Fresh install failed');
    ac_test_assert(acppauthorchat_activate() === true, 'Repeated install failed');
    echo "PASS: fresh and repeated installation\n";

    $prefix = $base . 'legacy_';
    ac_test_legacy($prefix, $owner, $member);
    $before = ac_test_history($prefix);
    $members_before = $wpdb->get_results("SELECT * FROM {$prefix}author_chat_room_participants ORDER BY id", ARRAY_A);
    ac_test_assert(acppauthorchat_activate() === true, 'Legacy migration failed');
    ac_test_assert(acppauthorchat_activate() === true, 'Repeated migration failed');
    ac_test_assert(ac_test_history($prefix) === $before, 'Historical messages changed');
    ac_test_assert($wpdb->get_results("SELECT * FROM {$prefix}author_chat_room_participants ORDER BY id", ARRAY_A) === $members_before, 'Memberships changed');
    ac_test_assert((int) acppauthorchat_room_owner(800) === $owner && (int) get_option('author_chat_settings') === 77, 'Owner/settings changed');
    ac_test_assert($wpdb->get_col("SELECT room_id FROM {$prefix}author_chat_rooms ORDER BY room_id") === array('800','900'), 'Historical IDs not registered');
    echo "PASS: legacy IDs, orphan history, empty rooms, duplicates, owners and settings preserved\n";

    ac_test_legacy($base . 'partial_', $owner, $member, true);
    $before = ac_test_history($wpdb->prefix);
    ac_test_assert(acppauthorchat_activate() === true && ac_test_history($wpdb->prefix) === $before, 'Partial installation not repaired');
    echo "PASS: missing participants table repaired\n";

    ac_test_legacy($base . 'resume_', $owner, $member);
    ac_test_sql("ALTER TABLE {$wpdb->prefix}author_chat ADD KEY ac_room_id(user_id)");
    ac_test_assert(is_wp_error(acppauthorchat_activate()) && get_option('author_chat_db_version') === '1.2', 'Failed upgrade marked complete');
    ac_test_sql("ALTER TABLE {$wpdb->prefix}author_chat ADD KEY recovered_room(chat_room_id,id)");
    ac_test_assert(acppauthorchat_activate() === true, 'Partial upgrade did not resume');
    echo "PASS: failed upgrade keeps old version; additive repair permits retry\n";

    ac_test_context($prefix, false);
    $token = bin2hex(random_bytes(16));
    $room = acppauthorchat_create_room($token);
    ac_test_assert(!is_wp_error($room) && $room['chat_room_id'] > 900, 'Allocator collided with history');
    $id = $room['chat_room_id'];
    $retry = acppauthorchat_create_room($token);
    ac_test_assert(!is_wp_error($retry) && $retry['chat_room_id'] === $id, 'Request not idempotent');
    ac_test_assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}author_chat_room_participants WHERE chat_room_id=$id") === 1, 'Owner duplicated');
    ac_test_assert(acppauthorchat_read_messages($id) === array(), 'Empty room unusable');
    ac_test_assert(!is_wp_error(acppauthorchat_ajax_operation(array('function'=>'addUser','room_id'=>(string)$id,'user_id'=>(string)$member))), 'Add member failed');
    wp_set_current_user($member);
    ac_test_assert(acppauthorchat_require_room($id) === true && is_wp_error(acppauthorchat_require_room($id, true)), 'Member/owner permissions wrong');
    wp_set_current_user($stranger);
    ac_test_assert(is_wp_error(acppauthorchat_read_messages($id)), 'Foreign room leaked');
    wp_set_current_user($owner);
    ac_test_assert(!is_wp_error(acppauthorchat_store_message($id, "Zażółć 😀 https://example.test/a%20b\nSecond")), 'Private send failed');
    ac_test_assert(rest_do_request(new WP_REST_Request('GET', '/wp/v2/types'))->get_status() === 200, 'Core REST disabled');
    ac_test_assert(rest_do_request(new WP_REST_Request('POST', '/author-chat/v2/chat'))->get_status() === 404, 'Retired endpoint still registered');
    echo "PASS: WordPress REST remains active; retired mobile route is absent\n";
    ac_test_assert(!is_wp_error(acppauthorchat_ajax_operation(array('function'=>'removeUser','room_id'=>(string)$id,'user_id'=>(string)$member))), 'Remove member failed');
    wp_set_current_user($member);
    ac_test_assert(is_wp_error(acppauthorchat_read_messages($id)), 'Revoked member still reads');
    wp_set_current_user($owner);
    acppauthorchat_ajax_operation(array('function'=>'removeUser','room_id'=>(string)$id,'user_id'=>(string)$owner));
    ac_test_assert(is_wp_error(acppauthorchat_create_room($token)), 'Retry rejoined a departed creator');
    echo "PASS: creation, idempotence, empty room, access, send and revocation\n";

    foreach (array(0, 5000, 5001, 50000) as $size) {
        ac_test_context($base . 'h' . $size . '_');
        ac_test_assert(acppauthorchat_activate() === true, 'History fixture install failed');
        for ($start = 1; $start <= $size; $start += 500) {
            $values = array();
            for ($i = $start; $i <= min($size, $start + 499); $i++) { $values[] = "($i,$owner,'Synthetic','message-$i',0,'2026-01-01 12:00:00')"; }
            ac_test_sql("INSERT INTO {$wpdb->prefix}author_chat(id,user_id,nickname,content,chat_room_id,date) VALUES " . implode(',', $values));
        }
        $cursor = '0'; $count = 0;
        do {
            $page = acppauthorchat_ajax_operation(array('function'=>'update','room_pressed_button_id'=>'0','after_id'=>$cursor));
            ac_test_assert(!is_wp_error($page) && count($page['id']) <= 200, 'History page failed');
            foreach ($page['id'] as $message_id) { $count++; ac_test_assert((int)$message_id === $count, 'History gap or duplicate'); }
            $cursor = $page['next_id'];
        } while ($page['has_more']);
        ac_test_assert($count === $size && (int)$cursor === $size, 'History truncated');
        echo "PASS: real SQL history $size\n";
    }

    ac_test_context($prefix, false);
    if (!function_exists('proc_open')) { echo "NOT TESTED: parallel workers unavailable\n"; exit(2); }
    foreach (array(true, false) as $same_token) {
        $shared = bin2hex(random_bytes(16)); $workers = array();
        for ($i=0; $i<2; $i++) {
            $worker_token = $same_token ? $shared : bin2hex(random_bytes(16));
            $process = proc_open(array('wp','--allow-root','eval-file',__FILE__,'worker',$prefix,(string)$owner,$worker_token),
                array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
            ac_test_assert(is_resource($process), 'Worker launch failed');
            fclose($pipes[0]); $workers[] = array($process,$pipes);
        }
        $ids = array();
        foreach ($workers as $worker) {
            $stdout = stream_get_contents($worker[1][1]); $stderr = stream_get_contents($worker[1][2]);
            fclose($worker[1][1]); fclose($worker[1][2]);
            ac_test_assert(proc_close($worker[0]) === 0, 'Worker failed: '.$stderr);
            $output = json_decode(trim($stdout), true);
            ac_test_assert(is_array($output) && isset($output['chat_room_id']), 'Invalid worker response');
            $ids[] = $output['chat_room_id'];
        }
        ac_test_assert(($ids[0] === $ids[1]) === $same_token, 'Concurrent reservation violated uniqueness');
        foreach ($ids as $worker_id) {
            ac_test_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}author_chat_room_participants WHERE chat_room_id=".(int)$worker_id) === 1, 'Concurrent owner duplication');
        }
    }
    echo "PASS: concurrent same-token and different-token room creation\n";

    // Real reconnect after a durable reservation, before owner initialization.
    $token = bin2hex(random_bytes(16)); $hash = hash('sha256', $owner . ':' . $token);
    ac_test_sql($wpdb->prepare("INSERT INTO {$prefix}author_chat_rooms(request_id,creator_id,initialized) VALUES(%s,%d,0)",$hash,$owner));
    $reserved = $wpdb->get_var($wpdb->prepare("SELECT room_id FROM {$prefix}author_chat_rooms WHERE request_id=%s",$hash));
    $connection = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
    $killer = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    ac_test_assert($killer->query("KILL $connection") !== false, 'Synthetic reconnect injection failed');
    $recovered = acppauthorchat_create_room($token);
    ac_test_assert(!is_wp_error($recovered) && (string)$recovered['chat_room_id'] === $reserved, 'Reconnect lost reservation');
    echo "PASS: real reconnect resumes durable reservation\n";

    // Retention runs only in this verified, new fixture prefix. No real chat data.
    ac_test_context($base . 'retention_');
    ac_test_assert(acppauthorchat_activate() === true, 'Retention fixture installation failed');
    acppauthorchat_register_settings();
    $registered = get_registered_settings();
    foreach (array('access_all_users','access_author','access_contributor','access_editor','access_subscriber','name','show_my_name','weekdays','window') as $flag) {
        ac_test_assert(isset($registered['author_chat_settings_'.$flag]['sanitize_callback']), 'Flag has no sanitizer');
    }
    update_option('author_chat_settings_interval', '500');
    ac_test_assert((int)get_option('author_chat_settings_interval') === 10, 'Settings API bypassed interval sanitizer');
    update_option('author_chat_settings', 'invalid');
    ac_test_assert((int)get_option('author_chat_settings') === 30, 'Settings API bypassed age sanitizer');
    $retention_room = acppauthorchat_create_room(bin2hex(random_bytes(16)));
    ac_test_assert(!is_wp_error($retention_room), 'Retention room failed');
    $retention_id = $retention_room['chat_room_id'];
    $retained_members = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}author_chat_room_participants", ARRAY_A);
    $retained_rooms = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}author_chat_rooms", ARRAY_A);
    $values = array();
    for ($i=1; $i<=501; $i++) { $values[] = "($owner,'Synthetic','old',$retention_id,'2020-01-01 00:00:00')"; }
    $values[] = "($owner,'Synthetic','future',0,'2099-01-01 00:00:00')";
    $values[] = "($owner,'Synthetic','invalid historical date',0,'0000-00-00 00:00:00')";
    ac_test_sql("INSERT INTO {$wpdb->prefix}author_chat(user_id,nickname,content,chat_room_id,date) VALUES ".implode(',', $values));
    // Older permissive servers may have accepted non-zero invalid calendar dates.
    $original_mode = $wpdb->get_var('SELECT @@SESSION.sql_mode');
    try {
        ac_test_sql("SET SESSION sql_mode='ALLOW_INVALID_DATES'");
        ac_test_sql("INSERT INTO {$wpdb->prefix}author_chat(user_id,nickname,content,chat_room_id,date) VALUES ($owner,'Synthetic','invalid calendar date',0,'2020-02-31 00:00:00')");
    } finally { ac_test_sql($wpdb->prepare('SET SESSION sql_mode=%s', $original_mode)); }
    $retained = ac_test_history($wpdb->prefix);
    update_option('author_chat_settings_delete', 1);
    acppauthorchat_activate(); acppauthorchat_deactivate();
    ac_test_assert(ac_test_history($wpdb->prefix) === $retained && (int)get_option('author_chat_settings_delete') === 1, 'Lifecycle erased historical data/options');
    wp_set_current_user($member);
    ac_test_assert(is_wp_error(acppauthorchat_prune_history(30)), 'Subscriber could clean history');
    wp_set_current_user($owner);
    foreach (array(0, -1, 36501, array(30)) as $invalid) { ac_test_assert(is_wp_error(acppauthorchat_prune_history($invalid)), 'Invalid cleanup age accepted'); }
    $request_before = array($_POST, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null);
    $die_filter = function () {
        return function ($message, $title, $parameters) { throw new RuntimeException('blocked-'.($parameters['response'] ?? 'unknown')); };
    };
    $redirect_filter = function ($location) { throw new RuntimeException('redirect-'.$location); };
    add_filter('wp_die_handler', $die_filter, PHP_INT_MAX);
    add_filter('wp_redirect', $redirect_filter, -PHP_INT_MAX);
    try {
        foreach (array(
            array($member, 'POST', true, '1', 403),
            array($owner, 'POST', false, '1', 403),
            array($owner, 'GET', true, '1', 400),
            array($owner, 'POST', true, '0', 400),
            array($owner, 'POST', true, array('1'), 400)
        ) as $attempt) {
            wp_set_current_user($attempt[0]);
            $_SERVER['REQUEST_METHOD'] = $attempt[1];
            $_REQUEST = array('_wpnonce' => $attempt[2] ? wp_create_nonce('acppauthorchat_prune') : 'invalid');
            $_POST = array('confirm' => $attempt[3]);
            $blocked = false;
            try { acppauthorchat_handle_prune(); }
            catch (RuntimeException $error) { $blocked = $error->getMessage() === 'blocked-'.$attempt[4]; }
            ac_test_assert($blocked && ac_test_history($wpdb->prefix) === $retained, 'Invalid cleanup request reached deletion');
        }
        wp_set_current_user($owner);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST = array('_wpnonce' => wp_create_nonce('acppauthorchat_prune'));
        $_POST = array('confirm' => '1');
        $redirected = false;
        try { acppauthorchat_handle_prune(); }
        catch (RuntimeException $error) { $redirected = strpos($error->getMessage(), 'acppauthorchat_result=500') !== false; }
        ac_test_assert($redirected && count(ac_test_history($wpdb->prefix)) === 4, 'Confirmed cleanup did not enforce 500 cap/report result');
    } finally {
        remove_filter('wp_die_handler', $die_filter, PHP_INT_MAX);
        remove_filter('wp_redirect', $redirect_filter, -PHP_INT_MAX);
        $_POST = $request_before[0]; $_REQUEST = $request_before[1];
        if ($request_before[2] === null) { unset($_SERVER['REQUEST_METHOD']); } else { $_SERVER['REQUEST_METHOD'] = $request_before[2]; }
        wp_set_current_user($owner);
    }
    ac_test_assert(acppauthorchat_prune_history(30) === 1, 'Second cleanup lost remainder');
    ac_test_assert(count(ac_test_history($wpdb->prefix)) === 3, 'Future/zero/invalid calendar dates were removed');
    ac_test_assert($wpdb->get_results("SELECT * FROM {$wpdb->prefix}author_chat_room_participants", ARRAY_A) === $retained_members, 'Retention changed participants');
    ac_test_assert($wpdb->get_results("SELECT * FROM {$wpdb->prefix}author_chat_rooms", ARRAY_A) === $retained_rooms, 'Retention changed room registry');
    echo "PASS: Settings API sanitizers, data-preserving lifecycle and retention permission/nonce/confirmation/500 cap\n";
    if (is_multisite()) {
        // Real WP blog prefix/options isolation, with only new synthetic sites.
        $wpdb->prefix=$original_prefix; $wpdb->options=$original_options; ac_test_cache();
        $first = wpmu_create_blog('localhost','/actest-'.$run.'-a/','Synthetic A',$owner);
        $second = wpmu_create_blog('localhost','/actest-'.$run.'-b/','Synthetic B',$owner);
        foreach (array($first,$second) as $blog) {
            ac_test_assert(!is_wp_error($blog), 'Synthetic blog creation failed');
            switch_to_blog($blog);
            try { ac_test_assert(acppauthorchat_activate() === true && get_option('author_chat_db_version') === '2.0', 'Per-site schema failed'); }
            finally { restore_current_blog(); }
        }
        $network_version = get_site_option('author_chat_db_version');
        $blog_before = get_current_blog_id();
        ac_test_assert(acppauthorchat_activate(true) === true && get_current_blog_id() === $blog_before, 'Network activation failed to restore blog context');
        ac_test_assert(get_site_option('author_chat_db_version') === $network_version, 'Schema version leaked into network options');
        echo "PASS: multisite per-blog schema/options\n";
    } else { echo "NOT TESTED: multisite (run the separate multisite wp-env configuration)\n"; }
    echo "Fixture tables retained with prefix $base\n";
} finally {
    $wpdb->prefix = $original_prefix; $wpdb->options = $original_options; ac_test_cache();
}
