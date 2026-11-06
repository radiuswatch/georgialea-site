<?php
// GET /api/posts.php → the member's recent auto-posts with their latest status.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'error' => 'Please sign in first.'], 401);
  $out = [];
  foreach (cn_post_history($m, 60) as $i => $p) {
    $row = ['at' => $p['at'], 'title' => $p['title'] ?? '', 'platforms' => $p['platforms'] ?? [], 'scheduled' => (int)($p['scheduled'] ?? 0), 'status' => 'sent', 'results' => []];
    // Check live status for the 5 most recent posts only, to keep this fast.
    if ($i < 5 && (($p['request_id'] ?? '') !== '' || ($p['job_id'] ?? '') !== '')) {
      try {
        $q = ($p['request_id'] ?? '') !== '' ? ['request_id' => $p['request_id']] : ['job_id' => $p['job_id']];
        $s = cn_up('GET', '/uploadposts/status', $q);
        $row['status'] = (string)($s['status'] ?? $row['status']);
        foreach ((array)($s['results'] ?? []) as $res) {
          if (!is_array($res)) continue;
          $row['results'][] = ['platform' => (string)($res['platform'] ?? ''), 'ok' => !empty($res['success']),
            'url' => (string)($res['url'] ?? $res['post_url'] ?? ''), 'error' => (string)($res['error'] ?? $res['message'] ?? '')];
        }
      } catch (Throwable $e) { /* keep the logged entry */ }
    }
    $out[] = $row;
  }
  cn_json_out(['ok' => true, 'posts' => $out]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => $e->getMessage()], 503);
}
