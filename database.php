<?php
// Top of database.php
require 'includes/auth_check.php';
require 'includes/db_connect.php';

// Fetch all bags and join with the donors table to get the names
$stmt = $pdo->query("
    SELECT blood_bags.id, blood_bags.blood_type, blood_bags.volume_ml, blood_bags.collection_date, blood_bags.expiry_date, blood_bags.status, 
           donors.first_name, donors.last_name 
    FROM blood_bags 
    JOIN donors ON blood_bags.donor_id = donors.id 
    ORDER BY blood_bags.collection_date DESC
");
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>