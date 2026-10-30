<?php
// Top of dashboard.php
require __DIR__ . '/backend/auth_check.php';
require __DIR__ . '/backend/db_connect.php';


// 1. Get total bags
$stmt_total = $pdo->query("SELECT COUNT(*) FROM blood_bags");
$total_bags = $stmt_total->fetchColumn();

// 2. Get counts grouped by blood type (for your bar chart)
$stmt_types = $pdo->query("SELECT blood_type, COUNT(*) as count FROM blood_bags GROUP BY blood_type");
$type_counts = $stmt_types->fetchAll(PDO::FETCH_ASSOC);
?>