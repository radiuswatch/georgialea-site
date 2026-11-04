<?php
/**
 * The "update your payment" link in late-payment notices lands here.
 * The link is signed and expires after 30 days. A valid link opens the Stripe
 * customer portal for that member; if the portal isn't turned on in Stripe, it
 * falls back to the unpaid invoice's payment page.
 */
declare(strict_types=1);
require __DIR__ . '/admin/lib.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$c = (string)($_GET['c'] ?? ''); $s = (string)($_GET['s'] ?? '');
$e = (string)($_GET['e'] ?? ''); $t = (string)($_GET['t'] ?? '');

function page(string $title, string $msg): never {
  $contact = htmlspecialchars(cn_config()['reply_to'] ?: 'info@georgialea.com');
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . ' · ClipNow</title>'
    . '<style>body{margin:0;background:#0B0D0B;color:#E9EDE9;font:17px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;display:grid;place-items:center;min-height:100vh;padding:16px;box-sizing:border-box}'
    . 'main{max-width:440px;background:#141714;border:1px solid #242923;border-radius:16px;padding:28px}h1{font:400 24px Georgia,serif;margin:0 0 10px}p{color:#96A096;margin:0 0 12px}a{color:#31C971}</style></head>'
    . '<body><main><h1>' . htmlspecialchars($title) . '</h1><p>' . $msg . '</p><p>Need help? Email <a href="mailto:' . $contact . '">' . $contact . '</a>.</p><p><a href="' . CN_SITE . '/">Back to ClipNow</a></p></main></body></html>';
  exit;
}

if (!preg_match('/^cus_\w+$/', $c) || !preg_match('/^sub_\w+$/', $s) || !cn_check_billing_link($c, $s, $e, $t)) {
  http_response_code(403);
  page('This link has expired', 'For your security, payment links only work for 30 days. Reply to the email you got from us and we\'ll send a fresh one.');
}

try {
  $portal = cn_stripe('POST', '/v1/billing_portal/sessions', ['customer' => $c, 'return_url' => CN_SITE . '/?billing=updated']);
  header('Location: ' . $portal['url'], true, 303);
  exit;
} catch (Throwable $ex) {
  try {
    $sub = cn_stripe('GET', '/v1/subscriptions/' . rawurlencode($s), ['expand' => ['latest_invoice']]);
    $url = $sub['latest_invoice']['hosted_invoice_url'] ?? '';
    if ($sub['customer'] === $c && $url) { header('Location: ' . $url, true, 303); exit; }
  } catch (Throwable $ex2) { /* fall through */ }
  http_response_code(503);
  page('We couldn\'t open the payment page', 'Something went wrong on our side. Please try the link again in a few minutes.');
}
