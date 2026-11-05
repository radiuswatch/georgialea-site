<?php
// POST /api/portal.php → a link to the Stripe billing portal for the signed-in member.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false], 400);
try {
  $m = cn_current_member(true);
  if (!$m || empty($m['customer_id'])) cn_json_out(['ok' => false, 'error' => 'Please sign in first.'], 401);
  $p = cn_stripe('POST', '/v1/billing_portal/sessions', ['customer' => $m['customer_id'], 'return_url' => CN_SITE . '/#studio']);
  cn_json_out(['ok' => true, 'url' => $p['url']]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => 'Billing is unavailable right now. Please try again shortly.'], 503);
}
