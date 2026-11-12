<?php
// POST /api/code.php {email, code} → signs in with the 6-digit code from the sign-in email,
// in the browser the member is already using.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$email = trim((string)($in['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) cn_json_out(['ok' => false, 'error' => 'Enter the email you used.'], 422);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
if (!cn_rate_ok('code-ip:' . $ip, 20) || !cn_rate_ok('code:' . strtolower($email), 10)) cn_json_out(['ok' => false, 'error' => 'Too many tries. Wait 15 minutes and try again.'], 429);
[$who, $why] = cn_use_login_code($email, (string)($in['code'] ?? ''));
if (!$who) cn_json_out(['ok' => false, 'error' => $why], 422);
cn_start_session($who);
// Before launch only members can sign in; give this browser the tester pass so it shows the full site.
if (!cn_site_live()) setcookie('cn_preview', '1', ['expires' => time() + 180 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
cn_json_out(['ok' => true]);
