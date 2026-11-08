<?php
// Launch list for the coming-soon page.
// POST {email} → saves the email (once) so the owner can tell everyone when ClipNow opens.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
  $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
  $email = strtolower(trim((string)($in['email'] ?? '')));
  if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) cn_json_out(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
  if (!cn_rate_ok('waitlist:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 10)) cn_json_out(['ok' => false, 'error' => 'Too many tries. Please wait a few minutes.'], 429);
  $already = cn_locked('waitlist.json', function (&$d) use ($email) {
    if (isset($d[$email])) return true;
    if (count($d) >= 50000) return false;
    $d[$email] = time();
    return false;
  });
  cn_json_out(['ok' => true, 'already' => $already]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
