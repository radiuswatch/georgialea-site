<?php
// GET /api/welcome.php?cs=cs_… → where Stripe sends new members after checkout.
// Confirms the checkout with Stripe, then signs the member in on this device.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
// 'cs' (not 'session_id'): the web host's firewall blocks a 'session_id' link coming from another site.
$id = (string)($_GET['cs'] ?? $_GET['session_id'] ?? '');
try {
  if (!preg_match('/^cs_(test|live)_\w+$/', $id)) throw new RuntimeException('bad id');
  $cs = cn_stripe('GET', '/v1/checkout/sessions/' . $id);
  $email = $cs['customer_details']['email'] ?? '';
  // Only a completed checkout from the last day can sign someone in.
  if (($cs['status'] ?? '') !== 'complete' || !$email || ($cs['created'] ?? 0) < time() - 86400) throw new RuntimeException('not complete');
  // Security (2026-11-23): Stripe lets the buyer type ANY email at checkout, so the email is not proof of ownership.
  // Sign in straight away only when this email has never been used before (no other Stripe customer, no member
  // record, no posting history). Anyone returning with an existing email signs in with the emailed code instead.
  if (welcome_email_taken($email, (string)($cs['customer'] ?? ''))) throw new RuntimeException('existing email: code sign-in');
  cn_start_session($email);
  header('Location: ' . CN_SITE . '/studio?signin=welcome', true, 303);
} catch (Throwable $e) {
  // Payment may still have worked; ask them to sign in with their email instead.
  header('Location: ' . CN_SITE . '/studio?signin=needed', true, 303);
}

/** True if $email already belongs to a ClipNow member other than this checkout's brand-new customer $cust. */
function welcome_email_taken(string $email, string $cust): bool {
  $e = strtolower(trim($email));
  if ($cust === '' || $e === '') return true;
  $map = cn_read('member-ids.json', []);
  if (!empty($map[$e]) && $map[$e] !== $cust) return true;
  $old = cn_find_old_customer($e); if ($old !== '' && $old !== $cust) return true;
  foreach (array_unique([trim($email), $e]) as $x)
    foreach ((cn_stripe('GET', '/v1/customers', ['email' => $x, 'limit' => 5])['data'] ?? []) as $c) if (($c['id'] ?? '') !== $cust) return true;
  try {
    $q = "email:'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $e) . "'";
    foreach ((cn_stripe('GET', '/v1/customers/search', ['query' => $q, 'limit' => 5])['data'] ?? []) as $c) if (($c['id'] ?? '') !== $cust) return true;
  } catch (Throwable $t) { /* search unavailable: the exact lookups above still ran */ }
  return false;
}
