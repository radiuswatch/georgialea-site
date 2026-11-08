<?php
// Affiliate program API (open to anyone with an email).
//
// GET  ?t=<token>          → sign-in link from the email: signs in and opens /affiliates
// GET  ?click=<code>       → count a visit through an affiliate link (the site calls this once)
// GET                      → the signed-in affiliate's dashboard
// POST action=join         → {email, name, agree}: creates the account if new, emails a sign-in link
// POST action=details      → {name, handle}: name and where to send payouts
// POST action=logout
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');

try {
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['t'])) {
    $id = cn_aff_use_link((string)$_GET['t']);
    if ($id) cn_aff_start_session($id);
    header('Location: ' . CN_SITE . '/affiliates' . ($id ? '?aff=ok' : '?aff=expired'), true, 303);
    exit;
  }
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['click'])) {
    $code = strtolower((string)$_GET['click']);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (preg_match('/^[a-z0-9_-]{3,40}$/', $code) && cn_rate_ok('affclick:' . $code . ':' . $ip, 1)) {
      cn_locked('affiliates.json', function (&$d) use ($code) {
        $id = $d['codes'][$code] ?? null;
        if ($id) { $d['by_id'][$id]['clicks'] = (int)($d['by_id'][$id]['clicks'] ?? 0) + 1; }
      });
    }
    cn_json_out(['ok' => true]);
  }
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $a = cn_aff_current();
    if (!$a) cn_json_out(['ok' => false, 'signedIn' => false], 401);
    $s = cn_aff_summary($a);
    $list = array_map(fn($r) => ['date' => $r['created'], 'who' => $r['who'], 'plan' => $r['plan'], 'status' => $r['status'],
      'commission_cents' => $r['commission_cents'], 'payable_at' => $r['payable_at'], 'why' => $r['why'] ?? ''], array_slice($s['list'], 0, 200));
    unset($s['list']);
    cn_json_out(['ok' => true, 'me' => ['name' => $a['name'], 'email' => $a['email'], 'code' => $a['code'], 'handle' => $a['handle'],
      'link' => CN_SITE . '/?ref=' . $a['code'], 'clicks' => (int)($a['clicks'] ?? 0)], 'stats' => $s, 'referrals' => $list,
      'rate' => CN_AFF_RATE, 'hold_days' => CN_AFF_HOLD_DAYS]);
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
  $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
  $act = (string)($in['action'] ?? '');

  if ($act === 'join') {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))), 0, 60);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) cn_json_out(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
    $exists = isset(cn_affs()['emails'][$email]);
    if (!$exists && empty($in['agree'])) cn_json_out(['ok' => false, 'error' => 'Please agree to the affiliate terms.'], 422);
    if (!$exists && $name === '') cn_json_out(['ok' => false, 'error' => 'Add your name.'], 422);
    if (!cn_rate_ok('afflogin:' . $email, 5) || !cn_rate_ok('affloginip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 20)) cn_json_out(['ok' => false, 'error' => 'Too many tries. Wait 15 minutes.'], 429);
    $a = $exists ? cn_affs()['by_id'][cn_affs()['emails'][$email]] : cn_aff_join($email, $name);
    if ($a['status'] !== 'active') cn_json_out(['ok' => false, 'error' => 'This affiliate account is closed. Contact us if you think that’s a mistake.'], 403);
    $sent = cn_aff_send_link($a);
    cn_json_out(['ok' => $sent, 'error' => $sent ? '' : 'We couldn’t send the email. Please try again.', 'new' => !$exists]);
  }

  $a = cn_aff_current();
  if (!$a) cn_json_out(['ok' => false, 'error' => 'Please sign in again.'], 401);
  if ($act === 'details') {
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))), 0, 60);
    $handle = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['handle'] ?? ''))), 0, 120);
    if ($name === '') cn_json_out(['ok' => false, 'error' => 'Add your name.'], 422);
    cn_locked('affiliates.json', function (&$d) use ($a, $name, $handle) { $d['by_id'][$a['id']]['name'] = $name; $d['by_id'][$a['id']]['handle'] = $handle; });
    cn_json_out(['ok' => true]);
  }
  if ($act === 'logout') { cn_aff_end_session(); cn_json_out(['ok' => true]); }
  cn_json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
