<?php
/* Shared authorization and transport-independent operations. */
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_acppauthorchat', 'acppauthorchat_ajax');

function acppauthorchat_denied() {
    // Do not distinguish missing rooms from rooms belonging to somebody else.
    return new WP_Error('author_chat_forbidden', __('Access denied.', 'author-chat'), array('status' => 403));
}

function acppauthorchat_db_error() {
    return new WP_Error('author_chat_database_error', __('Chat is temporarily unavailable.', 'author-chat'), array('status' => 503));
}

function acppauthorchat_invalid() {
    return new WP_Error('author_chat_invalid_parameter', __('Invalid chat request.', 'author-chat'), array('status' => 400));
}

function acppauthorchat_private_unavailable() {
    return new WP_Error('author_chat_private_unavailable', __('Private chat is unavailable. Please contact the site administrator.', 'author-chat'), array('status' => 503));
}

function acppauthorchat_participants_available($refresh = false) {
    global $wpdb;
    static $checked_db, $checked_prefix, $available;
    if (!$refresh && $checked_db === $wpdb && $checked_prefix === $wpdb->prefix) {
        return $available;
    }
    $table = $wpdb->prefix . 'author_chat_room_participants';
    $previous = $wpdb->suppress_errors(true);
    try {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error) {
            // A connection/permissions error is not evidence that a table is absent.
            return acppauthorchat_db_error();
        }
        $columns = $found === $table ? $wpdb->get_col("SHOW COLUMNS FROM $table") : array();
        if ($wpdb->last_error) {
            return acppauthorchat_db_error();
        }
        $available = !array_diff(array('id', 'user_id', 'chat_room_id'), $columns);
        $checked_db = $wpdb;
        $checked_prefix = $wpdb->prefix;
        return $available;
    } finally {
        $wpdb->suppress_errors($previous);
    }
}

function acppauthorchat_schema_notice() {
    if (current_user_can('manage_options') && !acppauthorchat_registry_ready()) {
        echo '<div class="notice notice-error"><p>';
        esc_html_e('Author Chat database upgrade is incomplete. Existing data has been retained. Please contact the site administrator.', 'author-chat');
        echo '</p></div>';
    }
    if (current_user_can('manage_options') && false === acppauthorchat_participants_available()) {
        echo '<div class="notice notice-warning"><p>';
        esc_html_e('Author Chat: the participants table is missing or incomplete. Database upgrade has not completed. Only the main room is available.', 'author-chat');
        echo '</p></div>';
    }
}

function acppauthorchat_validate_id($value, $allow_zero = false) {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)) {
        return acppauthorchat_invalid();
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => $allow_zero ? 0 : 1)));
    return false === $id ? acppauthorchat_invalid() : $id;
}

function acppauthorchat_can_access($user = null) {
    $user = null === $user ? wp_get_current_user() : $user;
    if (!$user || !$user->exists() || !is_user_member_of_blog($user->ID)) {
        return false;
    }
    if ('1' === (string) get_option('author_chat_settings_access_all_users') || user_can($user, 'manage_options')) {
        return true;
    }
    // Keep legacy level groups, including custom roles with level capabilities.
    for ($level = 10; $level >= 0; $level--) {
        if (user_can($user, 'level_' . $level)) {
            if ($level >= 8) {
                return true;
            }
            $group = $level >= 3 ? 'editor' : ($level === 2 ? 'author' : ($level === 1 ? 'contributor' : 'subscriber'));
            return '1' === (string) get_option('author_chat_settings_access_' . $group);
        }
    }
    return false;
}

function acppauthorchat_require_room($room_id, $manage = false) {
    global $wpdb;
    if (!acppauthorchat_can_access()) {
        return acppauthorchat_denied();
    }
    if (0 === $room_id) {
        return $manage ? acppauthorchat_denied() : true;
    }
    $available = acppauthorchat_participants_available();
    if (true !== $available) {
        return is_wp_error($available) ? $available : acppauthorchat_private_unavailable();
    }
    $table = $wpdb->prefix . 'author_chat_room_participants';
    $member = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE chat_room_id = %d AND user_id = %d LIMIT 1", $room_id, get_current_user_id()));
    if ($wpdb->last_error) {
        return acppauthorchat_db_error();
    }
    if (!$member) {
        return acppauthorchat_denied();
    }
    if (acppauthorchat_registry_ready()) {
        $state = $wpdb->get_var($wpdb->prepare('SELECT initialized FROM ' . $wpdb->prefix . 'author_chat_rooms WHERE room_id = %d', $room_id));
        if ($wpdb->last_error) { return acppauthorchat_db_error(); }
        if ((string) $state !== '1') { return acppauthorchat_denied(); }
    }
    if ($manage) {
        $owner = acppauthorchat_room_owner($room_id);
        if (is_wp_error($owner)) {
            return $owner;
        }
        if ((string) get_current_user_id() !== (string) $owner) {
            return acppauthorchat_denied();
        }
    }
    return true;
}

function acppauthorchat_room_owner($room_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'author_chat_room_participants';
    $owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE chat_room_id = %d ORDER BY id ASC LIMIT 1", $room_id));
    return $wpdb->last_error ? acppauthorchat_db_error() : $owner;
}

/**
 * Run a guarded write while this connection owns the membership lock.
 * @param callable $callback Receives the lock name and original connection ID.
 * @return mixed|WP_Error Callback result, or an error before any unsafe write.
 */
function acppauthorchat_with_room_lock($callback) {
    global $wpdb;
    // Serialize membership writes. Every DML statement must also test this lock
    // and the original connection ID, including when wpdb retries after reconnect.
    // Never fall back to an unsafe write if advisory locks are unavailable.
    $name = 'author_chat_' . md5(DB_NAME . ':' . $wpdb->prefix);
    $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
    if ('1' !== (string) $locked || $wpdb->last_error) {
        return acppauthorchat_db_error();
    }
    try {
        // A lock predicate in DML must not be replayed as a statement on a
        // replica with a different lock context. MIXED logs unsafe DML as rows.
        $safe_log = $wpdb->get_var("SELECT @@GLOBAL.log_bin = 0 OR @@SESSION.binlog_format IN ('ROW', 'MIXED')");
        if ('1' !== (string) $safe_log || $wpdb->last_error) {
            return new WP_Error('author_chat_database_mode', __('Chat writes are unavailable with the current database configuration. Please contact the site administrator.', 'author-chat'), array('status' => 503));
        }
        $connection = $wpdb->get_var('SELECT CONNECTION_ID()');
        if ($wpdb->last_error || !ctype_digit((string) $connection)) {
            return acppauthorchat_db_error();
        }
        return call_user_func($callback, array('name' => $name, 'connection' => $connection));
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }
}

function acppauthorchat_validate_message($message) {
    if (!is_string($message) || strlen($message) > 4000 || !preg_match('/^.{1,1000}$/usD', $message) ||
        '' === trim($message) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
        return acppauthorchat_invalid();
    }
    return $message;
}

/**
 * Sanitize an unslashed new message without stripping URL percent sequences.
 *
 * @param mixed $message Unslashed input from the authenticated AJAX dispatcher.
 * @return string|WP_Error Plain text or a validation error.
 */
function acppauthorchat_sanitize_message( $message ) {
	$message = acppauthorchat_validate_message( $message );
	if ( is_wp_error( $message ) ) {
		return $message;
	}
	// Keep internal line breaks and literal entities; never decode them into HTML.
	$message = wp_strip_all_tags( wp_check_invalid_utf8( $message ), false );
	// Tag removal can leave an empty message. Enforce the same limits again.
	return acppauthorchat_validate_message( $message );
}

function acppauthorchat_store_message($room_id, $message) {
    global $wpdb;
    return acppauthorchat_with_room_lock(function ($lock) use ($room_id, $message, $wpdb) {
        $allowed = acppauthorchat_require_room($room_id);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        // Sanitize at the persistence boundary; historical records stay untouched.
        $message = acppauthorchat_sanitize_message($message);
        if (is_wp_error($message)) {
            return $message;
        }
        $user = wp_get_current_user();
        $nickname = get_option('author_chat_settings_name') == 0 ? $user->user_login : $user->display_name;
        $row = array(
            'user_id' => $user->ID,
            'nickname' => sanitize_text_field(str_replace('-', ' ', $nickname)),
            'content' => $message,
            'chat_room_id' => $room_id,
            'date' => gmdate('Y-m-d H:i:s'),
        );
        $sql = 'INSERT INTO ' . $wpdb->prefix . 'author_chat (user_id, nickname, content, chat_room_id, date)
            SELECT %d, %s, %s, %d, %s WHERE IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d';
        $args = array($row['user_id'], $row['nickname'], $message, $room_id, $row['date'], $lock['name'], $lock['connection']);
        if (0 !== $room_id) {
            $sql .= ' AND EXISTS (SELECT 1 FROM ' . $wpdb->prefix . 'author_chat_room_participants WHERE user_id = %d AND chat_room_id = %d)';
            $args[] = $row['user_id'];
            $args[] = $room_id;
        }
        // All values are bound above; only WordPress table names and fixed clauses compose the template.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $written = $wpdb->query($wpdb->prepare($sql, $args));
        if (1 !== $written) {
            return acppauthorchat_db_error();
        }
        // Successful persistence is independent of notification delivery.
        return array('id' => (string) $wpdb->insert_id, 'row' => $row);
    });
}

function acppauthorchat_read_messages($room_id = null, $after_id = null) {
    global $wpdb;
    if (!acppauthorchat_can_access()) {
        return acppauthorchat_denied();
    }
    if (null !== $room_id) {
        $allowed = acppauthorchat_require_room($room_id);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
    }
    $messages = $wpdb->prefix . 'author_chat';
    $participants = $wpdb->prefix . 'author_chat_room_participants';
    $sql = "SELECT m.id, m.user_id, m.nickname, m.content, m.chat_room_id, m.date FROM $messages m WHERE ";
    $args = array();
    if (0 === $room_id) {
        // Main-room traffic must not depend on the participants table.
        $sql .= 'm.chat_room_id = 0';
    } else {
        $available = acppauthorchat_participants_available();
        if (is_wp_error($available)) {
            return $available;
        }
        $sql .= $available ? "(m.chat_room_id = 0 OR EXISTS (SELECT 1 FROM $participants p WHERE p.chat_room_id = m.chat_room_id AND p.user_id = %d))" : 'm.chat_room_id = 0';
        if ($available) {
            $args[] = get_current_user_id();
        }
        if (null !== $room_id) {
            $sql .= ' AND m.chat_room_id = %d';
            $args[] = $room_id;
        }
    }
    if (null !== $after_id) {
        $after_id = acppauthorchat_validate_id($after_id, true);
        if (is_wp_error($after_id)) {
            return $after_id;
        }
        $sql .= ' AND m.id > %d';
        $args[] = $after_id;
    }
    // Opt-in pages use 200 rows plus a lookahead. Legacy AJAX requests
    // retain full history: their response size and total cost remain O(N).
    $sql .= ' ORDER BY m.id ASC' . (null === $after_id ? '' : ' LIMIT 201');
    // Optional clauses use bound arguments; an empty argument list means only fixed SQL and WP table names.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
    if ($wpdb->last_error) {
        return acppauthorchat_db_error();
    }
    return $rows;
}

function acppauthorchat_history_page(&$rows, $after_id) {
    $more = count($rows) > 200;
    if ($more) {
        array_pop($rows);
    }
    return array('next_id' => $rows ? (string) $rows[count($rows) - 1]['id'] : (string) $after_id, 'has_more' => $more);
}

function acppauthorchat_user_rooms() {
    global $wpdb;
    $available = acppauthorchat_participants_available();
    if (true !== $available) {
        return is_wp_error($available) ? $available : array();
    }
    $table = $wpdb->prefix . 'author_chat_room_participants';
    $sql = "SELECT DISTINCT chat_room_id FROM $table WHERE user_id = %d AND chat_room_id > 0";
    if (acppauthorchat_registry_ready()) {
        $sql .= ' AND EXISTS (SELECT 1 FROM ' . $wpdb->prefix . "author_chat_rooms r WHERE r.room_id = $table.chat_room_id AND r.initialized = 1)";
    }
    // The user placeholder is in the fixed base template above.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    $rows = $wpdb->get_col($wpdb->prepare($sql . ' ORDER BY chat_room_id ASC', get_current_user_id()));
    if ($wpdb->last_error) {
        return acppauthorchat_db_error();
    }
    return $rows;
}

function acppauthorchat_legacy_date($value, $offset = 0) {
    $timestamp = strtotime($value);
    return false === $timestamp ? '' : gmdate('Y-m-d,H:i:s', $timestamp + (int) round($offset * 3600));
}

function acppauthorchat_ajax() {
    if (!is_user_logged_in()) {
        $result = new WP_Error('author_chat_session_expired', __('Please sign in again.', 'author-chat'), array('status' => 401));
    } elseif (!acppauthorchat_can_access()) {
        $result = new WP_Error('author_chat_access_denied', __('Chat access is disabled for this user.', 'author-chat'), array('status' => 403));
    } elseif (!isset($_POST['nonce']) || !is_string($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'acppauthorchat-ajax')) {
        $result = new WP_Error('author_chat_invalid_nonce', __('Please reload the page before using chat.', 'author-chat'), array('status' => 403));
    } else {
        $input = wp_unslash($_POST);
        $result = acppauthorchat_ajax_operation($input);
    }
    if (is_wp_error($result)) {
        $data = $result->get_error_data();
        wp_send_json(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()), $data['status']);
        return;
    }
    // Preserve successful legacy payloads without a success/data wrapper.
    wp_send_json($result);
}

function acppauthorchat_ajax_operation($input) {
    global $wpdb;
    if (!acppauthorchat_can_access()) {
        return acppauthorchat_denied();
    }
    $operations = array('getState', 'send', 'update', 'initiate', 'addRoom', 'getRoomsForUser', 'searchUser', 'addUser', 'getUsersForRoom', 'removeUser', 'whoIsChannelOwner');
    if (!isset($input['function']) || !is_string($input['function']) || !in_array($input['function'], $operations, true)) {
        return acppauthorchat_invalid();
    }
    $operation = $input['function'];
    $messages = $wpdb->prefix . 'author_chat';
    $participants = $wpdb->prefix . 'author_chat_room_participants';
    if ('getState' === $operation) {
        // Legacy callers only. The web client polls bounded history pages directly.
        $available = acppauthorchat_participants_available();
        if (is_wp_error($available)) {
            return $available;
        }
        $sql = "SELECT COUNT(*) FROM $messages m WHERE m.chat_room_id = 0";
        if ($available) {
            $sql .= $wpdb->prepare(" OR EXISTS (SELECT 1 FROM $participants p WHERE p.chat_room_id = m.chat_room_id AND p.user_id = %d)", get_current_user_id());
        }
        // The optional user clause was prepared above; the remainder contains only WP table names.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = $wpdb->get_var($sql);
        return $wpdb->last_error ? acppauthorchat_db_error() : $count;
    }
    if ('getRoomsForUser' === $operation) {
        // Never trust a caller-supplied acting user_id.
        $rooms = acppauthorchat_user_rooms();
        return is_wp_error($rooms) ? $rooms : array('chat_room_id' => $rooms, 'private_chat_available' => acppauthorchat_participants_available(), 'can_create_rooms' => acppauthorchat_registry_ready());
    }
    if ('addRoom' === $operation) {
        if (array_key_exists('request_id', $input) && !is_string($input['request_id'])) { return acppauthorchat_invalid(); }
        return acppauthorchat_create_room(isset($input['request_id']) ? $input['request_id'] : null);
    }
    $message_operation = in_array($operation, array('send', 'update', 'initiate'), true);
    $field = $message_operation ? 'room_pressed_button_id' : 'room_id';
    $room_id = acppauthorchat_validate_id(isset($input[$field]) ? $input[$field] : ($message_operation ? '0' : null), true);
    if (is_wp_error($room_id)) {
        return $room_id;
    }
    $manage = in_array($operation, array('searchUser', 'addUser', 'removeUser'), true);
    $allowed = acppauthorchat_require_room($room_id, $manage);
    if (is_wp_error($allowed)) {
        return $allowed;
    }
    if ('send' === $operation) {
        $stored = acppauthorchat_store_message($room_id, isset($input['message']) ? $input['message'] : null);
        return is_wp_error($stored) ? $stored : $stored['row'];
    }
    if ('update' === $operation || 'initiate' === $operation) {
        $offset = isset($input['user_time_zone']) ? $input['user_time_zone'] : 0;
        if ((!is_string($offset) && !is_int($offset) && !is_float($offset)) || !preg_match('/^-?\\d{1,2}(?:\\.\\d{1,2})?$/D', (string) $offset) || $offset < -14 || $offset > 14) {
            return acppauthorchat_invalid();
        }
        $after_id = array_key_exists('after_id', $input) ? acppauthorchat_validate_id($input['after_id'], true) : null;
        if (is_wp_error($after_id)) {
            return $after_id;
        }
        $rows = acppauthorchat_read_messages($room_id, $after_id);
        if (is_wp_error($rows)) {
            return $rows;
        }
        $result = array('id' => array(), 'uid' => array(), 'nick' => array(), 'msg' => array(), 'date' => array());
        if (null !== $after_id) {
            $result = array_merge($result, acppauthorchat_history_page($rows, $after_id));
        }
        foreach ($rows as $row) {
            $result['id'][] = $row['id'];
            $result['uid'][] = $row['user_id'];
            $result['nick'][] = $row['nickname'];
            $result['msg'][] = $row['content'];
            $result['date'][] = acppauthorchat_legacy_date($row['date'], (float) $offset);
        }
        return $result;
    }
    if ('searchUser' === $operation) {
        if (!isset($input['search_user']) || !is_string($input['search_user']) || strlen($input['search_user']) > 200) {
            return acppauthorchat_invalid();
        }
        $term = sanitize_text_field($input['search_user']);
        if (!preg_match('/^.{2,50}$/usD', $term)) {
            return acppauthorchat_invalid();
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY user_id ASC LIMIT 20", 'nickname', '%' . $wpdb->esc_like($term) . '%'), ARRAY_A);
        if ($wpdb->last_error) {
            return acppauthorchat_db_error();
        }
        $result = array('user_id' => array(), 'nickname' => array());
        foreach ($rows as $row) {
            if (acppauthorchat_can_access(get_user_by('id', $row['user_id']))) {
                $result['user_id'][] = $row['user_id'];
                $result['nickname'][] = $row['meta_value'];
            }
        }
        return $result;
    }
    if ('getUsersForRoom' === $operation) {
        if (0 === $room_id) {
            return array('user_id' => array(), 'nickname' => array());
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.user_id, um.meta_value FROM $participants p INNER JOIN {$wpdb->usermeta} um ON p.user_id = um.user_id WHERE p.chat_room_id = %d AND um.meta_key = %s AND EXISTS (SELECT 1 FROM $participants access_member WHERE access_member.chat_room_id = p.chat_room_id AND access_member.user_id = %d) ORDER BY p.user_id ASC", $room_id, 'nickname', get_current_user_id()), ARRAY_A);
        if ($wpdb->last_error) {
            return acppauthorchat_db_error();
        }
        $result = array('user_id' => array(), 'nickname' => array());
        foreach ($rows as $row) {
            $result['user_id'][] = $row['user_id'];
            $result['nickname'][] = $row['meta_value'];
        }
        return $result;
    }
    if ('whoIsChannelOwner' === $operation) {
        $owner = 0 === $room_id ? null : acppauthorchat_room_owner($room_id);
        return is_wp_error($owner) ? $owner : array('user_id' => null === $owner ? array() : array($owner));
    }
    $target = acppauthorchat_validate_id(isset($input['user_id']) ? $input['user_id'] : null);
    if (is_wp_error($target)) {
        return $target;
    }
    return acppauthorchat_with_room_lock(function ($lock) use ($operation, $target, $room_id, $participants, $wpdb) {
        // Revalidate ownership after acquiring the lock, not just before waiting.
        $allowed = acppauthorchat_require_room($room_id, true);
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        $row = array('user_id' => $target, 'chat_room_id' => $room_id);
        if ('addUser' === $operation) {
            if (!acppauthorchat_can_access(get_user_by('id', $target))) {
                return acppauthorchat_denied();
            }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $participants WHERE user_id = %d AND chat_room_id = %d LIMIT 1", $target, $room_id));
            if ($wpdb->last_error) {
                return acppauthorchat_db_error();
            }
            if ($exists) {
                return array();
            }
            $written = $wpdb->query($wpdb->prepare("INSERT INTO $participants (user_id, chat_room_id) SELECT %d, %d WHERE IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d", $target, $room_id, $lock['name'], $lock['connection']));
            return 1 === $written ? $row : acppauthorchat_db_error();
        }
        // Do not rewrite surviving memberships or remove any message history.
        // No matching membership is a harmless, idempotent removal.
        $written = $wpdb->query($wpdb->prepare("DELETE FROM $participants WHERE user_id = %d AND chat_room_id = %d AND IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d", $target, $room_id, $lock['name'], $lock['connection']));
        if (false === $written) {
            return acppauthorchat_db_error();
        }
        // Distinguish an idempotent deletion from a zero-row retry without a lock.
        $held = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d', $lock['name'], $lock['connection']));
        return '1' === (string) $held && !$wpdb->last_error ? $row : acppauthorchat_db_error();
    });
}
