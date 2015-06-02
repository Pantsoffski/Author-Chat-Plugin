<?php

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_REFERER']!="chat.js") {
	die();
}

$function = filter_var($_POST['function'], FILTER_SANITIZE_STRING);
$log = array();
$upload_dir = $_SERVER["DOCUMENT_ROOT"] . '/wp-content/uploads/author-chat-plugin/';
    
switch($function) {
    
	case('getState'):
		if(file_exists($upload_dir . 'chat.txt')){
			$lines = file($upload_dir . 'chat.txt');
		}
		$log['state'] = count($lines); 
	break;
    	
	case('update'):
		$state = filter_var($_POST['state'], FILTER_SANITIZE_STRING);
       	if(file_exists($upload_dir . 'chat.txt')){
       	   $lines = file($upload_dir . 'chat.txt');
		}
		$count = count($lines);
		if($state == $count){
			$log['state'] = $state;
			$log['text'] = false;
		}else{
			$text= array();
			$log['state'] = $state + count($lines) - $state;
			foreach ($lines as $line_num => $line){
				if($line_num >= $state){
					$text[] = $line = str_replace("\n", "", $line);
				}
			}
			$log['text'] = $text;
		}
	break;
             
	case('send'):
		$nickname = htmlentities(strip_tags(filter_var($_POST['nickname'], FILTER_SANITIZE_STRING)));
		$reg_exUrl = "/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/";
		$message = htmlentities(strip_tags($_POST['message']), ENT_COMPAT, "UTF-8");
		$time1 = date('H:i:s');
		$time2 = date('j/n/Y');
		if(($message) != "\n"){
			if(preg_match($reg_exUrl, $message, $url)) {
				$message = preg_replace($reg_exUrl, '<a href="'.$url[0].'" target="_blank">'.$url[0].'</a>', $message);
			} 
         	fwrite(fopen($upload_dir . 'chat.txt', 'a'), "<span id=\"time\">" . $time1 . "<br>" . $time2 . "</span><span id=\"nick\">". $nickname . "</span>" . $message = str_replace("\n", " ", $message) . $upload_dirr . "\n");
		}
	break;
        	 
	case('initiate'):
		$state = filter_var($_POST['state'], FILTER_SANITIZE_STRING);
		if(file_exists($upload_dir . 'chat.txt')){
			$lines = file($upload_dir . 'chat.txt');
			$count = count($lines);
        	$text= array();
			$log['state'] = $state + count($lines) - $state;
			foreach ($lines as $line_num => $line){
				$text[] = $line = str_replace("\n", "", $line);
			}
		}
        $log['text'] = $text;
	break;
}
    
echo json_encode($log);

?>