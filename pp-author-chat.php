<?php
/*
 * Plugin Name: Author Chat Plugin
 * Plugin URI: http://ordin.pl/
 * Description: Plugin that gives your authors an easy way to communicate through back-end UI (admin panel).
 * Author: Piotr Pesta
 * Version: 1.5.1
 * Author URI: http://ordin.pl/
 * License: GPL12
 * Text Domain: author-chat
 * Domain Path: /lang
 */

include 'pp-process.php';

add_action('admin_menu', 'pp_author_chat_setup_menu');
add_action('wp_dashboard_setup', 'pp_wp_dashboard_author_chat');
add_action('admin_enqueue_scripts', 'pp_scripts_admin_chat');
register_activation_hook(__FILE__, 'pp_author_chat_activate');
register_uninstall_hook(__FILE__, 'pp_author_chat_uninstall');
add_action('plugins_loaded', 'pp_author_chat_load_textdomain');
add_action('in_admin_footer', 'pp_author_chat_chat_on_top');

function pp_author_chat_load_textdomain() {
    load_plugin_textdomain('author-chat', false, dirname(plugin_basename(__FILE__)) . '/lang/');
}

// create author_chat table
function pp_author_chat_activate() {
    global $wpdb;
    $author_chat_table = $wpdb->prefix . 'author_chat';
    $wpdb->query("CREATE TABLE IF NOT EXISTS $author_chat_table (
		id BIGINT(50) NOT NULL AUTO_INCREMENT PRIMARY KEY,
		nickname TINYTEXT NOT NULL,
		content TEXT NOT NULL,
		date DATETIME)
		CHARACTER SET utf8 COLLATE utf8_bin
		;");
    add_option('author_chat_settings', 30);
    add_option('author_chat_settings_access_editor', 0);
    add_option('author_chat_settings_access_author', 0);
    add_option('author_chat_settings_access_contributor', 0);
    add_option('author_chat_settings_access_subscriber', 0);
    add_option('author_chat_settings_access_all_users', 1);
    add_option('author_chat_settings_name', 0);
    add_option('author_chat_settings_window', 0);
    add_option('author_chat_settings_val', 0);
}

// delete author_chat table
function pp_author_chat_uninstall() {
    global $wpdb;
    $author_chat_table = $wpdb->prefix . 'author_chat';
    $wpdb->query("DROP TABLE IF EXISTS $author_chat_table");
    delete_option('author_chat_settings');
    delete_option('author_chat_settings_delete');
    delete_option('author_chat_settings_access_editor');
    delete_option('author_chat_settings_access_author');
    delete_option('author_chat_settings_access_contributor');
    delete_option('author_chat_settings_access_subscriber');
    delete_option('author_chat_settings_access_all_users');
    delete_option('author_chat_settings_name');
    delete_option('author_chat_settings_window');
    delete_option('author_chat_settings_val');
}

function pp_scripts_admin_chat() {

    wp_enqueue_script('chat-script', plugins_url('chat.js', __FILE__), array('jquery'));
    wp_enqueue_style('author-chat-style', plugins_url('author-chat-style.css', __FILE__));
    wp_enqueue_style('wp-jquery-ui-dialog');
    wp_enqueue_script('jquery-ui-dialog');
}

function pp_author_chat_setup_menu() {
    include 'pp-options.php';
    $optionsTitle = __('Author Chat Options', 'author-chat');
    add_dashboard_page('Author Chat', 'Author Chat', 'read', 'author-chat', 'pp_author_chat');
    add_menu_page($optionsTitle, $optionsTitle, 'administrator', 'acset', 'author_chat_settings', 'dashicons-carrot');
    add_action('admin_init', 'register_author_chat_settings');
}

function pp_wp_dashboard_author_chat() {
    wp_add_dashboard_widget('author-chat-widget', 'Author Chat', 'pp_author_chat');
}

function register_author_chat_settings() {
    register_setting('author_chat_settings_group', 'author_chat_settings');
    register_setting('author_chat_settings_group', 'author_chat_settings_delete');
    register_setting('author_chat_settings_group', 'author_chat_settings_access_editor');
    register_setting('author_chat_settings_group', 'author_chat_settings_access_author');
    register_setting('author_chat_settings_group', 'author_chat_settings_access_contributor');
    register_setting('author_chat_settings_group', 'author_chat_settings_access_subscriber');
    register_setting('author_chat_settings_group', 'author_chat_settings_access_all_users');
    register_setting('author_chat_settings_group', 'author_chat_settings_name');
    register_setting('author_chat_settings_group', 'author_chat_settings_window');
    register_setting('author_chat_settings_group', 'author_chat_settings_val');
}

class author_chat {
    
}

function pp_author_chat() {
    $resultA = pp_author_chat_sec();
    $current_user = wp_get_current_user();
    $current_screen = get_current_screen();
    if ((get_option('author_chat_settings_access_subscriber') == '1' && $current_user->user_level == '0') || (get_option('author_chat_settings_access_contributor') == '1' && $current_user->user_level == '1') || (get_option('author_chat_settings_access_author') == '1' && $current_user->user_level == '2') || (get_option('author_chat_settings_access_editor') == '1' && $current_user->user_level == '3') || (get_option('author_chat_settings_access_editor') == '1' && $current_user->user_level == '4') || (get_option('author_chat_settings_access_editor') == '1' && $current_user->user_level == '5') || (get_option('author_chat_settings_access_editor') == '1' && $current_user->user_level == '6') || (get_option('author_chat_settings_access_editor') == '1' && $current_user->user_level == '7' || $current_user->user_level == '8' || $current_user->user_level == '9' || $current_user->user_level == '10') || get_option('author_chat_settings_access_all_users') == '1') {
        ?>

        <script type="text/javascript">
            var chat = new Chat();
            jQuery(window).load(function () {
                chat.initiate();
                setInterval(function () {
                    chat.getState();
                }, 2000);
            });

        </script>

        <div id="page-wrap">

            <h2><?php _e('Author Chat', 'author-chat'); ?></h2>

            <p id="name-area"></p>

            <div id="chat-wrap">
                <div id="chat-area"></div>
            </div>

            <?php if ($resultA === true || $current_screen->base == 'dashboard_page_author-chat' || $current_screen->base == 'dashboard') { ?>
                <form id="send-message-area">
                    <textarea id="sendie" maxlength = "1000" placeholder="<?php _e('Your message...', 'author-chat'); ?>"></textarea>
                </form>
            <?php } elseif ($resultA === false) { ?>
                <div id="sendie-overlay"><p>To send text from here you need to buy premium version of that plugin, <b>$10.99 for lifetime 1 domain licence (future premium features included)</b>).</p></div>
            <?php } ?>

        </div>

        <script type="text/javascript">

            // shows current user name as name
            var name = "<?php echo $username = (get_option('author_chat_settings_name') == 0) ? $current_user->user_login : $current_user->display_name; ?>";

            // display name on page
            jQuery("#name-area").html("<?php _e('You are:', 'author-chat'); ?> <span>" + name + "</span>");

            // kick off chat
            var chat = new Chat();
            jQuery(function () {

                // watch textarea for key presses
                jQuery("#sendie").keydown(function (event) {

                    var key = event.which;

                    //all keys including return.  
                    if (key >= 33) {

                        var maxLength = jQuery(this).attr("maxlength");
                        var length = this.value.length;

                        // don't allow new content if length is maxed out
                        if (length >= maxLength) {
                            event.preventDefault();
                        }
                    }
                });
                // watch textarea for release of key press
                jQuery('#sendie').keyup(function (e) {

                    if (e.keyCode == 13) {

                        var text = jQuery(this).val();
                        var maxLength = jQuery(this).attr("maxlength");
                        var length = text.length;

                        // send 
                        if (length <= maxLength + 1) {

                            chat.send(text, name);
                            jQuery(this).val("");

                        } else {

                            jQuery(this).val(text.substring(0, maxLength));

                        }
                    }
                });
            });
        </script>

        <?php
    }
    pp_author_chat_clean_up_chat_history();

    if (get_option('author_chat_settings_delete') == 1) {
        pp_author_chat_clean_up_database();
    }
}

function pp_author_chat_chat_on_top() {
    $resultA = pp_author_chat_sec();
    $current_screen = get_current_screen();
    if (get_option('author_chat_settings_window') == 1 && $current_screen->base != 'dashboard' && $current_screen->base != 'dashboard_page_author-chat') {
        ?>
        <script>
            jQuery(document).ready(function () {
                function getCookie(cname) {
                    var name = cname + "=";
                    var decodedCookie = decodeURIComponent(document.cookie);
                    var ca = decodedCookie.split(';');
                    for (var i = 0; i < ca.length; i++) {
                        var c = ca[i];
                        while (c.charAt(0) == ' ') {
                            c = c.substring(1);
                        }
                        if (c.indexOf(name) == 0) {
                            return c.substring(name.length, c.length);
                        }
                    }
                    return "";
                }
                var myCookie = getCookie("dialogPos");
                if (myCookie == "") {
                    var dialogPosition = {top: "10", left: "10"};
                } else {
                    var parsedPosition = JSON.parse(myCookie);
                    var dialogPosition = {top: parsedPosition.top - 38, left: parsedPosition.left};
                }
                jQuery('#onTopChat').dialog({
                    resizable: false,
                    position: {
                        my: "left+" + dialogPosition.left + " top+" + dialogPosition.top,
                        at: "left top"
                    },
                    dragStop: function (event, ui) {
                        var dialogPosition = jQuery('#onTopChat').offset();
                        document.cookie = "dialogPos = " + JSON.stringify(dialogPosition);
                    }
                });
        <?php if ($resultA === false) {  ?>
                jQuery('#onTopChat2').dialog({
                    autoOpen: false,
                    modal: true,
                    draggable: false,
                    resizable: false
                });
                jQuery("#onTopChat").click(function () {
                    jQuery("#onTopChat2").dialog('open');
                });
        <?php }  ?>
            });
        </script>
        <div id="onTopChat" title="Author Chat">
            <p><?php pp_author_chat(); ?></p>
        </div>
        <?php if ($resultA === false) {  ?>
        <div id="onTopChat2" title="Buy Premium Version">
            <div id="author-chat-pp">
                <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
                    <input type="hidden" name="cmd" value="_s-xclick">
                    <input type="hidden" name="hosted_button_id" value="5TGRZ4BSETP9G">
                    <table>
                        <tr><td><input type="hidden" name="on0" value="Domain name">If your domain name is correct, do not change it.</td></tr><tr><td><input type="text" name="os0" maxlength="200" value="<?php echo $_SERVER['HTTP_HOST']; ?>"></td></tr>
                    </table>
                    <input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_buynowCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
                    <img alt="" border="0" src="https://www.paypalobjects.com/pl_PL/i/scr/pixel.gif" width="1" height="1">
                </form>
            </div>
        </div>
        <?php }  ?>
        <?php
    }
}

function pp_author_chat_clean_up_chat_history() {
    global $wpdb;
    $daystoclear = get_option('author_chat_settings');
    $author_chat_table = $wpdb->prefix . 'author_chat';
    $wpdb->query("DELETE FROM $author_chat_table WHERE date <= NOW() - INTERVAL $daystoclear DAY");
}

function pp_author_chat_clean_up_database() {
    global $wpdb;
    $author_chat_table = $wpdb->prefix . 'author_chat';
    $wpdb->query("TRUNCATE TABLE $author_chat_table");
    $update_options = get_option('author_chat_settings_delete');
    $update_options = '';
    update_option('author_chat_settings_delete', $update_options);
}

function pp_author_chat_sec() {
    $valOption = explode(",", get_option('author_chat_settings_val'));
    if ($valOption[0] == 0 || $valOption[0] <= time() - (1 * 24 * 60 * 60) && get_option('author_chat_settings_window') == 1) {
        $checkFile = file_get_contents(aURL);
        if ($checkFile === false) {
            return true;
        }
        $checkFile = str_getcsv($checkFile);
        $dmCompare = array_search($_SERVER['HTTP_HOST'], $checkFile);
        if ($dmCompare !== false) {
            $toUpdate = time() . ",1";
            update_option('author_chat_settings_val', $toUpdate);
            $result = true;
        } else {
            $toUpdate = time() . ",0";
            update_option('author_chat_settings_val', $toUpdate);
            $result = false;
        }
    } elseif ($valOption[1] == 1) {
        $result = true;
    } elseif ($valOption[1] == 0) {
        $result = false;
    } elseif (get_option('author_chat_settings_window') == 0) {
        update_option('author_chat_settings_val', 0);
    }
    $checkFile = file_get_contents(aURL);
    return $result;
}
?>