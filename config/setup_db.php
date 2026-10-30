<?php
// setup_db.php - Clean Database Re-initialization
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/db_connect.php';

try {
    $pdo->exec("DROP TABLE IF EXISTS blood_bags");
    $pdo->exec("DROP TABLE IF EXISTS donors");
    $pdo->exec("DROP TABLE IF EXISTS users");

    // 1. Donors Table
    $pdo->exec("
        CREATE TABLE donors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT DEFAULT 'Anonymous Donor',
            first_name TEXT DEFAULT 'Anonymous',
            last_name TEXT DEFAULT 'Donor',
            blood_type TEXT DEFAULT 'O+',
            type TEXT DEFAULT 'O+',
            last_donation TEXT,
            is_deleted INTEGER DEFAULT 0
        )
    ");

    // 2. Blood Bags Table
    $pdo->exec("
        CREATE TABLE blood_bags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            donor_id INTEGER NOT NULL,
            blood_type TEXT DEFAULT 'O+',
            type TEXT DEFAULT 'O+',
            component TEXT DEFAULT 'Red cells',
            volume_ml INTEGER DEFAULT 450,
            status TEXT DEFAULT 'Stored',
            collection_date TEXT DEFAULT (CURRENT_DATE),
            expiry_date TEXT DEFAULT (CURRENT_DATE),
            is_deleted INTEGER DEFAULT 0,
            FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE
        )
    ");

    // 3. System Users Table
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL
        )
    ");

    // Default admin user
    $stmtUser = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmtUser->execute(['admin', password_hash('bloodbank.admin', PASSWORD_DEFAULT)]);

    // Performance Indexes
    $pdo->exec("CREATE INDEX idx_bags_donor_id ON blood_bags(donor_id)");
    $pdo->exec("CREATE INDEX idx_bags_type_status ON blood_bags(blood_type, status)");

    echo "<div style='font-family: sans-serif; padding: 20px; border: 1px solid #4CAF50; background: #e8f5e9; border-radius: 8px; color: #2e7d32;'>";
    echo "<h2>✅ Database Setup Successful!</h2>";
    echo "<p>Nabuong muli ang <code>database.sqlite</code> gamit ang tamang schema para sa <code>donors</code>, <code>blood_bags</code>, at <code>users</code>.</p>";
    echo "</div>";
} catch (Exception $e) {
    echo "<div style='font-family: sans-serif; padding: 20px; border: 1px solid #f44336; background: #ffebee; border-radius: 8px; color: #c62828;'>";
    echo "<h2>❌ Database Setup Error</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>