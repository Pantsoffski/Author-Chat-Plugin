<?php
/* Additive, per-site schema upgrades. Never removes or renumbers legacy rows. */
if (!defined('ABSPATH')) { exit; }

function acppauthorchat_schema_error() {
    return new WP_Error('author_chat_schema_incomplete', __('Author Chat database upgrade is incomplete. Please contact the site administrator.', 'author-chat'), array('status' => 503));
}

function acppauthorchat_columns($table) {
    global $wpdb;
    $rows = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) { return acppauthorchat_schema_error(); }
    $columns = array();
    foreach ($rows as $row) { $columns[$row['Field']] = $row; }
    return $columns;
}

function acppauthorchat_has_index($table, $columns, $unique = false, $primary = false) {
    global $wpdb;
    $rows = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) { return false; }
    $indexes = array();
    foreach ($rows as $row) {
        if (($unique && (int) $row['Non_unique'] !== 0) || ($primary && $row['Key_name'] !== 'PRIMARY') || !empty($row['Sub_part'])) { continue; }
        $indexes[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Column_name'];
    }
    foreach ($indexes as $parts) {
        ksort($parts);
        if (array_values($parts) === $columns) { return true; }
    }
    return false;
}

function acppauthorchat_install_schema() {
    global $wpdb, $acppauthorchat_db_version;
    $messages = $wpdb->prefix . 'author_chat';
    $members = $wpdb->prefix . 'author_chat_room_participants';
    $rooms = $wpdb->prefix . 'author_chat_rooms';
    $charset = $wpdb->get_charset_collate();
    $previous = $wpdb->suppress_errors(true);
    try {
        // Independent creates repair the old if/elseif partial-install defect.
        $creates = array(
            "CREATE TABLE IF NOT EXISTS $messages (
                id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL DEFAULT 0,
                nickname tinytext NOT NULL, content text NOT NULL,
                chat_room_id bigint(20) NOT NULL DEFAULT 0, date datetime NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB $charset",
            "CREATE TABLE IF NOT EXISTS $members (
                id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL DEFAULT 0,
                chat_room_id bigint(20) NOT NULL DEFAULT 0, PRIMARY KEY (id)
            ) ENGINE=InnoDB $charset",
            "CREATE TABLE IF NOT EXISTS $rooms (
                room_id bigint(20) NOT NULL AUTO_INCREMENT,
                request_id char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                creator_id bigint(20) NOT NULL DEFAULT 0,
                initialized tinyint(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (room_id), UNIQUE KEY request_id (request_id)
            ) ENGINE=InnoDB $charset"
        );
        foreach ($creates as $sql) {
            // Fixed DDL templates above contain only WordPress table names and its charset/collation.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if (false === $wpdb->query($sql)) { return acppauthorchat_schema_error(); }
        }
        foreach (array($messages, $members) as $table) {
            $columns = acppauthorchat_columns($table);
            if (is_wp_error($columns)) { return $columns; }
            foreach (array('user_id', 'chat_room_id') as $field) {
                if (!isset($columns[$field])) {
                    // Only the two historically supported additive columns.
                    $wpdb->query("ALTER TABLE $table ADD COLUMN $field bigint(20) NOT NULL DEFAULT 0");
                }
            }
            $columns = acppauthorchat_columns($table);
            $required = $table === $messages ? array('id', 'user_id', 'nickname', 'content', 'chat_room_id', 'date') : array('id', 'user_id', 'chat_room_id');
            if (is_wp_error($columns) || array_diff($required, array_keys($columns)) || !acppauthorchat_has_index($table, array('id'), true, true)) {
                return acppauthorchat_schema_error();
            }
            $invalid = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE chat_room_id < 0");
            if ($wpdb->last_error || (string) $invalid !== '0') { return acppauthorchat_schema_error(); }
        }
        $columns = acppauthorchat_columns($rooms);
        if (is_wp_error($columns) || array_diff(array('room_id', 'request_id', 'creator_id', 'initialized'), array_keys($columns)) ||
            stripos($columns['room_id']['Extra'], 'auto_increment') === false ||
            strtolower($columns['request_id']['Type']) !== 'char(64)' || $columns['request_id']['Null'] !== 'YES' ||
            !acppauthorchat_has_index($rooms, array('room_id'), true, true) ||
            !acppauthorchat_has_index($rooms, array('request_id'), true)) {
            return acppauthorchat_schema_error();
        }
        // Non-unique indexes retain historical duplicate memberships unchanged.
        foreach (array(
            array($messages, 'ac_room_id', array('chat_room_id', 'id')),
            array($members, 'ac_user_room', array('user_id', 'chat_room_id')),
            array($members, 'ac_room_order', array('chat_room_id', 'id'))
        ) as $index) {
            list($table, $name, $fields) = $index;
            if (!acppauthorchat_has_index($table, $fields)) {
                // Index and field names come exclusively from the fixed allowlist above.
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query("ALTER TABLE $table ADD KEY $name (" . implode(', ', $fields) . ')');
            }
            // Concurrent upgrades may already have added it. Verify the result.
            if (!acppauthorchat_has_index($table, $fields)) { return acppauthorchat_schema_error(); }
        }
        // Explicit IDs advance AUTO_INCREMENT; no MAX+1 allocation. UNION retains
        // empty rooms and orphaned history. Existing reservations are not modified.
        $sql = "INSERT INTO $rooms (room_id, request_id, creator_id, initialized)
            SELECT chat_room_id, NULL, 0, 1 FROM (
                SELECT chat_room_id FROM $messages WHERE chat_room_id > 0
                UNION SELECT chat_room_id FROM $members WHERE chat_room_id > 0
            ) historical_rooms
            ON DUPLICATE KEY UPDATE room_id = VALUES(room_id)";
        // Fixed migration SQL with WordPress table names; no request values or data interpolation.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.UnescapedDBParameter.QueryNotEscaped
        if (false === $wpdb->query($sql)) { return acppauthorchat_schema_error(); }
        $missing = $wpdb->get_var("SELECT COUNT(*) FROM (
            SELECT chat_room_id FROM $messages WHERE chat_room_id > 0
            UNION SELECT chat_room_id FROM $members WHERE chat_room_id > 0
        ) legacy LEFT JOIN $rooms r ON r.room_id = legacy.chat_room_id WHERE r.room_id IS NULL");
        if ($wpdb->last_error || (string) $missing !== '0' || true !== acppauthorchat_participants_available(true)) {
            return acppauthorchat_schema_error();
        }
        // Per-blog option, NOT the network-wide site option.
        update_option('author_chat_db_version', $acppauthorchat_db_version);
        return (string) get_option('author_chat_db_version') === $acppauthorchat_db_version ? true : acppauthorchat_schema_error();
    } finally {
        $wpdb->suppress_errors($previous);
    }
}

function acppauthorchat_registry_ready() {
    global $acppauthorchat_db_version;
    return (string) get_option('author_chat_db_version') === $acppauthorchat_db_version;
}

function acppauthorchat_create_room($request_id = null) {
    global $wpdb;
    if (!acppauthorchat_can_access()) { return acppauthorchat_denied(); }
    if (!acppauthorchat_registry_ready()) { return acppauthorchat_schema_error(); }
    if (null === $request_id) { $request_id = str_replace('-', '', wp_generate_uuid4()); }
    if (!is_string($request_id) || !preg_match('/^[a-f0-9]{32,64}$/D', $request_id)) { return acppauthorchat_invalid(); }
    $creator = get_current_user_id();
    $token = hash('sha256', $creator . ':' . $request_id);
    $rooms = $wpdb->prefix . 'author_chat_rooms';
    $members = $wpdb->prefix . 'author_chat_room_participants';
    // Retry the SAME unique token; never trust connection-local insert_id.
    $sql = $wpdb->prepare("INSERT INTO $rooms (request_id, creator_id, initialized) VALUES (%s, %d, 0)
        ON DUPLICATE KEY UPDATE request_id = VALUES(request_id)", $token, $creator);
    // This query was prepared immediately above with token and creator parameters.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    if (false === $wpdb->query($sql)) { return acppauthorchat_db_error(); }
    $room = $wpdb->get_row($wpdb->prepare("SELECT room_id, creator_id, initialized FROM $rooms WHERE request_id = %s", $token), ARRAY_A);
    if ($wpdb->last_error || !$room || (string) $room['creator_id'] !== (string) $creator) { return acppauthorchat_db_error(); }
    $id = acppauthorchat_validate_id($room['room_id']);
    if (is_wp_error($id)) { return acppauthorchat_db_error(); }
    if ((int) $room['initialized'] === 0) {
        // Reuse the existing membership-write lock only for initialization.
        // Pending rooms cannot be read/managed, so an interrupted initialization
        // can resume without re-adding a participant who has since left.
        $result = acppauthorchat_with_room_lock(function ($lock) use ($wpdb, $rooms, $members, $id, $token, $creator) {
            $state = $wpdb->get_var($wpdb->prepare("SELECT initialized FROM $rooms WHERE room_id = %d AND request_id = %s", $id, $token));
            if ($wpdb->last_error || null === $state) { return acppauthorchat_db_error(); }
            if ((int) $state === 1) { return true; }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $members WHERE chat_room_id = %d AND user_id = %d LIMIT 1", $id, $creator));
            if ($wpdb->last_error) { return acppauthorchat_db_error(); }
            if (!$exists) {
                $written = $wpdb->query($wpdb->prepare("INSERT INTO $members (user_id, chat_room_id)
                    SELECT %d, %d WHERE IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d",
                    $creator, $id, $lock['name'], $lock['connection']));
                if ($written !== 1) { return acppauthorchat_db_error(); }
            }
            $written = $wpdb->query($wpdb->prepare("UPDATE $rooms SET initialized = 1
                WHERE room_id = %d AND request_id = %s AND initialized = 0
                AND IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d",
                $id, $token, $lock['name'], $lock['connection']));
            return $written === 1 ? true : acppauthorchat_db_error();
        });
        if (is_wp_error($result)) { return $result; }
    }
    $allowed = acppauthorchat_require_room($id);
    return is_wp_error($allowed) ? $allowed : array('user_id' => $creator, 'chat_room_id' => $id, 'request_id' => $request_id);
}

function acppauthorchat_initialize_site($site) {
    $active = (array) get_site_option('active_sitewide_plugins', array());
    if (!isset($active[plugin_basename(__DIR__ . '/acppauthorchat-author-chat.php')])) { return; }
    switch_to_blog((int) $site->blog_id);
    try { acppauthorchat_activate(); } finally { restore_current_blog(); }
}
