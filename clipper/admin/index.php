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

  if ($do === 'settings') {
    $key = trim((string)($_POST['stripe_key'] ?? ''));
    if ($key !== '') {
      if (!preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', $key)) { flash('That doesn\'t look like a Stripe secret or restricted key (it starts with sk_ or rk_).', false); back('settings'); }
      $cfg['stripe_key'] = $key;
    }
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
      <a href="?view=log" class="<?= $view === 'log' ? 'on' : '' ?>">Notice log</a>
      <a href="?view=settings" class="<?= $view === 'settings' ? 'on' : '' ?>">Settings</a>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn" name="do" value="logout">Sign out</button></form>
    </nav>
  </header>
  <?php if ($flash): ?><div class="flash <?= $flash[1] ? '' : 'bad' ?>"><?= e($flash[0]) ?></div><?php endif; ?>

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
        <td><?= e($s['plan']) ?><div class="sub num"><?= cn_money($s['cents'], $s['currency']) ?>/<?= $s['interval'] === 'year' ? 'yr' : 'mo' ?></div></td>
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
      <p>Paste a secret key (starts with <code>sk_live_</code>) or a restricted key (<code>rk_live_</code>) with read access to Customers, Subscriptions and Invoices, and write access to Customer portal. In Stripe: Developers → API keys. Current key: <b><?= e($masked) ?></b></p>
      <label for="sk">New key (leave blank to keep the current one)</label>
      <input id="sk" name="stripe_key" autocomplete="off" spellcheck="false" placeholder="sk_live_…">
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
<?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
