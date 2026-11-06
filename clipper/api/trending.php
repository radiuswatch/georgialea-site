<?php
// GET /api/trending.php → today's AI-picked Top 10 paid clipping campaigns (Gold and Platinum only).
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'signedIn' => false, 'error' => 'Please sign in first.'], 401);
  if (!cn_can_see_trending($m)) cn_json_out(['ok' => false, 'signedIn' => true, 'allowed' => false, 'error' => 'The Top 10 trending campaigns are part of the Gold and Platinum plans.'], 403);
  $t = cn_trending();
  cn_json_out(['ok' => true, 'updated' => (int)$t['updated'], 'items' => $t['items']]);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => 'Trending campaigns are unavailable right now.'], 503);
}
