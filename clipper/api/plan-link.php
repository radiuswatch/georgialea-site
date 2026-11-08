<?php
// GET /api/plan-link.php?plan=corporate → the Corporate checkout link the admin portal created in Stripe.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
$link = (string)(cn_config()['corporate_link'] ?? '');
if (($_GET['plan'] ?? '') !== 'corporate' || $link === '') cn_json_out(['ok' => false, 'error' => 'Corporate checkout isn’t set up yet.'], 404);
header('Cache-Control: public, max-age=300');
cn_json_out(['ok' => true, 'url' => $link]);
