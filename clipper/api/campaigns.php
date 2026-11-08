<?php
// Campaigns (Platinum only). Creators post a campaign; Platinum clippers join, post clips and
// report views with a screenshot; the creator approves views and pays clippers directly.
//
// GET  ?list=1                  → campaigns the member can see
// GET  ?id=<id>                 → one campaign (owners also get every clipper and submission)
// GET  ?shot=<sub>&id=<id>      → a submission's screenshot (owner or the clipper who sent it)
// POST action=create|edit|join|submit|review|paid|report  (JSON, or multipart for submit)
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';

function camp_str($v, int $max): string { return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? ''), 0, $max); }
function camp_line($v, int $max): string { return camp_str(preg_replace('/\s+/u', ' ', (string)$v), $max); }
function camp_url($v): string {
  $u = trim((string)$v);
  if ($u === '') return '';
  if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
  return filter_var($u, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $u) ? mb_substr($u, 0, 500) : '';
}
function camp_money($v): int { $n = (float)str_replace([',', '$'], '', (string)$v); return max(0, (int)round($n * 100)); }

/** What a member may see of a campaign. */
function camp_public(array $c, string $me): array {
  $mine = $c['owner'] === $me;
  $joined = isset($c['clippers'][$me]);
  $approvedViews = 0; $pending = 0;
  foreach ($c['submissions'] as $s) { $approvedViews += (int)$s['approved_views']; if ($s['status'] === 'pending') $pending++; }
  $owed = cn_camp_owed($c);
  $out = [
    'id' => $c['id'], 'title' => $c['title'], 'creator' => $c['creator'], 'about' => $c['about'], 'rules' => $c['rules'],
    'rate_cents' => $c['rate_cents'], 'budget_cents' => $c['budget_cents'],
    'budget_left_cents' => $c['budget_cents'] ? max(0, $c['budget_cents'] - $owed) : null,
    'platforms' => $c['platforms'], 'tags' => $c['tags'], 'ends' => $c['ends'], 'pay_note' => $c['pay_note'],
    'status' => camp_status($c), 'created' => $c['created'],
    'clippers' => count($c['clippers']), 'approved_views' => $approvedViews,
    'mine' => $mine, 'joined' => $joined,
    'image' => !empty($c['image']) ? 'api/campaigns.php?img=' . $c['id'] . '&v=' . (int)($c['image_at'] ?? 0) : '',
  ];
  if ($mine || $joined) $out['source'] = $c['source'];
  if ($joined) {
    $out['me'] = [
      'handle' => $c['clippers'][$me]['handle'], 'name' => $c['clippers'][$me]['name'],
      'owed_cents' => cn_camp_owed($c, $me), 'paid_cents' => cn_camp_paid($c, $me),
      'submissions' => array_values(array_map('camp_sub_out', array_filter($c['submissions'], fn($s) => $s['email'] === $me))),
    ];
  }
  if ($mine) {
    $out['pending'] = $pending;
    $out['owed_cents'] = $owed; $out['paid_cents'] = cn_camp_paid($c);
    $out['all_clippers'] = [];
    foreach ($c['clippers'] as $email => $cl) {
      $out['all_clippers'][] = ['email' => $email, 'name' => $cl['name'], 'handle' => $cl['handle'], 'joined' => $cl['joined'],
        'owed_cents' => cn_camp_owed($c, $email), 'paid_cents' => cn_camp_paid($c, $email)];
    }
    $out['submissions'] = array_values(array_map(function ($s) use ($c) {
      $o = camp_sub_out($s); $o['email'] = $s['email']; $o['name'] = $c['clippers'][$s['email']]['name'] ?? $s['email']; return $o;
    }, $c['submissions']));
    $out['reports'] = count($c['reports'] ?? []);
  }
  return $out;
}
function camp_sub_out(array $s): array {
  return ['id' => $s['id'], 'platform' => $s['platform'], 'url' => $s['url'], 'views' => $s['views'], 'status' => $s['status'],
    'approved_views' => $s['approved_views'], 'note' => $s['note'], 'created' => $s['created'], 'updated' => $s['updated'], 'shot' => $s['shot'] !== ''];
}
function camp_status(array $c): string {
  if (!empty($c['hidden'])) return 'hidden';
  if ($c['status'] === 'closed') return 'closed';
  if ($c['ends'] && $c['ends'] < time()) return 'ended';
  if ($c['budget_cents'] && cn_camp_owed($c) >= $c['budget_cents']) return 'full';
  return 'open';
}
function camp_body(): array {
  if (!empty($_POST)) return $_POST;
  $j = json_decode((string)file_get_contents('php://input'), true);
  return is_array($j) ? $j : [];
}
/** Campaign fields from the create/edit form. */
function camp_fields(array $in, bool $editing): array|string {
  $f = [
    'title' => camp_line($in['title'] ?? '', 100),
    'creator' => camp_line($in['creator'] ?? '', 60),
    'about' => camp_str($in['about'] ?? '', 2000),
    'rules' => camp_str($in['rules'] ?? '', 2000),
    'source' => camp_url($in['source'] ?? ''),
    'rate_cents' => camp_money($in['rate'] ?? ''),
    'budget_cents' => camp_money($in['budget'] ?? ''),
    'platforms' => array_values(array_intersect(array_keys(CN_CAMP_PLATFORMS), (array)($in['platforms'] ?? []))),
    'tags' => camp_line($in['tags'] ?? '', 200),
    'pay_note' => camp_line($in['pay_note'] ?? '', 200),
    'ends' => 0,
  ];
  $ends = trim((string)($in['ends'] ?? ''));
  if ($ends !== '') {
    $t = strtotime($ends . ' 23:59:59');
    if (!$t) return 'Pick a valid end date.';
    if (!$editing && $t < time()) return 'The end date has passed. Pick a later date or leave it empty.';
    $f['ends'] = $t;
  }
  if ($f['title'] === '') return 'Give the campaign a title.';
  if ($f['creator'] === '') return 'Add your creator name (your channel or show).';
  if ($f['source'] === '' && trim((string)($in['source'] ?? '')) !== '') return 'The content link doesn\'t look like a web address.';
  if ($f['source'] === '') return 'Add a link where clippers can get your videos (Google Drive, Dropbox, YouTube channel…).';
  if ($f['rate_cents'] < 1) return 'Set how much you pay per 1,000 views (for example 1.00).';
  if ($f['rate_cents'] > 100000) return 'That rate looks too high. It\'s per 1,000 views.';
  if (!$f['platforms']) return 'Pick at least one platform.';
  if ($f['pay_note'] === '') return 'Say how and when you pay clippers (for example "PayPal, every Friday").';
  return $f;
}

try {
  $m = cn_current_member();
  $me = $m ? strtolower($m['email']) : '';
  $plat = cn_is_platinum($m);

  /* ---------- Reads ---------- */
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$m) cn_json_out(['ok' => false, 'signedIn' => false, 'error' => 'Please sign in first.'], 401);
    if (!$plat) cn_json_out(['ok' => false, 'signedIn' => true, 'platinum' => false, 'error' => 'Campaigns are part of the Platinum plan.'], 403);

    if (isset($_GET['img'])) {
      $c = cn_camp_load((string)$_GET['img']);
      if (!$c || empty($c['image']) || (!empty($c['hidden']) && $c['owner'] !== $me)) { http_response_code(404); exit; }
      $file = cn_dir('campaign-images') . '/' . basename($c['image']);
      if (!is_file($file)) { http_response_code(404); exit; }
      header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=604800'); header('X-Content-Type-Options: nosniff');
      readfile($file); exit;
    }
    if (isset($_GET['shot'])) {
      $c = cn_camp_load((string)($_GET['id'] ?? ''));
      $sid = (string)$_GET['shot'];
      $sub = null; foreach ($c['submissions'] ?? [] as $s) if ($s['id'] === $sid) $sub = $s;
      if (!$c || !$sub || $sub['shot'] === '' || ($c['owner'] !== $me && $sub['email'] !== $me)) { http_response_code(404); exit; }
      $file = cn_dir('campaign-shots') . '/' . basename($sub['shot']);
      if (!is_file($file)) { http_response_code(404); exit; }
      header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'image/jpeg'));
      header('Cache-Control: private, max-age=3600');
      header('X-Content-Type-Options: nosniff');
      readfile($file); exit;
    }
    if (isset($_GET['id'])) {
      $c = cn_camp_load((string)$_GET['id']);
      if (!$c || (!empty($c['hidden']) && $c['owner'] !== $me)) cn_json_out(['ok' => false, 'error' => 'That campaign isn\'t available.'], 404);
      cn_json_out(['ok' => true, 'platinum' => true, 'campaign' => camp_public($c, $me)]);
    }
    $list = [];
    foreach (cn_camp_all() as $c) {
      if (!empty($c['hidden']) && $c['owner'] !== $me) continue;
      $p = camp_public($c, $me);
      unset($p['rules'], $p['submissions'], $p['all_clippers']);
      $p['about'] = mb_substr($p['about'], 0, 220);
      $list[] = $p;
    }
    cn_json_out(['ok' => true, 'platinum' => true, 'me' => $me, 'campaigns' => $list, 'quota' => cn_camp_quota($m)]);
  }

  /* ---------- Changes ---------- */
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
  if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) cn_json_out(['ok' => false, 'error' => 'That file is too big (8 MB max for images, 6 MB for screenshots).'], 413);
  if (!$m) cn_json_out(['ok' => false, 'error' => 'Please sign in first.'], 401);
  if (!$plat) cn_json_out(['ok' => false, 'error' => 'Campaigns are part of the Platinum plan.'], 403);
  $in = camp_body();
  $act = (string)($in['action'] ?? '');
  $id = (string)($in['id'] ?? '');
  if (!cn_rate_ok('camp:' . $me, 120)) cn_json_out(['ok' => false, 'error' => 'Too many changes in a short time. Wait a few minutes and try again.'], 429);

  if ($act === 'create') {
    $f = camp_fields($in, false);
    if (is_string($f)) cn_json_out(['ok' => false, 'error' => $f], 422);
    $q = cn_camp_quota($m);
    if ($q['limit'] !== null && $q['left'] < 1) cn_json_out(['ok' => false, 'error' => 'Platinum includes ' . $q['limit'] . ' new campaigns a month, and you’ve used them all. You can start more on ' . date('F j', $q['resets']) . ', or upgrade to Corporate for unlimited campaigns.'], 422);
    $c = cn_camp_create($f + ['owner' => $me, 'status' => 'open', 'hidden' => false, 'created' => time(), 'updated' => time(),
      'clippers' => (object)[], 'submissions' => [], 'paid' => (object)[], 'reports' => []]);
    cn_camp_note_start($me);
    cn_camp_log(['event' => 'created', 'id' => $c['id'], 'title' => $c['title'], 'email' => $me]);
    $c = cn_camp_load($c['id']);
    cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
  }

  $c = cn_camp_load($id);
  if (!$c || (!empty($c['hidden']) && $c['owner'] !== $me)) cn_json_out(['ok' => false, 'error' => 'That campaign isn\'t available.'], 404);
  $owner = $c['owner'] === $me;

  if ($act === 'edit') {
    if (!$owner) cn_json_out(['ok' => false, 'error' => 'Only the campaign\'s creator can change it.'], 403);
    if (isset($in['status']) && count($in) <= 3) {   // just open/close
      $st = $in['status'] === 'closed' ? 'closed' : 'open';
      $c = cn_camp_update($id, function (&$c) use ($st) { $c['status'] = $st; });
    } else {
      $f = camp_fields($in, true);
      if (is_string($f)) cn_json_out(['ok' => false, 'error' => $f], 422);
      $c = cn_camp_update($id, function (&$c) use ($f) { $c = array_merge($c, $f); });
    }
    cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
  }

  if ($act === 'image' || $act === 'image_remove') {
    if (!$owner) cn_json_out(['ok' => false, 'error' => 'Only the campaign\'s creator can change its image.'], 403);
    if ($act === 'image_remove') {
      if (!empty($c['image'])) @unlink(cn_dir('campaign-images') . '/' . basename($c['image']));
      $c = cn_camp_update($id, function (&$c) { $c['image'] = ''; $c['image_at'] = time(); });
      cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
    }
    $f = $_FILES['image'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) cn_json_out(['ok' => false, 'error' => 'The image didn\'t upload. Please try again.'], 422);
    if ($f['size'] > CN_CAMP_IMG_BYTES) cn_json_out(['ok' => false, 'error' => 'That image is too big (8 MB max).'], 413);
    $name = cn_camp_save_image($id, $f['tmp_name']);
    $c = cn_camp_update($id, function (&$c) use ($name) { $c['image'] = $name; $c['image_at'] = time(); });
    cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
  }

  if ($act === 'join') {
    if ($owner) cn_json_out(['ok' => false, 'error' => 'This is your own campaign.'], 422);
    $handle = camp_line($in['handle'] ?? '', 120);
    $name = camp_line($in['name'] ?? '', 60);
    if ($name === '') cn_json_out(['ok' => false, 'error' => 'Add the name the creator will see.'], 422);
    if ($handle === '') cn_json_out(['ok' => false, 'error' => 'Add where the creator should pay you (for example PayPal: you@email.com or Cash App: $you).'], 422);
    $c = cn_camp_update($id, function (&$c) use ($me, $handle, $name) {
      $st = camp_status($c);
      if (!isset($c['clippers'][$me]) && $st !== 'open') return 'This campaign isn\'t taking new clippers.';
      $c['clippers'] = (array)$c['clippers'];
      $c['clippers'][$me] = ['name' => $name, 'handle' => $handle, 'joined' => $c['clippers'][$me]['joined'] ?? time()];
    });
    cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
  }

  if ($act === 'submit') {
    if (!isset($c['clippers'][$me])) cn_json_out(['ok' => false, 'error' => 'Join the campaign first.'], 403);
    $sid = (string)($in['sub'] ?? '');
    $views = (int)preg_replace('/\D/', '', (string)($in['views'] ?? ''));
    $platform = (string)($in['platform'] ?? '');
    $url = camp_url($in['url'] ?? '');
    if ($sid === '') {
      if (!in_array($platform, $c['platforms'], true)) cn_json_out(['ok' => false, 'error' => 'Pick one of the campaign\'s platforms.'], 422);
      if ($url === '') cn_json_out(['ok' => false, 'error' => 'Paste the link to your post.'], 422);
    }
    if ($views < 1) cn_json_out(['ok' => false, 'error' => 'Enter the post\'s current view count.'], 422);
    $f = $_FILES['shot'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) cn_json_out(['ok' => false, 'error' => 'Add a screenshot that shows the view count.'], 422);
    if ($f['size'] > CN_CAMP_SHOT_BYTES) cn_json_out(['ok' => false, 'error' => 'That screenshot is too big (6 MB max).'], 413);
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heic'][$mime] ?? '';
    if ($ext === '') cn_json_out(['ok' => false, 'error' => 'The screenshot must be a photo (JPG, PNG, WebP or HEIC).'], 422);
    $shotName = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], cn_dir('campaign-shots') . '/' . $shotName)) cn_json_out(['ok' => false, 'error' => 'The screenshot didn\'t save. Please try again.'], 500);
    @chmod(cn_dir('campaign-shots') . '/' . $shotName, 0600);
    try {
      $c = cn_camp_update($id, function (&$c) use ($me, $sid, $views, $platform, $url, $shotName) {
        if ($sid === '') {
          foreach ($c['submissions'] as $s) if ($s['url'] === $url) return 'That post is already submitted. Update its views instead.';
          $mineN = count(array_filter($c['submissions'], fn($s) => $s['email'] === $me));
          if ($mineN >= 200) return 'You\'ve reached 200 posts in this campaign.';
          if (camp_status($c) !== 'open') return 'This campaign isn\'t taking new posts.';
          $c['submissions'][] = ['id' => 's' . bin2hex(random_bytes(6)), 'email' => $me, 'platform' => $platform, 'url' => $url,
            'views' => $views, 'shot' => $shotName, 'status' => 'pending', 'approved_views' => 0, 'note' => '', 'created' => time(), 'updated' => time()];
          return null;
        }
        foreach ($c['submissions'] as &$s) {
          if ($s['id'] !== $sid || $s['email'] !== $me) continue;
          if ($views < (int)$s['approved_views']) return 'Views can\'t go below what was already approved (' . number_format((int)$s['approved_views']) . ').';
          $old = $s['shot'];
          $s['views'] = $views; $s['shot'] = $shotName; $s['updated'] = time();
          if ($s['status'] !== 'pending') { $s['prev_status'] = $s['status']; $s['status'] = 'pending'; }
          if ($old && $old !== $shotName) @unlink(cn_dir('campaign-shots') . '/' . basename($old));
          return null;
        }
        return 'That post wasn\'t found.';
      });
    } catch (InvalidArgumentException $e) {
      @unlink(cn_dir('campaign-shots') . '/' . $shotName);
      cn_json_out(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
  }

  if ($act === 'review') {
    if (!$owner) cn_json_out(['ok' => false, 'error' => 'Only the campaign\'s creator can approve views.'], 403);
    $sid = (string)($in['sub'] ?? '');
    $ok = ($in['decision'] ?? '') === 'approve';
    $av = (int)preg_replace('/\D/', '', (string)($in['approved_views'] ?? '0'));
    $note = camp_line($in['note'] ?? '', 200);
    $c = cn_camp_update($id, function (&$c) use ($sid, $ok, $av, $note) {
      foreach ($c['submissions'] as $i => $s) {
        if ($s['id'] !== $sid) continue;
        if ($ok) {
          if ($av < 1) return 'Enter how many views you approve.';
          if ($av > (int)$s['views']) return 'You can\'t approve more views than the clipper reported (' . number_format((int)$s['views']) . ').';
          if ($av < (int)$s['approved_views']) return 'You already approved ' . number_format((int)$s['approved_views']) . ' views on this post; approve at least that many.';
          if ($c['budget_cents']) {
            $others = 0;
            foreach ($c['submissions'] as $j => $o) if ($j !== $i) $others += (int)$o['approved_views'];
            $maxViews = (int)floor(($c['budget_cents'] * 1000 / max(1, $c['rate_cents'])) - $others);
            if ($av > $maxViews) return 'That goes over your budget. You can approve up to ' . number_format(max(0, $maxViews)) . ' more views on this post, or raise the budget.';
          }
          $c['submissions'][$i]['status'] = 'approved'; $c['submissions'][$i]['approved_views'] = $av;
        } else {
          if ((int)$s['approved_views'] > 0) { $c['submissions'][$i]['status'] = 'approved'; $c['submissions'][$i]['note'] = $note ?: 'Latest views not approved.'; return null; }
          $c['submissions'][$i]['status'] = 'rejected';
        }
        $c['submissions'][$i]['note'] = $note;
        return null;
      }
      return 'That post wasn\'t found.';
    });
    cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
  }

  if ($act === 'paid') {
    if (!$owner) cn_json_out(['ok' => false, 'error' => 'Only the campaign\'s creator can record payments.'], 403);
    $who = strtolower(trim((string)($in['email'] ?? '')));
    $amt = camp_money($in['amount'] ?? '');
    if ($amt < 1) cn_json_out(['ok' => false, 'error' => 'Enter the amount you paid.'], 422);
    $c = cn_camp_update($id, function (&$c) use ($who, $amt) {
      if (!isset($c['clippers'][$who])) return 'That clipper isn\'t in this campaign.';
      $c['paid'] = (array)$c['paid'];
      $c['paid'][$who] = (int)($c['paid'][$who] ?? 0) + $amt;
      $c['payments'][] = ['email' => $who, 'cents' => $amt, 'at' => time()];
    });
    cn_json_out(['ok' => true, 'campaign' => camp_public($c, $me)]);
  }

  if ($act === 'report') {
    if ($owner) cn_json_out(['ok' => false, 'error' => 'This is your own campaign.'], 422);
    $why = camp_str($in['reason'] ?? '', 1000);
    if ($why === '') cn_json_out(['ok' => false, 'error' => 'Tell us what\'s wrong.'], 422);
    cn_camp_update($id, function (&$c) use ($me, $why) { $c['reports'][] = ['email' => $me, 'reason' => $why, 'at' => time()]; });
    cn_camp_log(['event' => 'report', 'id' => $id, 'email' => $me, 'reason' => $why]);
    cn_json_out(['ok' => true, 'message' => 'Thanks. We\'ll look into it.']);
  }

  cn_json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (InvalidArgumentException $e) {
  cn_json_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
  cn_json_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
}
