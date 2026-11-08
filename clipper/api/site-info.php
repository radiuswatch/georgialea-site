<?php
// GET /api/site-info.php → public links the coming-soon page shows (set in the admin portal, Site status).
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
header('Cache-Control: public, max-age=300');
$ks = (string)(cn_config()['kickstarter_url'] ?? '');
cn_json_out(['ok' => true, 'kickstarter' => $ks]);
