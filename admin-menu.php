<?php

if($_POST['pp_author_chat_submit'] == 'Y') {
	$disclaimer = $_POST['pp_author_variable'];
}

?>

<div class="wrap">
	<h2>Author Chat Options</h2>
		<form name="pp_author_chat_form" method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
			<input type="hidden" name="pp_author_chat_submit" value="Y">
			<h3>Disclaimer Text:</h3>
			<textarea name="pp_author_variable" style="width: 100%;"><?php echo $variable; ?></textarea>
			<p class="submit">
				<input type="submit" name="Submit" value="Save" />
			</p>
		</form>
</div>