<?php
/*
Plugin Name: Author Chat Plugin
Plugin URI: http://smartfan.pl/
Description: Plugin that gives your authors an easy way to communicate through back-end UI (admin panel).'
Author: Piotr Pesta
Version: 0.7
Author URI: http://smartfan.pl/
License: GPL12
*/

add_action('admin_menu', 'author_chat_setup_menu');
add_action( 'wp_dashboard_setup', 'wp_dashboard_author_chat' );

function author_chat_setup_menu(){
	add_menu_page( 'Author Chat Options', 'Author Chat', 'manage_options', 'author-chat-options', 'author_chat_setup_init' );
	add_dashboard_page('Author Chat Window', 'Author Chat', 'read', 'author-chat', 'author_chat');
}

function wp_dashboard_author_chat(){
	wp_add_dashboard_widget('author-chat-widget', 'Author Chat', 'author_chat');
}

function author_chat_setup_init(){
	include('admin-menu.php');
}

function author_chat(){
	echo "Admin Page Test";
}

?>