<?php
// GET /api/me.php → who is signed in, and their ClipNow plan.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
try {
  $m = cn_current_member(isset($_GET['fresh']));
} catch (Throwable $e) {
  cn_json_out(['signedIn' => false, 'error' => 'unavailable'], 503);
}
if (!$m) cn_json_out(['signedIn' => false]);
cn_json_out([
  'signedIn' => true,
  'email' => $m['email'],
  'active' => (bool)$m['active'],
  'status' => $m['status'],
  'plan' => $m['plan'],
  'planKey' => $m['plan_key'],
  'free' => (bool)($m['free'] ?? false),
  'trialEnd' => (int)($m['trial_end'] ?? 0),
  'periodEnd' => (int)($m['period_end'] ?? 0),
  'cancelAtEnd' => (bool)($m['cancel_at_end'] ?? false),
]);
