<?php
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: analytics.php' . ($query ? ('?' . $query) : ''));
exit;
