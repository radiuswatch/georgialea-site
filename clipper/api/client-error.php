<?php
// Page errors reported by browsers (e.g. an iPhone where part of a page stays blank), so the owner
// and Claude can see what went wrong on a device we can't test. Appends one line per error to
// ~/clipnow-data/client-errors.jsonl. Never deletes anything; stops adding once the file is 2 MB.
// POST {msg, src, line, col, page, kind}
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false], 400);
  if (!cn_rate_ok('client-error:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30)) cn_json_out(['ok' => false], 429);
  $f = CN_DATA . '/client-errors.jsonl';
  if (is_file($f) && filesize($f) > 2 * 1048576) cn_json_out(['ok' => true]);
  $in = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true) ?: [];
  $s = fn($k, $n) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
  $m = cn_current_member();
  $row = ['at' => time(), 'kind' => $s('kind', 20), 'msg' => $s('msg', 300), 'src' => $s('src', 200), 'line' => (int)($in['line'] ?? 0), 'col' => (int)($in['col'] ?? 0),
    'page' => $s('page', 200), 'signed_in' => (bool)$m, 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)];
  if ($row['msg'] === '') cn_json_out(['ok' => false], 422);
  if (!is_dir(CN_DATA)) mkdir(CN_DATA, 0700, true);
  file_put_contents($f, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  cn_json_out(['ok' => true]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false], 500);
}
