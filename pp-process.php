<?php

/*global $wpdb;
$author_chat_table = $wpdb->prefix . 'author_chat';
$linesCount = $wpdb->get_var("SELECT COUNT(*) FROM $author_chat_table");*/

/*if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_REFERER']!="chat.js") {
	die();
}*/

if(isset($_POST['function'])){
	$function = filter_var($_POST['function'], FILTER_SANITIZE_STRING);
	$log = array();
    
	switch($function) {
		
		case('updateCount'):
			global $wpdb;
			$author_chat_table = $wpdb->prefix . 'author_chat';
			$linesCount = $wpdb->get_var("SELECT COUNT(*) FROM $author_chat_table");
			$log = $linesCount;
		break;
	    
		case('getState'):
			global $wpdb;
			$author_chat_table = $wpdb->prefix . 'author_chat';
			$newLinesCount = $wpdb->get_results("SELECT id FROM $author_chat_table ORDER BY id DESC LIMIT 1", ARRAY_A);
			$log = array_column($newLinesCount, 'id');
		break;
		
		case('send'):
			$nickname = htmlentities(strip_tags(filter_var($_POST['nickname'], FILTER_SANITIZE_STRING)));
			$reg_exUrl = "/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/";
			$message = htmlentities(strip_tags(filter_var($_POST['message'], FILTER_SANITIZE_STRING)), ENT_COMPAT, "UTF-8");
			if(($message) != "\n"){
				if(preg_match($reg_exUrl, $message, $url)) {
					$message = preg_replace($reg_exUrl, '<a href="'.$url[0].'" target="_blank">'.$url[0].'</a>', $message);
				}
				$content = "<span id=\"nick\">" . $nickname . "</span>" . $message = str_replace("\n", " ", $message) . "\n";
				global $wpdb;
				$author_chat_table = $wpdb->prefix . 'author_chat';
				$wpdb->query("INSERT INTO $author_chat_table (content, date) VALUES ('$content', NOW())");
			}
		break;
		
		case('update'):
			global $wpdb;
			$author_chat_table = $wpdb->prefix . 'author_chat';
			$lines = $wpdb->get_results("SELECT content FROM $author_chat_table ORDER BY id DESC LIMIT 1", ARRAY_A);
				$text = array();
				foreach ($lines as $line){
						$text[] = $line;
			}
			$log = array_column($text, 'content');
		break;

		case('initiate'):
				global $wpdb;
				$author_chat_table = $wpdb->prefix . 'author_chat';
				$lines = $wpdb->get_results("SELECT content FROM $author_chat_table ORDER BY id ASC", ARRAY_A);
				$text = array();
				foreach ($lines as $line){
					$text[] = $line;
				}
				$log = array_column($text, 'content');
		break;
	}
	echo wp_send_json($log);

}

?>