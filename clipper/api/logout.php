<?php
// POST /api/logout.php → signs out on this device.
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_same_origin()) cn_json_out(['ok' => false], 400);
cn_end_session();
cn_json_out(['ok' => true]);
