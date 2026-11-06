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

/** 'starter' | 'pro' | 'agency' | '' for a subscription's price. */
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
