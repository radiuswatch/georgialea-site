<?php
// /api/verify.php?t=… → the link from the sign-in email.
// GET shows a "Sign in" button; only the POST from that button uses the one-time link.
// (Email scanners open links automatically; this stops them using it up.)
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');
// same-origin (not no-referrer): keeps the link private from other sites, but lets the
// browser send this page's Origin with the Sign in button, which the POST check requires.
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex');
$t = (string)($_POST['t'] ?? $_GET['t'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = cn_same_origin() ? cn_use_login_link($t) : null;
  if (!$email) { header('Location: ' . CN_SITE . '/?signin=expired', true, 303); exit; }
  cn_start_session($email);
  header('Location: ' . CN_SITE . '/?signin=ok#studio', true, 303);
  exit;
}
$valid = preg_match('/^[A-Za-z0-9_-]{30,}$/', $t) && ($j = cn_json_file(cn_token_file('logins', $t), null)) && ($j['exp'] ?? 0) >= time();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · ClipNow</title><link rel="icon" href="/icon-192.png">
<style>
  body{margin:0;background:#0B0D0B;color:#E9EDE9;font:17px/1.6 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;display:grid;place-items:center;min-height:100vh;padding:16px;box-sizing:border-box}
  main{width:100%;max-width:420px;background:#141714;border:1px solid #242923;border-radius:16px;padding:30px;text-align:center}
  .mark{width:48px;height:48px;border-radius:13px;background:#31C971;color:#06230F;display:grid;place-items:center;font:700 20px Consolas,Menlo,monospace;margin:0 auto 16px}
  h1{font:400 26px Georgia,serif;margin:0 0 8px} p{color:#96A096;margin:0 0 20px} a{color:#31C971}
  button{width:100%;background:#31C971;color:#06230F;border:0;border-radius:10px;padding:14px;font:inherit;font-weight:600;cursor:pointer}
</style></head>
<body><main><div class="mark">CN</div>
<?php if ($valid): ?>
  <h1>Sign in to ClipNow</h1>
  <p>Tap the button to finish signing in on this device.</p>
  <form method="post"><input type="hidden" name="t" value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"><button>Sign in</button></form>
<?php else: ?>
  <h1>This link has expired</h1>
  <p>Sign-in links work once and expire after <?= CN_LINK_MINUTES ?> minutes. Request a new one from ClipNow.</p>
  <p><a href="<?= CN_SITE ?>/?signin=again">Back to ClipNow</a></p>
<?php endif; ?>
</main></body></html>
