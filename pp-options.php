<?php

function author_chat_settings(){
?>
<div class="wrap">
<h2>Author Chat Options</h2>

<form method="post" action="options.php">
	<?php settings_fields('author_chat_settings_group'); ?>
	<?php do_settings_sections('author_chat_settings_group'); ?>
	<table class="form-table">
		<tr valign="top">
		<th scope="row">Delete chat history older than how many days?</th>
		<td>
		<input type="number" name="author_chat_settings" value="<?php echo esc_attr(get_option('author_chat_settings')); ?>" />
		</td>
		</tr>
                
		<tr valign="top">
		<th scope="row">Who should have access to Author Chat?</th>
		<td>
                <input type="checkbox" name="author_chat_settings_access_all_users" value="1" <?php checked(get_option('author_chat_settings_access_all_users', '1')); ?>/>All users with access to admin area<br>
		<input type="checkbox" name="author_chat_settings_access_editor" value="1" <?php checked(get_option('author_chat_settings_access_editor', '1')); ?>/>Editor<br>
		<input type="checkbox" name="author_chat_settings_access_author" value="1" <?php checked(get_option('author_chat_settings_access_author', '1')); ?>/>Author<br>
		<input type="checkbox" name="author_chat_settings_access_contributor" value="1" <?php checked(get_option('author_chat_settings_access_contributor', '1')); ?>/>Contributor<br>
		<input type="checkbox" name="author_chat_settings_access_subscriber" value="1" <?php checked(get_option('author_chat_settings_access_subscriber', '1')); ?>/>Subscriber<br>
		</td>
                </tr>
                
                <tr valign="top">
		<th scope="row">Choose how to display the authors: by Name or by Login?</th>
		<td>
		<input type="radio" name="author_chat_settings_name" value="0" <?php checked(get_option('author_chat_settings_name'), '0'); ?>/>Login (Username)<br>
		<input type="radio" name="author_chat_settings_name" value="1" <?php checked(get_option('author_chat_settings_name'), '1'); ?>/>Name (Display name)<br>
		</td>
		</tr>
                
		<tr valign="top">
		<th scope="row">Permanently delete chat history? (data will be deleted when you check this box and click "Save Changes"</th>
		<td>
		<input type="checkbox" name="author_chat_settings_delete" value="1" <?php checked(get_option('author_chat_settings_delete'), 1); ?>/>
		</td>
		</tr>
	</table>
    
	<?php submit_button(); ?>

</form>
</div>
<?php }

?>