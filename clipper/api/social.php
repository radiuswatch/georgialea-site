<?php
// GET  /api/social.php                 → which social accounts the member has connected
// POST /api/social.php {action:connect, platform?, back?} → a link to the page where they connect
//      (just that platform when one is given; back = the ClipNow page to return to)
// POST /api/social.php {action:disconnect, platform}     → unlinks that platform
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'signedIn' => false, 'error' => 'Please sign in first.'], 401);
  $limit = cn_post_limit($m);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cn_same_origin()) cn_json_out(['ok' => false], 400);
    if ($limit === 0) cn_json_out(['ok' => false, 'error' => 'Auto-posting is included with an active ClipNow plan. Start a free trial to use it.'], 403);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $act = (string)($in['action'] ?? 'connect'); $plat = (string)($in['platform'] ?? '');
    if ($act === 'disconnect') { cn_up_disconnect($m, $plat); cn_json_out(['ok' => true]); }
    cn_json_out(['ok' => true, 'url' => cn_up_connect_url($m, $plat, (string)($in['back'] ?? '/studio'))]);
  }
  $configured = (string)(cn_config()['uploadpost_key'] ?? '') !== '';
  $accounts = ($configured && $limit > 0 && !empty($m['customer_id'])) ? cn_up_accounts(cn_up_profile(cn_up_username($m))) : cn_up_accounts(null);
  cn_json_out(['ok' => true, 'available' => $configured, 'limit' => $limit, 'plan' => $m['plan'], 'accounts' => $accounts]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => $e->getMessage()], 503);
}
