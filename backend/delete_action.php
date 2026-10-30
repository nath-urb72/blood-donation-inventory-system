<?php
// delete_action.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/db_connect.php';

if (isset($_GET['id'])) {
    $bag_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE blood_bags SET is_deleted = 1 WHERE id = ?");
    $stmt->execute([$bag_id]);
}

header("Location: ../index.html#database");
exit();
?>