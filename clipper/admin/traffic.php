<?php
// Website traffic (2026-11-22). The SAME file is used in App Planet USA (portal/traffic.php) and ClipNow (admin/traffic.php).
// Each admin's dropdown shows both businesses: App Planet data in portal-data/analytics, ClipNow data in clipnow-data/analytics.
// Privacy: no cookies, no names, no full IP. A visitor is a daily hash of (day + IP + browser + secret salt),
// so the same person counts once per day and can't be traced back.
// Storage: <dir>/<site>/<YYYY-MM-DD>.json  {v: views, u: {hash: 1}, pg: {path: n}, src: {source: n}, dev: {kind: n}}
// Site keys: 'apu' = appplanetusa.com, 'p-<pid>' = a partner's website, 'cn' = clipnow.vip.

const TR_SOURCES = ['google' => 'Google', 'bing' => 'Bing', 'yahoo' => 'Yahoo', 'duckduckgo' => 'DuckDuckGo', 'facebook' => 'Facebook',
  'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'x' => 'X (Twitter)', 'linkedin' => 'LinkedIn',
  'pinterest' => 'Pinterest', 'snapchat' => 'Snapchat', 'reddit' => 'Reddit', 'card' => 'Digital card', 'qr' => 'QR code', 'email' => 'Email',
  'banner' => 'Banner', 'direct' => 'Direct / typed in'];
const TR_APU_LABELS = ['/' => 'Home', '/card-order.html' => 'Digital Cards', '/cards' => 'Digital Cards', '/webdesign.html' => 'Websites', '/websites' => 'Websites',
  '/marketing.html' => 'Marketing', '/marketing' => 'Marketing', '/intake.html' => 'Contact', '/contact' => 'Contact', '/book.html' => 'Book a Call', '/book' => 'Book a Call',
  '/request.html' => 'Request Form', '/partner-program.html' => 'Business Opportunity', '/card/' => 'Card Sample', '/ad/' => 'Ad Sample'];
const TR_CN_LABELS = ['/' => 'Home (app)', '/coming-soon' => 'Coming-soon page', '/studio' => 'Studio', '/campaigns' => 'Campaigns', '/stats' => 'Stats',
  '/affiliates' => 'Affiliates', '/clipper-u' => 'Clipper U episode', '/clipper-u.html' => 'Clipper U episode', '/privacy.html' => 'Privacy', '/refunds.html' => 'Refunds',
  '/support.html' => 'Support', '/terms.html' => 'Terms'];
const TR_DEVICES = ['mobile' => 'Phone', 'tablet' => 'Tablet', 'desktop' => 'Computer'];

function tr_is_bot(string $ua): bool {
  return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|preview|fetch|monitor|headless|lighthouse|curl|wget|python|scrapy|httpclient|facebookexternalhit|whatsapp|telegram|discord|skype|embedly|uptime|pingdom/i', $ua);
}
function tr_device(string $ua): string {
  if (preg_match('/iPad|Tablet|Nexus (7|9|10)|SM-T|Kindle|Silk/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) return 'tablet';
  return preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $ua) ? 'mobile' : 'desktop';
}
/** Where a visit came from: ?s= / utm_source tag first, then the referring site. */
function tr_source(string $tag, string $ref, string $ownHost): string {
  $tag = strtolower(preg_replace('/[^a-z0-9]/i', '', $tag));
  $alias = ['fb' => 'facebook', 'ig' => 'instagram', 'tt' => 'tiktok', 'yt' => 'youtube', 'twitter' => 'x', 'nfc' => 'card', 'wallet' => 'card', 'link' => 'direct'];
  $tag = $alias[$tag] ?? $tag;
  if ($tag !== '' && isset(TR_SOURCES[$tag])) return $tag;
  $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
  $host = preg_replace('/^(www|m|l|lm|mobile)\./', '', $host);
  if ($host === '' || $host === $ownHost || $host === 'www.' . $ownHost) return 'direct';
  $map = ['google' => '/(^|\.)google\./', 'bing' => '/(^|\.)bing\.com$/', 'yahoo' => '/yahoo\./', 'duckduckgo' => '/duckduckgo\.com$/',
    'facebook' => '/facebook\.com$|fb\.me$|fb\.com$/', 'instagram' => '/instagram\.com$/', 'tiktok' => '/tiktok\.com$/', 'youtube' => '/youtube\.com$|youtu\.be$/',
    'x' => '/(^|\.)(x|twitter)\.com$|t\.co$/', 'linkedin' => '/linkedin\.com$|lnkd\.in$/', 'pinterest' => '/pinterest\./', 'snapchat' => '/snapchat\.com$/',
    'reddit' => '/reddit\.com$/', 'card' => '/\.pages\.dev$/', 'email' => '/mail\.|outlook\.|proton/'];
  foreach ($map as $k => $re) if (preg_match($re, $host)) return $k;
  return 'site:' . substr($host, 0, 60);
}
/** State (US) or country for an IP, from the DB-IP lite table in <home>/geo: geo-index.json + geo4-N.bin / geo6-N.bin pieces
    (rows: start 4 or 8 bytes big-endian + 2-byte label number; pieces kept under ~2.6 MB because bigger uploads fail).
    Returns 'US-Georgia', 'CA', ... or '' when unknown. The IP is only looked up here, never stored or sent anywhere. */
function tr_geo(string $ip, string $gdir): string {
  static $G = null, $pre = 'geo';
  if ($G === null) {   // city table (city-index.json, cityN-*.bin, city-names.txt/.idx) when it's all there, else the state table
    $C = json_decode((string)@file_get_contents("$gdir/city-index.json"), true);
    if ($C && is_file("$gdir/city-names.idx") && is_file("$gdir/city-names.txt")) { $G = $C; $pre = 'city'; }
    else $G = json_decode((string)@file_get_contents("$gdir/geo-index.json"), true) ?: [];
  }
  if (!$G) return '';
  $bin = @inet_pton($ip); if ($bin === false) return '';
  if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $bin = substr($bin, 12);   // IPv4 written as IPv6
  [$v, $w] = strlen($bin) === 4 ? ['4', 4] : ['6', 8]; $key = substr($bin, 0, $w);
  $piece = -1; foreach (($G['idx'][$v] ?? []) as $n => $first) { if (strcmp(hex2bin($first), $key) <= 0) $piece = $n; else break; }
  if ($piece < 0) return '';
  $f = @fopen("$gdir/$pre$v-$piece.bin", 'rb'); if (!$f) return '';
  $rec = $w + 2; $lo = 0; $hi = intdiv((int)fstat($f)['size'], $rec) - 1; $hit = -1;
  while ($lo <= $hi) {   // last row whose start <= ip
    $mid = ($lo + $hi) >> 1; fseek($f, $mid * $rec);
    if (strcmp(fread($f, $w), $key) <= 0) { $hit = $mid; $lo = $mid + 1; } else $hi = $mid - 1;
  }
  $id = 0; if ($hit >= 0) { fseek($f, $hit * $rec + $w); $id = unpack('n', fread($f, 2))[1]; }
  fclose($f);
  if ($pre === 'geo') return (string)($G['labels'][$id] ?? '');
  if ($id <= 0) return '';
  $x = @fopen("$gdir/city-names.idx", 'rb'); if (!$x) return ''; fseek($x, $id * 6); $ol = fread($x, 6); fclose($x);
  if (strlen($ol) < 6) return '';
  $u = unpack('Noff/nlen', $ol);
  $t = @fopen("$gdir/city-names.txt", 'rb'); if (!$t) return ''; fseek($t, $u['off']); $name = $u['len'] ? fread($t, $u['len']) : ''; fclose($t);
  return (string)$name;   // 'US-Georgia|Atlanta' or a country code
}
const TR_COUNTRIES = ['CA' => 'Canada', 'MX' => 'Mexico', 'GB' => 'United Kingdom', 'NG' => 'Nigeria', 'GH' => 'Ghana', 'JM' => 'Jamaica', 'IN' => 'India',
  'PH' => 'Philippines', 'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'AU' => 'Australia', 'BR' => 'Brazil', 'CN' => 'China',
  'JP' => 'Japan', 'KR' => 'South Korea', 'SG' => 'Singapore', 'ZA' => 'South Africa', 'KE' => 'Kenya', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'VN' => 'Vietnam',
  'ID' => 'Indonesia', 'RU' => 'Russia', 'UA' => 'Ukraine', 'TR' => 'Turkey', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'IT' => 'Italy', 'ES' => 'Spain',
  'PL' => 'Poland', 'SE' => 'Sweden', 'CO' => 'Colombia', 'DO' => 'Dominican Republic', 'HT' => 'Haiti', 'TT' => 'Trinidad and Tobago', 'BS' => 'Bahamas', 'PR' => 'Puerto Rico'];
function tr_place(string $k): string {
  if ($k === '' ) return 'Unknown';
  if ($k === 'US') return 'United States (state unknown)';
  if (strpos($k, 'US-') === 0) return explode('|', substr($k, 3))[0];
  return 'Outside the US · ' . (TR_COUNTRIES[$k] ?? $k);
}

function tr_salt(string $dir): string {
  $f = "$dir/salt.txt";
  if (!is_file($f)) { @mkdir($dir, 0750, true); @file_put_contents($f, bin2hex(random_bytes(16))); @chmod($f, 0640); }
  return trim((string)@file_get_contents($f));
}

/** Count one page view. $in = decoded beacon {p: path, r: referrer, s: tag}. Returns false when skipped. */
function tr_record(string $dir, string $site, array $in, string $ownHost): bool {
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
  if (tr_is_bot($ua) || !preg_match('/^[a-z0-9-]{2,40}$/', $site)) return false;
  $path = (string)($in['p'] ?? '/');
  $path = '/' . ltrim(preg_replace('/[?#].*$/', '', $path), '/');
  $path = preg_replace('/\/index\.(html|php)$/', '/', $path);
  $path = substr(preg_replace('/[^A-Za-z0-9\/._~-]/', '', $path), 0, 80) ?: '/';
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $day = gmdate('Y-m-d');
  $who = substr(hash('sha256', $day . '|' . $ip . '|' . $ua . '|' . tr_salt($dir)), 0, 16);
  $src = tr_source((string)($in['s'] ?? ''), (string)($in['r'] ?? ''), strtolower($ownHost));
  $d = "$dir/$site"; if (!is_dir($d)) @mkdir($d, 0750, true);
  $f = "$d/$day.json";
  $h = @fopen($f, 'c+'); if (!$h) return false;
  flock($h, LOCK_EX);
  $j = json_decode(stream_get_contents($h) ?: '{}', true); if (!is_array($j)) $j = [];
  $j['v'] = ($j['v'] ?? 0) + 1;
  $j['pg'][$path] = ($j['pg'][$path] ?? 0) + 1;
  if (empty($j['u'][$who]) && count($j['u'] ?? []) < 50000) {   // first page of this visitor today: count where they came from + device
    $j['u'][$who] = 1;   // (security 2026-11-23: past 50,000 visitors a day, new visitors only add a page view, so the file can't grow forever)
    $j['src'][$src] = ($j['src'][$src] ?? 0) + 1;
    $dv = tr_device($ua); $j['dev'][$dv] = ($j['dev'][$dv] ?? 0) + 1;
    $geo = tr_geo($ip, dirname($dir, 2) . '/geo'); $j['geo'][$geo] = ($j['geo'][$geo] ?? 0) + 1;
  }
  foreach (['pg' => 400, 'src' => 300, 'geo' => 3000] as $k => $cap) if (count($j[$k] ?? []) > $cap) { arsort($j[$k]); $j[$k] = array_slice($j[$k], 0, (int)($cap * .75), true); }
  ftruncate($h, 0); rewind($h); fwrite($h, json_encode($j, JSON_UNESCAPED_SLASHES));
  fflush($h); flock($h, LOCK_UN); fclose($h); @chmod($f, 0640);
  return true;
}

/** Collector endpoint body: reads the beacon, records it, answers 204. */
function tr_collect(string $dir, string $site, string $ownHost): void {
  header('Cache-Control: no-store');
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true);
    if (is_array($in)) { try { tr_record($dir, $site, $in, $ownHost); } catch (Throwable $e) {} }
  }
  http_response_code(204);
}

/** Totals for the last $days days (UTC days, today included). */
function tr_report(string $dir, string $site, int $days = 30): array {
  $r = ['days' => [], 'views' => 0, 'visitors' => 0, 'pages' => [], 'src' => [], 'dev' => [], 'geo' => [], 'prev_visitors' => 0];
  for ($i = $days - 1; $i >= 0; $i--) {
    $day = gmdate('Y-m-d', time() - $i * 86400);
    $j = json_decode((string)@file_get_contents("$dir/$site/$day.json"), true) ?: [];
    $u = count($j['u'] ?? []); $v = (int)($j['v'] ?? 0);
    $r['days'][$day] = ['u' => $u, 'v' => $v];
    $r['views'] += $v; $r['visitors'] += $u;
    foreach (['pg' => 'pages', 'src' => 'src', 'dev' => 'dev', 'geo' => 'geo'] as $k => $o) foreach (($j[$k] ?? []) as $n => $c) $r[$o][$n] = ($r[$o][$n] ?? 0) + (int)$c;
  }
  for ($i = 2 * $days - 1; $i >= $days; $i--) { $j = json_decode((string)@file_get_contents("$dir/$site/" . gmdate('Y-m-d', time() - $i * 86400) . '.json'), true) ?: []; $r['prev_visitors'] += count($j['u'] ?? []); }
  foreach (['pages', 'src', 'dev', 'geo'] as $k) arsort($r[$k]);
  return $r;
}
/** Every website both admins can show: key => [label, data dir, page labels]. $home = /home1/thrypjmy. */
function tr_sites(string $home): array {
  $s = ['apu' => ['appplanetusa.com (App Planet USA)', "$home/portal-data/analytics", TR_APU_LABELS],
        'cn' => ['clipnow.vip (ClipNow)', "$home/clipnow-data/analytics", TR_CN_LABELS]];
  $d = json_decode((string)@file_get_contents("$home/portal-data/data.json"), true) ?: [];
  foreach (($d['partners'] ?? []) as $pid => $p) if (preg_match('/^[a-z0-9]{1,30}$/', (string)$pid))
    $s['p-' . $pid] = ['Partner: ' . (($p['business'] ?? '') ?: ($p['name'] ?? $pid)) . (!empty($p['site']['domains'][0]) ? ' · ' . $p['site']['domains'][0] : ''), "$home/portal-data/analytics", TR_APU_LABELS];
  return $s;
}
/** Website dropdown + dashboard + "all websites" list. $base = URL start that already holds the admin's own query, ending in & */
function tr_admin_view(string $home, string $default, string $base): string {
  $S = tr_sites($home);
  $site = isset($S[$_GET['site'] ?? '']) ? $_GET['site'] : $default;
  $days = (int)($_GET['days'] ?? 30); if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;
  parse_str((string)parse_url($base, PHP_URL_QUERY), $keep);
  $o = '<form method="get" action="' . tr_e(explode('?', $base)[0]) . '" class="trf-pick">';
  foreach ($keep as $k => $v) if ($k !== 'site' && $k !== 'days') $o .= '<input type="hidden" name="' . tr_e($k) . '" value="' . tr_e($v) . '">';
  $o .= '<input type="hidden" name="days" value="' . $days . '"><label for="trsite" style="font-weight:700;margin:0">Website</label><select id="trsite" name="site" onchange="this.form.submit()">';
  foreach ($S as $k => $x) $o .= '<option value="' . tr_e($k) . '"' . ($k === $site ? ' selected' : '') . '>' . tr_e($x[0]) . '</option>';
  $o .= '</select><noscript><button type="submit">Show</button></noscript></form>';
  [$label, $dir, $labels] = $S[$site];
  $note = $site === 'cn' ? 'Counts clipnow.vip pages and app opens. While ClipNow is in coming-soon mode, visits show as "Coming-soon page".'
    : ($site === 'apu' ? 'Counts appplanetusa.com only.' : 'Counts this partner\'s website only (their private preview visits are not counted). The partner sees the same numbers in their portal.');
  $o .= tr_html(tr_report($dir, $site, $days), $days, ['base' => $base . 'site=' . rawurlencode($site) . '&', 'labels' => $labels, 'note' => $note]);
  $all = []; foreach ($S as $k => $x) $all[$k] = [$x[0], tr_report($x[1], $k, $days)['visitors']];
  uasort($all, fn($a, $b) => $b[1] <=> $a[1]);
  $o .= '<div class="trf-list" style="margin-top:14px"><p class="trf-lbl">All your websites · visitors, last ' . $days . ' days</p><ul>';
  foreach ($all as $k => [$l, $v]) $o .= '<li><span class="trf-k"><a href="' . tr_e($base . 'site=' . rawurlencode($k) . '&days=' . $days) . '">' . tr_e($l) . '</a></span><span class="trf-n">' . number_format($v) . '</span></li>';
  return $o . '</ul></div>';
}

/** Delete day files older than 400 days (called by the cron). */
function tr_prune(string $dir): void {
  $cut = gmdate('Y-m-d', time() - 400 * 86400);
  foreach (glob("$dir/*/*.json") ?: [] as $f) if (basename($f, '.json') < $cut) @unlink($f);
}

function tr_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Dashboard block. $opts: title, range links base ('?tab=traffic&'), page labels [path => label], note. */
function tr_html(array $r, int $days, array $opts = []): string {
  $base = $opts['base'] ?? '?'; $labels = $opts['labels'] ?? [];
  $n = fn($x) => number_format((int)$x);
  $o = '<div class="trf">';
  $o .= '<div class="trf-range" role="group" aria-label="Time range">';
  foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months'] as $d => $l) $o .= '<a href="' . tr_e($base . 'days=' . $d . ($opts['hash'] ?? '')) . '"' . ($d === $days ? ' class="on" aria-current="true"' : '') . '>' . $l . '</a>';
  $o .= '</div>';
  $ppv = $r['visitors'] ? round($r['views'] / $r['visitors'], 1) : 0;
  $chg = '';
  if ($r['prev_visitors'] > 0) { $p = round(($r['visitors'] - $r['prev_visitors']) / $r['prev_visitors'] * 100); $chg = ($p >= 0 ? '▲ ' : '▼ ') . abs($p) . '% vs the ' . $days . ' days before'; }
  $today = end($r['days']) ?: ['u' => 0, 'v' => 0];
  $o .= '<div class="trf-tiles">'
    . '<div><b>' . $n($r['visitors']) . '</b><span>Visitors</span>' . ($chg ? '<small>' . tr_e($chg) . '</small>' : '') . '</div>'
    . '<div><b>' . $n($r['views']) . '</b><span>Page views</span></div>'
    . '<div><b>' . tr_e($ppv) . '</b><span>Pages per visitor</span></div>'
    . '<div><b>' . $n($today['u']) . '</b><span>Visitors today</span></div></div>';
  // Daily visitors: one series, one hue, hover a bar for the exact number.
  $vals = array_column($r['days'], 'u'); $max = max(1, max($vals ?: [0]));
  $nice = (int)max(1, ceil($max / (10 ** floor(log10($max)))) * (10 ** floor(log10($max))));
  if ($nice % 2) $nice++;   // so the middle line is a whole number
  $cnt = count($vals); $W = 460; $H = 180; $L = 30; $B = 22; $T = 8; $pw = $W - $L - 4; $ph = $H - $B - $T;
  $slot = $pw / max(1, $cnt); $bw = max(1.5, min(18, $slot - 2));
  $svg = '<svg class="trf-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Visitors per day, last ' . $days . ' days">';
  foreach ([0, 0.5, 1] as $g) { $y = round($T + $ph - $ph * $g, 1); $svg .= '<line x1="' . $L . '" x2="' . $W . '" y1="' . $y . '" y2="' . $y . '" class="trf-grid"/><text x="' . ($L - 6) . '" y="' . ($y + 4) . '" text-anchor="end" class="trf-ax">' . $n($nice * $g) . '</text>'; }
  $i = 0; $keys = array_keys($r['days']);
  foreach ($r['days'] as $day => $c) {
    $x = $L + $i * $slot + ($slot - $bw) / 2; $hgt = $c['u'] ? max(2, $ph * $c['u'] / $nice) : 0; $y = $T + $ph - $hgt;
    $lab = date('D M j', strtotime($day)) . ': ' . $n($c['u']) . ' visitor' . ($c['u'] == 1 ? '' : 's') . ', ' . $n($c['v']) . ' page view' . ($c['v'] == 1 ? '' : 's');
    $svg .= '<g class="trf-bar"><rect x="' . round($L + $i * $slot, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . $ph . '" class="trf-hit"/>';
    if ($hgt) { $rr = min(4, $bw / 2, $hgt); $svg .= '<path d="M' . round($x, 1) . ',' . round($T + $ph, 1) . 'V' . round($y + $rr, 1) . 'q0,-' . $rr . ' ' . $rr . ',-' . $rr . 'h' . round($bw - 2 * $rr, 1) . 'q' . $rr . ',0 ' . $rr . ',' . $rr . 'V' . round($T + $ph, 1) . 'Z" class="trf-fill"/>'; }
    $svg .= '<title>' . tr_e($lab) . '</title></g>';
    $i++;
  }
  $tick = max(1, (int)ceil($cnt / 6));
  for ($k = 0; $k < $cnt; $k += $tick) $svg .= '<text x="' . round($L + $k * $slot + $slot / 2, 1) . '" y="' . ($H - 6) . '" text-anchor="middle" class="trf-ax">' . date('M j', strtotime($keys[$k])) . '</text>';
  $svg .= '<line x1="' . $L . '" x2="' . $W . '" y1="' . ($T + $ph) . '" y2="' . ($T + $ph) . '" class="trf-base"/></svg>';
  $o .= '<p class="trf-lbl">Visitors per day <small>tap or hover a bar for the number</small></p>' . $svg
    . '<p class="trf-read" aria-live="polite">' . tr_e(date('D M j') . ': ' . $n($today['u']) . ' visitor' . ($today['u'] == 1 ? '' : 's') . ' so far today') . '</p>'
    . '<script>document.querySelectorAll(".trf-chart").forEach(function(c){var o=c.nextElementSibling;c.addEventListener("click",function(e){var g=e.target.closest(".trf-bar");if(!g)return;c.querySelectorAll(".trf-bar.sel").forEach(function(x){x.classList.remove("sel")});g.classList.add("sel");o.textContent=g.querySelector("title").textContent;});});</script>';
  // Ranked lists with a thin share bar.
  $list = function (string $title, array $rows, int $limit, callable $label) use ($n) {
    $tot = array_sum($rows); $h = '<div class="trf-list"><p class="trf-lbl">' . tr_e($title) . '</p>';
    if (!$rows) return $h . '<p class="trf-none">Nothing yet.</p></div>';
    $h .= '<ul>';
    foreach (array_slice($rows, 0, $limit, true) as $k => $c) {
      $pc = $tot ? round($c / $tot * 100) : 0;
      $h .= '<li><span class="trf-k">' . $label($k) . '</span><span class="trf-n">' . $n($c) . ' <small>' . $pc . '%</small></span><i style="width:' . max(1, $pc) . '%"></i></li>';
    }
    return $h . '</ul></div>';
  };
  $o .= '<div class="trf-cols">';
  $o .= $list('Most viewed pages', $r['pages'], 12, function ($p) use ($labels) { return tr_e($labels[$p] ?? $p) . (isset($labels[$p]) ? ' <small>' . tr_e($p) . '</small>' : ''); });
  $o .= $list('Where visitors came from', $r['src'], 12, function ($s) { return strpos($s, 'site:') === 0 ? tr_e(substr($s, 5)) : tr_e(TR_SOURCES[$s] ?? $s); });
  $o .= tr_geo_html($r['geo'], $n);
  $o .= $list('Phone or computer', $r['dev'], 3, fn($d) => tr_e(TR_DEVICES[$d] ?? $d));
  $o .= '</div>';
  $o .= '<p class="trf-note">' . ($opts['note'] ?? '') . ' Visitors are counted once per day. No cookies, names or IP addresses are stored. Search bots and link previews are not counted. States are a close estimate from each visitor\'s internet provider (phone carriers can show a nearby state). <a href=\"https://db-ip.com\" target=\"_blank\" rel=\"noopener\">IP Geolocation by DB-IP</a>. Days are in UTC.</p>';
  return $o . '</div>';
}

/** "Where visitors are": one row per state; tap a state to see its cities. Keys: 'US-Georgia|Atlanta', 'US-Georgia' (no city), 'CA', ''. */
function tr_geo_html(array $geo, callable $n): string {
  $places = [];   // place => [total, [city => n]]
  foreach ($geo as $k => $c) {
    $k = (string)$k; [$st, $city] = array_pad(explode('|', $k, 2), 2, null);
    $p = strpos($k, 'US-') === 0 ? $st : $k;
    $places[$p][0] = ($places[$p][0] ?? 0) + $c;
    if (strpos($k, 'US-') === 0) { $city = ($city ?? '') !== '' ? $city : 'City not known'; $places[$p][1][$city] = ($places[$p][1][$city] ?? 0) + $c; }
  }
  uasort($places, fn($a, $b) => $b[0] <=> $a[0]);
  $tot = array_sum(array_column($places, 0));
  $h = '<div class="trf-list trf-geo"><p class="trf-lbl">Where visitors are <small>tap a state to see its cities</small></p>';
  if (!$places) return $h . '<p class="trf-none">Nothing yet.</p></div>';
  $h .= '<ul>';
  foreach (array_slice($places, 0, 25, true) as $p => $x) { $c = $x[0]; $cities = $x[1] ?? [];
    $pc = $tot ? round($c / $tot * 100) : 0;
    $row = '<span class="trf-k">' . tr_e(tr_place((string)$p)) . '</span><span class="trf-n">' . $n($c) . ' <small>' . $pc . '%</small></span><i style="width:' . max(1, $pc) . '%"></i>';
    if (!$cities) { $h .= '<li>' . $row . '</li>'; continue; }
    arsort($cities);
    $h .= '<li class="trf-st"><details><summary>' . $row . '</summary><ul class="trf-cities">';
    foreach (array_slice($cities, 0, 30, true) as $ct => $cc) $h .= '<li><span class="trf-k">' . tr_e($ct) . '</span><span class="trf-n">' . $n($cc) . '</span></li>';
    if (count($cities) > 30) $h .= '<li><span class="trf-k"><small>+ ' . (count($cities) - 30) . ' more cities</small></span></li>';
    $h .= '</ul></details></li>';
  }
  return $h . '</ul></div>';
}

/** Styles for tr_html. Works on dark pages; html[data-mode=light] switches to light. */
function tr_css(): string {
  return '<style>
.trf{--tr-ink:#E9EDE9;--tr-mut:#96A096;--tr-line:#2a312a;--tr-card:#141814;--tr-acc:#31C971;--tr-acc2:rgba(49,201,113,.16)}
html[data-mode="light"] .trf{--tr-ink:#14201a;--tr-mut:#5a685e;--tr-line:#dbe5dd;--tr-card:#fff;--tr-acc:#16743d;--tr-acc2:rgba(22,116,61,.12)}
.trf{color:var(--tr-ink);min-width:0}
.trf-range{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin:4px 0 14px;max-width:460px}
.trf .trf-range a{border:1px solid var(--tr-line);border-radius:999px;padding:7px 4px;font-size:13px;text-align:center;white-space:nowrap;text-decoration:none;color:var(--tr-ink);background:var(--tr-card)}
.trf .trf-range a.on{border-color:var(--tr-acc);color:var(--tr-acc);font-weight:700}
.trf-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}
.trf-tiles div{border:1px solid var(--tr-line);background:var(--tr-card);border-radius:14px;padding:12px 10px;text-align:center;min-width:0}
.trf-tiles b{display:block;font:700 clamp(19px,5.2vw,26px)/1.1 Consolas,"SF Mono",Menlo,monospace;color:var(--tr-ink)}
.trf-tiles span{display:block;font-size:12px;color:var(--tr-mut);margin-top:3px}
.trf-tiles small{display:block;font-size:11px;color:var(--tr-mut);margin-top:3px}
@media(max-width:520px){.trf-tiles{grid-template-columns:1fr 1fr}}
.trf-lbl{font-size:12.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--tr-mut);margin:6px 0 6px}
.trf-lbl small{text-transform:none;letter-spacing:0;font-weight:400;margin-left:6px}
.trf-chart{display:block;width:100%;height:auto;border:1px solid var(--tr-line);border-radius:14px;background:var(--tr-card);margin-bottom:6px}
.trf-read{font-size:13.5px;color:var(--tr-ink);margin:0 0 16px;min-height:1.3em;font-variant-numeric:tabular-nums}
.trf-grid{stroke:var(--tr-line);stroke-width:1}.trf-base{stroke:var(--tr-mut);stroke-width:1;opacity:.6}
.trf-ax{fill:var(--tr-mut);font:11px system-ui,sans-serif}
.trf-fill{fill:var(--tr-acc)}.trf-hit{fill:transparent}.trf-bar{cursor:pointer}.trf-bar:hover .trf-hit,.trf-bar.sel .trf-hit{fill:var(--tr-acc2)}
.trf-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
.trf-list{border:1px solid var(--tr-line);background:var(--tr-card);border-radius:14px;padding:10px 12px;min-width:0}
.trf-list ul{list-style:none;margin:0;padding:0}
.trf-list li{position:relative;display:flex;justify-content:space-between;gap:10px;padding:7px 0 9px;border-top:1px solid var(--tr-line);font-size:14px}
.trf-list li:first-child{border-top:0}
.trf-k{min-width:0;overflow-wrap:anywhere}.trf-k small,.trf-n small{color:var(--tr-mut);font-size:11.5px}
.trf-n{white-space:nowrap;font-variant-numeric:tabular-nums}
.trf-list i{position:absolute;left:0;bottom:2px;height:3px;border-radius:2px;background:var(--tr-acc);opacity:.75}
.trf-geo li.trf-st{display:block;padding:0}
.trf-geo details>summary{position:relative;display:flex;justify-content:space-between;gap:10px;padding:7px 0 9px 18px;cursor:pointer;list-style:none}
.trf-geo details>summary::-webkit-details-marker{display:none}
.trf-geo details>summary::before{content:"";position:absolute;left:2px;top:13px;border:5px solid transparent;border-left-color:var(--tr-acc);transition:transform .15s}
.trf-geo details[open]>summary::before{transform:rotate(90deg);left:0;top:15px}
.trf-geo details>summary .trf-k{text-decoration:underline;text-decoration-color:var(--tr-line);text-underline-offset:3px}
.trf-geo .trf-cities{margin:0 0 8px 18px;padding:2px 10px;border-left:2px solid var(--tr-acc2)}
.trf-geo .trf-cities li{padding:5px 0;font-size:13.5px}
.trf-none{color:var(--tr-mut);font-size:14px;margin:4px 0}
.trf-note{color:var(--tr-mut);font-size:12.5px;margin-top:12px}
.trf-pick{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}
.trf-pick select{font:inherit;padding:8px 10px;border-radius:10px;border:1px solid var(--tr-line);background:var(--tr-card);color:var(--tr-ink);max-width:100%}
</style>';
}
