<?php
// GET /api/posts.php → the member's recent auto-posts with where each one really ended up.
// POST /api/posts.php {action:"hide", ids:[...]} → clears those entries from the list (the post log is kept).
// POST /api/posts.php {action:"reschedule", id, when:"YYYY-MM-DDTHH:MM", tz} → moves a scheduled post
// POST /api/posts.php {action:"cancel", id} → cancels a scheduled post (it never goes out)
// Upload-Post is asked about posts that aren't finished yet (up to 15 per call); finished results
// are saved, so a post that was refused keeps showing why.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
@set_time_limit(90);
try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'error' => 'Please sign in first.'], 401);
  // Entries the member cleared from the list. The post log itself is kept (it counts toward daily limits).
  $hf = cn_dir('posts') . '/' . preg_replace('/[^A-Za-z0-9_]/', '', cn_data_id($m)) . '.hidden.json';
  $hidden = cn_json_file($hf, []);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $act = (string)($in['action'] ?? '');
    if ($act === 'reschedule' || $act === 'cancel') {
      if (!cn_rate_ok('sched:' . strtolower((string)$m['email']), 120)) cn_json_out(['ok' => false, 'error' => 'Too many changes in a short time. Wait a few minutes.'], 429);
      $id = (string)($in['id'] ?? ''); $p = null;
      foreach (cn_post_history($m, 400) as $x) {
        $xid = (string)($x['request_id'] ?? '') !== '' ? (string)$x['request_id'] : ('t' . $x['at'] . '-' . substr(md5((string)($x['title'] ?? '')), 0, 8));
        if ($xid === $id) { $p = $x; break; }
      }
      $jid = (string)($p['job_id'] ?? '');
      if (!$p || $jid === '' || !empty($p['cancelled'])) cn_json_out(['ok' => false, 'error' => 'That scheduled post wasn’t found. Reload the page and try again.'], 404);
      if ((int)($p['scheduled'] ?? 0) < time() + 120) cn_json_out(['ok' => false, 'error' => 'This post is about to go out (or already has), so it can’t be changed now.'], 422);
      if ($act === 'cancel') {
        try { cn_up('DELETE', '/uploadposts/schedule/' . rawurlencode($jid)); }
        catch (Throwable $e) { if ($e->getCode() !== 404) cn_json_out(['ok' => false, 'error' => 'Couldn’t cancel it: ' . $e->getMessage()], 502); }
        cn_post_change($m, $jid, ['cancelled' => time()]);
        cn_json_out(['ok' => true, 'cancelled' => true, 'id' => $id]);
      }
      try {
        $tz = new DateTimeZone((string)($in['tz'] ?? 'UTC'));
      } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
      $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string)($in['when'] ?? ''), $tz);
      if (!$dt) cn_json_out(['ok' => false, 'error' => 'Pick a day and time.'], 422);
      $t = $dt->getTimestamp();
      if ($t < time() + 300) cn_json_out(['ok' => false, 'error' => 'Pick a time at least 5 minutes from now.'], 422);
      if ($t > time() + 60 * 86400) cn_json_out(['ok' => false, 'error' => 'You can schedule up to 60 days ahead.'], 422);
      foreach ((array)($p['platforms'] ?? []) as $pf) {
        [$fits, $next] = cn_post_cap_check($m, (string)$pf, $t, (int)($p['set'] ?? 1), in_array($pf, (array)($p['draft'] ?? []), true), $jid);
        if (!$fits) cn_json_out(['ok' => false, 'error' => (CN_POST_PLATFORMS[$pf] ?? $pf) . ' allows ' . cn_daily_caps()[$pf] . ' posts in 24 hours, and that time would go over it.', 'next_slot' => $next], 422);
      }
      try { cn_up('PATCH', '/uploadposts/schedule/' . rawurlencode($jid), ['scheduled_date' => gmdate('Y-m-d\TH:i:s\Z', $t)]); }
      catch (Throwable $e) { cn_json_out(['ok' => false, 'error' => 'Couldn’t change the time: ' . $e->getMessage()], 502); }
      cn_post_change($m, $jid, ['scheduled' => $t]);
      cn_json_out(['ok' => true, 'scheduled' => $t, 'id' => $id]);
    }
    if ($act !== 'hide') cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
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
    if (isset($hidden[$id]) || !empty($p['cancelled'])) continue;
    $sched = (int)($p['scheduled'] ?? 0);
    $row = ['id' => $id, 'draft' => (array)($p['draft'] ?? []), 'at' => $p['at'], 'title' => $p['title'] ?? '', 'platforms' => $p['platforms'] ?? [], 'scheduled' => $sched, 'status' => $sched > $now ? 'scheduled' : 'sent', 'results' => [], 'can_change' => $jid !== '' && $sched > $now + 120];
    $c = $rid !== '' ? ($cache[$rid] ?? null) : null;
    $due = $sched === 0 || $sched <= $now;
    if (($rid !== '' || $jid !== '') && $due && !($c['final'] ?? false) && $asked < 15 && ($c['checked'] ?? 0) < $now - 20) {
      $asked++;
      try {
        $s = cn_up('GET', '/uploadposts/status', $rid !== '' ? ['request_id' => $rid] : ['job_id' => $jid]);
        $res = cn_post_results($s, empty($p['draft']));   // a drafts refusal mustn't be read as the posting limit
        $status = strtolower((string)($s['status'] ?? 'sent'));
        $final = in_array($status, ['completed', 'failed', 'error', 'success'], true) && count($res) >= count($row['platforms']);
        if ($final) foreach ($res as &$r) $r['pending'] = false;   // finished: anything not posted really didn't post
        unset($r);
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
