<?php
// GET /api/welcome.php?session_id=cs_… → where Stripe sends new members after checkout.
// Confirms the checkout with Stripe, then signs the member in on this device.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$id = (string)($_GET['session_id'] ?? '');
try {
  if (!preg_match('/^cs_(test|live)_\w+$/', $id)) throw new RuntimeException('bad id');
  $cs = cn_stripe('GET', '/v1/checkout/sessions/' . $id);
  $email = $cs['customer_details']['email'] ?? '';
  // Only a completed checkout from the last day can sign someone in.
  if (($cs['status'] ?? '') !== 'complete' || !$email || ($cs['created'] ?? 0) < time() - 86400) throw new RuntimeException('not complete');
  cn_start_session($email);
  header('Location: ' . CN_SITE . '/?signin=welcome#studio', true, 303);
} catch (Throwable $e) {
  // Payment may still have worked; ask them to sign in with their email instead.
  header('Location: ' . CN_SITE . '/?signin=needed#studio', true, 303);
}
