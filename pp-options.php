<?php

function baw_settings_page(){
?>
<div class="wrap">
<h2>Author Chat Options</h2>

<form method="post" action="options.php">
	<?php settings_fields('baw_settings_group'); ?>
	<?php do_settings_sections('baw_settings_group'); ?>
	<table class="form-table">
		<tr valign="top">
		<th scope="row">Delete chat history after how many days?</th>
		<td>
		<input type="number" name="baw_settings" value="<?php echo esc_attr(get_option('baw_settings')); ?>" />
		</td>
		</tr>
	</table>
    
	<?php submit_button(); ?>

</form>
</div>
<?php }

?>