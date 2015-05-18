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
add_action( 'admin_enqueue_scripts', 'scripts_admin_chat' );

function scripts_admin_chat(){
	wp_register_script('chat-script', plugins_url('chat.js', __FILE__ ));
	wp_enqueue_script('chat-script');
	wp_enqueue_style('author-chat-style', plugins_url('author-chat-style.css', __FILE__));
}

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
	global $current_user;
	get_currentuserinfo();
	?>
	
	<script type="text/javascript">
	
		jQuery(window).load(function(){
			initiateChat();
			setInterval(function(){ updateChat() }, 1000);
		});
	
	</script>

    <div id="page-wrap">
    
        <h2>Author Chat</h2>
        
        <p id="name-area"></p>
        
        <div id="chat-wrap"><div id="chat-area"></div></div>
        
        <form id="send-message-area">
            <textarea id="sendie" maxlength = "1000" placeholder="Your message..."></textarea>
        </form>
    
    </div>
	
    <script type="text/javascript">
    
        // shows current user name as name
        var name = "<?php echo "$current_user->user_login"; ?>";
    	
    	// display name on page
    	jQuery("#name-area").html("You are: <span>" + name + "</span>");
    	
    	// kick off chat
        var chat =  new Chat();
    	jQuery(function() {
    		
    		chat.getState();
    		 
    		 // watch textarea for key presses
			jQuery("#sendie").keydown(function(event) {  
             
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
    		 jQuery('#sendie').keyup(function(e) {	
    		 					 
    			  if (e.keyCode == 13) { 
    			  
                    var text = jQuery(this).val();
    				var maxLength = jQuery(this).attr("maxlength");  
                    var length = text.length; 
                     
                    // send 
                    if (length <= maxLength + 1) { 
                     
    			        chat.send(text, name);	
    			        jQuery(this).val("");
    			        
                    }else {
                    
    					jQuery(this).val(text.substring(0, maxLength));
    					
    				}
    			  }
             });
            chat.initiate();
    	});
    </script>
	
	<?php
}

?>