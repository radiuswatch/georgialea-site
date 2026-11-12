<?php
// My stats: views on a member's clips, and potential earnings per campaign. Any active plan.
//
// GET                         → clips, campaign earnings and totals (from saved numbers; fast)
// POST action=refresh         → update views from Upload-Post (a few clips per call), then the same
// POST action=add             → track a clip by its link   {url, title, views, campaign}
// POST action=views           → type in a clip's views     {id, views}
// POST action=campaign        → tag a clip with a campaign {id, campaign}
// POST action=remove          → stop tracking a clip       {id}  (or {ids:[...]} for several)
// POST action=est_rate        → the pay per 1,000 views used to estimate untagged clips {rate} (empty = automatic)
// POST action=campaign with {ids:[...]} tags several clips at once
// campaign = {kind:'clipnow', id} | {kind:'other', name, rate} | null
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';

function st_money($v): int { $n = (float)str_replace([',', '$'], '', (string)$v); return max(0, min(100000, (int)round($n * 100))); }

/** A campaign tag from the browser, checked. ClipNow campaigns must be ones the member created or joined. */
function st_campaign($in, string $me): array|string|null {
  if (!is_array($in) || empty($in['kind'])) return null;
  if ($in['kind'] === 'clipnow') {
    $c = cn_camp_load((string)($in['id'] ?? ''));
    if (!$c || (!isset($c['clippers'][$me]) && $c['owner'] !== $me)) return 'Pick a ClipNow campaign you’ve joined.';
    return ['kind' => 'clipnow', 'id' => $c['id']];
  }
  $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? ''))), 0, 80);
  $rate = st_money($in['rate'] ?? '');
  if ($name === '') return 'Give the campaign a name.';
  if ($rate < 1) return 'Enter what the campaign pays per 1,000 views (for example 1.00).';
  return ['kind' => 'other', 'name' => $name, 'rate_cents' => $rate];
}

function st_out(array $m, array $s): array {
  $me = strtolower($m['email']); $now = time();
  $clips = []; $groups = []; $totViews = 0; $totWeek = 0; $perClip = [];
  usort($s['clips'], fn($a, $b) => ($b['posted'] ?? $b['created']) <=> ($a['posted'] ?? $a['created']));
  $camps = [];
  foreach ($s['clips'] as $c) {
    if (!empty($c['hidden'])) continue;
    $base = 0; foreach ($c['history'] as $h) if ($h['at'] <= $now - 7 * 86400) $base = $h['views'];
    $first = $c['history'][0]['at'] ?? $now;
    $week = $first > $now - 7 * 86400 ? $c['views'] : max(0, $c['views'] - $base);
    $totViews += $c['views']; $totWeek += $week;
    $tag = $c['campaign'] ?? null; $label = null;
    if ($tag && $tag['kind'] === 'clipnow') {
      $camps[$tag['id']] = $camps[$tag['id']] ?? cn_camp_load($tag['id']);
      $label = $camps[$tag['id']] ? $camps[$tag['id']]['title'] : 'Deleted campaign';
    } elseif ($tag) $label = $tag['name'];
    $rate = 0;
    if ($tag && $tag['kind'] === 'clipnow') $rate = $camps[$tag['id']] ? (int)$camps[$tag['id']]['rate_cents'] : 0;
    elseif ($tag) $rate = (int)($tag['rate_cents'] ?? 0);
    $perClip[] = ['platform' => $c['platform'], 'views' => (int)$c['views'], 'week' => $week, 'likes' => (int)($c['likes'] ?? 0), 'comments' => (int)($c['comments'] ?? 0),
      'shares' => $c['shares'] ?? null, 'saves' => $c['saves'] ?? null, 'reach' => $c['reach'] ?? null, 'rate' => $rate,
      'group' => $tag ? ($tag['kind'] === 'clipnow' ? 'cn:' . $tag['id'] : 'o:' . strtolower($tag['name']) . ':' . $tag['rate_cents']) : ''];
    $clips[] = ['id' => $c['id'], 'source' => $c['source'], 'platform' => $c['platform'], 'url' => $c['url'], 'title' => $c['title'],
      'request_id' => (string)($c['request_id'] ?? ''), 'post_id' => (string)($c['post_id'] ?? ''),
      'views' => $c['views'], 'likes' => $c['likes'], 'comments' => $c['comments'], 'shares' => $c['shares'] ?? null, 'saves' => $c['saves'] ?? null, 'reach' => $c['reach'] ?? null,
      'week' => $week, 'auto' => !empty($c['auto']),
      'checked' => $c['checked'], 'error' => $c['error'], 'posted' => $c['posted'] ?? $c['created'],
      'waiting' => $c['source'] === 'autopost' && !$c['checked'], 'campaign' => $tag ? $tag + ['label' => $label] : null];
    $key = $tag ? ($tag['kind'] === 'clipnow' ? 'cn:' . $tag['id'] : 'o:' . strtolower($tag['name']) . ':' . $tag['rate_cents']) : '';
    if ($key === '') continue;
    $groups[$key] = $groups[$key] ?? ['kind' => $tag['kind'], 'id' => $tag['id'] ?? '', 'name' => $label, 'rate_cents' => $tag['rate_cents'] ?? 0, 'clips' => 0, 'views' => 0];
    $groups[$key]['clips']++; $groups[$key]['views'] += $c['views'];
  }
  // ClipNow campaigns the member joined show up even before any clip is tagged.
  foreach (cn_camp_all() as $c) {
    if (!isset($c['clippers'][$me])) continue;
    $camps[$c['id']] = $c;
    $groups['cn:' . $c['id']] = $groups['cn:' . $c['id']] ?? ['kind' => 'clipnow', 'id' => $c['id'], 'name' => $c['title'], 'rate_cents' => 0, 'clips' => 0, 'views' => 0];
  }
  $out = []; $totPotential = 0;
  $gScale = [];
  foreach ($groups as $gk => $g) {
    if ($g['kind'] === 'clipnow') {
      $c = $camps[$g['id']] ?? null;
      if (!$c) { $g['name'] = 'Deleted campaign'; $g['potential_cents'] = 0; $out[] = $g; continue; }
      $g['rate_cents'] = (int)$c['rate_cents'];
      $g['approved_cents'] = cn_camp_owed($c, $me); $g['paid_cents'] = cn_camp_paid($c, $me);
      $g['budget_cents'] = (int)$c['budget_cents'];
      $g['creator'] = $c['creator'];
    }
    $g['potential_cents'] = (int)round($g['views'] * $g['rate_cents'] / 1000);
    $uncapped = $g['potential_cents'];
    if (!empty($g['budget_cents'])) $g['potential_cents'] = min($g['potential_cents'], $g['budget_cents']);
    $gScale[$gk] = $uncapped > 0 ? $g['potential_cents'] / $uncapped : 1;
    $totPotential += $g['potential_cents'];
    $out[] = $g;
  }
  usort($out, fn($a, $b) => $b['potential_cents'] <=> $a['potential_cents']);
  // Rate used to estimate clips that aren't in a campaign: the member's own choice, else their average campaign rate, else $1.00.
  $rates = array_values(array_filter(array_map(fn($g) => (int)$g['rate_cents'], $out)));
  $estAuto = !isset($s['est_rate_cents']);
  $est = $estAuto ? ($rates ? (int)round(array_sum($rates) / count($rates)) : 100) : (int)$s['est_rate_cents'];
  // By platform: views, engagement and money.
  $plats = [];
  foreach ($perClip as $c) {
    $p = $c['platform'] ?: 'other';
    $plats[$p] = $plats[$p] ?? ['platform' => $p, 'clips' => 0, 'views' => 0, 'week' => 0, 'likes' => 0, 'comments' => 0, 'shares' => null, 'saves' => null, 'reach' => null,
      'campaign_cents' => 0, 'untagged_views' => 0, 'could_cents' => 0];
    $q = &$plats[$p];
    $q['clips']++; $q['views'] += $c['views']; $q['week'] += $c['week']; $q['likes'] += $c['likes']; $q['comments'] += $c['comments'];
    foreach (['shares', 'saves', 'reach'] as $k) if ($c[$k] !== null) $q[$k] = (int)$q[$k] + $c[$k];
    if ($c['group'] !== '' && $c['rate'] > 0) $q['campaign_cents'] += (int)round($c['views'] * $c['rate'] / 1000 * ($gScale[$c['group']] ?? 1));
    elseif ($c['group'] === '') $q['untagged_views'] += $c['views'];
    unset($q);
  }
  foreach ($plats as &$q) $q['could_cents'] = (int)round($q['untagged_views'] * $est / 1000);
  unset($q);
  $plats = array_values($plats);
  usort($plats, fn($a, $b) => $b['views'] <=> $a['views']);
  $joined = array_values(array_map(fn($c) => ['id' => $c['id'], 'title' => $c['title'], 'rate_cents' => (int)$c['rate_cents']],
    array_filter($camps, fn($c) => $c && isset($c['clippers'][$me]))));
  return ['ok' => true, 'clips' => $clips, 'campaigns' => $out, 'joined' => $joined, 'platforms' => $plats, 'est_rate_cents' => $est, 'est_rate_auto' => $estAuto,
    'totals' => ['views' => $totViews, 'week' => $totWeek, 'clips' => count($clips), 'potential_cents' => $totPotential],
    'auto_ready' => (string)(cn_config()['uploadpost_key'] ?? '') !== '',
    'due' => count(array_filter($s['clips'], fn($c) => !empty($c['auto']) && empty($c['hidden']) && $c['checked'] < $now - CN_STATS_REFRESH && ($c['posted'] ?? 0) <= $now - 120))];
}

try {
  $m = cn_current_member();
  if (!$m) cn_json_out(['ok' => false, 'signedIn' => false, 'error' => 'Please sign in first.'], 401);
  if (empty($m['active']) || ($m['customer_id'] ?? '') === '') cn_json_out(['ok' => false, 'signedIn' => true, 'error' => 'My stats are included with every ClipNow plan.'], 403);
  $me = strtolower($m['email']);
  $s = cn_stats_load($m);
  cn_stats_import($m, $s);

  if ($_SERVER['REQUEST_METHOD'] === 'GET') { cn_stats_save($m, $s); cn_json_out(st_out($m, $s)); }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
  $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
  $act = (string)($in['action'] ?? '');
  $id = (string)($in['id'] ?? '');
  $find = function () use (&$s, $id) { foreach ($s['clips'] as $i => $c) if ($c['id'] === $id && empty($c['hidden'])) return $i; return -1; };

  if ($act === 'refresh') {
    @set_time_limit(120);
    $n = cn_stats_refresh($m, $s, 8, $id);
    cn_stats_save($m, $s);
    cn_json_out(st_out($m, $s) + ['refreshed' => $n]);
  }
  if (!cn_rate_ok('stats:' . $me, 200)) cn_json_out(['ok' => false, 'error' => 'Too many changes in a short time. Wait a few minutes.'], 429);

  if ($act === 'est_rate') {
    $r = trim((string)($in['rate'] ?? ''));
    if ($r === '') unset($s['est_rate_cents']);
    else {
      $cents = (int)round((float)preg_replace('/[^0-9.]/', '', $r) * 100);
      if ($cents < 1 || $cents > 100000) cn_json_out(['ok' => false, 'error' => 'Enter a rate between $0.01 and $1,000 per 1,000 views.'], 422);
      $s['est_rate_cents'] = $cents;
    }
    cn_stats_save($m, $s);
    cn_json_out(st_out($m, $s));
  }

  if ($act === 'add') {
    $url = trim((string)($in['url'] ?? ''));
    if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    $host = (string)parse_url($url, PHP_URL_HOST);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $host)) cn_json_out(['ok' => false, 'error' => 'Paste the full link to your post (for example https://www.tiktok.com/@you/video/123).'], 422);
    foreach ($s['clips'] as $c) if (empty($c['hidden']) && $c['url'] === $url) cn_json_out(['ok' => false, 'error' => 'That post is already in your stats.'], 422);
    if (count(array_filter($s['clips'], fn($c) => empty($c['hidden']))) >= 1000) cn_json_out(['ok' => false, 'error' => 'You’re tracking 1,000 clips. Remove some first.'], 422);
    [$platform, $postId] = cn_stats_parse_url($url);
    $tag = st_campaign($in['campaign'] ?? null, $me);
    if (is_string($tag)) cn_json_out(['ok' => false, 'error' => $tag], 422);
    $views = (int)preg_replace('/\D/', '', (string)($in['views'] ?? ''));
    $auto = $postId !== '' && (string)(cn_config()['uploadpost_key'] ?? '') !== '';
    $c = ['id' => 'k' . bin2hex(random_bytes(6)), 'source' => 'manual', 'request_id' => '', 'platform' => $platform, 'url' => mb_substr($url, 0, 500), 'post_id' => $postId,
      'title' => mb_substr(trim((string)($in['title'] ?? '')), 0, 120), 'views' => 0, 'likes' => null, 'comments' => null,
      'auto' => $auto, 'checked' => 0, 'error' => '', 'history' => [], 'campaign' => $tag, 'posted' => time() - 3600, 'created' => time()];
    if ($views > 0) cn_stats_set_views($c, $views, 'typed');
    $s['clips'][] = $c;
    if ($auto) cn_stats_refresh($m, $s, 1, $c['id']);
    cn_stats_save($m, $s);
    cn_json_out(st_out($m, $s) + ['added' => $c['id']]);
  }

  // Several clips at once (Select all / selected).
  if (in_array($act, ['remove', 'campaign'], true) && is_array($in['ids'] ?? null)) {
    $ids = array_flip(array_slice(array_map('strval', $in['ids']), 0, 1000));
    $tag = null;
    if ($act === 'campaign') { $tag = st_campaign($in['campaign'] ?? null, $me); if (is_string($tag)) cn_json_out(['ok' => false, 'error' => $tag], 422); }
    $n = 0;
    foreach ($s['clips'] as &$c) {
      if (!isset($ids[$c['id']]) || !empty($c['hidden'])) continue;
      if ($act === 'remove') $c['hidden'] = true; else $c['campaign'] = $tag;
      $n++;
    }
    unset($c);
    cn_stats_save($m, $s);
    cn_json_out(st_out($m, $s) + ['changed' => $n]);
  }

  $i = $find();
  if ($i < 0) cn_json_out(['ok' => false, 'error' => 'That clip isn’t in your stats.'], 404);

  if ($act === 'views') {
    $views = (int)preg_replace('/\D/', '', (string)($in['views'] ?? ''));
    if ($views < 0 || (string)($in['views'] ?? '') === '') cn_json_out(['ok' => false, 'error' => 'Enter the views.'], 422);
    cn_stats_set_views($s['clips'][$i], $views, 'typed');
    $s['clips'][$i]['error'] = '';
  } elseif ($act === 'campaign') {
    $tag = st_campaign($in['campaign'] ?? null, $me);
    if (is_string($tag)) cn_json_out(['ok' => false, 'error' => $tag], 422);
    $s['clips'][$i]['campaign'] = $tag;
  } elseif ($act === 'remove') {
    $s['clips'][$i]['hidden'] = true;   // kept hidden so an auto-posted clip isn't imported again
  } else cn_json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
  cn_stats_save($m, $s);
  cn_json_out(st_out($m, $s));
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => 'Stats are unavailable right now. Please try again.'], 500);
}
