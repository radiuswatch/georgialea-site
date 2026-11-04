<?php
/**
 * ClipNow admin: shared code for the admin portal (admin/index.php), the daily
 * late-payment job (admin/cron.php) and the customer billing link (billing.php).
 *
 * Settings and records live OUTSIDE the public web folder, in ~/clipnow-data/:
 *   config.json   admin password hash, Stripe secret key, email settings
 *   notices.json  which late subscriptions were sent which notices
 *   log.jsonl     one line per notice sent (or failed)
 * Set CLIPNOW_HOME to point somewhere else (used for local testing).
 */
if (count(get_included_files()) === 1) { http_response_code(404); exit; }

define('CN_HOME', getenv('CLIPNOW_HOME') ?: dirname(__DIR__, 3));
define('CN_DATA', CN_HOME . '/clipnow-data');
define('CN_SITE', 'https://clipnow.vip');
define('CN_BRAND', 'ClipNow');

// Plan names by price in cents (monthly and yearly). Keys match the site's data-plan values.
const CN_PLANS = [
  'starter' => ['name' => 'Silver',   'cents' => [999, 9990]],
  'pro'     => ['name' => 'Gold',     'cents' => [1999, 19990]],
  'agency'  => ['name' => 'Platinum', 'cents' => [9999, 99990]],
];
const CN_LATE = ['past_due', 'unpaid'];

/* ---------- Storage ---------- */

function cn_read(string $file, $default) {
  $p = CN_DATA . '/' . $file;
  if (!is_file($p)) return $default;
  $j = json_decode((string)file_get_contents($p), true);
  return is_array($j) ? $j : $default;
}

function cn_write(string $file, array $data): void {
  if (!is_dir(CN_DATA)) mkdir(CN_DATA, 0700, true);
  $p = CN_DATA . '/' . $file;
  $tmp = $p . '.' . bin2hex(random_bytes(4));
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  chmod($tmp, 0600);
  rename($tmp, $p);
}

function cn_config(): array {
  $c = cn_read('config.json', []);
  $c += [
    'admin_hash'   => '',
    'stripe_key'   => '',
    'from_email'   => 'info@georgialea.com',
    'from_name'    => CN_BRAND,
    'reply_to'     => 'info@georgialea.com',
    'auto_notices' => true,
    'schedule'     => [0, 3, 7],   // days after a membership goes late
    'secret'       => '',
  ];
  if ($c['secret'] === '') { $c['secret'] = bin2hex(random_bytes(32)); cn_write('config.json', $c); }
  return $c;
}

function cn_save_config(array $c): void { cn_write('config.json', $c); }

function cn_log(array $entry): void {
  if (!is_dir(CN_DATA)) mkdir(CN_DATA, 0700, true);
  $entry = ['at' => time()] + $entry;
  file_put_contents(CN_DATA . '/log.jsonl', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function cn_recent_log(int $n = 100): array {
  $p = CN_DATA . '/log.jsonl';
  if (!is_file($p)) return [];
  $lines = array_slice(file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$n);
  return array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), $lines))));
}

/* ---------- Stripe ---------- */

function cn_stripe(string $method, string $path, array $params = []): array {
  $key = cn_config()['stripe_key'];
  if ($key === '') throw new RuntimeException('Stripe secret key is not set yet. Add it under Settings.');
  $base = getenv('CLIPNOW_STRIPE_BASE') ?: 'https://api.stripe.com';
  $url = $base . $path;
  $q = preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($params));
  $ch = curl_init();
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_USERPWD => $key . ':',
    CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
  ];
  if ($method === 'GET') { $opts[CURLOPT_URL] = $url . ($q ? "?$q" : ''); }
  else { $opts[CURLOPT_URL] = $url; $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $q; }
  curl_setopt_array($ch, $opts);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($body === false) throw new RuntimeException("Couldn't reach Stripe: $err");
  $j = json_decode($body, true);
  if ($code >= 400 || !is_array($j)) throw new RuntimeException('Stripe: ' . ($j['error']['message'] ?? "HTTP $code"));
  return $j;
}

/** Every subscription in the Stripe account, newest first, with customer and latest invoice. */
function cn_subscriptions(): array {
  $all = []; $after = null;
  do {
    $p = ['status' => 'all', 'limit' => 100, 'expand' => ['data.customer', 'data.latest_invoice']];
    if ($after) $p['starting_after'] = $after;
    $page = cn_stripe('GET', '/v1/subscriptions', $p);
    foreach ($page['data'] as $s) $all[] = cn_shape($s);
    $after = $page['data'] ? end($page['data'])['id'] : null;
  } while (!empty($page['has_more']) && count($all) < 5000);
  return $all;
}

/** Flatten a Stripe subscription into what the portal shows. */
function cn_shape(array $s): array {
  $price = $s['items']['data'][0]['price'] ?? [];
  $cents = (int)($price['unit_amount'] ?? 0) * (int)($s['items']['data'][0]['quantity'] ?? 1);
  $interval = $price['recurring']['interval'] ?? 'month';
  $plan = cn_plan_name($s['metadata']['plan'] ?? '', (int)($price['unit_amount'] ?? 0), $price['nickname'] ?? '');
  // Apply a coupon (e.g. the 100%-off family code) so revenue and the price shown are what's really billed.
  $coupon = $s['discount']['coupon'] ?? null;
  $listCents = $cents;
  if ($coupon) {
    if (!empty($coupon['percent_off'])) $cents = (int)round($cents * (100 - (float)$coupon['percent_off']) / 100);
    elseif (!empty($coupon['amount_off'])) $cents = max(0, $cents - (int)$coupon['amount_off']);
  }
  $cust = is_array($s['customer'] ?? null) ? $s['customer'] : ['id' => $s['customer'] ?? ''];
  $inv = is_array($s['latest_invoice'] ?? null) ? $s['latest_invoice'] : [];
  return [
    'id'          => $s['id'],
    'status'      => $s['status'],
    'late'        => in_array($s['status'], CN_LATE, true),
    'cancel_at_end' => !empty($s['cancel_at_period_end']),
    'customer_id' => $cust['id'] ?? '',
    'email'       => $cust['email'] ?? '',
    'name'        => $cust['name'] ?? '',
    'plan'        => $plan,
    'interval'    => $interval,
    'cents'       => $cents,
    'list_cents'  => $listCents,
    'coupon'      => $coupon ? ($coupon['name'] ?: ($coupon['percent_off'] ? $coupon['percent_off'] . '% off' : 'Discount')) : '',
    'currency'    => strtoupper($price['currency'] ?? 'usd'),
    'created'     => (int)($s['created'] ?? 0),
    'period_end'  => (int)($s['current_period_end'] ?? 0),
    'trial_end'   => (int)($s['trial_end'] ?? 0),
    'canceled_at' => (int)($s['canceled_at'] ?? 0),
    'due_cents'   => (int)($inv['amount_remaining'] ?? $inv['amount_due'] ?? 0),
    'invoice_url' => $inv['hosted_invoice_url'] ?? '',
    'late_since'  => (int)($inv['status_transitions']['finalized_at'] ?? $inv['created'] ?? 0),
    'livemode'    => !empty($s['livemode']),
  ];
}

function cn_plan_name(string $key, int $unitCents, string $nickname): string {
  if (isset(CN_PLANS[$key])) return CN_PLANS[$key]['name'];
  foreach (CN_PLANS as $p) if (in_array($unitCents, $p['cents'], true)) return $p['name'];
  return $nickname !== '' ? $nickname : 'Other';
}

function cn_money(int $cents, string $cur = 'USD'): string {
  return ($cur === 'USD' ? '$' : "$cur ") . number_format($cents / 100, 2);
}

/* ---------- Update-payment link ---------- */

function cn_billing_link(array $sub, int $days = 30): string {
  $exp = time() + $days * 86400;
  $data = $sub['customer_id'] . '|' . $sub['id'] . '|' . $exp;
  $sig = hash_hmac('sha256', $data, cn_config()['secret']);
  return CN_SITE . '/billing.php?' . http_build_query(['c' => $sub['customer_id'], 's' => $sub['id'], 'e' => $exp, 't' => $sig]);
}

function cn_check_billing_link(string $c, string $s, string $e, string $t): bool {
  if (!ctype_digit($e) || (int)$e < time()) return false;
  $sig = hash_hmac('sha256', "$c|$s|$e", cn_config()['secret']);
  return hash_equals($sig, $t);
}

/* ---------- Notices ---------- */

function cn_notice_email(array $sub): array {
  $name = trim(explode(' ', $sub['name'])[0] ?? '') ?: 'there';
  $due = $sub['due_cents'] > 0 ? cn_money($sub['due_cents'], $sub['currency']) : cn_money($sub['cents'], $sub['currency']);
  $link = cn_billing_link($sub);
  $subject = 'Action needed: update your payment for ' . CN_BRAND . ' ' . $sub['plan'];
  $body = "Hi $name,\n\n"
    . "We couldn't process the payment of $due for your " . CN_BRAND . " {$sub['plan']} membership. "
    . "Your card may have expired or been declined.\n\n"
    . "To keep your membership active, please update your payment details here:\n$link\n\n"
    . "It takes about a minute. Once your payment goes through, nothing else changes.\n\n"
    . "If you have questions, just reply to this email.\n\n"
    . "Thanks,\nThe " . CN_BRAND . " team\n" . CN_SITE . "\n";
  return [$subject, $body];
}

function cn_send_mail(string $to, string $subject, string $body): bool {
  $c = cn_config();
  if (getenv('CLIPNOW_MAIL_FILE')) { // local testing: write instead of sending
    file_put_contents(getenv('CLIPNOW_MAIL_FILE'), "To: $to\nSubject: $subject\n\n$body\n-----\n", FILE_APPEND);
    return true;
  }
  $from = $c['from_email'];
  $headers = [
    'From' => sprintf('%s <%s>', $c['from_name'], $from),
    'Reply-To' => $c['reply_to'] ?: $from,
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding' => '8bit',
  ];
  $enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  return mail($to, $enc, $body, $headers, '-f' . $from);
}

/** Send one late-payment notice and record it. $how is 'auto' or 'manual'. */
function cn_send_notice(array $sub, string $how): array {
  if ($sub['email'] === '') {
    cn_log(['sub' => $sub['id'], 'email' => '', 'how' => $how, 'ok' => false, 'error' => 'customer has no email']);
    return [false, 'This customer has no email address in Stripe.'];
  }
  [$subject, $body] = cn_notice_email($sub);
  $ok = cn_send_mail($sub['email'], $subject, $body);
  $state = cn_read('notices.json', []);
  $st = $state[$sub['id']] ?? ['first_seen' => time(), 'sent' => []];
  if ($ok) $st['sent'][] = time();
  $state[$sub['id']] = $st;
  cn_write('notices.json', $state);
  cn_log(['sub' => $sub['id'], 'email' => $sub['email'], 'plan' => $sub['plan'], 'how' => $how, 'ok' => $ok] + ($ok ? [] : ['error' => 'mail() refused the message']));
  return [$ok, $ok ? "Notice sent to {$sub['email']}." : "The server couldn't send the email to {$sub['email']}."];
}

/**
 * The automatic run: send each late membership the next notice on the schedule
 * (by default the day it goes late, then 3 and 7 days later), at most one a day.
 * Memberships that are no longer late are forgotten, so a later lapse starts over.
 */
function cn_run_auto(array $subs, bool $force = false): array {
  $c = cn_config();
  $report = ['checked' => 0, 'sent' => 0, 'failed' => 0, 'no_email' => 0, 'skipped' => !$c['auto_notices'] && !$force];
  if ($report['skipped']) return $report;
  $state = cn_read('notices.json', []);
  $lateIds = [];
  $now = time();
  foreach ($subs as $sub) {
    if (!$sub['late']) continue;
    $lateIds[$sub['id']] = true;
    $report['checked']++;
    if ($sub['email'] === '') { $report['no_email']++; continue; }
    $st = $state[$sub['id']] ?? ['first_seen' => $now, 'sent' => []];
    if (!isset($state[$sub['id']])) { $state[$sub['id']] = $st; cn_write('notices.json', $state); }
    $n = count($st['sent']);
    $schedule = $c['schedule'];
    if ($n >= count($schedule)) continue;
    $dueAt = $st['first_seen'] + (int)$schedule[$n] * 86400;
    $last = $n ? end($st['sent']) : 0;
    if ($now < $dueAt || ($last && $now - $last < 20 * 3600)) continue;
    [$ok] = cn_send_notice($sub, 'auto');
    $report[$ok ? 'sent' : 'failed']++;
    $state = cn_read('notices.json', []);
  }
  foreach (array_keys($state) as $id) if (!isset($lateIds[$id])) unset($state[$id]);
  cn_write('notices.json', $state);
  $fresh = cn_config();
  $fresh['last_auto_run'] = $now;
  cn_save_config($fresh);
  return $report;
}
