<?php
/**
 * Daily late-payment check, run by a cPanel cron job:
 *   php /home1/thrypjmy/public_html/website_05f463c5/admin/cron.php
 * Sends each late membership the next notice on the schedule set in the admin portal,
 * and refreshes the AI "Top 10 trending campaigns" list once a day.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

// Trending campaigns: about once a day (skips if it ran or was tried in the last 20 hours)
if ((string)(cn_config()['anthropic_key'] ?? '') !== '') {
  $t = cn_trending();
  if (max((int)$t['updated'], (int)($t['last_try'] ?? 0)) < time() - 20 * 3600) {
    $t = cn_trending_run();
    echo $t['error'] !== '' ? 'Trending campaigns: ' . $t['error'] . "\n" : 'Trending campaigns: ' . count($t['items']) . ' saved (about $' . $t['cost_usd'] . ")\n";
  }
}

if (cn_config()['stripe_key'] === '') { echo "Waiting for a Stripe key (admin portal → Settings).\n"; exit(0); }

try {
  $r = cn_run_auto(cn_subscriptions());
  echo $r['skipped'] ? "Automatic notices are off.\n" : sprintf("%s checked=%d sent=%d failed=%d no_email=%d\n", date('c'), $r['checked'], $r['sent'], $r['failed'], $r['no_email']);
  exit($r['failed'] ? 1 : 0);
} catch (Throwable $e) {
  cn_log(['sub' => '', 'email' => '', 'how' => 'auto', 'ok' => false, 'error' => 'Daily check failed: ' . $e->getMessage()]);
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}
