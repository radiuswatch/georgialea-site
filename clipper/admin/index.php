<?php
/**
 * ClipNow admin portal: https://clipnow.vip/admin/
 * Shows every Stripe subscription and sends late memberships a notice to update
 * their payment details (by hand, or automatically via admin/cron.php).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");

session_name('cn_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$cfg = cn_config();
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
function flash(string $msg, bool $ok = true): void { $_SESSION['flash'] = [$msg, $ok]; }
function back(string $view = ''): never { header('Location: ./' . ($view ? "?view=$view" : '')); exit; }
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function day(int $t): string { return $t ? date('M j, Y', $t) : '—'; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ---------- Login ---------- */

function too_many_attempts(): bool {
  $a = cn_read('login-attempts.json', []);
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
  $recent = array_filter($a[$ip] ?? [], fn($t) => $t > time() - 900);
  return count($recent) >= 5;
}
function note_failed_attempt(): void {
  $a = cn_read('login-attempts.json', []);
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
  $a[$ip] = array_values(array_filter($a[$ip] ?? [], fn($t) => $t > time() - 900));
  $a[$ip][] = time();
  cn_write('login-attempts.json', $a);
}

$authed = !empty($_SESSION['admin']) && ($_SESSION['seen'] ?? 0) > time() - 8 * 3600;
if ($authed) $_SESSION['seen'] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { flash('Your session expired. Please try again.', false); back(); }
  $do = $_POST['do'] ?? '';

  if ($do === 'login') {
    if (too_many_attempts()) { flash('Too many tries. Wait 15 minutes and try again.', false); back(); }
    if ($cfg['admin_hash'] !== '' && password_verify((string)($_POST['password'] ?? ''), $cfg['admin_hash'])) {
      session_regenerate_id(true);
      $_SESSION['admin'] = true; $_SESSION['seen'] = time();
      back();
    }
    note_failed_attempt();
    flash('That password is not right.', false); back();
  }

  if (!$authed) back();

  if ($do === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }

  if ($do === 'corporate_create') {
    try {
      $live = str_starts_with((string)$cfg['stripe_key'], 'sk_live') || str_starts_with((string)$cfg['stripe_key'], 'rk_live');
      $prod = cn_stripe('POST', '/v1/products', ['name' => 'ClipNow Corporate', 'description' => 'Everything in Silver, Gold and Platinum, with unlimited campaigns.', 'metadata' => ['plan' => 'corporate']]);
      $price = cn_stripe('POST', '/v1/prices', ['product' => $prod['id'], 'unit_amount' => 29999, 'currency' => 'usd', 'recurring' => ['interval' => 'month'], 'nickname' => 'Corporate monthly', 'metadata' => ['plan' => 'corporate']]);
      $pl = cn_stripe('POST', '/v1/payment_links', ['line_items' => [['price' => $price['id'], 'quantity' => 1]], 'allow_promotion_codes' => 'true',
        'subscription_data' => ['metadata' => ['plan' => 'corporate']], 'metadata' => ['plan' => 'corporate'],
        'after_completion' => ['type' => 'redirect', 'redirect' => ['url' => CN_SITE . '/api/welcome.php?session_id={CHECKOUT_SESSION_ID}']]]);
      $cfg['corporate_link'] = (string)$pl['url']; $cfg['corporate_price'] = $price['id']; $cfg['corporate_mode'] = $live ? 'live' : 'test';
      cn_save_config($cfg);
      flash('Corporate plan created in Stripe (' . ($live ? 'live' : 'test') . ' mode). The Corporate button on the pricing page now opens its checkout.');
    } catch (Throwable $ex) { flash('Stripe said: ' . $ex->getMessage(), false); }
    back('settings');
  }

  if (in_array($do, ['site_live', 'site_soon'], true)) {
    $ok = $do === 'site_live' ? @file_put_contents(cn_site_flag(), 'ClipNow is live since ' . date('c') . "\n") !== false : (!is_file(cn_site_flag()) || @unlink(cn_site_flag()));
    flash($ok ? ($do === 'site_live' ? 'ClipNow is live. Everyone sees the full site now.' : 'The coming-soon page is on. Visitors see it instead of the site.') : 'The server wouldn\'t let us change that file. Check the folder permissions.', $ok);
    back('site');
  }
  if ($do === 'kickstarter_save') {
    $u = trim((string)($_POST['kickstarter_url'] ?? ''));
    if ($u !== '' && !preg_match('#^https://(www\.)?kickstarter\.com/projects/[^\s"<>]+$#i', $u)) { flash('Paste the full Kickstarter link, starting with https://www.kickstarter.com/projects/', false); back('site'); }
    $cfg['kickstarter_url'] = $u; cn_save_config($cfg);
    flash($u === '' ? 'Kickstarter button removed from the coming-soon page.' : 'Saved. The coming-soon page now shows a "Follow us on Kickstarter" button.');
    back('site');
  }
  if ($do === 'preview_newlink') {
    $cfg['preview_key'] = bin2hex(random_bytes(12)); cn_save_config($cfg);
    flash('New tester link made. The old link no longer works for new people (anyone who already opened it keeps access on that browser).');
    back('site');
  }
  if (in_array($do, ['preview_on', 'preview_off'], true)) {
    $on = $do === 'preview_on';
    setcookie('cn_preview', $on ? '1' : '', ['expires' => $on ? time() + 365 * 86400 : time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    flash($on ? 'This browser now sees the full site, even while the coming-soon page is on.' : 'This browser now sees what visitors see.');
    back('site');
  }

  if ($do === 'aff_sync') {
    try { $r = cn_aff_sync(); flash("Checked Stripe: {$r['new']} new referrals, {$r['earned']} commissions earned, {$r['void']} voided."); }
    catch (Throwable $ex) { flash('Stripe said: ' . $ex->getMessage(), false); }
    back('affiliates');
  }
  if (in_array($do, ['aff_payout', 'aff_disable', 'aff_enable'], true)) {
    $aid = (string)($_POST['aff'] ?? '');
    $amt = (int)round((float)str_replace([',', '$'], '', (string)($_POST['amount'] ?? '0')) * 100);
    if ($do === 'aff_payout' && $amt < 1) { flash('Enter the amount you paid.', false); back('affiliates'); }
    $ok = cn_locked('affiliates.json', function (&$d) use ($aid, $do, $amt) {
      if (!isset($d['by_id'][$aid])) return false;
      if ($do === 'aff_payout') { $d['by_id'][$aid]['paid_cents'] = (int)($d['by_id'][$aid]['paid_cents'] ?? 0) + $amt; $d['by_id'][$aid]['payouts'][] = ['cents' => $amt, 'at' => time()]; }
      else $d['by_id'][$aid]['status'] = $do === 'aff_disable' ? 'disabled' : 'active';
      return true;
    });
    flash($ok ? ['aff_payout' => 'Payout recorded.', 'aff_disable' => 'Affiliate closed. Their link stops earning.', 'aff_enable' => 'Affiliate reopened.'][$do] : 'That affiliate wasn\'t found.', $ok);
    back('affiliates');
  }

  if ($do === 'trending_run') {
    if ((string)($cfg['anthropic_key'] ?? '') === '') { flash('Add your Anthropic API key in Settings first.', false); back('settings'); }
    $t = cn_trending_run();
    flash($t['error'] !== '' ? 'The search didn\'t work: ' . $t['error'] : 'Found ' . count($t['items']) . ' campaigns (cost about $' . $t['cost_usd'] . ').', $t['error'] === '');
    back('campaigns');
  }

  if (in_array($do, ['camp_hide', 'camp_show', 'camp_delete', 'camp_clear_reports'], true)) {
    $cid = (string)($_POST['camp'] ?? '');
    $c = cn_camp_load($cid);
    if (!$c) { flash('That campaign no longer exists.', false); back('campaigns'); }
    if ($do === 'camp_delete') {
      foreach ($c['submissions'] as $sub) if (($sub['shot'] ?? '') !== '') @unlink(cn_dir('campaign-shots') . '/' . basename($sub['shot']));
      if (!empty($c['image'])) @unlink(cn_dir('campaign-images') . '/' . basename($c['image']));
      @unlink(cn_camp_file($cid));
      cn_camp_log(['event' => 'deleted by admin', 'id' => $cid, 'title' => $c['title'], 'email' => $c['owner']]);
      flash('Deleted "' . $c['title'] . '" and its screenshots.');
    } else {
      cn_camp_update($cid, function (&$c) use ($do) {
        if ($do === 'camp_hide') $c['hidden'] = true;
        if ($do === 'camp_show') $c['hidden'] = false;
        if ($do === 'camp_clear_reports') $c['reports'] = [];
      });
      cn_camp_log(['event' => $do, 'id' => $cid, 'title' => $c['title']]);
      flash(['camp_hide' => 'Hidden. Only its creator can still see it.', 'camp_show' => 'It\'s visible to Platinum members again.', 'camp_clear_reports' => 'Reports cleared.'][$do]);
    }
    back('campaigns');
  }

  if ($do === 'send') {
    try {
      $s = cn_stripe('GET', '/v1/subscriptions/' . rawurlencode((string)$_POST['sub']), ['expand' => ['customer', 'latest_invoice']]);
      $sub = cn_shape($s);
      if (!$sub['late']) { flash("That membership isn't late any more, so no notice was sent."); back(); }
      [$ok, $msg] = cn_send_notice($sub, 'manual');
      flash($msg, $ok);
    } catch (Throwable $ex) { flash($ex->getMessage(), false); }
    back();
  }

  if ($do === 'send_all') {
    try {
      $sent = 0; $failed = 0;
      foreach (cn_subscriptions() as $sub) if ($sub['late']) { [$ok] = cn_send_notice($sub, 'manual'); $ok ? $sent++ : $failed++; }
      flash("Sent $sent notice" . ($sent === 1 ? '' : 's') . ($failed ? ", $failed failed (see Notice log)" : '') . '.', !$failed);
    } catch (Throwable $ex) { flash($ex->getMessage(), false); }
    back();
  }

  if ($do === 'run_auto') {
    try {
      $r = cn_run_auto(cn_subscriptions(), true);
      flash("Checked {$r['checked']} late membership(s): {$r['sent']} notice(s) were due and sent"
        . ($r['failed'] ? ", {$r['failed']} failed" : '')
        . ($r['no_email'] ? ", {$r['no_email']} skipped because Stripe has no email for them" : '') . '.', !$r['failed']);
    } catch (Throwable $ex) { flash($ex->getMessage(), false); }
    back('log');
  }

  if ($do === 'make_code') {
    $note = trim((string)($_POST['note'] ?? ''));
    try {
      $p = cn_create_family_code($note, 0); // single use, never expires
      $_SESSION['new_code'] = ['code' => $p['code'], 'note' => $note, 'expires' => (int)($p['expires_at'] ?? 0)];
      flash("New code {$p['code']} is ready. It works once.");
    } catch (Throwable $ex) { flash($ex->getMessage(), false); }
    back('codes');
  }

  if (in_array($do, ['code_off', 'code_on', 'code_delete'], true)) {
    try {
      $id = (string)($_POST['promo'] ?? '');
      if (!preg_match('/^promo_\w+$/', $id)) throw new RuntimeException('Unknown code.');
      $p = cn_stripe('GET', '/v1/promotion_codes/' . $id);
      if (($p['metadata']['purpose'] ?? '') !== 'family') throw new RuntimeException('That isn\'t a family code.');
      if ($do === 'code_on') {
        if ((int)$p['times_redeemed'] >= (int)($p['max_redemptions'] ?? PHP_INT_MAX)) throw new RuntimeException("{$p['code']} has already been used, so it can't be turned back on.");
        cn_stripe('POST', '/v1/promotion_codes/' . $id, ['active' => 'true']);
        flash("Code {$p['code']} is turned on again and can be used once.");
      } else {
        // Stripe never deletes promotion codes, so "delete" turns it off for good and hides it here.
        if (!empty($p['active'])) cn_stripe('POST', '/v1/promotion_codes/' . $id, ['active' => 'false']);
        if ($do === 'code_delete') {
          $hidden = cn_read('deleted-codes.json', []);
          $hidden[$id] = ['code' => $p['code'], 'at' => time()];
          cn_write('deleted-codes.json', $hidden);
          flash("Code {$p['code']} is deleted. It can't be used" . ((int)$p['times_redeemed'] ? '; whoever already used it keeps their membership.' : '.'));
        } else {
          flash("Code {$p['code']} is turned off and can't be used.");
        }
      }
    } catch (Throwable $ex) { flash($ex->getMessage(), false); }
    back('codes');
  }

  if ($do === 'settings') {
    $key = trim((string)($_POST['stripe_key'] ?? ''));
    if ($key !== '') {
      if (!preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', $key)) { flash('That doesn\'t look like a Stripe secret or restricted key (it starts with sk_ or rk_).', false); back('settings'); }
      $cfg['stripe_key'] = $key;
    }
    $up = trim((string)($_POST['uploadpost_key'] ?? ''));
    if ($up !== '') {
      if (strlen($up) < 20 || strlen($up) > 4096 || preg_match('/\s/', $up)) { flash('That doesn\'t look like an Upload-Post API key. Copy it again from Upload-Post → API Keys.', false); back('settings'); }
      $cfg['uploadpost_key'] = $up;
    }
    $ak = trim((string)($_POST['anthropic_key'] ?? ''));
    if ($ak !== '') {
      if (!str_starts_with($ak, 'sk-ant-') || strlen($ak) > 400 || preg_match('/\s/', $ak)) { flash('That doesn\'t look like an Anthropic API key. It starts with sk-ant-. Copy it again from the Anthropic Console → API Keys.', false); back('settings'); }
      $cfg['anthropic_key'] = $ak;
    }
    if (!empty($_POST['anthropic_key_remove'])) $cfg['anthropic_key'] = '';
    $rk = trim((string)($_POST['resend_key'] ?? ''));
    if ($rk !== '') {
      if (!str_starts_with($rk, 're_') || strlen($rk) > 200 || preg_match('/\s/', $rk)) { flash('That doesn\'t look like a Resend API key. It starts with re_. Copy it again from Resend → API Keys.', false); back('settings'); }
      $cfg['resend_key'] = $rk;
    }
    if (!empty($_POST['resend_key_remove'])) $cfg['resend_key'] = '';
    foreach (['from_email', 'reply_to'] as $f) {
      $v = trim((string)($_POST[$f] ?? ''));
      if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { flash("“$v” isn't a valid email address.", false); back('settings'); }
      $cfg[$f] = $v;
    }
    if ($cfg['from_email'] === '') { flash('The From address is required.', false); back('settings'); }
    $cfg['from_name'] = trim((string)($_POST['from_name'] ?? '')) ?: CN_BRAND;
    $cfg['auto_notices'] = !empty($_POST['auto_notices']);
    $days = array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', (string)($_POST['schedule'] ?? ''))), 'is_numeric'))));
    sort($days);
    if (!$days || min($days) < 0 || max($days) > 60 || count($days) > 10) { flash('Schedule: enter up to 10 day numbers between 0 and 60, like 0, 3, 7.', false); back('settings'); }
    $cfg['schedule'] = $days;
    $pw = (string)($_POST['new_password'] ?? '');
    if ($pw !== '') {
      if (strlen($pw) < 10) { flash('The new password needs at least 10 characters.', false); back('settings'); }
      $cfg['admin_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    }
    cn_save_config($cfg);
    flash('Settings saved.'); back('settings');
  }

  if ($do === 'test_email') {
    $to = trim((string)($_POST['to'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { flash('Enter a valid email address for the test.', false); back('settings'); }
    $sample = ['id' => 'sub_TEST', 'customer_id' => 'cus_TEST', 'name' => 'Sample Customer', 'email' => $to, 'plan' => 'Gold', 'due_cents' => 1999, 'cents' => 1999, 'currency' => 'USD'];
    [$subject, $body] = cn_notice_email($sample);
    $ok = cn_send_mail($to, '[Test] ' . $subject, "This is a test of the late-payment notice. The link below won't work for a real account.\n\n" . $body);
    flash($ok ? "Test notice sent to $to. Check the inbox and the spam folder." : "The server couldn't send to $to.", $ok);
    back('settings');
  }
  back();
}

$view = $_GET['view'] ?? 'subs';
if ($authed && $view === 'site' && isset($_GET['export'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="clipnow-launch-list-' . date('Y-m-d') . '.csv"');
  echo "email,joined\n";
  foreach (cn_waitlist() as $em => $t) echo '"' . str_replace('"', '""', (string)$em) . '",' . date('Y-m-d H:i', (int)$t) . "\n";
  exit;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ClipNow Admin</title>
<style>
  :root{--bg:#0B0D0B;--card:#141714;--card-2:#181C18;--line:#242923;--text:#E9EDE9;--muted:#96A096;--faint:#6B756B;--green:#31C971;--green-deep:#0F3B23;--warn:#E8B04A;--warn-deep:#3A2C10;--red:#F0706A;--red-deep:#3D1714;--blue:#7DB4F0;--blue-deep:#13283F;
    --sans:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;--mono:Consolas,'SF Mono',Menlo,monospace;--serif:Georgia,'Times New Roman',serif}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 var(--sans);-webkit-font-smoothing:antialiased}
  a{color:var(--green)}
  .wrap{max-width:1240px;margin:0 auto;padding:24px 16px 60px}
  header{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:22px}
  .mark{width:30px;height:30px;border-radius:8px;background:var(--green);color:#06230F;display:grid;place-items:center;font:700 13px var(--mono)}
  header h1{font:400 22px var(--serif);margin:0;flex:1}
  nav{display:flex;gap:4px;flex-wrap:wrap}
  nav a,.btn{display:inline-block;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:9px;padding:7px 13px;text-decoration:none;font:inherit;font-size:14px;cursor:pointer}
  nav a.on{border-color:var(--green);color:var(--green)}
  .btn.go{background:var(--green);border-color:var(--green);color:#06230F;font-weight:600}
  .btn.warn{border-color:var(--warn);color:var(--warn);background:transparent}
  .btn:disabled{opacity:.5;cursor:default}
  form.inline{display:inline}
  .flash{border-radius:10px;padding:11px 14px;margin-bottom:18px;border:1px solid var(--green);background:var(--green-deep)}
  .flash.bad{border-color:var(--red);background:var(--red-deep)}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px}
  .stat b{display:block;font:500 26px var(--mono);font-variant-numeric:tabular-nums}
  .stat span{color:var(--muted);font-size:13px}
  .stat.late{border-color:var(--warn)} .stat.late b{color:var(--warn)}
  .bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .bar input[type=search]{flex:1;min-width:200px}
  input,select{background:var(--card-2);border:1px solid var(--line);color:var(--text);border-radius:9px;padding:8px 11px;font:inherit;font-size:14px;width:100%}
  .chips{display:flex;gap:6px;flex-wrap:wrap}
  .chips button{background:transparent;border:1px solid var(--line);color:var(--muted);border-radius:999px;padding:5px 12px;font:12.5px var(--mono);cursor:pointer}
  .chips button.on{border-color:var(--green);color:var(--green)}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
  .scroll{overflow-x:auto}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;font:500 11.5px var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--faint);padding:11px 12px;border-bottom:1px solid var(--line);white-space:nowrap}
  td{padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}
  tr:last-child td{border-bottom:0}
  td.num{font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
  .who b{display:block;font-weight:600}
  .who span,.sub{color:var(--muted);font-size:12.5px}
  .badge{display:inline-block;font:12px var(--mono);border-radius:999px;padding:2px 9px;white-space:nowrap}
  .s-active{background:var(--green-deep);color:var(--green)}
  .s-trialing{background:var(--blue-deep);color:var(--blue)}
  .s-past_due,.s-unpaid{background:var(--warn-deep);color:var(--warn)}
  .s-canceled,.s-incomplete_expired{background:#222;color:var(--faint)}
  .s-incomplete,.s-paused{background:#2a2a2a;color:var(--muted)}
  .empty{padding:40px 20px;text-align:center;color:var(--muted)}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px;margin-bottom:18px}
  .panel h2{font:400 19px var(--serif);margin:0 0 4px}
  .panel p{color:var(--muted);margin:0 0 14px}
  .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
  label{display:block;font-size:13px;color:var(--muted);margin-bottom:5px}
  .check{display:flex;gap:10px;align-items:center;color:var(--text);font-size:15px}
  .check input{width:auto}
  pre{white-space:pre-wrap;background:var(--card-2);border:1px solid var(--line);border-radius:10px;padding:14px;font:13px/1.55 var(--mono);color:var(--muted);margin:0}
  .login{max-width:360px;margin:12vh auto}
  @media (max-width:720px){
    th.hide-sm,td.hide-sm{display:none}
    table.members thead{display:none}
    table.members,table.members tbody,table.members tr,table.members td{display:block}
    table.members tr{display:grid;grid-template-columns:1fr auto;gap:4px 12px;padding:14px;border-bottom:1px solid var(--line)}
    table.members tr[hidden]{display:none}
    table.members td{padding:0;border:0}
    table.members td.hide-sm{display:none}
    table.members td.who{grid-column:1/-1}
    table.members td.acts{grid-column:1/-1;margin-top:8px}
    table.members td.ncol::before{content:"Notices ";color:var(--faint);font-size:12px}
  }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$authed): ?>
  <div class="login panel">
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px"><div class="mark">CN</div><h1 style="font:400 22px var(--serif);margin:0">ClipNow Admin</h1></div>
    <?php if ($flash): ?><div class="flash <?= $flash[1] ? '' : 'bad' ?>"><?= e($flash[0]) ?></div><?php endif; ?>
    <?php if ($cfg['admin_hash'] === ''): ?>
      <p>The admin password hasn't been set up on the server yet.</p>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="do" value="login">
      <label for="pw">Password</label>
      <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
      <p style="margin:14px 0 0"><button class="btn go" style="width:100%">Sign in</button></p>
    </form>
    <?php endif; ?>
  </div>
<?php else: ?>
  <header>
    <div class="mark">CN</div><h1>ClipNow Admin</h1>
    <nav>
      <a href="./" class="<?= $view === 'subs' ? 'on' : '' ?>">Subscriptions</a>
      <a href="?view=codes" class="<?= $view === 'codes' ? 'on' : '' ?>">Family codes</a>
      <a href="?view=campaigns" class="<?= $view === 'campaigns' ? 'on' : '' ?>">Campaigns</a>
      <a href="?view=affiliates" class="<?= $view === 'affiliates' ? 'on' : '' ?>">Affiliates</a>
      <a href="?view=site" class="<?= $view === 'site' ? 'on' : '' ?>">Site status</a>
      <a href="?view=log" class="<?= $view === 'log' ? 'on' : '' ?>">Notice log</a>
      <a href="?view=settings" class="<?= $view === 'settings' ? 'on' : '' ?>">Settings</a>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn" name="do" value="logout">Sign out</button></form>
    </nav>
  </header>
  <?php if ($flash): ?><div class="flash <?= $flash[1] ? '' : 'bad' ?>"><?= e($flash[0]) ?></div><?php endif; ?>
  <?php if (!cn_site_live() && $view !== 'site'): ?><div class="flash bad">The site is in coming-soon mode: visitors see the coming-soon page. <a href="?view=site">Site status</a></div><?php endif; ?>

<?php if ($view === 'subs'):
  $subs = []; $error = '';
  try { $subs = cn_subscriptions(); } catch (Throwable $ex) { $error = $ex->getMessage(); }
  $notices = cn_read('notices.json', []);
  $count = fn($st) => count(array_filter($subs, fn($s) => $s['status'] === $st));
  $late = array_values(array_filter($subs, fn($s) => $s['late']));
  $mrr = 0; foreach ($subs as $s) if ($s['status'] === 'active') $mrr += $s['interval'] === 'year' ? intdiv($s['cents'], 12) : $s['cents'];
  $test = $cfg['stripe_key'] !== '' && str_contains($cfg['stripe_key'], '_test_');
?>
  <?php if ($error): ?>
    <div class="flash bad"><?= e($error) ?><?php if ($cfg['stripe_key'] === ''): ?> <a href="?view=settings">Open Settings</a><?php endif; ?></div>
  <?php else: ?>
  <div class="stats">
    <div class="stat"><b><?= $count('active') ?></b><span>Active</span></div>
    <div class="stat"><b><?= $count('trialing') ?></b><span>On free trial</span></div>
    <div class="stat late"><b><?= count($late) ?></b><span>Late payments</span></div>
    <div class="stat"><b><?= $count('canceled') ?></b><span>Canceled</span></div>
    <div class="stat"><b><?= cn_money($mrr) ?></b><span>Monthly revenue (active)</span></div>
  </div>
  <?php if ($test): ?><p class="sub" style="margin:-8px 0 14px">Showing Stripe <b>test mode</b> data.</p><?php endif; ?>

  <div class="bar">
    <input type="search" id="q" placeholder="Search name, email or plan">
    <div class="chips" id="chips">
      <button data-f="all" class="on">All <?= count($subs) ?></button>
      <button data-f="active">Active</button><button data-f="trialing">Trial</button>
      <button data-f="late">Late <?= count($late) ?></button><button data-f="canceled">Canceled</button>
    </div>
    <?php if ($late): ?>
    <form method="post" class="inline" onsubmit="return confirm('Email all <?= count($late) ?> late member(s) now?')">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn warn" name="do" value="send_all">Notify all late (<?= count($late) ?>)</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card"><div class="scroll">
  <?php if (!$subs): ?>
    <div class="empty">No subscriptions in Stripe yet. They'll show up here as soon as someone signs up through a Stripe Payment Link.</div>
  <?php else: ?>
  <table class="members">
    <thead><tr><th>Member</th><th>Plan</th><th>Status</th><th class="hide-sm">Started</th><th class="hide-sm">Next bill</th><th>Notices</th><th></th></tr></thead>
    <tbody id="rows">
    <?php foreach ($subs as $s):
      $n = $notices[$s['id']]['sent'] ?? [];
      $label = ['active' => 'Active', 'trialing' => 'Trial', 'past_due' => 'Past due', 'unpaid' => 'Unpaid', 'canceled' => 'Canceled', 'incomplete' => 'Incomplete', 'incomplete_expired' => 'Expired', 'paused' => 'Paused'][$s['status']] ?? $s['status'];
      $dash = 'https://dashboard.stripe.com/' . ($s['livemode'] ? '' : 'test/') . 'subscriptions/' . rawurlencode($s['id']);
      $filter = $s['late'] ? 'late' : $s['status'];
    ?>
      <tr data-f="<?= e($filter) ?>" data-q="<?= e(strtolower($s['name'] . ' ' . $s['email'] . ' ' . $s['plan'])) ?>">
        <td class="who"><b><?= e($s['name'] ?: '(no name)') ?></b><span><?= e($s['email'] ?: 'no email') ?></span></td>
        <td><?= e($s['plan']) ?><div class="sub num"><?= $s['cents'] === 0 && $s['coupon'] ? 'Free' : cn_money($s['cents'], $s['currency']) . '/' . ($s['interval'] === 'year' ? 'yr' : 'mo') ?></div>
          <?php if ($s['coupon']): ?><div class="sub"><?= e($s['coupon']) ?></div><?php endif; ?></td>
        <td><span class="badge s-<?= e($s['status']) ?>"><?= e($label) ?></span>
          <?php if ($s['late']): ?><div class="sub">Owes <?= cn_money($s['due_cents'], $s['currency']) ?><?= $s['late_since'] ? ' since ' . day($s['late_since']) : '' ?></div><?php endif; ?>
          <?php if ($s['cancel_at_end'] && $s['status'] !== 'canceled'): ?><div class="sub">Cancels <?= day($s['period_end']) ?></div><?php endif; ?>
          <?php if ($s['status'] === 'trialing'): ?><div class="sub">Trial ends <?= day($s['trial_end']) ?></div><?php endif; ?>
        </td>
        <td class="hide-sm num"><?= day($s['created']) ?></td>
        <td class="hide-sm num"><?= $s['status'] === 'canceled' ? 'Ended ' . day($s['canceled_at']) : day($s['period_end']) ?></td>
        <td class="num ncol"><?= count($n) ?><?php if ($n): ?><div class="sub">last <?= day(end($n)) ?></div><?php endif; ?></td>
        <td class="acts" style="white-space:nowrap">
          <?php if ($s['late']): ?>
          <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="sub" value="<?= e($s['id']) ?>"><button class="btn warn" name="do" value="send" <?= $s['email'] ? '' : 'disabled title="No email on file"' ?>>Send notice</button></form>
          <?php endif; ?>
          <a class="btn" href="<?= e($dash) ?>" target="_blank" rel="noopener">Stripe</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="empty" id="none" hidden>No memberships match.</div>
  <?php endif; ?>
  </div></div>
  <?php endif; ?>
  <script>
  (function(){
    var q=document.getElementById('q'), chips=document.getElementById('chips'), f='all';
    if(!q) return;
    function apply(){ var t=q.value.trim().toLowerCase(), shown=0;
      document.querySelectorAll('#rows tr').forEach(function(r){ var ok=(f==='all'||r.dataset.f===f)&&(!t||r.dataset.q.indexOf(t)>-1); r.hidden=!ok; if(ok) shown++; });
      var n=document.getElementById('none'); if(n) n.hidden=shown>0; }
    q.addEventListener('input',apply);
    chips.addEventListener('click',function(e){ var b=e.target.closest('button'); if(!b) return; f=b.dataset.f;
      chips.querySelectorAll('button').forEach(function(x){x.classList.toggle('on',x===b);}); apply(); });
  })();
  </script>

<?php elseif ($view === 'codes'):
  $codes = []; $link = ''; $error = '';
  try { $link = cn_family_link(); $codes = cn_family_codes(cn_subscriptions()); } catch (Throwable $ex) { $error = $ex->getMessage(); }
  $hidden = cn_read('deleted-codes.json', []);
  $codes = array_values(array_filter($codes, fn($c) => !isset($hidden[$c['id']])));
  $new = $_SESSION['new_code'] ?? null; unset($_SESSION['new_code']);
  $signup = $link ?: CN_SITE . '/#pricing';
  $unused = count(array_filter($codes, fn($c) => $c['status'] === 'unused'));
  $usedN = count(array_filter($codes, fn($c) => $c['status'] === 'used'));
?>
  <?php if ($error): ?><div class="flash bad"><?= e($error) ?></div><?php endif; ?>
  <?php if ($new):
    $msg = "Here's free ClipNow Platinum for you. Sign up here: $signup and tap \"Add promotion code\", then enter {$new['code']}. It works once"
      . ($new['expires'] ? ' and expires ' . date('M j, Y', $new['expires']) : '') . '. No card needed.'; ?>
  <div class="panel" style="border-color:var(--green)">
    <h2>Your new code</h2>
    <p><?= $new['note'] !== '' ? 'For ' . e($new['note']) . '. ' : '' ?>Free Platinum, no monthly charge. Works one time<?= $new['expires'] ? ', until ' . e(date('M j, Y', $new['expires'])) : '' ?>.</p>
    <div class="bar" style="margin:0 0 14px">
      <code id="newCode" style="font:600 30px var(--mono);letter-spacing:.06em;color:var(--green);background:var(--card-2);border:1px solid var(--line);border-radius:10px;padding:8px 16px"><?= e($new['code']) ?></code>
      <button class="btn" type="button" data-copy="newCode">Copy code</button>
    </div>
    <label for="shareMsg">Message to send</label>
    <textarea id="shareMsg" rows="3" readonly style="width:100%;background:var(--card-2);border:1px solid var(--line);color:var(--text);border-radius:9px;padding:10px 12px;font:inherit;resize:vertical"><?= e($msg) ?></textarea>
    <p style="margin:10px 0 0"><button class="btn" type="button" data-copy="shareMsg">Copy message</button></p>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Make a family code</h2>
    <p>Every code works one time only and never expires. It gives one person free Platinum forever. They sign up with the family link<?= $link ? '' : ' (not set up in Stripe yet, so they\'d use the Platinum button on the site, which asks for a card)' ?>, enter the code, and pay $0.</p>
    <form method="post" class="grid2" style="align-items:end">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="do" value="make_code">
      <div><label for="note">Who is it for? (only you see this)</label><input id="note" name="note" maxlength="200" placeholder="e.g. Mom, cousin Dee"></div>
      <div><button class="btn go" <?= $error ? 'disabled' : '' ?>>Generate one-time code</button></div>
    </form>
    <?php if ($link): ?><p style="margin:14px 0 0">Family sign-up link (no card needed): <a href="<?= e($link) ?>" target="_blank" rel="noopener" style="overflow-wrap:anywhere"><?= e($link) ?></a></p><?php endif; ?>
  </div>

  <div class="stats">
    <div class="stat"><b><?= $unused ?></b><span>Unused codes</span></div>
    <div class="stat"><b><?= $usedN ?></b><span>Used</span></div>
    <div class="stat"><b><?= count($codes) ?></b><span>All codes</span></div>
  </div>
  <div class="card"><div class="scroll">
  <?php if (!$codes): ?><div class="empty">No family codes yet.</div><?php else: ?>
  <table class="members"><thead><tr><th>Code</th><th>For</th><th>Status</th><th class="hide-sm">Made</th><th></th></tr></thead><tbody>
  <?php foreach ($codes as $c):
    $badge = ['unused' => ['s-trialing', 'Unused'], 'used' => ['s-active', 'Used'], 'expired' => ['s-canceled', 'Expired'], 'off' => ['s-canceled', 'Turned off']][$c['status']]; ?>
    <tr>
      <td class="who"><b class="num" style="font-family:var(--mono)"><?= e($c['code']) ?></b><?php if ((int)$c['max'] !== 1): ?><span><?= (int)$c['used'] ?> of <?= $c['max'] ? (int)$c['max'] : '∞' ?> uses</span><?php endif; ?></td>
      <td><?= e($c['note'] ?: '—') ?></td>
      <td><span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span><?php if ($c['by']): ?><div class="sub">by <?= e($c['by']) ?><?= $c['by_status'] && $c['by_status'] !== 'active' ? ' (' . e($c['by_status']) . ')' : '' ?></div><?php endif; ?></td>
      <td class="hide-sm num"><?= day($c['created']) ?></td>
      <td class="acts" style="white-space:nowrap">
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="promo" value="<?= e($c['id']) ?>">
          <?php if ($c['status'] === 'unused'): ?><button class="btn" name="do" value="code_off" onclick="return confirm('Turn off <?= e($c['code']) ?>? It will stop working until you turn it back on.')">Turn off</button><?php endif; ?>
          <?php if ($c['status'] === 'off'): ?><button class="btn" name="do" value="code_on">Turn on</button><?php endif; ?>
          <button class="btn warn" name="do" value="code_delete" onclick="return confirm('Delete <?= e($c['code']) ?>? It will stop working for good and disappear from this list.<?= $c['status'] === 'used' ? ' The person who used it keeps their membership.' : '' ?>')">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  </div></div>
  <p class="sub" style="margin-top:12px"><b>Turn off</b> pauses an unused code (you can turn it back on). <b>Delete</b> stops a code for good and removes it from this list. Neither one takes away a membership someone already started with a code; to end that, cancel their subscription from the Subscriptions page (Stripe button).</p>
  <script>
  document.querySelectorAll('[data-copy]').forEach(function(b){ b.addEventListener('click', function(){
    var el=document.getElementById(b.dataset.copy), t=el.value!==undefined?el.value:el.textContent;
    (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){ b.textContent='Copied'; }, function(){ if(el.select){el.select();} document.execCommand('copy'); b.textContent='Copied'; });
    setTimeout(function(){ b.textContent=b.dataset.copy==='newCode'?'Copy code':'Copy message'; },1500); }); });
  </script>

<?php elseif ($view === 'site'):
  $live = cn_site_live(); $wl = cn_waitlist(); $preview = ($_COOKIE['cn_preview'] ?? '') === '1';
  if (empty($cfg['preview_key'])) { $cfg['preview_key'] = bin2hex(random_bytes(12)); cn_save_config($cfg); }
  $tlink = CN_SITE . '/api/preview.php?k=' . $cfg['preview_key'];
?>
  <div class="panel">
    <h2>Site status: <?= $live ? '<span style="color:var(--green)">Live</span>' : '<span style="color:var(--warn)">Coming soon</span>' ?></h2>
    <p><?= $live ? 'Everyone sees the full ClipNow site.' : 'Visitors to clipnow.vip, /studio, /campaigns, /stats and /affiliates see the <a href="../coming-soon.html" target="_blank" rel="noopener">coming-soon page</a>, where they can join the launch list. This admin portal, sign-in links, Stripe checkout and the Terms, Privacy and Refunds pages keep working.' ?></p>
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?php if ($live): ?><button class="btn warn" name="do" value="site_soon" onclick="return confirm('Show the coming-soon page to visitors instead of the site?')">Switch to coming soon</button>
      <?php else: ?><button class="btn go" name="do" value="site_live" onclick="return confirm('Open ClipNow to everyone now?')">Go live now</button><?php endif; ?>
    </form>
  </div>
  <div class="panel">
    <h2>Preview the real site</h2>
    <p>While the coming-soon page is on, you can still use the full site on this browser to test it. <?= $preview ? '<b>This browser is previewing the full site.</b>' : 'This browser sees what visitors see.' ?></p>
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?php if ($preview): ?><button class="btn" name="do" value="preview_off">Stop previewing</button> <a class="btn" href="/" target="_blank" rel="noopener">Open the site</a>
      <?php else: ?><button class="btn go" name="do" value="preview_on">Preview the full site on this browser</button><?php endif; ?>
    </form>
  </div>
  <div class="panel">
    <h2>Kickstarter button</h2>
    <p>Paste your Kickstarter link (the pre-launch page works too) to show a <b>Follow us on Kickstarter</b> button on the coming-soon page. Leave it empty to hide the button.</p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input name="kickstarter_url" value="<?= e($cfg['kickstarter_url'] ?? '') ?>" placeholder="https://www.kickstarter.com/projects/…" style="flex:1;min-width:260px" spellcheck="false">
      <button class="btn go" name="do" value="kickstarter_save">Save</button>
    </form>
  </div>
  <div class="panel">
    <h2>Tester link (for family and testers)</h2>
    <p>Send this link to anyone who should keep testing the full site while visitors see the coming-soon page. Opening it once unlocks the full site on that phone or computer for 6 months. Keep it private: anyone with the link gets in.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input id="tlink" readonly value="<?= e($tlink) ?>" style="flex:1;min-width:260px" onclick="this.select()">
      <button class="btn go" type="button" onclick="var i=document.getElementById('tlink');i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).then(()=>{this.textContent='Copied!'},()=>{document.execCommand('copy');this.textContent='Copied!'})">Copy link</button>
    </div>
    <p class="sub" style="margin-top:10px">To go back to what visitors see on a device, open <a href="<?= e(CN_SITE . '/api/preview.php?off=1') ?>"><?= e(CN_SITE . '/api/preview.php?off=1') ?></a>. Shared the link with the wrong person? Make a new one (the old link stops unlocking new devices).</p>
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn" name="do" value="preview_newlink" onclick="return confirm('Make a new tester link? The current link will stop working for new devices.')">Make a new link</button></form>
  </div>
  <div class="panel">
    <h2>Launch list (<?= count($wl) ?>)</h2>
    <p>People who asked to be emailed when ClipNow opens. Download the list to email them on launch day.</p>
    <?php if ($wl): ?>
      <p><a class="btn" href="?view=site&amp;export=1">Download as a spreadsheet (CSV)</a></p>
      <table class="members"><thead><tr><th>Email</th><th>Joined</th></tr></thead><tbody>
      <?php foreach (array_slice($wl, 0, 200, true) as $em => $t): ?><tr><td><?= e($em) ?></td><td class="num"><?= e(date('M j, Y g:i a', (int)$t)) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php if (count($wl) > 200): ?><p class="sub">Showing the newest 200. The download has everyone.</p><?php endif; ?>
    <?php else: ?><div class="empty">No one yet.</div><?php endif; ?>
  </div>

<?php elseif ($view === 'affiliates'):
  $affs = cn_affs()['by_id']; $refs = cn_refs(); $sync = cn_read('aff-sync.json', ['last' => 0]);
  $money = fn($c) => '$' . number_format($c / 100, 2);
  $rows = []; $tot = ['earned' => 0, 'balance' => 0, 'paid' => 0, 'sales' => 0];
  foreach ($affs as $a) { $s = cn_aff_summary($a, $refs); $rows[] = [$a, $s]; $tot['earned'] += $s['earned_cents']; $tot['balance'] += $s['balance_cents']; $tot['paid'] += $s['paid_cents']; $tot['sales'] += $s['sales']; }
  usort($rows, fn($x, $y) => $y[1]['balance_cents'] <=> $x[1]['balance_cents'] ?: $y[1]['earned_cents'] <=> $x[1]['earned_cents']);
?>
  <div class="panel">
    <h2>Affiliates</h2>
    <p>Anyone can join at clipnow.vip/affiliates. They earn <?= (int)(CN_AFF_RATE * 100) ?>% of each new customer's first payment (after any trial, before tax), one time per sale. A commission becomes payable <?= CN_AFF_HOLD_DAYS ?> days after the payment if it wasn't refunded. You pay affiliates yourself, then record it here.
      Stripe is checked once a day by the cron job<?= $sync['last'] ? '; last check ' . e(date('M j, Y g:i a', (int)$sync['last'])) : '' ?>.</p>
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn" name="do" value="aff_sync" <?= $cfg['stripe_key'] === '' ? 'disabled' : '' ?>>Check Stripe for new referrals now</button></form>
  </div>
  <div class="stats">
    <div class="stat"><b><?= count($affs) ?></b><span>Affiliates</span></div>
    <div class="stat"><b><?= $tot['sales'] ?></b><span>Sales referred</span></div>
    <div class="stat"><b><?= $money($tot['earned']) ?></b><span>Commissions earned</span></div>
    <div class="stat"><b><?= $money($tot['balance']) ?></b><span>Ready to pay now</span></div>
    <div class="stat"><b><?= $money($tot['paid']) ?></b><span>Paid out</span></div>
  </div>
  <div class="card"><div class="scroll">
  <?php if (!$rows): ?><div class="empty">No affiliates yet. Share clipnow.vip/affiliates.</div><?php else: ?>
  <table class="members"><thead><tr><th>Affiliate</th><th>Pay to</th><th>Clicks · sign-ups · sales</th><th>Earned · waiting</th><th>Ready to pay</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as [$a, $s]): ?>
    <tr>
      <td class="who"><b><?= e($a['name'] ?: $a['email']) ?></b><span><?= e($a['email']) ?> · code <?= e($a['code']) ?><?= $a['status'] !== 'active' ? ' · <b style="color:var(--warn)">closed</b>' : '' ?></span></td>
      <td><?= e($a['handle'] ?: 'Not set yet') ?></td>
      <td class="num"><?= (int)($a['clicks'] ?? 0) ?> · <?= $s['signups'] ?> · <?= $s['sales'] ?></td>
      <td class="num"><?= $money($s['earned_cents']) ?><div class="sub"><?= $money($s['pending_cents']) ?> in the <?= CN_AFF_HOLD_DAYS ?>-day hold · <?= $money($s['paid_cents']) ?> paid</div></td>
      <td class="num"><b><?= $money($s['balance_cents']) ?></b></td>
      <td class="acts" style="white-space:nowrap">
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="aff" value="<?= e($a['id']) ?>">
          <input name="amount" inputmode="decimal" placeholder="Amount" value="<?= $s['balance_cents'] ? number_format($s['balance_cents'] / 100, 2, '.', '') : '' ?>" style="width:90px">
          <button class="btn go" name="do" value="aff_payout">Record payout</button>
          <?php if ($a['status'] === 'active'): ?><button class="btn warn" name="do" value="aff_disable" onclick="return confirm('Close this affiliate? Their link stops earning; what they already earned stays.')">Close</button><?php else: ?><button class="btn" name="do" value="aff_enable">Reopen</button><?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  </div></div>
  <?php if ($refs): uasort($refs, fn($x, $y) => $y['created'] <=> $x['created']); ?>
  <div class="panel" style="margin-top:18px"><h2>Latest referrals</h2>
    <table class="members"><thead><tr><th>Date</th><th>Affiliate</th><th>Customer</th><th>Status</th><th>Commission</th></tr></thead><tbody>
    <?php foreach (array_slice($refs, 0, 50) as $r): $a = $affs[$r['aff']] ?? ['name' => '?', 'email' => '']; ?>
      <tr><td class="num"><?= e(date('M j, Y', (int)$r['created'])) ?></td><td><?= e($a['name'] ?: $a['email']) ?></td><td><?= e($r['email'] ?: $r['who']) ?><div class="sub"><?= e($r['plan']) ?></div></td>
        <td><?= ['signed_up' => 'Signed up, no payment yet', 'earned' => ($r['payable_at'] <= time() ? 'Earned, payable' : 'Earned, payable ' . date('M j', (int)$r['payable_at'])), 'void' => 'Void: ' . e($r['why'] ?? '')][$r['status']] ?? e($r['status']) ?></td>
        <td class="num"><?= $r['status'] === 'earned' ? $money($r['commission_cents']) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>

<?php elseif ($view === 'campaigns'):
  $camps = cn_camp_all();
  $reported = count(array_filter($camps, fn($c) => !empty($c['reports'])));
  $hiddenN = count(array_filter($camps, fn($c) => !empty($c['hidden'])));
  $money = fn($c) => '$' . number_format($c / 100, 2);
?>
  <div class="panel">
    <h2>Campaigns</h2>
    <p>Platinum members create these and other Platinum members join them. Creators pay clippers directly; ClipNow only keeps track. <b>Hide</b> takes a campaign out of everyone's list except its creator's (you can show it again). <b>Delete</b> removes it and its screenshots for good.</p>
  </div>
  <?php $tr = cn_trending(); $hasKey = (string)($cfg['anthropic_key'] ?? '') !== ''; ?>
  <div class="panel">
    <h2>AI: Top 10 trending campaigns</h2>
    <p>Gold and Platinum members see this list on the Campaigns page. It refreshes about once a day through the daily cron job<?= $hasKey ? '' : '. <b>No Anthropic API key yet:</b> add one in <a href="?view=settings">Settings</a>' ?>.
      <?php if ($tr['updated']): ?>Last updated <b><?= e(date('M j, Y g:i a', (int)$tr['updated'])) ?></b>: <?= count($tr['items']) ?> campaigns, <?= (int)($tr['searches'] ?? 0) ?> web searches, about $<?= e(number_format((float)($tr['cost_usd'] ?? 0), 2)) ?>.<?php else: ?>It hasn't run yet.<?php endif; ?></p>
    <?php if (($tr['error'] ?? '') !== ''): ?><div class="flash bad">Last try (<?= e(date('M j g:i a', (int)$tr['last_try'])) ?>): <?= e($tr['error']) ?></div><?php endif; ?>
    <form method="post" class="inline" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Searching… (up to 3 minutes)'"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="do" value="trending_run"><button class="btn go" id="trRun" <?= $hasKey ? '' : 'disabled' ?>>Run search now</button></form>
    <?php if ($tr['items']): ?><ol style="margin:14px 0 0;padding-left:20px">
      <?php foreach ($tr['items'] as $it): ?><li style="margin-bottom:6px"><a href="<?= e($it['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($it['title']) ?></a> · <?= e($it['creator']) ?> · <?= e($it['pay']) ?> <span class="sub">(<?= e($it['confidence']) ?> confidence)</span></li><?php endforeach; ?>
    </ol><?php endif; ?>
  </div>
  <div class="stats">
    <div class="stat"><b><?= count($camps) ?></b><span>Campaigns</span></div>
    <div class="stat"><b><?= $reported ?></b><span>With reports</span></div>
    <div class="stat"><b><?= $hiddenN ?></b><span>Hidden</span></div>
  </div>
  <div class="card"><div class="scroll">
  <?php if (!$camps): ?><div class="empty">No campaigns yet.</div><?php else: ?>
  <table class="members"><thead><tr><th>Campaign</th><th>Creator</th><th>Rate · budget</th><th>Clippers · posts</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($camps as $c):
    $owed = cn_camp_owed($c); $pend = count(array_filter($c['submissions'], fn($x) => $x['status'] === 'pending'));
    $st = !empty($c['hidden']) ? ['s-canceled', 'Hidden'] : ($c['status'] === 'closed' ? ['s-canceled', 'Closed'] : (($c['ends'] && $c['ends'] < time()) ? ['s-canceled', 'Ended'] : ['s-active', 'Open'])); ?>
    <tr>
      <td class="who"><b><?= e($c['title']) ?></b><span><?= e($c['creator']) ?> · started <?= day((int)$c['created']) ?></span>
        <?php foreach ($c['reports'] ?? [] as $r): ?><div class="sub" style="color:var(--warn)">Report from <?= e($r['email']) ?> (<?= e(day((int)$r['at'])) ?>): <?= e($r['reason']) ?></div><?php endforeach; ?></td>
      <td><?= e($c['owner']) ?></td>
      <td class="num"><?= $money($c['rate_cents']) ?>/1K views<div class="sub"><?= $c['budget_cents'] ? $money($owed) . ' of ' . $money($c['budget_cents']) . ' used' : 'No budget cap · ' . $money($owed) . ' earned' ?></div></td>
      <td class="num"><?= count($c['clippers']) ?> · <?= count($c['submissions']) ?><?= $pend ? '<div class="sub">' . $pend . ' waiting for review</div>' : '' ?></td>
      <td><span class="badge <?= $st[0] ?>"><?= $st[1] ?></span><?= !empty($c['reports']) ? ' <span class="badge s-past_due">' . count($c['reports']) . ' report' . (count($c['reports']) > 1 ? 's' : '') . '</span>' : '' ?></td>
      <td class="acts" style="white-space:nowrap">
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="camp" value="<?= e($c['id']) ?>">
          <?php if (empty($c['hidden'])): ?><button class="btn" name="do" value="camp_hide">Hide</button><?php else: ?><button class="btn" name="do" value="camp_show">Show</button><?php endif; ?>
          <?php if (!empty($c['reports'])): ?><button class="btn" name="do" value="camp_clear_reports">Clear reports</button><?php endif; ?>
          <button class="btn warn" name="do" value="camp_delete" onclick="return confirm('Delete this campaign, its clippers\' posts and screenshots for good?')">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  </div></div>

<?php elseif ($view === 'log'): $log = cn_recent_log(200); ?>
  <div class="panel">
    <h2>Automatic notices</h2>
    <p><?= $cfg['auto_notices'] ? 'On.' : '<b>Off.</b>' ?> Late members get a notice <?= e(implode(', ', array_map(fn($d) => $d === 0 ? 'the day they go late' : "after $d day" . ($d === 1 ? '' : 's'), $cfg['schedule']))) ?>. The check runs once a day<?= !empty($cfg['last_auto_run']) ? '; last run ' . e(date('M j, Y g:i a', (int)$cfg['last_auto_run'])) : '' ?>.</p>
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn" name="do" value="run_auto">Run the check now</button></form>
  </div>
  <div class="card"><div class="scroll">
  <?php if (!$log): ?><div class="empty">No notices sent yet.</div><?php else: ?>
  <table><thead><tr><th>When</th><th>Member</th><th>Plan</th><th>How</th><th>Result</th></tr></thead><tbody>
  <?php foreach ($log as $l): ?>
    <tr><td class="num"><?= e(date('M j, Y g:i a', (int)$l['at'])) ?></td><td><?= e($l['email'] ?: '(no email)') ?><div class="sub num"><?= e($l['sub']) ?></div></td><td><?= e($l['plan'] ?? '') ?></td>
      <td><?= $l['how'] === 'auto' ? 'Automatic' : 'Sent by you' ?></td>
      <td><?= $l['ok'] ? '<span class="badge s-active">Sent</span>' : '<span class="badge s-past_due">Failed</span><div class="sub">' . e($l['error'] ?? '') . '</div>' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  </div></div>

<?php elseif ($view === 'settings'):
  $k = $cfg['stripe_key'];
  $masked = $k === '' ? 'Not set' : substr($k, 0, 8) . '…' . substr($k, -4);
  [$sampleSubject, $sampleBody] = cn_notice_email(['id' => 'sub_x', 'customer_id' => 'cus_x', 'name' => 'Sam Rivera', 'email' => '', 'plan' => 'Gold', 'due_cents' => 1999, 'cents' => 1999, 'currency' => 'USD']);
?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="do" value="settings">
    <div class="panel">
      <h2>Stripe</h2>
      <p>Paste a secret key (starts with <code>sk_live_</code>) or a restricted key (<code>rk_live_</code>) with read access to Customers, Subscriptions, Invoices, Products and Payment Links, and write access to Customer portal, Coupons and Promotion codes. In Stripe: Developers → API keys. Current key: <b><?= e($masked) ?></b></p>
      <label for="sk">New key (leave blank to keep the current one)</label>
      <input id="sk" name="stripe_key" autocomplete="off" spellcheck="false" placeholder="sk_live_…">
    </div>
    <div class="panel">
      <h2>Upload-Post (auto-posting)</h2>
      <?php $u = (string)($cfg['uploadpost_key'] ?? ''); ?>
      <p>Lets members connect TikTok, YouTube, Instagram, Facebook and X and post clips straight from ClipNow. Paste your API key from Upload-Post → API Keys. Current key: <b><?= e($u === '' ? 'Not set' : substr($u, 0, 6) . '…' . substr($u, -4)) ?></b></p>
      <label for="upk">New key (leave blank to keep the current one)</label>
      <input id="upk" name="uploadpost_key" type="password" autocomplete="off" spellcheck="false" placeholder="Paste your Upload-Post API key">
    </div>
    <div class="panel">
      <h2>Corporate plan ($299.99/month)</h2>
      <?php $cl = (string)($cfg['corporate_link'] ?? ''); $keyMode = str_contains((string)$cfg['stripe_key'], '_live_') ? 'live' : 'test'; ?>
      <p><?php if ($cl === ''): ?>Not created in Stripe yet. The button below makes the product, the $299.99 monthly price (no free trial) and its checkout link using your Stripe key.<?php else: ?>Created in Stripe (<?= e($cfg['corporate_mode'] ?? '') ?> mode). Checkout link: <a href="<?= e($cl) ?>" target="_blank" rel="noopener" style="overflow-wrap:anywhere"><?= e($cl) ?></a><?php if (($cfg['corporate_mode'] ?? '') !== $keyMode): ?><br><b>Your Stripe key is now in <?= e($keyMode) ?> mode:</b> create it again so live customers can buy it.<?php endif; ?><?php endif; ?></p>
      <button class="btn<?= $cl === '' ? ' go' : '' ?>" type="submit" name="do" value="corporate_create" formnovalidate onclick="return confirm('Create the Corporate plan in Stripe now?')"><?= $cl === '' ? 'Create Corporate plan in Stripe' : 'Create it again' ?></button>
    </div>
    <div class="panel">
      <h2>Anthropic (AI trending campaigns)</h2>
      <?php $ak = (string)($cfg['anthropic_key'] ?? ''); ?>
      <p>Powers the daily "Top 10 trending campaigns" list for Gold and Platinum members. Create a key in the Anthropic Console → API Keys and make sure the account has credits. Current key: <b><?= e($ak === '' ? 'Not set' : substr($ak, 0, 10) . '…' . substr($ak, -4)) ?></b></p>
      <label for="ank">New key (leave blank to keep the current one)</label>
      <input id="ank" name="anthropic_key" type="password" autocomplete="off" spellcheck="false" placeholder="sk-ant-…">
      <?php if ($ak !== ''): ?><label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="anthropic_key_remove" value="1" style="width:auto"> Remove the key (stops the daily search)</label><?php endif; ?>
    </div>
    <div class="panel">
      <h2>Fast email delivery</h2>
      <?php $rk = (string)($cfg['resend_key'] ?? ''); ?>
      <p>Sign-in links and notices arrive in seconds when they're sent through Resend (free for up to 3,000 emails a month). Without it they go through the web host's mail queue, which can take several minutes. In Resend: add and verify the domain <b>clipnow.vip</b>, then create an API key with "Sending access". Status: <b style="color:<?= $rk === '' ? '#F5C451' : 'var(--green, #31C971)' ?>"><?= e($rk === '' ? 'Not set: using the web host\'s mail queue' : 'On (' . substr($rk, 0, 6) . '…' . substr($rk, -4) . ')') ?></b></p>
      <label for="rsk">Resend API key (leave blank to keep the current one)</label>
      <input id="rsk" name="resend_key" type="password" autocomplete="off" spellcheck="false" placeholder="re_…">
      <?php if ($rk !== ''): ?><label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="resend_key_remove" value="1" style="width:auto"> Remove the key (go back to the web host's mail queue)</label><?php endif; ?>
    </div>
    <div class="panel">
      <h2>Late-payment notices</h2>
      <p>A membership is late when Stripe can't charge the card (status Past due or Unpaid). Each notice links to a secure page where the member updates their card.</p>
      <div class="grid2">
        <div><label class="check"><input type="checkbox" name="auto_notices" value="1" <?= $cfg['auto_notices'] ? 'checked' : '' ?>> Send notices automatically</label></div>
        <div><label for="sch">Days after going late to send each notice</label><input id="sch" name="schedule" value="<?= e(implode(', ', $cfg['schedule'])) ?>"></div>
        <div><label for="fn">From name</label><input id="fn" name="from_name" value="<?= e($cfg['from_name']) ?>"></div>
        <div><label for="fe">From address</label><input id="fe" name="from_email" type="email" value="<?= e($cfg['from_email']) ?>" required></div>
        <div><label for="rt">Replies go to</label><input id="rt" name="reply_to" type="email" value="<?= e($cfg['reply_to']) ?>"></div>
      </div>
    </div>
    <div class="panel">
      <h2>Admin password</h2>
      <label for="np">New password (leave blank to keep it)</label>
      <input id="np" name="new_password" type="password" autocomplete="new-password" minlength="10">
    </div>
    <p><button class="btn go">Save settings</button></p>
  </form>
  <div class="panel">
    <h2>What members receive</h2>
    <p>Subject: <b style="color:var(--text)"><?= e($sampleSubject) ?></b></p>
    <pre><?= e($sampleBody) ?></pre>
    <form method="post" class="bar" style="margin:14px 0 0">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="do" value="test_email">
      <input name="to" type="email" placeholder="you@example.com" style="max-width:300px" required>
      <button class="btn">Send me a test</button>
    </form>
  </div>
  <div class="panel">
    <h2>Posts ClipNow turned down</h2>
    <?php
      $rf = cn_dir('posts') . '/refused.jsonl';
      $refused = is_file($rf) ? array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), array_slice(file($rf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -25))))) : [];
    ?>
    <?php if (!$refused): ?><p>None yet. When a member's post is stopped before it's sent (not connected, daily limit, a file problem, an error from the posting service), it shows here with the reason the member saw.</p><?php else: ?>
    <p>The last <?= count($refused) ?> posts that were stopped before reaching the posting service, with the exact message the member saw.</p>
    <table class="members"><thead><tr><th>When</th><th>Member</th><th>Platforms</th><th>Message shown</th></tr></thead><tbody>
    <?php foreach ($refused as $r): ?>
      <tr><td><?= e(date('M j, g:i:s a', (int)$r['at'])) ?></td><td><?= e($r['email'] ?: '(not signed in)') ?></td><td><?= e(implode(', ', $r['platforms'] ?? []) . (($r['mode'] ?? '') === 'draft' ? ' (draft)' : '') . (($r['schedule'] ?? '') ? ' · scheduled' : '')) ?></td><td><?= e($r['error'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h2>Questions asked in the Ask ClipNow chat</h2>
    <?php
      $cf = CN_DATA . '/chat-log.jsonl';
      $chats = is_file($cf) ? array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), array_slice(file($cf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -30))))) : [];
    ?>
    <?php if (!$chats): ?><p>None yet. Questions visitors ask the chat on the home and coming-soon pages show here, with the answer they got. Use them to spot gaps in the FAQ.</p><?php else: ?>
    <p>The last <?= count($chats) ?> questions, newest first. Visitors' IP addresses aren't stored.</p>
    <table class="members"><thead><tr><th>When</th><th>Question</th><th>Answer</th></tr></thead><tbody>
    <?php foreach ($chats as $c): ?>
      <tr><td><?= e(date('M j, g:i a', (int)$c['at'])) ?></td><td><?= e($c['q'] ?? '') ?></td><td><?= e(($c['a'] ?? '') !== '' ? $c['a'] : 'No answer: ' . ($c['note'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <div class="panel">
    <h2>Recent emails</h2>
    <?php $ml = cn_mail_recent(15); ?>
    <?php if (!$ml): ?><p>No emails sent yet.</p><?php else: ?>
    <p>"Sent" means the email left ClipNow. Through Resend it reaches the inbox within seconds; through the web host's queue it can take minutes. "Delivery" is what Resend reports back from the inbox provider: <b>Delivered</b> means it reached the inbox (or the spam folder), <b>Still on its way</b> or <b>Delayed</b> means the inbox provider hasn't accepted it yet, <b>Bounced</b> means the address refused it, and <b>Marked as spam</b> means the reader reported it.</p>
    <table class="members"><thead><tr><th>When</th><th>To</th><th>Email</th><th>Sent by</th><th>Time</th><th>Result</th><th>Delivery</th></tr></thead><tbody>
    <?php foreach ($ml as $m): ?>
      <tr><td><?= e(date('M j, g:i:s a', (int)$m['at'])) ?></td><td><?= e($m['to']) ?></td><td><?= e($m['subject']) ?></td><td><?= e($m['via'] === 'resend' ? 'Resend' : 'Web host queue') ?></td><td><?= e(number_format($m['ms'] / 1000, 1)) ?>s</td><td><?= $m['ok'] ? 'Sent' : e('Failed: ' . ($m['error'] ?? '')) ?></td><td><?php
        $ds = !empty($m['rid']) ? cn_resend_status((string)$m['rid']) : '';
        $dl = ['delivered' => 'Delivered', 'sent' => 'Still on its way', 'queued' => 'Still on its way', 'scheduled' => 'Still on its way', 'delivery_delayed' => 'Delayed', 'bounced' => 'Bounced', 'complained' => 'Marked as spam', 'failed' => 'Failed', 'opened' => 'Delivered (opened)', 'clicked' => 'Delivered (link tapped)', 'canceled' => 'Canceled'];
        echo e($ds === '' ? (empty($m['rid']) ? '–' : 'Unknown') : ($dl[$ds] ?? $ds));
      ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
