<?php
// backend/logout.php
header('Content-Type: application/json');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION = [];
session_destroy();
echo json_encode(['success' => true]);