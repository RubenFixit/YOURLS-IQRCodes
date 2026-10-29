<?php
/*
 * IQRCodes cache check, exposed as the YOURLS /qrchk user page.
 * Only existing short URLs from this installation may populate the cache.
 */
if (!defined('YOURLS_ABSPATH')) {
    die();
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    return;
}

if (!isset($_POST['action'], $_POST['data'])
    || $_POST['action'] !== 'qrchk'
    || !is_string($_POST['data'])
    || $_POST['data'] === '') {
    http_response_code(400);
    return;
}

// PHP already decodes form fields. Decoding again changes '+' and '%xx' in
// the URL, and produces a different cache key from the JavaScript client.
$shorturl = $_POST['data'];
$prefix = rtrim(YOURLS_SITE, '/') . '/';
if (strpos($shorturl, $prefix) !== 0) {
    http_response_code(400);
    return;
}

$keyword = substr($shorturl, strlen($prefix));
if ($keyword === '' || strlen($keyword) > 200
    || yourls_sanitize_keyword($keyword) !== $keyword) {
    http_response_code(400);
    return;
}

if (!yourls_keyword_is_taken($keyword)) {
    http_response_code(404);
    return;
}

$opt = iqrcodes_get_opts();
if (!in_array($opt[5], array('png', 'jpg', 'svg'), true)) {
    http_response_code(503);
    return;
}

$filename = 'qrc_' . md5($shorturl) . '.' . $opt[5];
$filepath = rtrim($opt[10], '/\\') . '/' . $filename;
if (!is_file($filepath)) {
    iqrcodes_mkdir($opt[10]);
    QRcode::{$opt[5]}($shorturl, $filepath, $opt[1], $opt[2], $opt[3]);
}
