<?php
// Private tester link: /api/preview.php?k=KEY lets this browser see the full site while the
// coming-soon page is on (it sets the cn_preview cookie that .htaccess looks for).
// /api/preview.php?off=1 goes back to what visitors see. The key is in the admin portal (Site status).
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$opts = fn(int $exp) => ['expires' => $exp, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax'];
if (isset($_GET['off'])) {
  setcookie('cn_preview', '', $opts(time() - 3600));
  header('Location: ' . CN_SITE . '/', true, 303); exit;
}
$key = (string)(cn_config()['preview_key'] ?? '');
$k = (string)($_GET['k'] ?? '');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($key !== '' && $k !== '' && cn_rate_ok('preview:' . $ip, 20) && hash_equals($key, $k)) {
  setcookie('cn_preview', '1', $opts(time() + 180 * 86400));
  header('Location: ' . CN_SITE . '/?preview=on', true, 303); exit;
}
header('Location: ' . CN_SITE . '/', true, 303);
