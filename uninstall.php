<?php
/**
 * Permanent data removal, invoked only by WordPress plugin deletion.
 * Deactivation and updates deliberately never include this file.
 */
if (!defined('WP_UNINSTALL_PLUGIN') || WP_UNINSTALL_PLUGIN !== 'author-chat/acppauthorchat-author-chat.php') {
    exit;
}

/** Whether a persistent key belongs to the plugin's reserved namespaces. */
function acppauthorchat_owns_key($key) {
    return str_starts_with($key, 'author_chat_') || str_starts_with($key, 'acppauthorchat_');
}

/** Abort deletion when DB permissions/connectivity prevent complete cleanup. */
function acppauthorchat_uninstall_failed() {
    load_plugin_textdomain('author-chat', false, dirname(plugin_basename(__FILE__)) . '/lang/');
    wp_die(esc_html__('Author Chat data removal failed. Check database permissions and retry deleting the plugin.', 'author-chat'));
}

/** Remove only the current site's tables, namespaced options, transients and jobs. */
function acppauthorchat_uninstall_site() {
    global $wpdb;
    foreach (array('author_chat', 'author_chat_room_participants', 'author_chat_rooms') as $suffix) {
        // Fixed suffix allowlist; %i quotes the current WordPress site's table identifier.
        if (false === $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $suffix))) {
            acppauthorchat_uninstall_failed();
        }
    }
    $patterns = array('author_chat_', 'acppauthorchat_', '_transient_author_chat_', '_transient_acppauthorchat_', '_transient_timeout_author_chat_', '_transient_timeout_acppauthorchat_');
    foreach ($patterns as $prefix) {
        $keys = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
        if ($wpdb->last_error) { acppauthorchat_uninstall_failed(); }
        foreach ($keys as $key) {
            if (str_starts_with($key, '_transient_timeout_')) { delete_transient(substr($key, 19)); }
            elseif (str_starts_with($key, '_transient_')) { delete_transient(substr($key, 11)); }
            else { delete_option($key); }
        }
    }
    // No production jobs are currently scheduled; also remove namespaced legacy jobs.
    foreach (_get_cron_array() as $events) {
        foreach ($events as $hook => $instances) {
            if (acppauthorchat_owns_key($hook)) { wp_unschedule_hook($hook); }
        }
    }
    // No plugin-owned user/post metadata exists. In particular, keep shared nicknames.
}

/** Clean all sites/networks without loading the plugin or running a migration. */
function acppauthorchat_uninstall_all_sites() {
    global $wpdb;
    if (is_multisite()) {
        $offset = 0;
        do {
            $sites = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $offset, 'network_id' => 0));
            foreach ($sites as $site_id) {
                switch_to_blog((int) $site_id);
                try { acppauthorchat_uninstall_site(); } finally { restore_current_blog(); }
            }
            $offset += count($sites);
        } while (count($sites) === 100);

        // Old releases read the schema version as a network option. Clean that namespace too.
        foreach (array('author_chat_', 'acppauthorchat_', '_site_transient_author_chat_', '_site_transient_acppauthorchat_', '_site_transient_timeout_author_chat_', '_site_transient_timeout_acppauthorchat_') as $prefix) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT site_id, meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like($prefix) . '%'), ARRAY_A);
            if ($wpdb->last_error) { acppauthorchat_uninstall_failed(); }
            foreach ($rows as $row) { delete_network_option((int) $row['site_id'], $row['meta_key']); }
        }
    } else {
        acppauthorchat_uninstall_site();
        // On single-site, site transients are stored in this site's options table.
        foreach (array('author_chat_', 'acppauthorchat_') as $prefix) {
            foreach (array('_site_transient_', '_site_transient_timeout_') as $type) {
                $keys = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($type . $prefix) . '%'));
                if ($wpdb->last_error) { acppauthorchat_uninstall_failed(); }
                foreach ($keys as $key) { delete_site_transient(substr($key, strlen($type))); }
            }
        }
    }
}

acppauthorchat_uninstall_all_sites();
