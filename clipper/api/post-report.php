<?php
// POST /api/post-report.php {status, text, bytes, platforms} → the page reports a posting reply that wasn't
// ClipNow's JSON (a host error page, a firewall block, a time-out), so it can be looked into.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false], 400);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$m = cn_current_member();
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!cn_rate_ok('postreport:' . $ip, 30)) cn_json_out(['ok' => false], 429);
$e = ['at' => time(), 'step' => 'browser', 'customer' => (string)($m['customer_id'] ?? ''), 'email' => (string)($m['email'] ?? ''),
  'status' => (int)($in['status'] ?? 0), 'bytes' => (int)($in['bytes'] ?? 0), 'seconds' => (float)($in['seconds'] ?? 0),
  'platforms' => array_slice(array_map('strval', (array)($in['platforms'] ?? [])), 0, 6),
  'text' => mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)($in['text'] ?? ''))), 0, 300), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)];
$f = cn_dir('posts') . '/attempts.jsonl';
file_put_contents($f, json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
cn_json_out(['ok' => true]);
