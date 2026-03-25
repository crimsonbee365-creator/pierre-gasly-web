<?php
header('Content-Type: application/json');
$host = getenv('DB_HOST') ?: 'not set';
echo json_encode([
    'status' => 'ok',
    'php'    => PHP_VERSION,
    'db_host_set' => $host !== 'not set',
    'time'   => date('Y-m-d H:i:s')
]);
