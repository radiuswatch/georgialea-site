<?php
// GET  /api/social.php                 → which social accounts the member has connected
// POST /api/social.php {action:connect} → a link to the page where they connect accounts
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'signedIn' => false, 'error' => 'Please sign in first.'], 401);
  $limit = cn_post_limit($m);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cn_same_origin()) cn_json_out(['ok' => false], 400);
    if ($limit === 0) cn_json_out(['ok' => false, 'error' => 'Auto-posting is included with an active ClipNow plan. Start a free trial to use it.'], 403);
    cn_json_out(['ok' => true, 'url' => cn_up_connect_url($m)]);
  }
  $configured = (string)(cn_config()['uploadpost_key'] ?? '') !== '';
  $accounts = ($configured && $limit > 0 && !empty($m['customer_id'])) ? cn_up_accounts(cn_up_profile(cn_up_username($m))) : cn_up_accounts(null);
  cn_json_out(['ok' => true, 'available' => $configured, 'limit' => $limit, 'plan' => $m['plan'], 'accounts' => $accounts]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => $e->getMessage()], 503);
}
