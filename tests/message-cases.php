<?php
// Synthetic payloads only. No executable web endpoint.
return array(
    'valid' => array(
        'multiline Unicode and encoded URLs' => array("Zażółć gęślą jaźń 😀 👩‍💻 مرحبا\r\nSecond\n\tThird https://example.test/a%20b%2Fc?q=%25&x=1", "Zażółć gęślą jaźń 😀 👩‍💻 مرحبا\r\nSecond\n\tThird https://example.test/a%20b%2Fc?q=%25&x=1"),
        'slashes and quotes' => array("O'Reilly C:\\path\\file \\\"quote\\\"", "O'Reilly C:\\path\\file \\\"quote\\\""),
        'script and style content removed' => array('before<script>alert(1)</script><style>body{display:none}</style>after', 'beforeafter'),
        'SVG event and nested script removed' => array('<svg onload=alert(1)><script>alert(2)</script><text>hello</text></svg>', 'hello'),
        'image event removed' => array('before<img src=x onerror=alert(1)>after', 'beforeafter'),
        'mixed case and multiline tags' => array("first\n<ScRiPt>bad\ncode</sCrIpT><b>second</b>", "first\nsecond"),
        'HTML entities remain literal' => array('&lt;script&gt;alert(1)&lt;/script&gt; &#60;svg/onload=alert(2)&#62; &amp; &#x1F600;', '&lt;script&gt;alert(1)&lt;/script&gt; &#60;svg/onload=alert(2)&#62; &amp; &#x1F600;'),
        'double entities remain literal' => array('&amp;lt;img src=x onerror=alert(1)&amp;gt;', '&amp;lt;img src=x onerror=alert(1)&amp;gt;'),
        'text survives tag removal' => array("  <b>Zażółć</b>\n<i>😀</i> https://example.test/%20/%2F/%25  ", "Zażółć\n😀 https://example.test/%20/%2F/%25"),
        'plain comparison' => array('1 < 2 and 3 > 2', '1 < 2 and 3 > 2'),
        '1000 code points' => array(str_repeat('a', 1000), str_repeat('a', 1000)),
        '4000 bytes' => array(str_repeat('😀', 1000), str_repeat('😀', 1000)),
    ),
    'invalid' => array('', '   ', "\n\t\r\n", '<script>alert(1)</script>', '<svg onload=alert(1)></svg>', '<img src=x onerror=alert(1)>', '<b> </b>', '<!-- comment -->', "a\x80b", "a\0b", "a\x7fb", str_repeat('a', 1001), str_repeat('😀', 1001), '<b>'.str_repeat('a', 1000).'</b>', array('x'), null, 123),
);
