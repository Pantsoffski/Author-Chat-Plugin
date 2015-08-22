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
		<th scope="row">Permanently delete chat history? (data will be deleted when you check this box and click ("Save Changes")</th>
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