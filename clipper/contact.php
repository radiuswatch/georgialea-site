<?php
// ClipNow contact form → email. Runs on Bluehost (PHP + mail()).
// Change TO_EMAIL to wherever you want messages to arrive.
// FROM_EMAIL must be an address on a domain hosted on this Bluehost account,
// or mail providers will treat the message as spam. Replies go to the customer.
const TO_EMAIL   = 'helpdesk@clipnow.vip';
const FROM_EMAIL = 'helpdesk@clipnow.vip';
const REASONS = [
  'Question about plans',
  'Start a free trial',
  'Billing or cancelling',
  'Technical problem',
  'Help connecting a platform',
  'Clipping tips',
  'Partnership or campaign',
  'Something else',
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function done(int $code, bool $ok, string $msg): void {
  http_response_code($code);
  echo json_encode(['ok' => $ok, 'message' => $msg]);
  exit;
}
// One line, no control characters (stops header injection)
function clean_line(string $s, int $max): string {
  $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
  return mb_substr(trim($s), 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') done(405, false, 'Send the form to contact us.');

$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) $data = $_POST;

// Spam traps: bots fill the hidden "website" field and submit instantly
if (!empty($data['website'])) done(200, true, 'Thanks! We got your message.');
$elapsed = (int)($data['elapsed'] ?? 0);
if ($elapsed > 0 && $elapsed < 2500) done(400, false, 'That was quick. Please wait a moment and send again.');

$name    = clean_line((string)($data['name'] ?? ''), 80);
$email   = clean_line((string)($data['email'] ?? ''), 120);
$reason  = clean_line((string)($data['reason'] ?? ''), 60);
$message = mb_substr(trim(str_replace("\r", '', (string)($data['message'] ?? ''))), 0, 4000);
$page    = clean_line((string)($data['plan'] ?? ''), 60);

if ($name === '')                                   done(422, false, 'Please add your name.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))     done(422, false, 'Please check your email address so we can reply.');
if (!in_array($reason, REASONS, true))              done(422, false, 'Please pick a reason.');
if (mb_strlen($message) < 10)                       done(422, false, 'Please tell us a little more about your issue.');

// Light rate limit: 5 messages per address per hour
$dir = sys_get_temp_dir() . '/clipnow-contact';
@mkdir($dir, 0700, true);
$ipFile = $dir . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x');
$hits = array_filter(explode("\n", @file_get_contents($ipFile) ?: ''), fn($t) => $t !== '' && (int)$t > time() - 3600);
if (count($hits) >= 5) done(429, false, 'You\'ve sent a few messages already. We\'ll reply soon, or try again in an hour.');
$hits[] = (string)time();
@file_put_contents($ipFile, implode("\n", $hits));

$subject = 'ClipNow: ' . $reason . ' (' . $name . ')';
$body  = "Name:   $name\nEmail:  $email\nReason: $reason\n";
if ($page !== '') $body .= "Plan:   $page\n";
$body .= "Sent:   " . gmdate('Y-m-d H:i') . " UTC\n\n$message\n";

$headers = [
  'From: ClipNow <' . FROM_EMAIL . '>',
  'Reply-To: "' . str_replace(['"', '\\', '<', '>', ':', ';', ','], '', $name) . '" <' . $email . '>',
  'Content-Type: text/plain; charset=UTF-8',
  'X-Mailer: ClipNow contact form',
];
$sent = getenv('CLIPNOW_MAIL_FILE')   // local testing: write instead of sending
  ? (bool)file_put_contents(getenv('CLIPNOW_MAIL_FILE'), "To: " . TO_EMAIL . "\n" . implode("\n", $headers) . "\nSubject: $subject\n\n$body\n-----\n", FILE_APPEND)
  : @mail(TO_EMAIL, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . FROM_EMAIL);

if (!$sent) done(500, false, 'We couldn\'t send that right now. Please email ' . TO_EMAIL . ' instead.');
done(200, true, 'Thanks, ' . $name . '! We got your message and will reply to ' . $email . '.');
