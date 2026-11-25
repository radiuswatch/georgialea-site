<?php
// Traffic counter endpoint for clipnow.vip. Data: /home1/thrypjmy/clipnow-data/analytics/cn. See admin/traffic.php.
require_once __DIR__ . '/admin/traffic.php';
tr_collect((getenv('CLIPNOW_HOME') ?: dirname(__DIR__, 2)) . '/clipnow-data/analytics', 'cn', preg_replace('/:\\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))));
