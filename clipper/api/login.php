<?php
// POST /api/login.php {email} → emails a one-time sign-in link to a ClipNow member.
// Always answers the same way, so it can't be used to find out who is a member.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
$in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$email = trim((string)($in['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) cn_json_out(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
if (!cn_rate_ok('ip:' . $ip, 10) || !cn_rate_ok('email:' . strtolower($email), 3)) {
  cn_json_out(['ok' => false, 'error' => 'Too many sign-in requests. Please wait 15 minutes and try again.'], 429);
}
try { cn_send_login_link($email); }
catch (Throwable $e) { cn_json_out(['ok' => false, 'error' => 'Sign-in is unavailable right now. Please try again shortly.'], 503); }
cn_json_out(['ok' => true]);
