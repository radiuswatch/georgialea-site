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
define('CN_SITE', getenv('CLIPNOW_SITE') ?: 'https://clipnow.vip');
define('CN_BRAND', 'ClipNow');

// Plan names by price in cents (monthly and yearly). Keys match the site's data-plan values.
const CN_PLANS = [
  'starter' => ['name' => 'Silver',   'cents' => [999, 9990]],
  'pro'     => ['name' => 'Gold',     'cents' => [1999, 19990]],
  'agency'  => ['name' => 'Platinum', 'cents' => [3999, 39990, 9999, 99990]], // 99.99 was the launch price
  'corporate' => ['name' => 'Corporate', 'cents' => [29999]],                // monthly only, no trial
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
    'from_email'   => 'helpdesk@clipnow.vip',
    'from_name'    => CN_BRAND,
    'reply_to'     => 'helpdesk@clipnow.vip',
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
    'plan_key'    => cn_plan_key($s['metadata']['plan'] ?? '', (int)($price['unit_amount'] ?? 0)),
    'interval'    => $interval,
    'cents'       => $cents,
    'list_cents'  => $listCents,
    'promo_id'    => (string)($s['discount']['promotion_code'] ?? ''),
    'coupon'      => $coupon ?($coupon['name'] ?: ($coupon['percent_off'] ? $coupon['percent_off'] . '% off' : 'Discount')) : '',
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

/** 'starter' | 'pro' | 'agency' | 'corporate' | '' for a subscription's price. */
function cn_plan_key(string $key, int $unitCents): string {
  if (isset(CN_PLANS[$key])) return $key;
  foreach (CN_PLANS as $k => $p) if (in_array($unitCents, $p['cents'], true)) return $k;
  return '';
}

function cn_plan_name(string $key, int $unitCents, string $nickname): string {
  if (isset(CN_PLANS[$key])) return CN_PLANS[$key]['name'];
  foreach (CN_PLANS as $p) if (in_array($unitCents, $p['cents'], true)) return $p['name'];
  return $nickname !== '' ? $nickname : 'Other';
}

function cn_money(int $cents, string $cur = 'USD'): string {
  return ($cur === 'USD' ? '$' : "$cur ") . number_format($cents / 100, 2);
}

/* ---------- Family codes (single-use, 100% off Platinum) ---------- */

/** The 100%-off-forever Platinum coupon (metadata purpose=family). Created on first use. */
function cn_family_coupon(): string {
  foreach (cn_stripe('GET', '/v1/coupons', ['limit' => 100])['data'] as $c) {
    if (($c['metadata']['purpose'] ?? '') === 'family' && !empty($c['valid'])) return $c['id'];
  }
  $platinum = '';
  foreach (cn_stripe('GET', '/v1/products', ['active' => 'true', 'limit' => 100])['data'] as $p) {
    if (($p['metadata']['plan'] ?? '') === 'agency') { $platinum = $p['id']; break; }
  }
  if ($platinum === '') throw new RuntimeException('No Platinum product found in Stripe (a product with metadata plan=agency).');
  $c = cn_stripe('POST', '/v1/coupons', ['percent_off' => 100, 'duration' => 'forever', 'name' => 'Family: Platinum free',
    'applies_to' => ['products' => [$platinum]], 'metadata' => ['purpose' => 'family']]);
  return $c['id'];
}

/** The no-card-needed Platinum sign-up link (payment link with metadata purpose=family), or ''. */
function cn_family_link(): string {
  foreach (cn_stripe('GET', '/v1/payment_links', ['active' => 'true', 'limit' => 100])['data'] as $l) {
    if (($l['metadata']['purpose'] ?? '') === 'family') return $l['url'];
  }
  return '';
}

/** Make a new single-use code. $days = 0 means it never expires. */
function cn_create_family_code(string $note, int $days): array {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I lookalikes
  for ($try = 0; $try < 5; $try++) {
    $code = 'FAM-';
    for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $p = ['coupon' => cn_family_coupon(), 'code' => $code, 'max_redemptions' => 1,
          'metadata' => ['purpose' => 'family', 'note' => mb_substr($note, 0, 200), 'created_by' => 'admin portal']];
    if ($days > 0) $p['expires_at'] = time() + $days * 86400;
    try { return cn_stripe('POST', '/v1/promotion_codes', $p); }
    catch (RuntimeException $e) { if (!str_contains($e->getMessage(), 'already exists')) throw $e; }
  }
  throw new RuntimeException('Could not make a unique code. Please try again.');
}

/** Every family code, newest first, with who redeemed it (from $subs). */
function cn_family_codes(array $subs): array {
  $coupon = cn_family_coupon();
  $byPromo = [];
  foreach ($subs as $s) if ($s['promo_id'] !== '') $byPromo[$s['promo_id']][] = $s;
  $out = []; $after = null;
  do {
    $q = ['coupon' => $coupon, 'limit' => 100]; if ($after) $q['starting_after'] = $after;
    $page = cn_stripe('GET', '/v1/promotion_codes', $q);
    foreach ($page['data'] as $p) {
      $used = (int)$p['times_redeemed'];
      $expired = !empty($p['expires_at']) && $p['expires_at'] < time();
      $status = $used >= (int)($p['max_redemptions'] ?? PHP_INT_MAX) ? 'used' : ($expired ? 'expired' : (!$p['active'] ? 'off' : 'unused'));
      $who = $byPromo[$p['id']] ?? [];
      $out[] = ['id' => $p['id'], 'code' => $p['code'], 'note' => $p['metadata']['note'] ?? '', 'created' => (int)$p['created'],
                'expires' => (int)($p['expires_at'] ?? 0), 'status' => $status, 'max' => $p['max_redemptions'], 'used' => $used,
                'by' => $who ? ($who[0]['email'] ?: $who[0]['name']) : '', 'by_status' => $who ? $who[0]['status'] : ''];
    }
    $after = $page['data'] ? end($page['data'])['id'] : null;
  } while (!empty($page['has_more']));
  usort($out, fn($a, $b) => $b['created'] <=> $a['created']);
  return $out;
}

/* ---------- Member sign-in ---------- */
// Members sign in with an emailed one-time link (or automatically after Stripe checkout).
// Sessions and links are stored server-side in ~/clipnow-data/{sessions,logins}/ by the
// SHA-256 of their secret, so the files never contain a usable token.

const CN_MEMBER_COOKIE = 'cn_member';
const CN_SESSION_DAYS = 30;
const CN_LINK_MINUTES = 20;
const CN_ACTIVE = ['active', 'trialing', 'past_due'];

function cn_dir(string $name): string {
  $d = CN_DATA . '/' . $name;
  if (!is_dir($d)) mkdir($d, 0700, true);
  return $d;
}
function cn_token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function cn_token_file(string $dir, string $token): string { return cn_dir($dir) . '/' . hash('sha256', $token) . '.json'; }

/** The member's best subscription across every Stripe customer with this email, or null. */
function cn_member_lookup(string $email): ?array {
  $raw = trim($email); $email = strtolower($raw);
  $customers = [];
  // Stripe's email filter is case-sensitive, but its search isn't. Search can lag up to a
  // minute for brand-new customers, so also try the exact spellings.
  try {
    $q = "email:'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $email) . "'";
    foreach (cn_stripe('GET', '/v1/customers/search', ['query' => $q, 'limit' => 20])['data'] as $c) $customers[$c['id']] = $c;
  } catch (RuntimeException $e) { /* search not available: fall back to exact lookups */ }
  foreach (array_unique([$raw, $email]) as $e) {
    foreach (cn_stripe('GET', '/v1/customers', ['email' => $e, 'limit' => 20])['data'] as $c) $customers[$c['id']] = $c;
  }
  if (!$customers) return null;
  $rank = ['active' => 0, 'trialing' => 1, 'past_due' => 2, 'unpaid' => 3, 'incomplete' => 4, 'paused' => 5, 'canceled' => 6, 'incomplete_expired' => 7];
  $best = null;
  foreach ($customers as $cid => $c) {
    foreach (cn_stripe('GET', '/v1/subscriptions', ['customer' => $cid, 'status' => 'all', 'limit' => 20])['data'] as $s) {
      $s['customer'] = $c; $sh = cn_shape($s);
      if (!$best || ($rank[$sh['status']] ?? 9) < ($rank[$best['status']] ?? 9)) $best = $sh;
    }
  }
  $first = reset($customers);
  return [
    'email' => $email,
    'customer_id' => $best['customer_id'] ?? $first['id'],
    'name' => $best['name'] ?? ($first['name'] ?? ''),
    'plan' => $best['plan'] ?? '',
    'plan_key' => $best['plan_key'] ?? '',
    'status' => $best['status'] ?? 'none',
    'active' => $best ? in_array($best['status'], CN_ACTIVE, true) : false,
    'free' => $best ? ($best['cents'] === 0 && $best['coupon'] !== '') : false,
    'trial_end' => $best['trial_end'] ?? 0,
    'period_end' => $best['period_end'] ?? 0,
    'cancel_at_end' => $best['cancel_at_end'] ?? false,
  ];
}

/** Rate limit sign-in requests: $max per 15 minutes per key. */
function cn_rate_ok(string $key, int $max): bool {
  $f = cn_dir('rate') . '/' . hash('sha256', $key) . '.json';
  $hits = array_values(array_filter(cn_json_file($f, []), fn($t) => $t > time() - 900));
  if (count($hits) >= $max) return false;
  $hits[] = time();
  file_put_contents($f, json_encode($hits), LOCK_EX);
  return true;
}
function cn_json_file(string $f, $default) {
  if (!is_file($f)) return $default;
  $j = json_decode((string)file_get_contents($f), true);
  return is_array($j) ? $j : $default;
}

/** Email a one-time sign-in link if this email belongs to a Stripe customer. Returns true if sent. */
function cn_send_login_link(string $email): bool {
  $m = cn_member_lookup($email);
  if (!$m) return false;
  $token = cn_token();
  file_put_contents(cn_token_file('logins', $token), json_encode(['email' => $m['email'], 'exp' => time() + CN_LINK_MINUTES * 60]), LOCK_EX);
  $link = CN_SITE . '/api/verify.php?t=' . $token;
  $name = trim(explode(' ', $m['name'])[0] ?? '') ?: 'there';
  $body = "Hi $name,\n\nTap the link below to sign in to " . CN_BRAND . ":\n$link\n\n"
    . "The link works once and expires in " . CN_LINK_MINUTES . " minutes. If you didn't ask to sign in, you can ignore this email.\n\n"
    . "Thanks,\nThe " . CN_BRAND . " team\n" . CN_SITE . "\n";
  return cn_send_mail($m['email'], 'Your ' . CN_BRAND . ' sign-in link', $body);
}

/** Use a sign-in link once. Returns the email, or null if invalid/expired. */
function cn_use_login_link(string $token): ?string {
  if (!preg_match('/^[A-Za-z0-9_-]{30,}$/', $token)) return null;
  $f = cn_token_file('logins', $token);
  $j = cn_json_file($f, null);
  if (is_file($f)) unlink($f);
  if (!$j || ($j['exp'] ?? 0) < time()) return null;
  return $j['email'];
}

function cn_start_session(string $email): void {
  $token = cn_token();
  $exp = time() + CN_SESSION_DAYS * 86400;
  file_put_contents(cn_token_file('sessions', $token), json_encode(['email' => strtolower($email), 'created' => time(), 'exp' => $exp]), LOCK_EX);
  setcookie(CN_MEMBER_COOKIE, $token, ['expires' => $exp, 'path' => '/', 'secure' => str_starts_with(CN_SITE, 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
}

/** The signed-in member (with a 5-minute cached Stripe lookup), or null. */
function cn_current_member(bool $fresh = false): ?array {
  $token = (string)($_COOKIE[CN_MEMBER_COOKIE] ?? '');
  if (!preg_match('/^[A-Za-z0-9_-]{30,}$/', $token)) return null;
  $f = cn_token_file('sessions', $token);
  $s = cn_json_file($f, null);
  if (!$s || ($s['exp'] ?? 0) < time()) { if (is_file($f)) unlink($f); return null; }
  if ($fresh || empty($s['member']) || ($s['member_at'] ?? 0) < time() - 300) {
    $s['member'] = cn_member_lookup($s['email']) ?? ['email' => $s['email'], 'active' => false, 'status' => 'none', 'plan' => '', 'plan_key' => '', 'customer_id' => ''];
    $s['member_at'] = time();
    file_put_contents($f, json_encode($s), LOCK_EX);
  }
  return $s['member'];
}

function cn_end_session(): void {
  $token = (string)($_COOKIE[CN_MEMBER_COOKIE] ?? '');
  if (preg_match('/^[A-Za-z0-9_-]{30,}$/', $token)) { $f = cn_token_file('sessions', $token); if (is_file($f)) unlink($f); }
  setcookie(CN_MEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => str_starts_with(CN_SITE, 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Reject cross-site POSTs to the member API. */
function cn_same_origin(): bool {
  $o = $_SERVER['HTTP_ORIGIN'] ?? '';
  return $o === CN_SITE;
}

function cn_json_out(array $data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  echo json_encode($data);
  exit;
}

/* ---------- Auto-posting (Upload-Post) ---------- */
// Each member gets one Upload-Post profile named after their Stripe customer id. Members
// connect their social accounts on Upload-Post's connect page; ClipNow then sends clips
// to Upload-Post, which posts them. The API key never leaves the server.

const CN_POST_PLATFORMS = ['tiktok' => 'TikTok', 'youtube' => 'YouTube Shorts', 'instagram' => 'Instagram Reels', 'facebook' => 'Facebook Reels', 'x' => 'X'];
const CN_POST_MAX_BYTES = 60 * 1048576;

function cn_up(string $method, string $path, array $params = [], bool $multipart = false, array $headers = []): array {
  $key = (string)(cn_config()['uploadpost_key'] ?? '');
  if ($key === '') throw new RuntimeException('Auto-posting isn\'t set up yet.');
  $url = (getenv('CLIPNOW_UP_BASE') ?: 'https://api.upload-post.com/api') . $path;
  $h = array_merge(['Authorization: Apikey ' . $key], $headers);
  $ch = curl_init();
  $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 20];
  if ($method === 'GET') { $o[CURLOPT_URL] = $url . ($params ? '?' . http_build_query($params) : ''); }
  else {
    $o[CURLOPT_URL] = $url; $o[CURLOPT_CUSTOMREQUEST] = $method;
    if ($multipart) {
      // $params is a list of [name, value] pairs so names like platform[] can repeat.
      $b = '----clipnow' . bin2hex(random_bytes(12)); $body = '';
      foreach ($params as [$name, $val]) {
        $body .= "--$b\r\n";
        if ($val instanceof CURLFile) {
          $body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $val->getPostFilename() . "\"\r\nContent-Type: " . $val->getMimeType() . "\r\n\r\n" . file_get_contents($val->getFilename()) . "\r\n";
        } else {
          $body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n" . $val . "\r\n";
        }
      }
      $body .= "--$b--\r\n";
      $o[CURLOPT_POSTFIELDS] = $body; $h[] = 'Content-Type: multipart/form-data; boundary=' . $b;
    }
    else { $o[CURLOPT_POSTFIELDS] = json_encode($params); $h[] = 'Content-Type: application/json'; }
  }
  $o[CURLOPT_HTTPHEADER] = $h;
  curl_setopt_array($ch, $o);
  $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($body === false) throw new RuntimeException("Couldn't reach the posting service: $err");
  $j = json_decode($body, true);
  if ($code >= 400 || !is_array($j)) {
    $msg = is_array($j) ? ($j['message'] ?? $j['error'] ?? $j['detail'] ?? "HTTP $code") : "HTTP $code";
    $e = new RuntimeException('Posting service: ' . (is_string($msg) ? $msg : json_encode($msg)), $code);
    throw $e;
  }
  return $j;
}

function cn_up_username(array $member): string {
  return 'cn_' . preg_replace('/[^a-z0-9_]/', '', strtolower((string)$member['customer_id']));
}

/** How many platforms a plan may post to at once (0 = auto-posting not included). */
function cn_post_limit(array $member): int {
  if (empty($member['active'])) return 0;
  return ($member['plan_key'] ?? '') === 'starter' ? 3 : count(CN_POST_PLATFORMS);
}

/** The member's Upload-Post profile and connected accounts, or null if they have none yet. */
function cn_up_profile(string $username): ?array {
  foreach ((cn_up('GET', '/uploadposts/users')['profiles'] ?? []) as $p) {
    if (($p['username'] ?? '') === $username) return $p;
  }
  return null;
}

/** [platform => ['connected' => bool, 'name' => string]] for the platforms ClipNow supports. */
function cn_up_accounts(?array $profile): array {
  $out = [];
  foreach (CN_POST_PLATFORMS as $id => $label) {
    $a = $profile['social_accounts'][$id] ?? '';
    $name = '';
    if (is_array($a)) $name = (string)($a['display_name'] ?? $a['username'] ?? $a['handle'] ?? $a['name'] ?? '');
    elseif (is_string($a)) $name = $a;
    $out[$id] = ['label' => $label, 'connected' => !empty($a), 'name' => $name];
  }
  return $out;
}

/** A link to Upload-Post's connect page for this member (creates their profile first). */
function cn_up_connect_url(array $member): string {
  $u = cn_up_username($member);
  if (!cn_up_profile($u)) {
    try { cn_up('POST', '/uploadposts/users', ['username' => $u]); }
    catch (RuntimeException $e) {
      cn_log(['sub' => '', 'email' => $member['email'], 'how' => 'autopost', 'ok' => false, 'error' => 'Could not create posting profile: ' . $e->getMessage()]);
      throw new RuntimeException('Auto-posting is at capacity right now. We\'ve been notified. Please try again later.');
    }
  }
  $j = cn_up('POST', '/uploadposts/users/generate-jwt', [
    'username' => $u,
    'redirect_url' => CN_SITE . '/studio?connected=1',
    'redirect_button_text' => 'Back to ClipNow',
    'logo_image' => CN_SITE . '/brand/clipnow-mark-512.png',
    'platforms' => array_keys(CN_POST_PLATFORMS),
    'connect_title' => 'Connect your accounts to ClipNow',
    'connect_description' => 'Connect the accounts you want ClipNow to post your clips to. You can disconnect any time.',
  ]);
  $url = $j['access_url'] ?? '';
  if ($url === '') throw new RuntimeException('The posting service didn\'t return a connect link. Please try again.');
  return $url;
}

/** The member's Facebook Page to post Reels to (Upload-Post needs a Page id). */
function cn_up_facebook_page(string $username): string {
  $j = cn_up('GET', '/uploadposts/facebook/pages', ['profile' => $username]);
  $pages = $j['pages'] ?? $j['data'] ?? [];
  foreach ($pages as $p) { $id = (string)($p['page_id'] ?? $p['id'] ?? ''); if ($id !== '') return $id; }
  return '';
}

function cn_post_log(array $member, array $entry): void {
  $f = cn_dir('posts') . '/' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$member['customer_id']) . '.jsonl';
  file_put_contents($f, json_encode(['at' => time()] + $entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
function cn_post_history(array $member, int $n = 20): array {
  $f = cn_dir('posts') . '/' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$member['customer_id']) . '.jsonl';
  if (!is_file($f)) return [];
  $lines = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$n);
  return array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), $lines))));
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

/* ---------- Campaigns (Platinum only) ----------
 * A Platinum creator posts a campaign: their content, a rate per 1,000 views and an optional
 * budget. Other Platinum members join it, post clips, and report each post's views with a
 * screenshot. The creator approves the views and pays clippers directly (ClipNow never holds
 * money). Stored in ~/clipnow-data/campaigns/<id>.json; screenshots in campaign-shots/.
 */
const CN_CAMP_PLATFORMS = ['tiktok' => 'TikTok', 'youtube' => 'YouTube Shorts', 'instagram' => 'Instagram Reels', 'facebook' => 'Facebook Reels', 'snapchat' => 'Snapchat', 'x' => 'X'];
const CN_CAMP_SHOT_BYTES = 6 * 1048576;

/** Campaigns are for Platinum and Corporate (Corporate includes everything in Platinum). */
function cn_is_platinum(?array $m): bool { return $m !== null && !empty($m['active']) && in_array($m['plan_key'] ?? '', ['agency', 'corporate'], true); }

/** New campaigns a member may still create this calendar month: null = unlimited (Corporate), else 0-3 (Platinum). */
const CN_PLATINUM_CAMPAIGNS_PER_MONTH = 3;
function cn_camp_quota(array $m): array {
  if (($m['plan_key'] ?? '') === 'corporate') return ['limit' => null, 'used' => 0, 'left' => null, 'resets' => 0];
  $me = strtolower((string)$m['email']);
  $start = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
  $used = 0;
  foreach (cn_camp_all() as $c) if ($c['owner'] === $me && (int)$c['created'] >= $start) $used++;
  // Campaigns deleted since still count, so deleting doesn't free up a slot.
  $log = cn_read('campaign-starts.json', []);
  $mine = array_values(array_filter($log[$me] ?? [], fn($t) => $t >= $start));
  $used = max($used, count($mine));
  return ['limit' => CN_PLATINUM_CAMPAIGNS_PER_MONTH, 'used' => $used, 'left' => max(0, CN_PLATINUM_CAMPAIGNS_PER_MONTH - $used),
    'resets' => mktime(0, 0, 0, (int)date('n') + 1, 1, (int)date('Y'))];
}
function cn_camp_note_start(string $email): void {
  $log = cn_read('campaign-starts.json', []);
  $cut = time() - 62 * 86400;
  $log[$email] = array_values(array_filter($log[$email] ?? [], fn($t) => $t > $cut));
  $log[$email][] = time();
  cn_write('campaign-starts.json', $log);
}
function cn_camp_id_ok($id): bool { return is_string($id) && (bool)preg_match('/^c[a-z0-9]{12}$/', $id); }
function cn_camp_new_id(): string { return 'c' . substr(bin2hex(random_bytes(8)), 0, 12); }
function cn_camp_file(string $id): string { return cn_dir('campaigns') . '/' . $id . '.json'; }
function cn_camp_load(string $id): ?array { return cn_camp_id_ok($id) ? cn_json_file(cn_camp_file($id), null) : null; }

/** Every campaign, newest first. */
function cn_camp_all(): array {
  $out = [];
  foreach (glob(cn_dir('campaigns') . '/c*.json') ?: [] as $f) { $c = cn_json_file($f, null); if ($c) $out[] = $c; }
  usort($out, fn($a, $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
  return $out;
}

/** Change a campaign under a file lock. $fn gets the campaign by reference; return a string to refuse. */
function cn_camp_update(string $id, callable $fn): array {
  if (!cn_camp_id_ok($id)) throw new InvalidArgumentException('That campaign doesn\'t exist.');
  $f = cn_camp_file($id);
  if (!is_file($f)) throw new InvalidArgumentException('That campaign doesn\'t exist.');
  $h = fopen($f, 'c+');
  if (!$h || !flock($h, LOCK_EX)) throw new RuntimeException('Busy, please try again.');
  try {
    $c = json_decode((string)stream_get_contents($h), true);
    if (!is_array($c)) throw new RuntimeException('That campaign couldn\'t be read.');
    $err = $fn($c);
    if (is_string($err)) throw new InvalidArgumentException($err);
    $c['updated'] = time();
    ftruncate($h, 0); rewind($h);
    fwrite($h, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($h);
    return $c;
  } finally { flock($h, LOCK_UN); fclose($h); }
}

function cn_camp_create(array $c): array {
  $c['id'] = cn_camp_new_id();
  $f = cn_camp_file($c['id']);
  file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  chmod($f, 0600);
  return $c;
}

/** Approved views → money owed, in cents, for one clipper or (email '') everyone. */
function cn_camp_owed(array $c, string $email = ''): int {
  $views = 0;
  // approved_views stays counted while a clipper's updated views wait for review
  foreach ($c['submissions'] ?? [] as $s) if ($email === '' || $s['email'] === $email) $views += (int)($s['approved_views'] ?? 0);
  return (int)round($views * (int)$c['rate_cents'] / 1000);
}
function cn_camp_paid(array $c, string $email = ''): int {
  $paid = $c['paid'] ?? [];
  return $email === '' ? (int)array_sum($paid) : (int)($paid[$email] ?? 0);
}
/** Save a campaign cover image: resized to 1280px wide and re-saved as JPEG (strips anything hidden in the file). */
const CN_CAMP_IMG_BYTES = 8 * 1048576;
function cn_camp_save_image(string $id, string $tmp): string {
  $info = @getimagesize($tmp);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new InvalidArgumentException('The cover must be a JPG, PNG or WebP image.');
  [$w, $h] = $info;
  if ($w < 300 || $h < 150) throw new InvalidArgumentException('That image is too small. Use one at least 300 pixels wide (1280 × 720 looks best).');
  $dest = cn_dir('campaign-images') . '/' . $id . '.jpg';
  if (function_exists('imagecreatetruecolor')) {
    $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp), IMAGETYPE_PNG => @imagecreatefrompng($tmp), IMAGETYPE_WEBP => @imagecreatefromwebp($tmp), default => false };
    if (!$src) throw new InvalidArgumentException('That image couldn\'t be read. Try another file.');
    $nw = min(1280, $w); $nh = (int)round($h * $nw / $w);
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 11, 13, 11));
    imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($out, $dest, 84);
    imagedestroy($src); imagedestroy($out);
  } else {
    if ($info[2] !== IMAGETYPE_JPEG) throw new InvalidArgumentException('Please upload a JPG image.');
    copy($tmp, $dest);
  }
  @chmod($dest, 0600);
  return basename($dest);
}

function cn_camp_log(array $entry): void {
  if (!is_dir(CN_DATA)) mkdir(CN_DATA, 0700, true);
  file_put_contents(CN_DATA . '/campaign-log.jsonl', json_encode(['at' => time()] + $entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- Trending paid campaigns (AI, Gold & Platinum) ----------
 * Once a day Claude searches the web for paid clipping campaigns that are running now and
 * returns the Top 10 through a strict "save_campaigns" tool. Saved to trending-campaigns.json.
 * Key: admin portal → Settings → Anthropic API key (config.json 'anthropic_key').
 */
const CN_AI_MODEL = 'claude-opus-5-5';
const CN_AI_MAX_SEARCHES = 8;

function cn_trending(): array { return cn_read('trending-campaigns.json', ['updated' => 0, 'items' => [], 'last_try' => 0, 'error' => '']); }
function cn_can_see_trending(?array $m): bool { return $m !== null && !empty($m['active']) && in_array($m['plan_key'] ?? '', ['pro', 'agency', 'corporate'], true); }

/** One Messages API call. Returns the decoded response or throws with Anthropic's error message. */
function cn_claude(array $body, array $betas = []): array {
  $key = (string)(cn_config()['anthropic_key'] ?? '');
  if ($key === '') throw new RuntimeException('Add your Anthropic API key in Settings first.');
  $h = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2026-10-21'];
  if ($betas) $h[] = 'anthropic-beta: ' . implode(',', $betas);
  $ch = curl_init(getenv('CLIPNOW_ANTHROPIC_URL') ?: 'https://api.anthropic.com/v1/messages');   // the override is only for local tests
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 280, CURLOPT_CONNECTTIMEOUT => 20]);
  $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($raw === false) throw new RuntimeException('Couldn\'t reach Anthropic: ' . $err);
  $j = json_decode((string)$raw, true);
  if ($code >= 400 || !is_array($j)) {
    $msg = $j['error']['message'] ?? ('HTTP ' . $code);
    if ($code === 401) $msg = 'Anthropic says the API key is wrong. Paste it again in Settings.';
    if ($code === 429) $msg = 'Anthropic is busy or your API limit was reached. Try again later.';
    if ($code === 400 && stripos((string)$msg, 'credit') !== false) $msg = 'Your Anthropic account is out of credits. Add credits in the Anthropic Console → Billing.';
    throw new RuntimeException($msg);
  }
  return $j;
}

/** Ask Claude for today's Top 10 and save it. Returns the saved data (with 'error' set on failure). */
function cn_trending_run(): array {
  @set_time_limit(600);
  $old = cn_trending();
  $old['last_try'] = time();
  $item = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['title', 'creator', 'platforms', 'pay', 'rate_usd_per_1k', 'budget', 'deadline', 'how_to_join', 'url', 'why_trending', 'confidence'],
    'properties' => [
      'title' => ['type' => 'string', 'description' => 'Campaign name as posted'],
      'creator' => ['type' => 'string', 'description' => 'Creator, streamer, brand or agency running it'],
      'platforms' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Where clips must be posted, e.g. TikTok, YouTube Shorts, Instagram Reels'],
      'pay' => ['type' => 'string', 'description' => 'Pay terms in plain words, e.g. "$1.50 per 1,000 views, $500 cap per clipper"'],
      'rate_usd_per_1k' => ['type' => 'number', 'description' => 'Pay per 1,000 views in US dollars; 0 if not stated'],
      'budget' => ['type' => 'string', 'description' => 'Total or remaining budget if stated, else "Not stated"'],
      'deadline' => ['type' => 'string', 'description' => 'End date if stated, else "Not stated"'],
      'how_to_join' => ['type' => 'string', 'description' => 'One sentence: where and how a clipper joins'],
      'url' => ['type' => 'string', 'description' => 'The page where the campaign is posted (must be a page you found in search)'],
      'why_trending' => ['type' => 'string', 'description' => 'One short sentence on why it ranks here'],
      'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low'], 'description' => 'How sure you are the details are current and correct'],
    ]];
  $tools = [
    ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => CN_AI_MAX_SEARCHES],
    ['name' => 'save_campaigns', 'strict' => true,
      'description' => 'Save the final ranked list of up to 10 paid clipping campaigns. Call this exactly once, at the end.',
      'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['campaigns'],
        'properties' => ['campaigns' => ['type' => 'array', 'items' => $item]]]],
  ];
  $today = gmdate('F j, Y');
  $prompt = "Today is $today. Find the 10 best paid clipping campaigns that are open right now: campaigns where a creator, streamer, podcaster, brand or agency pays clippers for views on short clips (TikTok, YouTube Shorts, Instagram Reels and similar), usually per 1,000 views.\n\n"
    . "Search public sources such as Whop Content Rewards listings, clipping agencies' public pages, and creators' public posts on X or Reddit announcing campaigns. Only include campaigns that appear to be open now and that a new clipper could join from a public page. Skip anything that looks expired, unpaid, a scam (asks clippers to pay, unrealistic rates), or that requires reposting content without the owner's permission.\n\n"
    . "Rank by what matters to a clipper: higher pay per 1,000 views, more budget left, newer, and from creators with real audiences. Use only details you saw on the source page; if something isn't stated, say \"Not stated\" (or 0 for the rate). Every url must be a page you actually found.\n\n"
    . "When you're done searching, call save_campaigns once with the ranked list (best first, at most 10).";
  $messages = [['role' => 'user', 'content' => $prompt]];
  $body = ['model' => CN_AI_MODEL, 'max_tokens' => 16000, 'output_config' => ['effort' => 'medium'],
    'tools' => $tools, 'tool_choice' => ['type' => 'auto'], 'fallbacks' => 'default', 'messages' => $messages];
  $searches = 0; $in = 0; $out = 0;
  try {
    for ($turn = 0; $turn < 5; $turn++) {
      $body['messages'] = $messages;
      $r = cn_claude($body, ['server-side-fallback-2026-07-01']);
      $in += (int)($r['usage']['input_tokens'] ?? 0) + (int)($r['usage']['cache_read_input_tokens'] ?? 0) + (int)($r['usage']['cache_creation_input_tokens'] ?? 0);
      $out += (int)($r['usage']['output_tokens'] ?? 0);
      $searches += (int)($r['usage']['server_tool_use']['web_search_requests'] ?? 0);
      $stop = $r['stop_reason'] ?? '';
      if ($stop === 'refusal') throw new RuntimeException('Claude declined the search this time. It will try again tomorrow.');
      foreach ($r['content'] ?? [] as $blk) {
        if (($blk['type'] ?? '') === 'tool_use' && ($blk['name'] ?? '') === 'save_campaigns') {
          $items = cn_trending_clean((array)($blk['input']['campaigns'] ?? []));
          if (!$items) throw new RuntimeException('The search finished but found no campaigns it could confirm. It will try again tomorrow.');
          $data = ['updated' => time(), 'last_try' => time(), 'items' => $items, 'error' => '', 'model' => $r['model'] ?? CN_AI_MODEL,
            'searches' => $searches, 'input_tokens' => $in, 'output_tokens' => $out,
            'cost_usd' => round($searches * 0.01 + $in * 4 / 1e6 + $out * 20 / 1e6, 2)];
          cn_write('trending-campaigns.json', $data);
          cn_camp_log(['event' => 'trending updated', 'count' => count($items), 'searches' => $searches, 'cost_usd' => $data['cost_usd']]);
          return $data;
        }
      }
      if ($stop === 'pause_turn') { $messages[] = ['role' => 'assistant', 'content' => $r['content']]; continue; }
      // Finished without saving: ask once more, plainly, for the tool call.
      $messages[] = ['role' => 'assistant', 'content' => $r['content']];
      $messages[] = ['role' => 'user', 'content' => 'Please call save_campaigns now with the campaigns you found.'];
    }
    throw new RuntimeException('The search didn\'t finish. It will try again tomorrow.');
  } catch (Throwable $e) {
    $old['error'] = $e->getMessage();
    cn_write('trending-campaigns.json', $old);
    cn_camp_log(['event' => 'trending failed', 'error' => $e->getMessage()]);
    return $old;
  }
}

/** Keep only well-formed items with a real web address; trim long text. */
function cn_trending_clean(array $list): array {
  $out = [];
  foreach ($list as $c) {
    if (!is_array($c)) continue;
    $url = trim((string)($c['url'] ?? ''));
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) continue;
    $s = fn($k, $n) => mb_substr(trim((string)($c[$k] ?? '')), 0, $n);
    $out[] = ['title' => $s('title', 120), 'creator' => $s('creator', 80),
      'platforms' => array_slice(array_values(array_filter(array_map(fn($p) => mb_substr(trim((string)$p), 0, 40), (array)($c['platforms'] ?? [])))), 0, 6),
      'pay' => $s('pay', 160), 'rate' => max(0, min(1000, (float)($c['rate_usd_per_1k'] ?? 0))), 'budget' => $s('budget', 100), 'deadline' => $s('deadline', 60),
      'how_to_join' => $s('how_to_join', 240), 'url' => mb_substr($url, 0, 500), 'why' => $s('why_trending', 200),
      'confidence' => in_array($c['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $c['confidence'] : 'low'];
    if (count($out) >= 10) break;
  }
  return $out;
}

/* ---------- My stats: views on members' clips ----------
 * ~/clipnow-data/stats/<customer>.json holds every clip a member tracks: clips ClipNow posted
 * (imported from the post log, request_id) and clips they add by link. Views come from
 * Upload-Post's per-post analytics where possible, otherwise the member types them in.
 * All members share one Upload-Post key, limited to 100 live analytics calls per 5 minutes,
 * so ClipNow keeps itself under 80 and refreshes each clip at most every 6 hours.
 */
const CN_STATS_REFRESH = 6 * 3600;
const CN_UP_ANALYTICS_PER_5MIN = 80;

function cn_stats_file(array $m): string { return cn_dir('stats') . '/' . preg_replace('/[^A-Za-z0-9_]/', '', (string)$m['customer_id']) . '.json'; }
function cn_stats_load(array $m): array { $s = cn_json_file(cn_stats_file($m), []); $s += ['clips' => [], 'imported' => []]; return $s; }
function cn_stats_save(array $m, array $s): void { file_put_contents(cn_stats_file($m), json_encode($s, JSON_UNESCAPED_SLASHES), LOCK_EX); }

/** Platform name and native post ID from a post link. */
function cn_stats_parse_url(string $url): array {
  $u = strtolower($url); $id = ''; $m = [];
  if (preg_match('~(youtube\.com|youtu\.be)~', $u)) { if (preg_match('~(?:shorts/|watch\?(?:.*&)?v=|youtu\.be/|live/|embed/)([\w-]{11})~i', $url, $m)) $id = $m[1]; return ['youtube', $id]; }
  if (str_contains($u, 'tiktok.com')) { if (preg_match('~/video/(\d+)~', $url, $m)) $id = $m[1]; return ['tiktok', $id]; }
  if (preg_match('~(^|//|\.)(x|twitter)\.com/~', $u)) { if (preg_match('~/status/(\d+)~', $url, $m)) $id = $m[1]; return ['x', $id]; }
  if (str_contains($u, 'instagram.com')) return ['instagram', ''];
  if (str_contains($u, 'facebook.com') || str_contains($u, 'fb.watch')) return ['facebook', ''];
  if (str_contains($u, 'snapchat.com')) return ['snapchat', ''];
  return ['other', ''];
}

/** Shared budget for live Upload-Post analytics calls. Returns true and books a call if one is free. */
function cn_up_analytics_slot(): bool {
  $f = CN_DATA . '/up-analytics-rate.json';
  if (!is_dir(CN_DATA)) mkdir(CN_DATA, 0700, true);
  $h = fopen($f, 'c+'); if (!$h) return false;
  flock($h, LOCK_EX);
  $hits = json_decode((string)stream_get_contents($h), true) ?: [];
  $hits = array_values(array_filter($hits, fn($t) => $t > time() - 300));
  $ok = count($hits) < CN_UP_ANALYTICS_PER_5MIN;
  if ($ok) $hits[] = time();
  ftruncate($h, 0); rewind($h); fwrite($h, json_encode($hits)); fflush($h);
  flock($h, LOCK_UN); fclose($h);
  return $ok;
}

/** Add clips ClipNow posted for this member that aren't tracked yet. */
function cn_stats_import(array $m, array &$s): void {
  foreach (cn_post_history($m, 200) as $p) {
    $rid = (string)($p['request_id'] ?? '');
    if ($rid === '' || isset($s['imported'][$rid])) continue;
    $s['imported'][$rid] = time();
    foreach ((array)($p['platforms'] ?? []) as $pf) {
      $s['clips'][] = ['id' => 'k' . bin2hex(random_bytes(6)), 'source' => 'autopost', 'request_id' => $rid, 'platform' => (string)$pf,
        'url' => '', 'post_id' => '', 'title' => (string)($p['title'] ?? ''), 'views' => 0, 'likes' => null, 'comments' => null,
        'auto' => true, 'checked' => 0, 'error' => '', 'history' => [], 'campaign' => null,
        'posted' => (int)(($p['scheduled'] ?? 0) ?: ($p['at'] ?? time())), 'created' => time()];
    }
  }
}

/** Views as the platform's own app shows them (Instagram Reels shared to Facebook count both). */
function cn_stats_views(array $pm): int { return (int)($pm['crossposted_views'] ?? $pm['views'] ?? 0); }

/** Record a new view count (keeps a short history for "gained since"). */
function cn_stats_set_views(array &$c, int $views, string $how): void {
  $c['views'] = max(0, $views);
  $last = end($c['history']) ?: null;
  if (!$last || $last['views'] !== $c['views'] || $last['at'] < time() - 20 * 3600) $c['history'][] = ['at' => time(), 'views' => $c['views'], 'how' => $how];
  if (count($c['history']) > 40) $c['history'] = array_slice($c['history'], -40);
}

/**
 * Refresh up to $max clips from Upload-Post (oldest first). Clips posted through ClipNow are read by
 * request_id (one call covers every platform of that post); other clips on the member's connected
 * accounts by their platform post ID. Returns how many calls were made.
 */
function cn_stats_refresh(array $m, array &$s, int $max = 8, string $onlyId = ''): int {
  if ((string)(cn_config()['uploadpost_key'] ?? '') === '') return 0;
  $now = time(); $calls = 0; $done = [];
  $due = array_keys(array_filter($s['clips'], fn($c) => !empty($c['auto']) && empty($c['hidden']) && ($onlyId === '' ? $c['checked'] < $now - CN_STATS_REFRESH : $c['id'] === $onlyId) && ($c['posted'] ?? 0) <= $now - 120));
  usort($due, fn($a, $b) => $s['clips'][$a]['checked'] <=> $s['clips'][$b]['checked']);
  foreach ($due as $i) {
    if ($calls >= $max) break;
    $c = $s['clips'][$i];
    if ($c['source'] === 'autopost' && isset($done[$c['request_id']])) continue;
    if (!cn_up_analytics_slot()) break;
    $calls++;
    try {
      if ($c['source'] === 'autopost') {
        $done[$c['request_id']] = true;
        $r = cn_up('GET', '/uploadposts/post-analytics/' . rawurlencode($c['request_id']));
        foreach ($s['clips'] as &$o) {
          if (($o['request_id'] ?? '') !== $c['request_id']) continue;
          $pd = $r['platforms'][$o['platform']] ?? null;
          $o['checked'] = $now;
          if (!is_array($pd)) { $o['error'] = 'No stats from ' . $o['platform'] . ' yet.'; continue; }
          if (!empty($pd['post_url'])) $o['url'] = (string)$pd['post_url'];
          if (!empty($pd['platform_post_id'])) $o['post_id'] = (string)$pd['platform_post_id'];
          if (isset($pd['post_metrics']['views'])) {
            cn_stats_set_views($o, cn_stats_views($pd['post_metrics']), 'auto');
            $o['likes'] = isset($pd['post_metrics']['likes']) ? (int)$pd['post_metrics']['likes'] : null;
            $o['comments'] = isset($pd['post_metrics']['comments']) ? (int)$pd['post_metrics']['comments'] : null;
            $o['error'] = '';
          } else {
            $o['error'] = (string)($pd['post_metrics_error'] ?? (empty($pd['success']) ? 'This post didn’t publish.' : 'Views aren’t available for this post yet.'));
          }
        }
        unset($o);
      } else {
        $r = cn_up('GET', '/uploadposts/post-analytics', ['platform_post_id' => $c['post_id'], 'platform' => $c['platform'], 'user' => cn_up_username($m)]);
        $pd = $r['platforms'][$c['platform']] ?? null;
        $pm = is_array($pd) ? ($pd['post_metrics'] ?? null) : null;
        $s['clips'][$i]['checked'] = $now;
        if (is_array($pm) && isset($pm['views'])) {
          cn_stats_set_views($s['clips'][$i], cn_stats_views($pm), 'auto');
          $s['clips'][$i]['likes'] = isset($pm['likes']) ? (int)$pm['likes'] : null;
          $s['clips'][$i]['comments'] = isset($pm['comments']) ? (int)$pm['comments'] : null;
          $s['clips'][$i]['error'] = '';
        } else {
          $s['clips'][$i]['error'] = (string)($pd['post_metrics_error'] ?? 'Views aren’t available for this post. Type them in instead.');
        }
      }
    } catch (Throwable $e) {
      $code = $e->getCode();
      foreach ($s['clips'] as &$o) {
        if ($o['id'] !== $c['id'] && !($c['source'] === 'autopost' && ($o['request_id'] ?? '') === $c['request_id'])) continue;
        $o['checked'] = $now;
        // Posts on accounts that aren't connected can't be read automatically: switch them to typed-in views.
        if ($c['source'] !== 'autopost' && in_array($code, [400, 403, 404], true)) { $o['auto'] = false; $o['error'] = 'ClipNow can’t read this post automatically (it needs to be on an account you connected in Auto-post). Type its views in instead.'; }
        else $o['error'] = 'Couldn’t update the views right now. ClipNow will try again later.';
      }
      unset($o);
    }
  }
  return $calls;
}

/* ---------- Affiliate program ----------
 * Anyone can sign up (email link sign-in, separate from member accounts) and gets a link
 * clipnow.vip/?ref=CODE. The site remembers the code for 60 days and passes it to Stripe
 * checkout as client_reference_id "aff_CODE". Once a day cn_aff_sync() matches completed
 * checkouts to affiliates and pays 50% of each new customer's first real payment (after any
 * trial, before tax), one time per sale, payable after a 14-day hold if not refunded.
 * Payouts are made by the owner (PayPal etc.) and recorded in the admin portal.
 * Data: ~/clipnow-data/affiliates.json and referrals.json.
 */
const CN_AFF_RATE = 0.50;
const CN_AFF_HOLD_DAYS = 14;
const CN_AFF_COOKIE = 'cn_aff';

/** Read-modify-write a JSON file in ~/clipnow-data under a lock. $fn gets the data by reference. */
function cn_locked(string $file, callable $fn) {
  if (!is_dir(CN_DATA)) mkdir(CN_DATA, 0700, true);
  $h = fopen(CN_DATA . '/' . $file, 'c+');
  if (!$h || !flock($h, LOCK_EX)) throw new RuntimeException('Busy, please try again.');
  try {
    $d = json_decode((string)stream_get_contents($h), true) ?: [];
    $r = $fn($d);
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode($d, JSON_UNESCAPED_SLASHES)); fflush($h);
    @chmod(CN_DATA . '/' . $file, 0600);
    return $r;
  } finally { flock($h, LOCK_UN); fclose($h); }
}
/* ---------- Coming-soon mode ----------
 * The site is live only while the file "site-live.on" sits next to index.html. Without it,
 * .htaccess shows coming-soon.html on the public pages (admin, API and policy pages keep working).
 * The file isn't part of the code, so deploying never switches the site on or off.
 * Browsers with the cn_preview=1 cookie (set from the admin portal) still see the real site. */
function cn_site_flag(): string { return dirname(__DIR__) . '/site-live.on'; }
function cn_site_live(): bool { return is_file(cn_site_flag()); }
function cn_waitlist(): array { $d = cn_read('waitlist.json', []); arsort($d); return $d; }

function cn_affs(): array { $d = cn_read('affiliates.json', []); return $d + ['by_id' => [], 'codes' => [], 'emails' => []]; }
function cn_refs(): array { return cn_read('referrals.json', []); }

/** Find or create an affiliate for this email. */
function cn_aff_join(string $email, string $name): array {
  $email = strtolower(trim($email));
  return cn_locked('affiliates.json', function (&$d) use ($email, $name) {
    $d += ['by_id' => [], 'codes' => [], 'emails' => []];
    if (isset($d['emails'][$email])) return $d['by_id'][$d['emails'][$email]];
    $base = substr(preg_replace('/[^a-z0-9]/', '', strtolower($name !== '' ? $name : explode('@', $email)[0])), 0, 14) ?: 'clip';
    do { $code = $base . random_int(10, 999); } while (isset($d['codes'][$code]));
    $id = 'a' . bin2hex(random_bytes(6));
    $d['by_id'][$id] = ['id' => $id, 'email' => $email, 'name' => $name, 'code' => $code, 'handle' => '', 'created' => time(), 'status' => 'active', 'clicks' => 0, 'paid_cents' => 0, 'payouts' => []];
    $d['codes'][$code] = $id; $d['emails'][$email] = $id;
    return $d['by_id'][$id];
  });
}
function cn_aff_send_link(array $a): bool {
  $token = cn_token();
  file_put_contents(cn_token_file('aff-logins', $token), json_encode(['id' => $a['id'], 'exp' => time() + CN_LINK_MINUTES * 60]), LOCK_EX);
  $link = CN_SITE . '/api/affiliate.php?t=' . $token;
  $hi = trim(explode(' ', (string)$a['name'])[0] ?? '') ?: 'there';
  return cn_send_mail($a['email'], 'Your ' . CN_BRAND . ' affiliate sign-in link',
    "Hi $hi,\n\nTap the link below to open your " . CN_BRAND . " affiliate dashboard:\n$link\n\nIt works once and expires in " . CN_LINK_MINUTES . " minutes. If you didn't ask for it, you can ignore this email.\n\nThanks,\nThe " . CN_BRAND . " team\n" . CN_SITE . "\n");
}
function cn_aff_use_link(string $token): ?string {
  if (!preg_match('/^[A-Za-z0-9_-]{30,}$/', $token)) return null;
  $f = cn_token_file('aff-logins', $token); $j = cn_json_file($f, null);
  if (is_file($f)) unlink($f);
  return ($j && ($j['exp'] ?? 0) >= time()) ? $j['id'] : null;
}
function cn_aff_start_session(string $id): void {
  $token = cn_token(); $exp = time() + CN_SESSION_DAYS * 86400;
  file_put_contents(cn_token_file('aff-sessions', $token), json_encode(['id' => $id, 'exp' => $exp]), LOCK_EX);
  setcookie(CN_AFF_COOKIE, $token, ['expires' => $exp, 'path' => '/', 'secure' => str_starts_with(CN_SITE, 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
}
function cn_aff_current(): ?array {
  $token = (string)($_COOKIE[CN_AFF_COOKIE] ?? '');
  if (!preg_match('/^[A-Za-z0-9_-]{30,}$/', $token)) return null;
  $s = cn_json_file(cn_token_file('aff-sessions', $token), null);
  if (!$s || ($s['exp'] ?? 0) < time()) return null;
  $a = cn_affs()['by_id'][$s['id']] ?? null;
  return ($a && $a['status'] === 'active') ? $a : null;
}
function cn_aff_end_session(): void {
  $token = (string)($_COOKIE[CN_AFF_COOKIE] ?? '');
  if (preg_match('/^[A-Za-z0-9_-]{30,}$/', $token)) { $f = cn_token_file('aff-sessions', $token); if (is_file($f)) unlink($f); }
  setcookie(CN_AFF_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => str_starts_with(CN_SITE, 'https://'), 'httponly' => true, 'samesite' => 'Lax']);
}

/** Money and counts for one affiliate. */
function cn_aff_summary(array $a, ?array $refs = null): array {
  $refs = $refs ?? cn_refs(); $now = time();
  $o = ['signups' => 0, 'sales' => 0, 'earned_cents' => 0, 'pending_cents' => 0, 'payable_cents' => 0, 'list' => []];
  foreach ($refs as $r) {
    if ($r['aff'] !== $a['id']) continue;
    $o['signups']++;
    if ($r['status'] === 'earned') {
      $o['sales']++; $o['earned_cents'] += $r['commission_cents'];
      if ($r['payable_at'] <= $now) $o['payable_cents'] += $r['commission_cents']; else $o['pending_cents'] += $r['commission_cents'];
    }
    $o['list'][] = $r;
  }
  usort($o['list'], fn($x, $y) => $y['created'] <=> $x['created']);
  $o['paid_cents'] = (int)($a['paid_cents'] ?? 0);
  $o['balance_cents'] = max(0, $o['payable_cents'] - $o['paid_cents']);
  return $o;
}

/** Match new Stripe checkouts to affiliates and work out commissions. Returns a short report. */
function cn_aff_sync(): array {
  $rep = ['new' => 0, 'earned' => 0, 'void' => 0];
  $affs = cn_affs(); if (!$affs['by_id']) return $rep;
  $state = cn_read('aff-sync.json', ['last' => 0]);
  $since = max(0, (int)$state['last'] - 3600) ?: time() - 90 * 86400;
  $found = []; $after = null;
  for ($page = 0; $page < 10; $page++) {
    $q = ['limit' => 100, 'status' => 'complete', 'created' => ['gte' => $since]];
    if ($after) $q['starting_after'] = $after;
    $r = cn_stripe('GET', '/v1/checkout/sessions', $q);
    foreach ($r['data'] ?? [] as $cs) {
      $ref = (string)($cs['client_reference_id'] ?? '');
      if (!str_starts_with($ref, 'aff_') || empty($cs['subscription'])) continue;
      $aid = $affs['codes'][substr($ref, 4)] ?? null; if (!$aid) continue;
      $a = $affs['by_id'][$aid];
      $email = strtolower((string)($cs['customer_details']['email'] ?? ''));
      if ($email !== '' && $email === $a['email']) continue;   // no self-referrals
      $found[$cs['id']] = ['aff' => $aid, 'session' => $cs['id'], 'customer' => (string)($cs['customer'] ?? ''), 'subscription' => (string)$cs['subscription'],
        'who' => $email !== '' ? substr($email, 0, 2) . '•••@' . (explode('@', $email)[1] ?? '') : 'customer', 'email' => $email,
        'created' => (int)$cs['created'], 'status' => 'signed_up', 'plan' => '', 'commission_cents' => 0, 'payable_at' => 0, 'charge' => ''];
    }
    if (empty($r['has_more']) || empty($r['data'])) break;
    $after = end($r['data'])['id'];
  }
  $refs = cn_refs();
  // One commission per customer: skip a checkout from a customer who was already referred.
  $seen = []; foreach ($refs as $r) { if ($r['customer'] !== '') $seen['c:' . $r['customer']] = true; if ($r['email'] !== '') $seen['e:' . $r['email']] = true; }
  foreach ($found as $sid => $r) {
    if (isset($refs[$sid]) || ($r['customer'] !== '' && isset($seen['c:' . $r['customer']])) || ($r['email'] !== '' && isset($seen['e:' . $r['email']]))) continue;
    $refs[$sid] = $r; $seen['c:' . $r['customer']] = true; $seen['e:' . $r['email']] = true; $rep['new']++;
  }
  $now = time();
  foreach ($refs as $sid => &$r) {
    try {
      if ($r['status'] === 'signed_up') {
        $inv = cn_stripe('GET', '/v1/invoices', ['subscription' => $r['subscription'], 'status' => 'paid', 'limit' => 10]);
        $paid = array_values(array_filter($inv['data'] ?? [], fn($i) => (int)($i['amount_paid'] ?? 0) > 0));
        usort($paid, fn($x, $y) => $x['created'] <=> $y['created']);
        if ($paid) {
          $i = $paid[0];
          $base = (int)$i['amount_paid'] - (int)($i['tax'] ?? 0);
          $r['commission_cents'] = (int)round($base * CN_AFF_RATE);
          $r['sale_cents'] = $base;
          $r['plan'] = cn_plan_name('', (int)($i['lines']['data'][0]['price']['unit_amount'] ?? 0), '');
          $r['charge'] = (string)($i['charge'] ?? '');
          $r['earned_at'] = (int)($i['status_transitions']['paid_at'] ?? $i['created']);
          $r['payable_at'] = $r['earned_at'] + CN_AFF_HOLD_DAYS * 86400;
          $r['status'] = 'earned'; $rep['earned']++;
        } elseif ($r['created'] < $now - 45 * 86400) {
          $r['status'] = 'void'; $r['why'] = 'No payment (trial ended or canceled)'; $rep['void']++;
        }
      } elseif ($r['status'] === 'earned' && $r['payable_at'] > $now - 86400 && $r['charge'] !== '') {
        $ch = cn_stripe('GET', '/v1/charges/' . rawurlencode($r['charge']));
        if (!empty($ch['refunded']) || (int)($ch['amount_refunded'] ?? 0) > 0 || !empty($ch['disputed'])) {
          $r['status'] = 'void'; $r['why'] = 'Payment refunded or disputed'; $rep['void']++;
        }
      }
    } catch (Throwable $e) { $r['last_error'] = $e->getMessage(); }
  }
  unset($r);
  cn_write('referrals.json', $refs);
  cn_write('aff-sync.json', ['last' => $now, 'report' => $rep]);
  return $rep;
}
