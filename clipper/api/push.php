<?php
// Push notifications for signed-in members.
//
// GET  ?endpoint=<url>          → the public key, whether this device is on, and the member's choices
// POST action=subscribe        → {sub: PushSubscription JSON}   turn this device on
// POST action=unsubscribe      → {endpoint}                     turn this device off
// POST action=prefs            → {prefs: {campaigns, views}}    which kinds to send
// POST action=test             → send a test to every device that's on
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');

try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'signedIn' => false, 'error' => 'Please sign in first.'], 401);
  $id = cn_data_id($m);
  if (empty($m['active']) || $id === '') cn_json_out(['ok' => false, 'error' => 'Notifications are included with every ClipNow plan.'], 403);
  if (!function_exists('openssl_pkey_derive')) cn_json_out(['ok' => false, 'error' => 'Notifications aren’t available on this server yet.'], 503);
  $out = function (array $p, string $endpoint = '') {
    $on = false; foreach ($p['subs'] as $s) if ($endpoint !== '' && $s['endpoint'] === $endpoint) $on = true;
    return ['ok' => true, 'key' => cn_push_keys()['public'], 'on' => $on, 'devices' => count($p['subs']), 'prefs' => array_intersect_key($p['prefs'], CN_PUSH_KINDS)];
  };

  if ($_SERVER['REQUEST_METHOD'] === 'GET') cn_json_out($out(cn_push_load($id), (string)($_GET['endpoint'] ?? '')));
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
  if (!cn_rate_ok('push:' . strtolower($m['email']), 60)) cn_json_out(['ok' => false, 'error' => 'Too many changes in a short time. Wait a few minutes.'], 429);
  $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
  $act = (string)($in['action'] ?? '');

  $h = fopen(cn_push_file($id) . '.lock', 'c'); if ($h) flock($h, LOCK_EX);
  $p = cn_push_load($id);

  if ($act === 'subscribe') {
    $sub = is_array($in['sub'] ?? null) ? $in['sub'] : [];
    $ep = (string)($sub['endpoint'] ?? ''); $k = is_array($sub['keys'] ?? null) ? $sub['keys'] : [];
    $p256 = (string)($k['p256dh'] ?? ''); $auth = (string)($k['auth'] ?? '');
    if (!cn_push_endpoint_ok($ep)) cn_json_out(['ok' => false, 'error' => 'This browser’s notification service isn’t supported.'], 422);
    if (strlen(cn_b64u_dec($p256)) !== 65 || strlen(cn_b64u_dec($auth)) < 16) cn_json_out(['ok' => false, 'error' => 'The browser sent an unusable subscription. Try again.'], 422);
    $p['subs'] = array_values(array_filter($p['subs'], fn($s) => $s['endpoint'] !== $ep));
    $p['subs'][] = ['endpoint' => $ep, 'p256dh' => $p256, 'auth' => $auth, 'created' => time(),
      'device' => mb_substr(preg_replace('/[^\x20-\x7E]/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 160)];
    $p['subs'] = array_slice($p['subs'], -10);   // up to 10 devices
    cn_push_save($id, $p);
    cn_push_remember_email((string)$m['email'], $id);
    if ($h) { flock($h, LOCK_UN); fclose($h); }
    $sent = cn_push($id, 'test', ['title' => 'Notifications are on', 'body' => 'ClipNow will let you know about your campaigns and when your clips take off.', 'url' => '/stats', 'tag' => 'welcome']);
    cn_json_out($out(cn_push_load($id), $ep) + ['sent' => $sent]);
  }
  if ($act === 'unsubscribe') {
    $ep = (string)($in['endpoint'] ?? '');
    $p['subs'] = array_values(array_filter($p['subs'], fn($s) => $s['endpoint'] !== $ep));
    cn_push_save($id, $p);
    cn_json_out($out($p, $ep));
  }
  if ($act === 'prefs') {
    $pr = is_array($in['prefs'] ?? null) ? $in['prefs'] : [];
    foreach (CN_PUSH_KINDS as $k => $_) if (array_key_exists($k, $pr)) $p['prefs'][$k] = (bool)$pr[$k];
    cn_push_save($id, $p);
    cn_json_out($out($p, (string)($in['endpoint'] ?? '')));
  }
  if ($act === 'test') {
    if ($h) { flock($h, LOCK_UN); fclose($h); }
    if (!$p['subs']) cn_json_out(['ok' => false, 'error' => 'Turn notifications on first.'], 422);
    $sent = cn_push($id, 'test', ['title' => 'Test from ClipNow', 'body' => 'Notifications are working on this device.', 'url' => '/', 'tag' => 'test']);
    cn_json_out($out(cn_push_load($id), (string)($in['endpoint'] ?? '')) + ['sent' => $sent, 'message' => $sent ? 'Sent. It should appear in a few seconds.' : 'It didn’t go through. Turn notifications off and on again on this device.']);
  }
  cn_json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => 'Notifications are unavailable right now. Please try again.'], 500);
}
