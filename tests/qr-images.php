<?php
// Run with: php tests/qr-images.php (requires PHP GD).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "SKIP: PHP GD is required for real image tests.\n");
    exit(77);
}
$root = dirname(__DIR__);
$cache = sys_get_temp_dir() . '/iqrcodes-images-' . uniqid('', true);
mkdir($cache);
define('YOURLS_ABSPATH', $root);
define('YOURLS_SITE', 'https://sho.rt/links');
$_SERVER['DOCUMENT_ROOT'] = $cache;
mkdir($cache.'/user/plugins/iqrcodes/cache', 0777, true);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array('action'=>'qrchk', 'data'=>YOURLS_SITE.'/existing');
$format='png';
function yourls_sanitize_keyword($keyword) { return $keyword; }
function yourls_keyword_is_taken($keyword) { return $keyword==='existing'; }
function iqrcodes_get_opts() { global $cache,$format; return array('qr', 'H', 5, 2, 'preserve',$format,.25,'center','png','no',$cache); }
function iqrcodes_mkdir($path) { if (!is_dir($path)) mkdir($path); }
require $root.'/iqrcodes/assets/phpqrcode.php';
try {
 foreach (array('png','jpg','svg') as $format) {
  require $root.'/iqrcodes/assets/qrchk.php';
  $file=$cache.'/qrc_'.md5($_POST['data']).'.'.$format;
  if (!is_file($file) || filesize($file)===0) throw new Exception('Missing '.$format.' image');
  if ($format==='svg') {
   if (strpos(file_get_contents($file),'<svg')===false) throw new Exception('Invalid SVG');
  } elseif (getimagesize($file)===false) throw new Exception('Invalid bitmap');
  $hash=hash_file('sha256', $file);
  touch($file, 946684800);
  clearstatcache(true, $file);
  require $root.'/iqrcodes/assets/qrchk.php';
  clearstatcache(true, $file);
  if (filemtime($file)!==946684800 || hash_file('sha256', $file)!==$hash) throw new Exception('Existing cache entry was rewritten');
  echo 'PASS: actual '.$format.' output ('.filesize($file).' bytes)'.PHP_EOL;
 }
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
 rmdir($cache);
}
