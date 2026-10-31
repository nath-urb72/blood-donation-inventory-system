<?php
// CHANGED
// config/setup_db.php: run ONCE from localhost. WARNING: wipes all data.
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/db_connect.php';

$adminPassword = 'bloodbank.admin';   // CHANGE THIS to your own password

try {
    $pdo->exec('PRAGMA journal_mode = DELETE;');   // undo WAL mode from the old setup
    $pdo->exec('DROP TABLE IF EXISTS blood_bags');
    $pdo->exec('DROP TABLE IF EXISTS donors');
    $pdo->exec('DROP TABLE IF EXISTS users');

    $pdo->exec("
        CREATE TABLE donors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            blood_type TEXT NOT NULL CHECK (blood_type IN ('A+','A-','B+','B-','AB+','AB-','O+','O-')),
            is_deleted INTEGER NOT NULL DEFAULT 0
        )");

    $pdo->exec("
        CREATE TABLE blood_bags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            donor_id INTEGER NOT NULL,
            component TEXT NOT NULL DEFAULT 'Red cells' CHECK (component IN ('Red cells','Plasma','Platelets')),
            volume_ml INTEGER NOT NULL DEFAULT 450,
            status TEXT NOT NULL DEFAULT 'Stored' CHECK (status IN ('Stored','Testing','Expired','Used')),
            collection_date TEXT NOT NULL,
            expiry_date TEXT NOT NULL,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE
        )");

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL
        )");

    $pdo->exec('CREATE INDEX idx_bags_donor ON blood_bags(donor_id)');
    $pdo->exec('CREATE INDEX idx_bags_status ON blood_bags(status, is_deleted)');

    // Admin account
    $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
        ->execute(['admin', password_hash($adminPassword, PASSWORD_DEFAULT)]);

    // Sample data so the demo isn't empty
    $insDonor = $pdo->prepare('INSERT INTO donors (name, blood_type) VALUES (?, ?)');
    $insBag   = $pdo->prepare('INSERT INTO blood_bags (donor_id, component, status, collection_date, expiry_date) VALUES (?, ?, ?, ?, ?)');

    $ids = [];
    foreach ([['Juan Dela Cruz', 'O+'], ['Maria Santos', 'A+'], ['Pedro Garcia', 'B+'],
              ['Ana Reyes', 'AB-'], ['Luis Ramos', 'O-'], ['Liza Mendoza', 'A-']] as [$n, $t]) {
        $insDonor->execute([$n, $t]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    // [donor index, component, days ago, status]
    foreach ([[0, 'Red cells', 3, 'Stored'],  [0, 'Plasma', 3, 'Stored'],  [1, 'Red cells', 10, 'Stored'],
              [2, 'Platelets', 2, 'Testing'], [3, 'Red cells', 20, 'Stored'], [4, 'Red cells', 5, 'Stored'],
              [5, 'Plasma', 40, 'Stored'],    [1, 'Platelets', 9, 'Stored']] as [$di, $comp, $ago, $st]) {
        $date = date('Y-m-d', strtotime("-$ago days"));
        $insBag->execute([$ids[$di], $comp, $st, $date, expiry_for($comp, $date)]);
    }

    echo 'Database ready. Log in as <b>admin</b> with the password set in this file.';
} catch (Exception $e) {
    echo 'Setup error: ' . htmlspecialchars($e->getMessage());
}