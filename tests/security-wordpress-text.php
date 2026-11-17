<?php
// Read-only assertions using an already loaded isolated WordPress and Author Chat.
if (PHP_SAPI !== 'cli' || !defined('AUTHOR_CHAT_SYNTHETIC_TESTS') || AUTHOR_CHAT_SYNTHETIC_TESTS !== true ||
    !function_exists('acppauthorchat_sanitize_message')) {
    fwrite(STDERR, "NOT TESTED: load an isolated WordPress and Author Chat first.\n");
    exit(2);
}
$cases = require __DIR__ . '/message-cases.php';
foreach ($cases['valid'] as $label => $case) {
    $actual = acppauthorchat_sanitize_message($case[0]);
    if ($actual !== $case[1] || json_decode(wp_json_encode($actual)) !== $case[1]) {
        throw new Exception('Real WordPress sanitizer/JSON mismatch: ' . $label);
    }
}
foreach ($cases['invalid'] as $message) {
    if (!is_wp_error(acppauthorchat_sanitize_message($message))) {
        throw new Exception('Real WordPress accepted invalid or empty-after-sanitization content');
    }
}
echo "PASS: real WordPress plain-text sanitization, HTML/entities, UTF-8, multiline text, percent URLs and length boundaries.\n";
