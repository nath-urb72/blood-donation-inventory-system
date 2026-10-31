<?php
// CHANGED
// config/db_connect.php
date_default_timezone_set('Asia/Manila');   // change if your timezone differs

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    error_log('[db] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

// Shelf life by component (used by the API and the seed data)
function expiry_for(string $component, string $collected): string {
    $days = ['Red cells' => 35, 'Plasma' => 365, 'Platelets' => 5][$component] ?? 35;
    return date('Y-m-d', strtotime("+$days days", strtotime($collected)));
}