<?php
// POST /api/hooks.php {topic, clips:[{dur, frames:[base64 JPEG, ...]}]} → {ok, hooks:[string per clip]}
// Writes an on-screen title ("hook") for each clip from a few small still frames, using Claude.
// The frames are used for this one request and not stored. Members with an active plan only.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
@set_time_limit(120);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);

const CN_HOOK_MAX_CLIPS = 10;
const CN_HOOK_MAX_FRAMES = 3;
const CN_HOOK_MAX_FRAME_B64 = 120000;   // about 90 KB per still
const CN_HOOK_DAILY = 40;               // AI title requests per member per day

try {
  $m = cn_current_member();   // membership is re-checked with Stripe every few minutes
  if (!$m) cn_json_out(['ok' => false, 'error' => 'Sign in to write titles with AI.'], 401);
  if (empty($m['active'])) cn_json_out(['ok' => false, 'error' => 'AI titles are included with an active ClipNow plan.'], 403);
  if ((string)(cn_config()['anthropic_key'] ?? '') === '') cn_json_out(['ok' => false, 'error' => 'AI titles aren\'t switched on yet. Please try again later.'], 503);

  $who = strtolower((string)$m['email']);
  if (!cn_rate_ok('hooks:' . $who, 8)) cn_json_out(['ok' => false, 'error' => 'That\'s a lot of AI titles in a few minutes. Wait a bit and try again.'], 429);
  $dayFile = cn_dir('rate') . '/' . hash('sha256', 'hooksday:' . $who . ':' . gmdate('Y-m-d')) . '.json';
  $used = (int)(cn_json_file($dayFile, ['n' => 0])['n'] ?? 0);
  if ($used >= CN_HOOK_DAILY) cn_json_out(['ok' => false, 'error' => 'You\'ve used today\'s AI titles. They reset tomorrow, and you can still type your own.'], 429);

  $in = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($in) || !is_array($in['clips'] ?? null)) cn_json_out(['ok' => false, 'error' => 'bad request'], 400);
  $topic = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($in['topic'] ?? ''))), 0, 120);
  $clips = array_slice(array_values($in['clips']), 0, CN_HOOK_MAX_CLIPS);
  if (!$clips) cn_json_out(['ok' => false, 'error' => 'Find the best moments first.'], 422);

  $content = [];
  foreach ($clips as $i => $c) {
    $frames = array_slice(array_values((array)($c['frames'] ?? [])), 0, CN_HOOK_MAX_FRAMES);
    $dur = max(1, min(600, (int)round((float)($c['dur'] ?? 0))));
    $content[] = ['type' => 'text', 'text' => 'Clip ' . ($i + 1) . " ({$dur} seconds): stills from its beginning, middle and end."];
    foreach ($frames as $f) {
      $f = (string)$f;
      if ($f === '' || strlen($f) > CN_HOOK_MAX_FRAME_B64 || !preg_match('#^[A-Za-z0-9+/]+=*$#', $f)) continue;
      $bin = base64_decode($f, true);
      if ($bin === false || substr($bin, 0, 3) !== "\xFF\xD8\xFF") continue;   // JPEG only
      $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $f]];
    }
  }
  $n = count($clips);
  $content[] = ['type' => 'text', 'text' => ($topic !== '' ? "The creator describes the video as: \"$topic\".\n" : '')
    . "Write one on-screen title for each of the $n clips, in order."];

  $system = "You write the short title that sits on top of a vertical short-form clip (TikTok, YouTube Shorts, Instagram Reels). "
    . "Each title must be about that specific clip, based on what the stills actually show, so a viewer who watches it feels the title was accurate.\n\n"
    . "Rules (they follow the platforms' policies on misleading content):\n"
    . "- Describe or tease what really happens in the clip. Don't promise anything the stills don't support, and don't invent names, quotes, numbers, results or events.\n"
    . "- Only name a person if their name is clearly visible on screen or given in the creator's description.\n"
    . "- No fake urgency or bait such as \"you won't believe\", \"gone wrong\" or \"must watch\" unless it's literally true of the clip.\n"
    . "- Nothing sexual, hateful, violent or shocking for its own sake, and no claims about earnings, health or anyone's private life.\n"
    . "- Plain words, at most 50 characters, no hashtags, no emojis, no quotation marks, not in all capitals.\n"
    . "- Make each clip's title different from the others.\n"
    . "- If the stills don't show much, write an honest, curiosity-raising title about what is visible (the setting, the activity, the reaction).";

  $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['titles'],
    'properties' => ['titles' => ['type' => 'array', 'items' => ['type' => 'string']]]];
  $body = ['model' => CN_AI_MODEL, 'max_tokens' => 4000, 'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
    'fallbacks' => 'default', 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]];
  $r = cn_claude($body, ['server-side-fallback-2026-07-01']);
  file_put_contents($dayFile, json_encode(['n' => $used + 1]), LOCK_EX);

  if (($r['stop_reason'] ?? '') === 'refusal') cn_json_out(['ok' => false, 'error' => 'The AI couldn\'t write titles for these clips. Type your own instead.'], 422);
  $text = '';
  foreach ($r['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
  $titles = (array)(json_decode($text, true)['titles'] ?? []);
  $out = [];
  for ($i = 0; $i < $n; $i++) {
    $t = trim(preg_replace('/\s+/', ' ', str_replace(['"', '“', '”', '#'], '', (string)($titles[$i] ?? ''))));
    if (mb_strlen($t) > 70) $t = rtrim(mb_substr($t, 0, 69)) . '…';
    $out[] = $t;
  }
  if (!array_filter($out)) cn_json_out(['ok' => false, 'error' => 'The AI didn\'t come back with titles. Please try again.'], 502);
  cn_json_out(['ok' => true, 'hooks' => $out, 'left' => max(0, CN_HOOK_DAILY - $used - 1)]);
} catch (Throwable $e) {
  error_log('ClipNow hooks: ' . $e->getMessage());
  cn_json_out(['ok' => false, 'error' => 'AI titles aren\'t available right now. Please try again shortly.'], 503);
}
