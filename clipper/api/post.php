<?php
// POST /api/post.php (multipart) → post one clip to the member's connected accounts, now or later.
//   video        the clip file (MP4 recommended; max 60 MB)
//   platforms[]  tiktok | youtube | instagram | facebook | x
//   caption[<platform>]  the caption to use on each platform
//   schedule     optional local date-time "2026-11-06T18:30" + tz (IANA name, e.g. America/New_York)
//   key          a random id from the browser so a retry doesn't post twice
//   set          optional account set to post from (otherwise ClipNow picks a connected account with room)
//   tiktok_mode  "draft" sends the clip to the member's TikTok drafts/inbox to finish posting in the app
//                (used when TikTok's daily posting limit is reached; captions can't be passed to a draft)
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
/* Every attempt is noted when it starts. If the script is stopped part-way (the host's time limit, a crash),
   the shutdown handler records why and still answers in JSON, so the member doesn't get a bare error page. */
$GLOBALS['cn_post_done'] = false;
$GLOBALS['cn_post_t0'] = microtime(true);
function post_note(array $e): void {
  try {
    $f = cn_dir('posts') . '/attempts.jsonl';
    file_put_contents($f, json_encode(['at' => time()] + $e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    if (filesize($f) > 500000) { $lines = array_slice(file($f), -1000); file_put_contents($f, implode('', $lines), LOCK_EX); }
  } catch (Throwable $t) {}
}
post_note(['step' => 'start', 'bytes' => (int)($_FILES['video']['size'] ?? 0), 'upload_error' => (int)($_FILES['video']['error'] ?? -1),
  'length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0), 'platforms' => array_values(array_map('strval', (array)($_POST['platforms'] ?? []))),
  'limit' => ini_get('max_execution_time'), 'post_max' => ini_get('post_max_size'), 'upload_max' => ini_get('upload_max_filesize')]);
register_shutdown_function(function () {
  if ($GLOBALS['cn_post_done']) return;
  global $m;
  $err = error_get_last();
  post_note(['step' => 'stopped', 'customer' => (string)($m['customer_id'] ?? ''), 'email' => (string)($m['email'] ?? ''),
    'seconds' => round(microtime(true) - $GLOBALS['cn_post_t0'], 1), 'error' => $err ? mb_substr($err['message'] . ' @' . basename((string)$err['file']) . ':' . $err['line'], 0, 400) : '', 'limit' => ini_get('max_execution_time')]);
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'The post stopped part-way through on our server. We’ve logged it. Please try again in a minute.']);
  }
});

/** Answer with an error and keep a record of it (posts/refused.jsonl), so a failed post can be looked into later. */
function post_refuse(array $body, int $code): never {
  global $m;
  $GLOBALS['cn_post_done'] = true;
  post_note(['step' => 'refused', 'customer' => (string)($m['customer_id'] ?? ''), 'code' => $code, 'seconds' => round(microtime(true) - $GLOBALS['cn_post_t0'], 1), 'error' => mb_substr((string)($body['error'] ?? ''), 0, 200)]);
  try {
    $f = cn_dir('posts') . '/refused.jsonl';
    $e = ['at' => time(), 'code' => $code, 'customer' => (string)($m['customer_id'] ?? ''), 'email' => (string)($m['email'] ?? ''),
      'platforms' => array_values(array_map('strval', (array)($_POST['platforms'] ?? []))), 'schedule' => (string)($_POST['schedule'] ?? ''),
      'mode' => (string)($_POST['tiktok_mode'] ?? ''), 'bytes' => (int)($_FILES['video']['size'] ?? 0), 'error' => mb_substr((string)($body['error'] ?? ''), 0, 300)];
    file_put_contents($f, json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    if (filesize($f) > 500000) { $lines = array_slice(file($f), -1000); file_put_contents($f, implode('', $lines), LOCK_EX); }
  } catch (Throwable $t) {}
  cn_json_out($body, $code);
}
@set_time_limit(300);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) post_refuse(['ok' => false, 'error' => 'bad request'], 400);
// When an upload is over PHP's size limit, PHP silently drops both the file and the form fields.
if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) post_refuse(['ok' => false, 'error' => 'That clip is too big to post (60 MB max). Export it at 720p or make it shorter.'], 413);

try {
  $m = cn_current_member(true);
  if (!$m) post_refuse(['ok' => false, 'error' => 'Please sign in first.'], 401);
  $limit = cn_post_limit($m);
  if ($limit === 0) post_refuse(['ok' => false, 'error' => 'Auto-posting is included with an active ClipNow plan.'], 403);

  $f = $_FILES['video'] ?? null;
  if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) post_refuse(['ok' => false, 'error' => 'The clip didn\'t upload. Please try again.'], 422);
  if ($f['size'] > CN_POST_MAX_BYTES) post_refuse(['ok' => false, 'error' => 'That clip is too big to post (60 MB max). Export it at 720p or make it shorter.'], 413);
  $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  if (!in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime', 'application/octet-stream'], true)) post_refuse(['ok' => false, 'error' => 'That file isn\'t a video ClipNow can post.'], 422);
  $isWebm = $mime === 'video/webm';

  $want = array_values(array_unique(array_filter((array)($_POST['platforms'] ?? []), fn($p) => isset(CN_POST_PLATFORMS[$p]))));
  if (!$want) post_refuse(['ok' => false, 'error' => 'Pick at least one connected platform.'], 422);
  if (count($want) > $limit) post_refuse(['ok' => false, 'error' => "Your {$m['plan']} plan posts to up to $limit platforms at a time. Upgrade to Gold to post everywhere at once."], 403);
  if ($isWebm && array_intersect($want, ['instagram', 'facebook'])) post_refuse(['ok' => false, 'error' => 'Instagram and Facebook need an MP4 clip. Your browser saved this one as WebM. Export it again in Chrome, Edge or Safari.'], 422);

  $sets = cn_up_sets($m);   // [set => [platform => ['connected', 'name']]]
  $draftTk = ($_POST['tiktok_mode'] ?? '') === 'draft' && in_array('tiktok', $want, true);
  $pin = (int)($_POST['set'] ?? 0);
  if ($pin > 0 && !isset($sets[$pin])) $pin = 0;

  $caps = (array)($_POST['caption'] ?? []);
  $cap = function (string $p) use ($caps): string { return trim((string)($caps[$p] ?? '')); };
  $limits = ['tiktok' => 2200, 'youtube' => 5000, 'instagram' => 2200, 'facebook' => 2200, 'x' => 280];
  foreach ($want as $p) if (mb_strlen($cap($p)) > $limits[$p]) post_refuse(['ok' => false, 'error' => CN_POST_PLATFORMS[$p] . ' caption is too long.'], 422);

  $when = trim((string)($_POST['schedule'] ?? ''));
  $tz = trim((string)($_POST['tz'] ?? 'UTC'));
  $scheduled = 0;
  if ($when !== '') {
    try { $dt = new DateTime($when, new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC')); }
    catch (Throwable $e) { post_refuse(['ok' => false, 'error' => 'That date and time isn\'t valid.'], 422); }
    $scheduled = $dt->getTimestamp();
    if ($scheduled < time() + 300) post_refuse(['ok' => false, 'error' => 'Pick a time at least 5 minutes from now.'], 422);
    if ($scheduled > time() + 60 * 86400) post_refuse(['ok' => false, 'error' => 'You can schedule up to 60 days ahead.'], 422);
  }

  // Pick an account for each platform: a connected one that still has room under the platform's daily
  // limit, preferring the least-used, so posts spread across the member's accounts.
  $groups = []; $used = [];
  foreach ($want as $p) {
    $cands = [];
    foreach ($sets as $i => $acc) if ($acc[$p]['connected'] && (!$pin || $pin === $i)) $cands[] = $i;
    if (!$cands) post_refuse(['ok' => false, 'error' => 'Connect ' . CN_POST_PLATFORMS[$p] . ' first.'], 422);
    $best = 0; $bestN = PHP_INT_MAX; $soonest = 0;
    foreach ($cands as $i) {
      $asDraft = $draftTk && $p === 'tiktok';
      [$fits, $next] = cn_post_cap_check($m, $p, $scheduled, $i, $asDraft);
      if (!$fits) { $soonest = $soonest ? min($soonest, $next) : $next; continue; }
      $n = cn_post_recent_count($m, $p, $i, $scheduled);
      if ($n < $bestN) { $best = $i; $bestN = $n; }
    }
    if (!$best && $draftTk && $p === 'tiktok') {
      post_refuse(['ok' => false, 'cap' => $p, 'draft' => true, 'next_slot' => $soonest,
        'error' => 'TikTok drafts are full for now (TikTok allows about ' . CN_DRAFT_CAPS['tiktok'] . ' clips waiting in drafts per account in 24 hours).'], 429);
    }
    if (!$best) {
      $nAcc = count($cands); $max = cn_account_sets($m);
      $more = $nAcc < $max ? ' Or connect another ' . CN_POST_PLATFORMS[$p] . ' account (your plan allows ' . $max . ') to post more.'
        : ($max < 3 ? ' Platinum connects up to 3 ' . CN_POST_PLATFORMS[$p] . ' accounts, and Corporate up to 10.' : '');
      post_refuse(['ok' => false, 'cap' => $p, 'next_slot' => $soonest,
        'error' => ($nAcc > 1 ? 'All ' . $nAcc . ' of your ' . CN_POST_PLATFORMS[$p] . ' accounts are' : 'Your ' . CN_POST_PLATFORMS[$p] . ' account is')
          . ' at ' . CN_POST_PLATFORMS[$p] . '\'s limit of ' . cn_daily_caps()[$p] . ' posts per account in 24 hours. Schedule it for later (the next opening is shown) or turn ' . CN_POST_PLATFORMS[$p] . ' off for this clip.' . $more], 429);
    }
    $groups[$best][] = $p;
    $used[$p] = $sets[$best][$p]['name'] ?: ('account set ' . $best);
  }

  // One upload per account set.
  $first = $cap($want[0]);
  $title = mb_substr(trim(strtok($first, "\n") ?: 'New clip'), 0, 100);
  $baseKey = preg_match('/^[A-Za-z0-9_-]{8,80}$/', (string)($_POST['key'] ?? '')) ? (string)$_POST['key'] : '';
  $rids = []; $jobs = [];
  foreach ($groups as $set => $plats) {
    $user = cn_up_username($m, $set);
    $fields = [
      'user' => $user,
      'video' => new CURLFile($f['tmp_name'], $isWebm ? 'video/webm' : 'video/mp4', $isWebm ? 'clip.webm' : 'clip.mp4'),
      'title' => $title,
      'async_upload' => 'true',
    ];
    foreach ($plats as $p) {
      $c = $cap($p);
      if ($p === 'youtube') {
        $fields['youtube_title'] = mb_substr(trim(strtok($c, "\n") ?: $title), 0, 100);
        $fields['youtube_description'] = $c;
        $fields['privacyStatus'] = 'public';
      } else {
        $fields[$p . '_title'] = $c;
      }
    }
    if (in_array('tiktok', $plats, true)) {
      if ($draftTk) $fields['post_mode'] = 'MEDIA_UPLOAD';   // to the member's TikTok drafts/inbox
      else $fields['privacy_level'] = 'PUBLIC_TO_EVERYONE';
    }
    if (in_array('instagram', $plats, true)) $fields['media_type'] = 'REELS';
    if (in_array('facebook', $plats, true)) {
      $page = cn_up_facebook_page($user);
      if ($page === '') post_refuse(['ok' => false, 'error' => 'Facebook Reels post to a Facebook Page, and none is connected' . ($set > 1 ? ' on account set ' . $set : '') . '. Reconnect Facebook and pick a Page.'], 422);
      $fields['facebook_page_id'] = $page;
      $fields['facebook_media_type'] = 'REELS';
    }
    if ($scheduled) $fields['scheduled_date'] = gmdate('Y-m-d\TH:i:s\Z', $scheduled);

    $multipart = [];
    foreach ($fields as $k => $v) $multipart[] = [$k, $v];
    foreach ($plats as $p) $multipart[] = ['platform[]', $p];
    $idem = $baseKey !== '' ? ['Idempotency-Key: cn-' . $baseKey . ($set > 1 ? '-s' . $set : '')] : [];
    $r = cn_up('POST', '/upload', $multipart, true, $idem);

    $entry = ['platforms' => $plats, 'title' => $title, 'scheduled' => $scheduled, 'set' => $set,
              'draft' => $draftTk && in_array('tiktok', $plats, true) ? ['tiktok'] : [],
              'request_id' => (string)($r['request_id'] ?? ''), 'job_id' => (string)($r['job_id'] ?? '')];
    cn_post_log($m, $entry);
    $rids[] = $entry['request_id']; $jobs[] = $entry['job_id'];
  }
  $GLOBALS['cn_post_done'] = true;
  post_note(['step' => 'sent', 'customer' => (string)($m['customer_id'] ?? ''), 'seconds' => round(microtime(true) - $GLOBALS['cn_post_t0'], 1), 'request_ids' => $rids]);
  cn_json_out(['ok' => true, 'scheduled' => $scheduled, 'requestId' => $rids[0] ?? '', 'requestIds' => $rids, 'jobId' => $jobs[0] ?? '', 'used' => $used, 'drafted' => $draftTk ? ['tiktok'] : []]);
} catch (Throwable $e) {
  $msg = $e->getMessage();
  if (($_POST['tiktok_mode'] ?? '') !== 'draft') cn_learn_cap($msg);
  if (stripos($msg, 'daily posting cap') !== false) post_refuse(['ok' => false, 'error' => $msg], 429);
  if (stripos($msg, 'rate limit') !== false) post_refuse(['ok' => false, 'retry' => 30, 'error' => 'The posting service is busy. Wait about 30 seconds and try again.'], 429);
  post_refuse(['ok' => false, 'error' => $msg], 502);
}
