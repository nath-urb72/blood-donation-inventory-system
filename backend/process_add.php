<?php
// process_add.php

require_once __DIR__ . '/config/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $rawName   = trim($_POST['name'] ?? $_POST['first_name'] ?? 'Anonymous');
        $lastName  = trim($_POST['last_name'] ?? '');
        $bloodType = trim($_POST['blood_type'] ?? $_POST['type'] ?? 'O+');
        $component = trim($_POST['component'] ?? 'Red cells');
        $volume    = (int)($_POST['volume_ml'] ?? $_POST['volume'] ?? 450);

        if ($lastName === '') {
            $nameParts = explode(' ', $rawName, 2);
            $firstName = $nameParts[0];
            $lastName  = $nameParts[1] ?? 'Donor';
        } else {
            $firstName = $rawName;
        }

        if (!empty($_POST['expiry_date'])) {
            $expiry = $_POST['expiry_date'];
        } else {
            if ($component === 'Platelets') {
                $expiry = date('Y-m-d', strtotime('+5 days'));
            } elseif ($component === 'Plasma') {
                $expiry = date('Y-m-d', strtotime('+365 days'));
            } else {
                $expiry = date('Y-m-d', strtotime('+35 days'));
            }
        }

        $fullName = trim($firstName . ' ' . $lastName);

        $stmtDonor = $pdo->prepare("
            INSERT INTO donors (name, first_name, last_name, blood_type, type) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtDonor->execute([$fullName, $firstName, $lastName, $bloodType, $bloodType]);
        $donorId = $pdo->lastInsertId();

        $stmtBag = $pdo->prepare("
            INSERT INTO blood_bags (donor_id, blood_type, type, component, volume_ml, expiry_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'Stored')
        ");
        $stmtBag->execute([$donorId, $bloodType, $bloodType, $component, $volume, $expiry]);

        $pdo->commit();

        header("Location: ../index.html#database");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("Error saving entry: " . htmlspecialchars($e->getMessage()));
    }
}
?>