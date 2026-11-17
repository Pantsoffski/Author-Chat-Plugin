<?php
// Dependency-free unit tests using WP/database doubles, NOT a WordPress/MySQL integration suite.
// Run when PHP CLI is available: php tests/security-php.php
if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', __DIR__ . '/unavailable-wordpress/');
define('DB_NAME', 'author_chat_synthetic_tests');
define('ARRAY_A', 'ARRAY_A');

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code, $message, $data = array()) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_User {
    public $ID;
    public $user_login;
    public $display_name;
    public $caps;
    public $member = true;
    public function __construct($id, $caps = array()) {
        $this->ID = $id; $this->caps = $caps; $this->user_login = 'synthetic-' . $id; $this->display_name = 'User ' . $id;
    }
    public function exists() { return $this->ID > 0; }
}
class Chat_Json extends Exception {
    public $payload;
    public $status;
    public function __construct($payload, $status) { $this->payload = $payload; $this->status = $status; }
}
class Chat_Database_Double {
    public $prefix = 'synthetic_';
    public $usermeta = 'synthetic_usermeta';
    public $last_error = '';
    public $insert_id = 501;
    public $vars = array();
    public $rows = array();
    public $columns = array();
    public $queries = array();
    public $writes = array();
    public $lock = '1';
    public $write_ok = true;
    public $read_error = false;
    public $participants_available = true;
    public $participant_columns = array('id', 'user_id', 'chat_room_id');
    public $history = null;
    public $members = array(12 => array(7), 13 => array(8));
    public $connection = 101;
    public $owns_lock = false;
    public $reconnect_before_write = false;
    public $lose_lock_before_write = false;
    public $safe_binlog = true;
    private $suppressed = false;
    public function prepare($sql, ...$args) { return array('sql' => $sql, 'args' => count($args) === 1 && is_array($args[0]) ? $args[0] : $args); }
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function suppress_errors($value) { $old = $this->suppressed; $this->suppressed = $value; return $old; }
    public function get_var($query) {
        $this->queries[] = $query;
        $this->last_error = '';
        $sql = is_array($query) ? $query['sql'] : $query;
        if (strpos($sql, 'GET_LOCK') !== false) { $this->owns_lock = $this->lock === '1'; return $this->lock; }
        if (strpos($sql, 'RELEASE_LOCK') !== false) { $this->owns_lock = false; return '1'; }
        if ($sql === 'SELECT CONNECTION_ID()') { return (string) $this->connection; }
        if (strpos($sql, 'SELECT @@GLOBAL.log_bin') === 0) { return $this->safe_binlog ? '1' : '0'; }
        if (strpos($sql, 'SELECT IS_USED_LOCK') === 0) { return $this->owns_lock && (int) $query['args'][1] === $this->connection ? '1' : '0'; }
        if ($this->read_error) { $this->last_error = 'synthetic missing table'; return null; }
        if (strpos($sql, 'SHOW TABLES') === 0) { return $this->participants_available ? $this->prefix . 'author_chat_room_participants' : null; }
        if (!$this->vars) { throw new Exception('Unexpected database scalar read'); }
        return array_shift($this->vars);
    }
    public function get_results($query, $format) {
        $this->queries[] = $query;
        $this->last_error = $this->read_error ? 'synthetic missing table' : '';
        if ($this->read_error) { return null; }
        if (null === $this->history) { return $this->rows; }
        // This is a narrow query model, NOT a SQL engine. Integration tests must
        // still execute these statements on real WordPress/MySQL/MariaDB.
        $sql = is_array($query) ? $query['sql'] : $query;
        $args = is_array($query) ? $query['args'] : array();
        $scoped = strpos($sql, 'WHERE (m.chat_room_id = 0 OR EXISTS') !== false;
        check($scoped || strpos($sql, 'WHERE m.chat_room_id = 0') !== false, 'unscoped history query');
        $user = $scoped ? array_shift($args) : null;
        $room = strpos($sql, 'AND m.chat_room_id = %d') !== false ? array_shift($args) : null;
        $after = strpos($sql, 'AND m.id > %d') !== false ? array_shift($args) : null;
        $rows = array_values(array_filter($this->history, function ($row) use ($scoped, $user, $room, $after) {
            $allowed = (int) $row['chat_room_id'] === 0 || ($scoped && isset($this->members[$row['chat_room_id']]) && in_array($user, $this->members[$row['chat_room_id']], true));
            return $allowed && (null === $room || (int) $row['chat_room_id'] === $room) && (null === $after || (int) $row['id'] > $after);
        }));
        return strpos($sql, 'LIMIT 201') !== false ? array_slice($rows, 0, 201) : $rows;
    }
    public function get_col($query) {
        $this->queries[] = $query;
        $this->last_error = $this->read_error ? 'synthetic read error' : '';
        if (is_string($query) && strpos($query, 'SHOW COLUMNS') === 0) { return $this->participant_columns; }
        return $this->columns;
    }
    public function query($query) {
        $this->queries[] = $query;
        $sql = $query['sql']; $args = $query['args'];
        check(strpos($sql, 'IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d') !== false, 'unguarded write');
        $message = strpos($sql, 'author_chat (user_id, nickname, content') !== false;
        $connection = $args[$message ? 6 : 3];
        if ($this->reconnect_before_write) { $this->connection++; $this->owns_lock = false; }
        if ($this->lose_lock_before_write) { $this->owns_lock = false; }
        if (!$this->write_ok) { return false; }
        if (!$this->owns_lock || (int) $connection !== $this->connection) { return 0; }
        if ($message) {
            $row = array('user_id' => $args[0], 'nickname' => $args[1], 'content' => $args[2], 'chat_room_id' => $args[3], 'date' => $args[4]);
            $this->writes[] = array('insert', $this->prefix . 'author_chat', $row);
        } else {
            $this->writes[] = array(strpos($sql, 'DELETE') === 0 ? 'delete' : 'insert', $this->prefix . 'author_chat_room_participants', array('user_id' => $args[0], 'chat_room_id' => $args[1]));
        }
        return 1;
    }
    public function insert($table, $row, $formats) { throw new Exception('Unprotected insert'); }
    public function delete($table, $row, $formats) { throw new Exception('Unprotected delete'); }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($value, $domain = '') { return $value; }
function current_user_can($cap) { return user_can(wp_get_current_user(), $cap); }
function add_action($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function add_filter($name, $callback) {}
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}
function register_uninstall_hook($file, $callback) {}
function plugin_basename($file) { return 'author-chat/' . basename($file); }
function wp_get_current_user() { return $GLOBALS['acting']; }
function get_current_user_id() { return wp_get_current_user()->ID; }
function is_user_logged_in() { return wp_get_current_user()->exists(); }
function get_option($name, $default = false) { return isset($GLOBALS['options'][$name]) ? $GLOBALS['options'][$name] : $default; }
function user_can($user, $cap) { return in_array($cap, $user->caps, true); }
function is_user_member_of_blog($id) { return isset($GLOBALS['users'][$id]) && $GLOBALS['users'][$id]->member; }
function get_user_by($field, $id) { return isset($GLOBALS['users'][$id]) ? $GLOBALS['users'][$id] : false; }
function wp_set_current_user($id) { $GLOBALS['acting'] = $GLOBALS['users'][$id]; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'synthetic-nonce' && $action === 'acppauthorchat-ajax'; }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
// Narrow doubles; the shared payload matrix also runs against real WordPress.
function wp_check_invalid_utf8($value) { return preg_match('//u', $value) ? $value : ''; }
function wp_strip_all_tags($value, $remove_breaks = false) {
    $value = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $value);
    $value = strip_tags($value);
    return trim($remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $value) : $value);
}
function sanitize_textarea_field($value) { throw new Exception('Do not sanitize chat messages destructively; exercise actual WP separately'); }
function wp_send_json($value, $status = 200) { throw new Chat_Json($value, $status); }

$hooks = array();
require dirname(__DIR__) . '/acppauthorchat-author-chat.php';

function check($condition, $message) { if (!$condition) { throw new Exception($message); } }
function fixture() {
    $GLOBALS['users'] = array(0 => new WP_User(0), 7 => new WP_User(7, array('level_2')), 8 => new WP_User(8, array('level_0')), 9 => new WP_User(9, array('manage_options')));
    $GLOBALS['acting'] = $GLOBALS['users'][7];
    $GLOBALS['options'] = array('author_chat_settings_access_all_users' => '1', 'author_chat_settings_name' => '0');
    $GLOBALS['wpdb'] = new Chat_Database_Double();
    $_POST = array();
}
function run_case($name, $callback) { fixture(); $callback(); echo "PASS: $name\n"; }
function find_query($needle) {
    foreach ($GLOBALS['wpdb']->queries as $query) {
        $sql = is_array($query) ? $query['sql'] : $query;
        if (strpos($sql, $needle) !== false) { return $query; }
    }
    throw new Exception('Expected query not found: ' . $needle);
}

run_case('only an authenticated AJAX hook is registered', function () {
    check(isset($GLOBALS['hooks']['wp_ajax_acppauthorchat']), 'missing hook');
    foreach (array_keys($GLOBALS['hooks']) as $hook) { check(strpos($hook, 'wp_ajax_nopriv_') !== 0, 'public hook'); }
});
run_case('access follows role settings and rejects guests and foreign-site users', function () {
    $GLOBALS['options']['author_chat_settings_access_all_users'] = '0';
    check(!acppauthorchat_can_access(), 'author must be excluded by default');
    $GLOBALS['options']['author_chat_settings_access_author'] = '1';
    check(acppauthorchat_can_access(), 'author option ignored');
    check(!acppauthorchat_can_access($GLOBALS['users'][8]), 'subscriber inherited author access');
    $GLOBALS['users'][7]->member = false;
    check(!acppauthorchat_can_access(), 'foreign site member');
    check(!acppauthorchat_can_access($GLOBALS['users'][0]), 'guest allowed');
});
run_case('each legacy role group uses its own option', function () {
    $GLOBALS['options']['author_chat_settings_access_all_users'] = '0';
    foreach (array(0 => 'subscriber', 1 => 'contributor', 2 => 'author', 3 => 'editor', 7 => 'editor') as $level => $group) {
        $GLOBALS['users'][7]->caps = array('level_' . $level);
        $GLOBALS['options'] = array('author_chat_settings_access_' . $group => '1');
        check(acppauthorchat_can_access(), 'enabled role denied');
        $GLOBALS['options'] = array();
        check(!acppauthorchat_can_access(), 'disabled role allowed');
    }
});
run_case('administrator cannot bypass private membership', function () {
    $GLOBALS['acting'] = $GLOBALS['users'][9];
    $GLOBALS['wpdb']->vars = array(null);
    check(is_wp_error(acppauthorchat_require_room(12)), 'administrator bypass');
});
run_case('only the oldest membership can manage participants', function () {
    $GLOBALS['wpdb']->vars = array('50', '8', '50', '7');
    check(is_wp_error(acppauthorchat_require_room(12, true)), 'ordinary member can manage');
    check(true === acppauthorchat_require_room(12, true), 'owner denied');
    find_query('ORDER BY id ASC LIMIT 1');
    check(is_wp_error(acppauthorchat_require_room(0, true)), 'main room can be managed');
});
run_case('strict IDs reject coercion, overflow and non-scalars', function () {
    foreach (array(-1, '1 OR 1=1', '1.0', '1e2', array(1), null, true, '99999999999999999999999999') as $value) {
        check(is_wp_error(acppauthorchat_validate_id($value)), 'unsafe ID accepted');
    }
    check(acppauthorchat_validate_id('0', true) === 0, 'main room rejected');
});
run_case('bad nonce never reaches a write', function () {
    $_POST = array('function' => 'send', 'nonce' => 'bad', 'message' => 'x');
    try { acppauthorchat_ajax(); check(false, 'JSON did not terminate'); }
    catch (Chat_Json $response) { check($response->status === 403, 'wrong denial'); }
    check(!$GLOBALS['wpdb']->writes, 'bad nonce wrote data');
});
run_case('sender identity comes from the session and multiline Unicode survives', function () {
    $result = acppauthorchat_ajax_operation(array('function' => 'send', 'user_id' => '9', 'nickname' => 'forged', 'message' => "Hello 😀\nSecond", 'room_pressed_button_id' => '0'));
    check(!is_wp_error($result), 'send failed');
    check($result['user_id'] === 7 && $result['nickname'] === 'synthetic 7', 'forged identity');
    check($result['content'] === "Hello 😀\nSecond", 'text damaged');
});
run_case('lock failure and write failure never report successful persistence', function () {
    $GLOBALS['wpdb']->lock = '0';
    check(is_wp_error(acppauthorchat_store_message(0, 'x')), 'lock failure accepted');
    check(!$GLOBALS['wpdb']->writes, 'write without lock');
    $GLOBALS['wpdb']->lock = '1'; $GLOBALS['wpdb']->write_ok = false;
    check(is_wp_error(acppauthorchat_store_message(0, 'x')), 'failed write accepted');
});
run_case('foreign-room operations stop before reading or changing content', function () {
    foreach (array('initiate', 'update', 'send', 'getUsersForRoom', 'whoIsChannelOwner', 'searchUser', 'addUser', 'removeUser') as $operation) {
        $GLOBALS['wpdb']->vars = array(null);
        $input = array('function' => $operation, 'room_pressed_button_id' => '77', 'room_id' => '77', 'user_id' => '8', 'message' => 'x', 'search_user' => 'ab');
        check(is_wp_error(acppauthorchat_ajax_operation($input)), 'foreign room operation allowed: ' . $operation);
    }
    check(!$GLOBALS['wpdb']->writes, 'foreign room mutated');
});
run_case('membership writes keep existing owner records and adding a member is idempotent', function () {
    // Membership ID 50 belongs to the acting user, who is the oldest member.
    $GLOBALS['wpdb']->vars = array('50', '7', '50', '7', '51');
    $duplicate = acppauthorchat_ajax_operation(array('function' => 'addUser', 'room_id' => '12', 'user_id' => '8'));
    check($duplicate === array() && !$GLOBALS['wpdb']->writes, 'duplicate membership added');
    $GLOBALS['wpdb']->vars = array('50', '7', '50', '7');
    acppauthorchat_ajax_operation(array('function' => 'removeUser', 'room_id' => '12', 'user_id' => '8'));
    check($GLOBALS['wpdb']->writes === array(array('delete', 'synthetic_author_chat_room_participants', array('user_id' => 8, 'chat_room_id' => 12))), 'unrelated membership changed');
});
run_case('room enumeration always uses the authenticated user', function () {
    $GLOBALS['wpdb']->columns = array('12');
    $result = acppauthorchat_ajax_operation(array('function' => 'getRoomsForUser', 'user_id' => '9'));
    check($result['chat_room_id'] === array('12'), 'wrong payload');
    check(find_query('SELECT DISTINCT chat_room_id')['args'] === array(7), 'enumerated another user');
});
run_case('search escapes LIKE metacharacters, binds SQL and limits results', function () {
    $GLOBALS['wpdb']->vars = array('50', '7');
    $GLOBALS['wpdb']->rows = array();
    $result = acppauthorchat_ajax_operation(array('function' => 'searchUser', 'room_id' => '12', 'search_user' => "a%_' OR 1=1"));
    check(!is_wp_error($result), 'search failed');
    $query = find_query('meta_value LIKE %s');
    check(strpos($query['sql'], 'LIMIT 20') !== false && strpos($query['sql'], 'LIKE %s') !== false && strpos($query['sql'], 'OR 1=1') === false, 'unsafe search SQL');
    check($query['args'][1] === "%a\\%\\_' OR 1=1%", 'LIKE wildcard not escaped');
});
run_case('empty, oversized and non-string messages never persist', function () {
    foreach (array('', '   ', str_repeat('a', 1001), array('x')) as $message) {
        check(is_wp_error(acppauthorchat_store_message(0, $message)), 'invalid message persisted');
    }
    check(!$GLOBALS['wpdb']->writes, 'invalid message wrote data');
});
run_case('room creation refuses unsafe allocation without changing orphaned history or memberships', function () {
    $_POST = array('function' => 'addRoom', 'nonce' => 'synthetic-nonce', 'room_id' => '12', 'user_id' => '9');
    try { acppauthorchat_ajax(); } catch (Chat_Json $response) {
        check($response->status === 503 && $response->payload['code'] === 'author_chat_schema_incomplete', 'unsafe allocation');
    }
    check(!$GLOBALS['wpdb']->writes && !$GLOBALS['wpdb']->queries, 'allocation attempted without durable reservations');
});
run_case('legacy histories beyond 5000 records remain complete', function () {
    $row = array('id' => '1', 'user_id' => '7', 'nickname' => 'n', 'content' => 'm', 'chat_room_id' => '0', 'date' => '2026-11-11 12:00:00');
    $GLOBALS['wpdb']->rows = array_fill(0, 5000, $row);
    check(count(acppauthorchat_read_messages()) === 5000, 'boundary history denied');
    $GLOBALS['wpdb']->rows[] = $row;
    $result = acppauthorchat_read_messages();
    check(count($result) === 5001, 'legacy history blocked or truncated');
    check(!$GLOBALS['wpdb']->writes, 'history mutated');
});
run_case('missing tables fail closed and cleanup/FCM do not run', function () {
    $GLOBALS['wpdb']->read_error = true;
    check(is_wp_error(acppauthorchat_require_room(12)), 'missing table allowed');
    check(is_wp_error(acppauthorchat_clean_up_chat_history()), 'cleanup active');
    check(is_wp_error(acppauthorchat_clean_up_database()), 'delete active');
    check(!function_exists('acppauthorchat_sendFCMNotification'), 'FCM transport remains');
    check(!$GLOBALS['wpdb']->writes, 'unexpected data mutation');
});


function history_row($id, $room = 0) {
    return array('id' => (string) $id, 'user_id' => '7', 'nickname' => 'Synthetic', 'content' => 'message-' . $id, 'chat_room_id' => (string) $room, 'date' => '2026-11-11 12:00:00');
}

foreach (array(0, 1, 5000, 5001, 50000) as $size) {
    run_case('paged and legacy history both retain all ' . $size . ' messages', function () use ($size) {
        $db = $GLOBALS['wpdb'];
        $db->history = array();
        for ($id = 1; $id <= $size; $id++) { $db->history[] = history_row($id); }
        $cursor = '0'; $seen = array();
        do {
            $result = acppauthorchat_ajax_operation(array('function' => 'initiate', 'room_pressed_button_id' => '0', 'after_id' => $cursor));
            check(!is_wp_error($result), 'page refused');
            check(count($result['id']) <= 200, 'oversized page');
            $seen = array_merge($seen, $result['id']);
            check(!$result['has_more'] || (int) $result['next_id'] > (int) $cursor, 'non-advancing cursor');
            $cursor = $result['next_id'];
        } while ($result['has_more']);
        check(count($seen) === $size && count(array_unique($seen)) === $size, 'records lost or duplicated');
        check($cursor === (string) $size, 'incorrect final cursor');
        $legacy = acppauthorchat_ajax_operation(array('function' => 'initiate', 'room_pressed_button_id' => '0'));
        check(!is_wp_error($legacy) && count($legacy['id']) === $size, 'legacy AJAX blocked or truncated');
        check(!isset($legacy['has_more']), 'legacy contract unexpectedly paginated');
        check(!$db->writes, 'reading mutated history');
    });
}

run_case('history pages cannot mix foreign rooms and membership is rechecked between pages', function () {
    $db = $GLOBALS['wpdb'];
    $db->history = array(history_row(1, 0), history_row(2, 12), history_row(3, 13), history_row(4, 12));
    $all = array('id' => array_column(acppauthorchat_read_messages(), 'id'));
    check($all['id'] === array('1', '2', '4'), 'foreign history leaked');
    $db->vars = array('50', '50');
    $room = acppauthorchat_ajax_operation(array('function' => 'update', 'room_pressed_button_id' => '12', 'after_id' => '0'));
    check($room['id'] === array('2', '4'), 'rooms mixed');
    $db->members[12] = array();
    $after = array('id' => array_column(acppauthorchat_read_messages(null, '1'), 'id'));
    check($after['id'] === array(), 'removed participant received another page');
});

run_case('AJAX paging rejects malformed cursors', function () {
    foreach (array(-1, array('0'), '1e2') as $cursor) {
        check(is_wp_error(acppauthorchat_ajax_operation(array('function'=>'update', 'after_id'=>$cursor))), 'bad cursor accepted');
    }
});

run_case('main chat survives missing participants while all private operations fail closed', function () {
    $db = $GLOBALS['wpdb']; $db->participants_available = false;
    $db->history = array(history_row(1, 0), history_row(2, 12), history_row(3, 13));
    $result = acppauthorchat_ajax_operation(array('function' => 'initiate', 'room_pressed_button_id' => '0'));
    check($result['id'] === array('1'), 'private history leaked without participants');
    $result = acppauthorchat_ajax_operation(array('function' => 'getRoomsForUser'));
    check($result['chat_room_id'] === array() && $result['private_chat_available'] === false, 'private rooms enumerated');
    check(is_wp_error(acppauthorchat_require_room(12)), 'private access without participants');
    $stored = acppauthorchat_store_message(0, 'main message');
    check(!is_wp_error($stored) && count($db->writes) === 1, 'main message unnecessarily blocked');
    $query = find_query('SELECT m.id');
    $sql = is_array($query) ? $query['sql'] : $query;
    check(strpos($sql, 'author_chat_room_participants') === false, 'main fallback still depends on missing table');
});

run_case('incomplete participants columns are unavailable but connection errors are not disguised as missing schema', function () {
    $GLOBALS['wpdb']->participant_columns = array('id', 'user_id');
    check(acppauthorchat_participants_available() === false, 'incomplete schema accepted');
    check(is_wp_error(acppauthorchat_require_room(12)), 'incomplete schema granted access');
    fixture();
    $GLOBALS['wpdb']->read_error = true;
    check(is_wp_error(acppauthorchat_participants_available()), 'connection error treated as absence');
});

run_case('text, encoded URLs, slashes, quotes and Unicode reach persistence unchanged', function () {
    $message = "https://example.test/a%20b%2Fc?q=%25\nZażółć 😀 O'Reilly C:\\path\\file";
    $result = acppauthorchat_store_message(0, $message);
    check(!is_wp_error($result) && $result['row']['content'] === $message, 'message changed');
    check($GLOBALS['wpdb']->writes[0][2]['content'] === $message, 'persistence changed text');
    // AJAX unslashes exactly once.
    fixture();
    $_POST = array('function' => 'send', 'nonce' => 'synthetic-nonce', 'message' => addslashes($message), 'room_pressed_button_id' => '0');
    try { acppauthorchat_ajax(); }
    catch (Chat_Json $response) { check($response->payload['content'] === $message, 'AJAX slash handling damaged text'); }
    foreach (array("x\0y", "x\x80y") as $invalid) {
        check(is_wp_error(acppauthorchat_validate_message($invalid)), 'invalid control/UTF-8 accepted');
    }
});

foreach (array('reconnect_before_write', 'lose_lock_before_write') as $failure) {
    run_case($failure . ' cannot write a message or mutate membership', function () use ($failure) {
        $db = $GLOBALS['wpdb']; $db->$failure = true;
        check(is_wp_error(acppauthorchat_store_message(0, 'message')), 'write after lock/connection loss');
        check(!$db->writes, 'message persisted without lock');
        foreach (array('addUser', 'removeUser') as $operation) {
            fixture(); $db = $GLOBALS['wpdb']; $db->$failure = true;
            $db->vars = array('50', '7', '50', '7', null);
            $result = acppauthorchat_ajax_operation(array('function' => $operation, 'room_id' => '12', 'user_id' => '8'));
            check(is_wp_error($result) && !$db->writes, 'membership mutation after lock/connection loss');
        }
    });
}

run_case('exceptions release the connection lock', function () {
    try { acppauthorchat_with_room_lock(function ($lock) { throw new Exception('synthetic callback error'); }); }
    catch (Exception $error) { check($error->getMessage() === 'synthetic callback error', 'wrong exception'); }
    check(!$GLOBALS['wpdb']->owns_lock, 'lock leaked');
});

run_case('allocation before migration fails before SQL regardless of caller, history or reconnect', function () {
    $db = $GLOBALS['wpdb'];
    $db->history = array(history_row(1, 900));
    $db->reconnect_before_write = true;
    $db->lose_lock_before_write = true;
    foreach (array(7, 8, 7, 9) as $user) {
        $GLOBALS['acting'] = $GLOBALS['users'][$user];
        $result = acppauthorchat_ajax_operation(array('function' => 'addRoom', 'room_id' => '900'));
        check(is_wp_error($result) && $result->get_error_code() === 'author_chat_schema_incomplete', 'unsafe allocation permitted');
    }
    check(!$db->queries && !$db->writes, 'attempted allocation or touched orphaned history');
});


run_case('large histories across main, own and foreign rooms never share foreign records', function () {
    $db = $GLOBALS['wpdb']; $db->history = array();
    for ($id = 1; $id <= 15003; $id++) { $db->history[] = history_row($id, $id <= 5001 ? 0 : ($id <= 10002 ? 12 : 13)); }
    $legacy = acppauthorchat_read_messages();
    check(count($legacy) === 10002 && !in_array('13', array_column($legacy, 'chat_room_id'), true), 'large legacy history mixed or truncated');
    $cursor = '0'; $seen = array();
    do {
        $db->vars = array('50', '50');
        $result = acppauthorchat_ajax_operation(array('function' => 'update', 'room_pressed_button_id' => '12', 'after_id' => $cursor));
        check(!is_wp_error($result), 'own-room page refused');
        $seen = array_merge($seen, $result['id']);
        $cursor = $result['next_id'];
    } while ($result['has_more']);
    check(count($seen) === 5001 && $seen[0] === '5002' && $seen[5000] === '10002', 'room page scope broken');
    $db->vars = array(null);
    check(is_wp_error(acppauthorchat_ajax_operation(array('function' => 'update', 'room_pressed_button_id' => '12', 'after_id' => $cursor))), 'revocation did not deny next private page');
});

run_case('global access loss and nonce expiry are distinct AJAX errors', function () {
    $GLOBALS['options']['author_chat_settings_access_all_users'] = '0';
    $_POST = array('function' => 'send', 'nonce' => 'synthetic-nonce', 'message' => 'x');
    try { acppauthorchat_ajax(); } catch (Chat_Json $response) {
        check($response->payload['code'] === 'author_chat_access_denied' && $response->status === 403, 'global access error ambiguous');
    }
    fixture(); $_POST = array('function' => 'send', 'nonce' => 'expired', 'message' => 'x');
    try { acppauthorchat_ajax(); } catch (Chat_Json $response) {
        check($response->payload['code'] === 'author_chat_invalid_nonce', 'nonce expiry ambiguous');
    }
    check(!$GLOBALS['wpdb']->writes, 'denied AJAX persisted data');
});


run_case('unsafe statement-based logging refuses guarded writes and releases the lock', function () {
    $GLOBALS['wpdb']->safe_binlog = false;
    $result = acppauthorchat_store_message(0, 'synthetic message');
    check(is_wp_error($result) && $result->get_error_code() === 'author_chat_database_mode', 'unsafe replication mode accepted');
    check(!$GLOBALS['wpdb']->writes && !$GLOBALS['wpdb']->owns_lock, 'unsafe write or leaked lock');
});

run_case('setting sanitizers reject compound types and enforce bounded values', function () {
    foreach (array(array('1'), new stdClass(), null, 'yes', '0') as $value) {
        check(acppauthorchat_sanitize_flag($value) === 0, 'invalid flag enabled a setting');
    }
    check(acppauthorchat_sanitize_flag('1') === 1 && acppauthorchat_sanitize_flag(1) === 1, 'checked flag lost');
    foreach (array(array(1), new stdClass(), 'not-a-number') as $value) {
        check(acppauthorchat_sanitize_interval($value) === 2, 'invalid interval');
        check(acppauthorchat_sanitize_days($value) === 30, 'invalid age');
    }
    check(acppauthorchat_sanitize_interval(-1) === 1 && acppauthorchat_sanitize_interval(900) === 10, 'interval bounds');
    check(acppauthorchat_sanitize_days(0) === 1 && acppauthorchat_sanitize_days(90000) === 36500, 'age bounds');
});

run_case('deactivation preserves data and legacy settings', function () {
    $before = $GLOBALS['options'];
    acppauthorchat_deactivate();
    check($GLOBALS['options'] === $before && !$GLOBALS['wpdb']->writes && !$GLOBALS['wpdb']->queries, 'data or settings touched');
});


$message_cases = require __DIR__ . '/message-cases.php';
foreach ($message_cases['valid'] as $label => $case) {
    run_case('plain-text storage: ' . $label, function () use ($case) {
        $result = acppauthorchat_store_message(0, $case[0]);
        check(!is_wp_error($result) && $result['row']['content'] === $case[1], 'response did not use sanitized text');
        check($GLOBALS['wpdb']->writes[0][2]['content'] === $case[1], 'database did not use sanitized text');
        fixture();
        $_POST = array('function' => 'send', 'nonce' => 'synthetic-nonce', 'message' => addslashes($case[0]), 'room_pressed_button_id' => '0');
        $responded = false;
        try { acppauthorchat_ajax(); }
        catch (Chat_Json $response) { $responded = $response->status === 200 && $response->payload['content'] === $case[1]; }
        check($responded && $GLOBALS['wpdb']->writes[0][2]['content'] === $case[1], 'AJAX sanitization or unslashing changed expected text');
    });
}
run_case('invalid or empty-after-sanitization messages never reach INSERT', function () use ($message_cases) {
    foreach ($message_cases['invalid'] as $message) {
        check(is_wp_error(acppauthorchat_store_message(0, $message)), 'invalid message accepted');
    }
    check(!$GLOBALS['wpdb']->writes, 'invalid content reached persistence');
});
