<?php
/**
 * Near-live view tally: run every 5 minutes by a cPanel cron job
 *   php /home1/thrypjmy/public_html/website_05f463c5/admin/views-cron.php
 * Re-checks the views of members' clips that are due (new clips every 5–10 minutes, older ones
 * less often) using the shared Upload-Post analytics budget, so My stats stays close to live
 * even when nobody has it open.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
if ((string)(cn_config()['uploadpost_key'] ?? '') === '') exit(0);
// Never let two runs overlap (a slow run could otherwise collide with the next one).
$lock = fopen(CN_DATA . '/views-cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
$t = microtime(true);
$r = cn_stats_background(70);   // leave ~10 of the 80-per-5-minutes budget for the Stats page itself
if ($r['checked'] > 0 || $r['due'] > 0) {
  printf("%s members=%d due=%d checked=%d in %.1fs\n", date('c'), $r['members'], $r['due'], $r['checked'], microtime(true) - $t);
}
