<?php
// Clip sync across a member's devices (clips stored in Cloudflare R2).
//   GET  /api/clips.php                      → {ok, available, days, cap, used, items:[{id,name,size,dur,hook,type,at,meta,url}]}
//   POST {action:'start', id, name, size, type, dur, hook, at, meta} → {ok, put}   (a signed upload link)
//   POST {action:'done',  id}                → {ok}   (checks the upload arrived)
//   POST {action:'meta',  id, meta, hook}    → {ok}   (posting status etc. changed on a device)
//   POST {action:'delete', ids:[...]}        → {ok}
// Only signed-in members with an active plan. Clips expire by plan (Silver 7 days, Gold 14, Platinum and Corporate 30).
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: no-store');

$m = cn_current_member();
$c = cn_r2();
if (!$m || empty($m['active']) || !$c) cn_json_out(['ok' => true, 'available' => false, 'reason' => !$m ? 'signin' : (empty($m['active']) ? 'plan' : 'off')]);
if (!cn_rate_ok('clips:' . strtolower($m['email']), 900)) cn_json_out(['ok' => false, 'error' => 'Too many sync requests. Wait a few minutes.'], 429);

/** Only the fields the Studio uses; kept small. */
function clip_meta($v): array {
  $v = is_array($v) ? $v : [];
  $out = [];
  foreach (['sent', 'manual', 'only', 'postKey'] as $k) if (array_key_exists($k, $v)) $out[$k] = $v[$k];
  $js = json_encode($out);
  return strlen((string)$js) > 6000 ? [] : $out;
}
function clip_public(array $c, array $it): array {
  return ['id' => $it['id'], 'name' => $it['name'], 'size' => $it['size'], 'dur' => $it['dur'], 'hook' => $it['hook'], 'type' => $it['type'], 'at' => $it['at'],
    'meta' => $it['meta'] ?? [], 'mt' => $it['mt'] ?? $it['at'], 'url' => cn_r2_presign($c, 'GET', $it['key'], 7200)];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $items = cn_clip_list($m, $c);
  $used = 0; $out = [];
  foreach ($items as $it) { $used += (int)$it['size']; if (!empty($it['ready'])) $out[] = clip_public($c, $it); }
  usort($out, fn($a, $b) => $a['at'] <=> $b['at']);
  cn_json_out(['ok' => true, 'available' => true, 'days' => cn_clip_days($m), 'cap' => cn_clip_cap($m), 'used' => $used, 'items' => $out]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');
$id = (string)($in['id'] ?? '');
$validId = fn($x) => is_string($x) && preg_match('/^[A-Za-z0-9_-]{6,40}$/', $x);
$items = cn_clip_list($m, $c);

if ($act === 'start') {
  if (!$validId($id)) cn_json_out(['ok' => false, 'error' => 'bad clip'], 422);
  $size = (int)($in['size'] ?? 0); $type = (string)($in['type'] ?? '');
  if ($size < 1 || $size > CN_CLIP_MAX_BYTES) cn_json_out(['ok' => false, 'error' => 'Clips over 60 MB aren’t synced.', 'skip' => true], 422);
  if (!preg_match('#^video/(mp4|webm|quicktime)$#', $type)) $type = 'video/mp4';
  $used = 0; foreach ($items as $k => $it) if ($k !== $id) $used += (int)$it['size'];
  if ($used + $size > cn_clip_cap($m)) cn_json_out(['ok' => false, 'error' => 'Your clip storage is full. Remove some clips to sync more.', 'full' => true], 422);
  $ext = $type === 'video/webm' ? 'webm' : ($type === 'video/quicktime' ? 'mov' : 'mp4');
  $at = min(time(), (int)(($in['at'] ?? 0) / 1000) ?: time());
  if ($at < time() - cn_clip_days($m) * 86400 + 3600) cn_json_out(['ok' => false, 'error' => 'This clip is older than your plan keeps clips, so it stays on this device only.', 'skip' => true], 422);
  $items[$id] = ['id' => $id, 'key' => cn_clip_prefix($m) . $id . '.' . $ext, 'name' => mb_substr((string)($in['name'] ?? 'clip.' . $ext), 0, 120),
    'size' => $size, 'type' => $type, 'dur' => (float)($in['dur'] ?? 0), 'hook' => mb_substr((string)($in['hook'] ?? ''), 0, 200),
    'at' => $at, 'meta' => clip_meta($in['meta'] ?? []), 'mt' => time(), 'ready' => false, 'started' => time()];
  cn_clip_save($m, $items);
  cn_json_out(['ok' => true, 'put' => cn_r2_presign($c, 'PUT', $items[$id]['key'], 3600)]);
}

if ($act === 'done') {
  if (!isset($items[$id])) cn_json_out(['ok' => false, 'error' => 'unknown clip'], 404);
  [$code, , $h] = cn_r2_request($c, 'HEAD', $items[$id]['key']);
  if ($code !== 200) cn_json_out(['ok' => false, 'error' => 'The upload didn’t arrive. It will try again.'], 409);
  $items[$id]['size'] = (int)($h['content-length'] ?? $items[$id]['size']);
  $items[$id]['ready'] = true; unset($items[$id]['started']);
  cn_clip_save($m, $items);
  cn_json_out(['ok' => true]);
}

if ($act === 'meta') {
  if (!isset($items[$id])) cn_json_out(['ok' => false, 'error' => 'unknown clip', 'gone' => true], 404);
  $items[$id]['meta'] = clip_meta($in['meta'] ?? []);
  if (isset($in['hook'])) $items[$id]['hook'] = mb_substr((string)$in['hook'], 0, 200);
  $items[$id]['mt'] = time();
  cn_clip_save($m, $items);
  cn_json_out(['ok' => true]);
}

if ($act === 'delete') {
  $ids = array_slice(array_filter((array)($in['ids'] ?? []), $validId), 0, 200);
  foreach ($ids as $x) if (isset($items[$x])) { cn_r2_request($c, 'DELETE', $items[$x]['key']); unset($items[$x]); }
  cn_clip_save($m, $items);
  cn_json_out(['ok' => true]);
}

cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
