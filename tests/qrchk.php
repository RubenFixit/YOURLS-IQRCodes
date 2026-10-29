<?php
/* Run with: php tests/qrchk.php (no database or GD extension required). */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

define('YOURLS_ABSPATH', dirname(__DIR__));
define('YOURLS_SITE', 'https://sho.rt/links');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$cache = sys_get_temp_dir() . '/iqrcodes-test-' . uniqid('', true);
$format = 'png';
$lookups = 0;
$requests = 0;
$known = array('existing', 'plus+sign', 'encoded%2Bsign', 'nested/link');
function yourls_sanitize_keyword($keyword) {
    // Model the sanitizer's rejection of whitespace without a database bootstrap.
    return preg_replace('/\s+/', '', $keyword);
}
function yourls_keyword_is_taken($keyword) {
    global $known, $lookups;
    $lookups++;
    return in_array($keyword, $known, true);
}
function iqrcodes_get_opts() {
    global $cache, $format;
    return array('qr', 'H', 5, 2, 'preserve', $format, .25, 'center', 'png', 'no', $cache);
}
function iqrcodes_mkdir($path) {
    if (!is_dir($path)) {
        mkdir($path);
    }
}
class QRcode {
    public static $calls = array();
    public static function __callStatic($format, $args) {
        self::$calls[] = array($format, $args);
        file_put_contents($args[1], 'encoder fixture');
    }
}
function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function request($method, $post, $expectedStatus) {
    global $requests;
    $requests++;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $post;
    http_response_code(200);
    ob_start();
    require dirname(__DIR__) . '/iqrcodes/assets/qrchk.php';
    $body = ob_get_clean();
    check(http_response_code() === $expectedStatus, 'Unexpected status for ' . json_encode(array($method, $post)));
    check($body === '', 'Endpoint must not emit warnings or HTML');
}

try {
    $invalid = array(
        array('GET', array(), 405),
        array('HEAD', array(), 405),
        array('POST', array(), 400),
        array('POST', array('action' => array('qrchk'), 'data' => YOURLS_SITE . '/existing'), 400),
        array('POST', array('action' => 'other', 'data' => YOURLS_SITE . '/existing'), 400),
        array('POST', array('action' => 'qrchk'), 400),
        array('POST', array('action' => 'qrchk', 'data' => null), 400),
        array('POST', array('action' => 'qrchk', 'data' => array('unexpected')), 400),
        array('POST', array('action' => 'qrchk', 'data' => ''), 400),
        array('POST', array('action' => 'qrchk', 'data' => 'https://other.example/existing'), 400),
        array('POST', array('action' => 'qrchk', 'data' => 'https://sho.rt.evil/links/existing'), 400),
        array('POST', array('action' => 'qrchk', 'data' => 'https://sho.rt/wrong/existing'), 400),
        array('POST', array('action' => 'qrchk', 'data' => 'https://other.example/?next=' . YOURLS_SITE . '/existing'), 400),
        array('POST', array('action' => 'qrchk', 'data' => 'javascript:alert(1)'), 400),
        array('POST', array('action' => 'qrchk', 'data' => YOURLS_SITE . '/'), 400),
        array('POST', array('action' => 'qrchk', 'data' => YOURLS_SITE . '/bad keyword'), 400),
        array('POST', array('action' => 'qrchk', 'data' => YOURLS_SITE . '/' . str_repeat('x', 201)), 400),
    );
    foreach ($invalid as $case) {
        request($case[0], $case[1], $case[2]);
    }
    check($lookups === 0, 'Malformed inputs must be rejected before database lookup');
    request('POST', array('action' => 'qrchk', 'data' => YOURLS_SITE . '/missing'), 404);
    check(count(QRcode::$calls) === 0 && !is_dir($cache), 'Rejected requests must not generate cache entries');

    foreach (array('png', 'jpg', 'svg') as $format) {
        foreach ($known as $keyword) {
            $url = YOURLS_SITE . '/' . $keyword;
            $post = array('action' => 'qrchk', 'data' => $url);
            $before = count(QRcode::$calls);
            request('POST', $post, 200);
            check(count(QRcode::$calls) === $before + 1, 'Generate exactly once for a missing cache entry');
            $call = QRcode::$calls[$before];
            check($call[0] === $format && $call[1][0] === $url, 'Preserve the URL and selected format');
            check($call[1][1] === $cache . '/qrc_' . md5($url) . '.' . $format, 'Cache filename must match the JavaScript client');
            check(array_slice($call[1], 2) === array('H', 5, 2), 'Preserve encoder settings');
            request('POST', $post, 200);
            check(count(QRcode::$calls) === $before + 1, 'Reuse cached images without regenerating');
        }
    }
    $format = 'invalid';
    $before = count(QRcode::$calls);
    request('POST', array('action' => 'qrchk', 'data' => YOURLS_SITE . '/existing'), 503);
    check(count(QRcode::$calls) === $before, 'Reject unsupported configured formats');
    echo "PASS: $requests request cases; invalid inputs, site/base-path validation, missing links, URL preservation, all three formats, and cache reuse.\n";
} finally {
    foreach (glob($cache . '/*') as $file) {
        unlink($file);
    }
    if (is_dir($cache)) {
        rmdir($cache);
    }
}
