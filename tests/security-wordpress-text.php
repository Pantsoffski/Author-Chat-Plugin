<?php
// Optional read-only test in an ALREADY loaded isolated WordPress with this plugin.
// Example, only on a synthetic local site: wp eval-file tests/security-wordpress-text.php
// This file does not load wp-config, connect to a database, or write any data itself.
if (PHP_SAPI !== 'cli' || !defined('ABSPATH') || !function_exists('sanitize_textarea_field') || !function_exists('acppauthorchat_validate_message')) {
    fwrite(STDERR, "NOT TESTED: load an isolated WordPress and Author Chat first.\n");
    exit(1);
}

$url = 'https://example.test/a%20b%2Fc?q=%25';
if (sanitize_textarea_field($url) === $url) {
    throw new Exception('WordPress sanitizer characterization changed; inspect core and active filters.');
}
foreach (array($url, "Zażółć 😀 مرحبا\r\nO'Reilly C:\\path\\file", '<img src=x onerror=alert(1)>', '  literal text  ') as $message) {
    if (acppauthorchat_validate_message($message) !== $message || json_decode(wp_json_encode($message)) !== $message) {
        throw new Exception('Message text changed during validation/JSON encoding');
    }
}
echo "PASS: real WordPress sanitization is lossy; chat validation and JSON preserve text.\n";
