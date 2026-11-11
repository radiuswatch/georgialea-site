<?php
// GET /api/posts.php → the member's recent auto-posts with where each one really ended up.
// Upload-Post is asked about posts that aren't finished yet (up to 15 per call); finished results
// are saved, so a post that was refused keeps showing why.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
@set_time_limit(90);
try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'error' => 'Please sign in first.'], 401);
  $sf = cn_post_status_file($m);
  $cache = cn_json_file($sf, []);
  $changed = false; $asked = 0; $now = time();
  $out = [];
  foreach (cn_post_history($m, 100) as $p) {
    $rid = (string)($p['request_id'] ?? ''); $jid = (string)($p['job_id'] ?? '');
    $sched = (int)($p['scheduled'] ?? 0);
    $row = ['at' => $p['at'], 'title' => $p['title'] ?? '', 'platforms' => $p['platforms'] ?? [], 'scheduled' => $sched, 'status' => $sched > $now ? 'scheduled' : 'sent', 'results' => []];
    $c = $rid !== '' ? ($cache[$rid] ?? null) : null;
    $due = $sched === 0 || $sched <= $now;
    if (($rid !== '' || $jid !== '') && $due && !($c['final'] ?? false) && $asked < 15 && ($c['checked'] ?? 0) < $now - 20) {
      $asked++;
      try {
        $s = cn_up('GET', '/uploadposts/status', $rid !== '' ? ['request_id' => $rid] : ['job_id' => $jid]);
        $res = cn_post_results($s);
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
