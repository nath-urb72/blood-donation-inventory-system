<?php
// create_admin.php
require_once __DIR__ . '/config/db_connect.php';

$username = 'admin';
$password = 'wad1password';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$username, $hashed_password]);
    echo "Admin user created successfully!";
} catch (Exception $e) {
    echo "Notice: Admin user already exists or could not be inserted. Error: " . htmlspecialchars($e->getMessage());
}
?>