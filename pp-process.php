<?php

/* Author Chat Process v1.8.0 */

define('aURL', 'https://ordin.pl/auth/author_chat/author_chat.csv');

function debug_to_console( $data ) {
    $output = $data;
    if ( is_array( $output ) ) {
        $output = implode( ',', $output);
    }
    echo "<script>console.log( 'Debug Objects: " . $output . "' );</script>";
}

if (!function_exists('array_column')) {

    function array_column(array $input, $columnKey, $indexKey = null) {
        $array = array();
        foreach ($input as $value) {
            if (!isset($value[$columnKey])) {
                trigger_error("Key \"$columnKey\" does not exist in array");
                return false;
            }
            if (is_null($indexKey)) {
                $array[] = $value[$columnKey];
            } else {
                if (!isset($value[$indexKey])) {
                    trigger_error("Key \"$indexKey\" does not exist in array");
                    return false;
                }
                if (!is_scalar($value[$indexKey])) {
                    trigger_error("Key \"$indexKey\" does not contain scalar value");
                    return false;
                }
                $array[$value[$indexKey]] = $value[$columnKey];
            }
        }
        return $array;
    }

}

if (isset($_POST['function'])) {
    global $wpdb;
    $author_chat_table = $wpdb->prefix . 'author_chat';
    $author_chat_room_participants_table = $wpdb->prefix . 'author_chat_room_participants';
    $wp_usermeta = $wpdb->prefix . 'usermeta';
    $function = filter_var($_POST['function'], FILTER_SANITIZE_STRING);
    
    if (isset($_POST['room_pressed_button_id'])) {
        $room_pressed_button_id = strip_tags(filter_var($_POST['room_pressed_button_id'], FILTER_SANITIZE_STRING));
    } else {
        $room_pressed_button_id = '0';
    }
    
    $result = array();

    switch ($function) {
        case( 'getState' ):
            $result = $wpdb->get_var("SELECT COUNT(*) FROM $author_chat_table");
            break;

        case( 'send' ):
            $user_id = strip_tags(filter_var($_POST['user_id'], FILTER_SANITIZE_STRING));
            $nickname = strip_tags(filter_var($_POST['nickname'], FILTER_SANITIZE_STRING));
            $message = strip_tags(filter_var($_POST['message'], FILTER_SANITIZE_STRING));
            if (( $message ) != '\n') {
                $result = array(
                    'user_id' => $user_id,
                    'nickname' => $nickname,
                    'content' => $message,
                    'chat_room_id' => $room_pressed_button_id,
                    'date' => date('Y-m-d H:i:s')
                );

                $wpdb->insert($author_chat_table, $result, array('%d', '%s', '%s', '%d', '%s'));
            }
            break;

        case( 'update' ):
            $lines = $wpdb->get_results("SELECT id, user_id, nickname, content, chat_room_id, date FROM $author_chat_table ORDER BY id ASC", ARRAY_A);
            $text = array();
            foreach ($lines as $line) {
                if ($line['chat_room_id'] == $room_pressed_button_id) { // Show only main chat room conversation
                    $text[] = $line;
                }
            }
            
            $date = array_column($text, 'date');
            array_walk_recursive($date, function( &$element ) {
                $element = strtotime($element);
                $element = date('Y-m-d,H:i:s', $element);
            });
            $result = array(
                'id' => array_column($text, 'id'),
                'uid' => array_column($text, 'user_id'),
                'nick' => array_column($text, 'nickname'),
                'msg' => array_column($text, 'content'),
                'date' => $date
            );
            break;

        case( 'initiate' ):
            $lines = $wpdb->get_results("SELECT id, user_id, nickname, content, chat_room_id, date FROM $author_chat_table ORDER BY id ASC", ARRAY_A);
            $text = array();
            foreach ($lines as $line) {
                if ($line['chat_room_id'] == $room_pressed_button_id) { // Show only main chat room conversation
                    $text[] = $line;
                }
            }
            $date = array_column($text, 'date');
            array_walk_recursive($date, function( &$element ) {
                $element = strtotime($element);
                $element = date('Y-m-d,H:i:s', $element);
            });
            $result = array(
                'id' => array_column($text, 'id'),
                'uid' => array_column($text, 'user_id'),
                'nick' => array_column($text, 'nickname'),
                'msg' => array_column($text, 'content'),
                'date' => $date
            );
            break;
            
        case( 'addRoom' ):
            //Remove rooms without conversations before adding another one
            $wpdb->query(
                    $wpdb->prepare("
                SELECT acrpt.* FROM $author_chat_room_participants_table acrpt
		INNER JOIN $author_chat_table act ON acrpt.chat_room_id = act.chat_room_id
		")
            );
            //$messagesRoomsIds = $wpdb->get_results("SELECT DISTINCT chat_room_id FROM $author_chat_table WHERE NOT chat_room_id = 0", ARRAY_A);
                    
            //$wpdb->delete($author_chat_room_participants_table, $messagesRoomsIds);

            $user_id = strip_tags(filter_var($_POST['user_id'], FILTER_SANITIZE_STRING));
            $room_id = strip_tags(filter_var($_POST['room_id'], FILTER_SANITIZE_STRING));

            $result = array(
                'user_id' => $user_id,
                'chat_room_id' => $room_id
            );

            $wpdb->insert($author_chat_room_participants_table, $result, array('%d', '%d'));
            break;
        
        case( 'getRoomsForUser' ):
            $user_id = strip_tags(filter_var($_POST['user_id'], FILTER_SANITIZE_STRING));
            
            $lines = $wpdb->get_results("SELECT user_id, chat_room_id FROM $author_chat_room_participants_table WHERE user_id = $user_id", ARRAY_A);
                        
            $text = array();
            foreach ($lines as $line) {
                    $text[] = $line;
            }

            $result = array(
                'chat_room_id' => array_column($text, 'chat_room_id')
            );
            break;
            
        case( 'searchUser' ):
            if (isset($_POST['search_user'])) {
                $user_name = strip_tags(filter_var($_POST['search_user'], FILTER_SANITIZE_STRING));

                $lines = $wpdb->get_results("SELECT user_id, meta_value FROM $wp_usermeta WHERE meta_value LIKE '%$user_name%' AND meta_key = 'nickname'", ARRAY_A);

                $text = array();
                foreach ($lines as $line) {
                    $text[] = $line;
                }

                $result = array(
                    'user_id' => array_column($text, 'user_id'),
                    'nickname' => array_column($text, 'meta_value')
                );
            }
            break;
    }
    echo wp_send_json($result);
}
?>