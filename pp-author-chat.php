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

function test_plugin_setup_menu(){
	add_menu_page( 'Author Chat Options', 'Author Chat', 'manage_options', 'author-chat', 'author_chat_setup_init' );
}

function author_chat_setup_init() {
	include('admin.php');
}

?>