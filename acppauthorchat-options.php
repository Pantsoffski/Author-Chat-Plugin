<?php
if (!defined('ABSPATH')) { exit; }

function acppauthorchat_settings_page() {
    if (!current_user_can('manage_options')) { return; }
    $flags = array(
        'access_all_users' => __('All users belonging to this site', 'author-chat'),
        'access_editor' => __('Editors', 'author-chat'),
        'access_author' => __('Authors', 'author-chat'),
        'access_contributor' => __('Contributors', 'author-chat'),
        'access_subscriber' => __('Subscribers', 'author-chat'),
        'name' => __('Use display names instead of login names', 'author-chat'),
        'window' => __('Show the floating chat on other admin pages', 'author-chat'),
        'show_my_name' => __('Show my name on my messages', 'author-chat'),
        'weekdays' => __('Show weekday names for recent messages', 'author-chat'),
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Author Chat settings', 'author-chat'); ?></h1>
        <?php
        if (isset($_GET['acppauthorchat_result'], $_GET['acppauthorchat_nonce']) && is_string($_GET['acppauthorchat_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['acppauthorchat_nonce'])), 'acppauthorchat_prune_result')) {
            $removed = filter_var(wp_unslash($_GET['acppauthorchat_result']), FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 500)));
            if (false !== $removed) {
                /* translators: %d: number of messages removed by this request. */
                echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Removed %d old message(s).', 'author-chat'), $removed)) . '</p></div>';
            }
        }
        ?>
        <p><?php esc_html_e('Private rooms require membership. Only the room owner can manage participants.', 'author-chat'); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields('author_chat_settings_group'); ?>
            <table class="form-table" role="presentation">
                <?php foreach ($flags as $key => $label) {
                    $option = 'author_chat_settings_' . $key;
                    ?>
                    <tr><th scope="row"><label for="<?php echo esc_attr($option); ?>"><?php echo esc_html($label); ?></label></th>
                    <td><input type="hidden" name="<?php echo esc_attr($option); ?>" value="0">
                    <input type="checkbox" id="<?php echo esc_attr($option); ?>" name="<?php echo esc_attr($option); ?>" value="1" <?php checked(get_option($option), 1); ?>></td></tr>
                <?php } ?>
                <tr><th scope="row"><label for="acppauthorchat-interval"><?php esc_html_e('Refresh interval (seconds)', 'author-chat'); ?></label></th>
                <td><input id="acppauthorchat-interval" type="number" min="1" max="10" name="author_chat_settings_interval" value="<?php echo esc_attr(get_option('author_chat_settings_interval', 2)); ?>"></td></tr>
                <tr><th scope="row"><label for="acppauthorchat-days"><?php esc_html_e('Age threshold for manual cleanup (days)', 'author-chat'); ?></label></th>
                <td><input id="acppauthorchat-days" type="number" min="1" max="36500" name="author_chat_settings" value="<?php echo esc_attr(get_option('author_chat_settings', 30)); ?>">
                <p class="description"><?php esc_html_e('Saving this value does not delete messages. There is no automatic retention job.', 'author-chat'); ?></p></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2><?php esc_html_e('Manual history cleanup', 'author-chat'); ?></h2>
        <p><?php esc_html_e('Each confirmed request permanently removes at most 500 messages older than the saved threshold, across all rooms on this site. Memberships and room IDs are retained. Back up the database first.', 'author-chat'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="acppauthorchat_prune">
            <?php wp_nonce_field('acppauthorchat_prune'); ?>
            <p><label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e('I understand that these messages cannot be restored by the plugin.', 'author-chat'); ?></label></p>
            <?php submit_button(__('Remove up to 500 old messages', 'author-chat'), 'secondary', 'acppauthorchat_prune_submit', false); ?>
        </form>
        <p><?php esc_html_e('External previews and push notifications are not used. Older preview, deletion and license options are retained in storage but are not active settings.', 'author-chat'); ?></p>
    </div>
    <?php
}

function acppauthorchat_prune_history($days) {
    global $wpdb;
    if (!current_user_can('manage_options')) { return acppauthorchat_denied(); }
    $valid = filter_var($days, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 36500)));
    if (false === $valid) { return acppauthorchat_invalid(); }
    $cutoff = gmdate('Y-m-d H:i:s', time() - $valid * DAY_IN_SECONDS);
    // Prefix is supplied by WordPress. Keep invalid/zero dates for manual review.
    $result = $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'author_chat WHERE date >= %s AND date < %s AND DAYOFMONTH(date) BETWEEN 1 AND DAYOFMONTH(LAST_DAY(date)) ORDER BY id ASC LIMIT 500', '1970-01-01 00:00:00', $cutoff));
    return false === $result ? acppauthorchat_db_error() : $result;
}

function acppauthorchat_handle_prune() {
    if (!current_user_can('manage_options')) { wp_die(esc_html__('Access denied.', 'author-chat'), '', array('response' => 403)); }
    check_admin_referer('acppauthorchat_prune');
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['confirm']) || !is_string($_POST['confirm']) || sanitize_text_field(wp_unslash($_POST['confirm'])) !== '1') {
        wp_die(esc_html__('Explicit confirmation is required.', 'author-chat'), '', array('response' => 400));
    }
    $result = acppauthorchat_prune_history(get_option('author_chat_settings', 30));
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', array('response' => 503)); }
    wp_safe_redirect(add_query_arg(array('page' => 'acppauthorchat-settings', 'acppauthorchat_result' => (int) $result, 'acppauthorchat_nonce' => wp_create_nonce('acppauthorchat_prune_result')), admin_url('admin.php')));
    exit;
}
