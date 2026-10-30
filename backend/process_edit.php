<?php
// process_edit.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $bag_id = (int)($_POST['bag_id'] ?? 0);
    $new_status = $_POST['status'] ?? 'Stored';

    $stmt = $pdo->prepare("UPDATE blood_bags SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $bag_id]);

    header("Location: ../index.html#database");
    exit();
}
?>