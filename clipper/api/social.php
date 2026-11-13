<?php
// GET  /api/social.php                 → which social accounts the member has connected
// POST /api/social.php {action:connect, platform?, back?} → a link to the page where they connect
//      (just that platform when one is given; back = the ClipNow page to return to)
// POST /api/social.php {action:disconnect, platform}     → unlinks that platform
// Platinum and Corporate can connect several accounts per platform ("account sets"); pass set: 2, 3, …
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
    $set = max(1, (int)($in['set'] ?? 1));
    if ($set > cn_account_sets($m)) cn_json_out(['ok' => false, 'error' => 'Your plan connects up to ' . cn_account_sets($m) . ' account' . (cn_account_sets($m) === 1 ? '' : 's') . ' per platform. Platinum connects 3 and Corporate 10.'], 403);
    if ($act === 'disconnect') { cn_up_disconnect($m, $plat, $set); cn_json_out(['ok' => true]); }
    cn_json_out(['ok' => true, 'url' => cn_up_connect_url($m, $plat, (string)($in['back'] ?? '/studio'), $set)]);
  }
  $configured = (string)(cn_config()['uploadpost_key'] ?? '') !== '';
  $sets = ($configured && $limit > 0 && cn_data_id($m) !== '') ? cn_up_sets($m) : [1 => cn_up_accounts(null)];
  $list = [];
  foreach ($sets as $i => $acc) $list[] = ['set' => $i, 'accounts' => $acc];
  // 'accounts' = set 1 (kept for older pages); 'connectedAny' = connected on at least one set.
  $any = [];
  foreach (CN_POST_PLATFORMS as $p => $label) { $any[$p] = false; foreach ($sets as $acc) if ($acc[$p]['connected']) $any[$p] = true; }
  cn_json_out(['ok' => true, 'available' => $configured, 'limit' => $limit, 'plan' => $m['plan'], 'accounts' => $sets[1], 'sets' => $list, 'maxSets' => max(1, cn_account_sets($m)), 'connectedAny' => $any]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => $e->getMessage()], 503);
}
