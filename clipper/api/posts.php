<?php
// GET /api/posts.php → the member's recent auto-posts with where each one really ended up.
// POST /api/posts.php {action:"hide", ids:[...]} → clears those entries from the list (the post log is kept).
// Upload-Post is asked about posts that aren't finished yet (up to 15 per call); finished results
// are saved, so a post that was refused keeps showing why.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
@set_time_limit(90);
try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'error' => 'Please sign in first.'], 401);
  // Entries the member cleared from the list. The post log itself is kept (it counts toward daily limits).
  $hf = cn_dir('posts') . '/' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$m['customer_id']) . '.hidden.json';
  $hidden = cn_json_file($hf, []);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (($in['action'] ?? '') !== 'hide') cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
    $ids = array_slice(array_values(array_filter((array)($in['ids'] ?? []), fn($x) => is_string($x) && preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $x))), 0, 500);
    foreach ($ids as $id) $hidden[$id] = time();
    if (count($hidden) > 5000) $hidden = array_slice($hidden, -5000, null, true);
    file_put_contents($hf, json_encode($hidden), LOCK_EX);
    cn_json_out(['ok' => true, 'hidden' => count($ids)]);
  }
  $sf = cn_post_status_file($m);
  $cache = cn_json_file($sf, []);
  $changed = false; $asked = 0; $now = time();
  $out = [];
  foreach (cn_post_history($m, 150) as $p) {
    $rid = (string)($p['request_id'] ?? ''); $jid = (string)($p['job_id'] ?? '');
    $id = $rid !== '' ? $rid : ('t' . $p['at'] . '-' . substr(md5((string)($p['title'] ?? '')), 0, 8));
    if (isset($hidden[$id])) continue;
    $sched = (int)($p['scheduled'] ?? 0);
    $row = ['id' => $id, 'draft' => (array)($p['draft'] ?? []), 'at' => $p['at'], 'title' => $p['title'] ?? '', 'platforms' => $p['platforms'] ?? [], 'scheduled' => $sched, 'status' => $sched > $now ? 'scheduled' : 'sent', 'results' => []];
    $c = $rid !== '' ? ($cache[$rid] ?? null) : null;
    $due = $sched === 0 || $sched <= $now;
    if (($rid !== '' || $jid !== '') && $due && !($c['final'] ?? false) && $asked < 15 && ($c['checked'] ?? 0) < $now - 20) {
      $asked++;
      try {
        $s = cn_up('GET', '/uploadposts/status', $rid !== '' ? ['request_id' => $rid] : ['job_id' => $jid]);
        $res = cn_post_results($s, empty($p['draft']));   // a drafts refusal mustn't be read as the posting limit
        $status = strtolower((string)($s['status'] ?? 'sent'));
        $final = in_array($status, ['completed', 'failed', 'error', 'success'], true) && count($res) >= count($row['platforms']);
        $c = ['status' => $status, 'results' => $res, 'final' => $final, 'checked' => $now];
        if ($rid !== '') { $cache[$rid] = $c; $changed = true; }
      } catch (Throwable $e) { /* keep what we know */ }
    }
    if ($c) { $row['status'] = $c['status']; $row['results'] = $c['results']; }
    $out[] = $row;
  }
  if ($changed) file_put_contents($sf, json_encode($cache, JSON_UNESCAPED_SLASHES), LOCK_EX);
  $failed = 0;
  foreach ($out as $r) foreach ($r['results'] as $x) if (!$x['ok']) { $failed++; break; }
  cn_json_out(['ok' => true, 'posts' => $out, 'failed' => $failed]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => $e->getMessage()], 503);
}
