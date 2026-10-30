<?php
// api.php - Universal API Router (Updated with Direct Blood Type Mapping)
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

session_start();

require_once __DIR__ . '/config/db_connect.php';

$inputJSON = json_decode(file_get_contents('php://input'), true) ?? [];
$request = array_merge($_POST, $_GET, $inputJSON);

$action = $request['action'] ?? '';

// Auto-expire maintenance
try {
    $pdo->exec("UPDATE blood_bags SET status = 'Expired' WHERE status = 'Stored' AND date(expiry_date) < date('now')");
} catch (Exception $e) {}

// 1. Fetch Donors & Bags
if ($action === 'get_data' || ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($action))) {
    try {
        $donorRows = $pdo->query("SELECT * FROM donors WHERE is_deleted = 0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $donors = array_map(function($d) {
            return [
                'id'    => 'D-' . str_pad($d['id'], 3, '0', STR_PAD_LEFT),
                'db_id' => (int)$d['id'],
                'name'  => $d['name'] ?: trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')),
                'type'  => $d['blood_type'] ?: $d['type'] ?: 'O+',
                'last'  => $d['last_donation'] ?: date('Y-m-d')
            ];
        }, $donorRows);

        $bagRows = $pdo->query("
            SELECT b.*, d.blood_type as donor_blood_type 
            FROM blood_bags b 
            LEFT JOIN donors d ON b.donor_id = d.id 
            WHERE b.is_deleted = 0 
            ORDER BY b.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $bags = array_map(function($b) {
            return [
                'id'     => 'B-' . str_pad($b['id'], 4, '0', STR_PAD_LEFT),
                'db_id'  => (int)$b['id'],
                'donor'  => 'D-' . str_pad($b['donor_id'], 3, '0', STR_PAD_LEFT),
                'type'   => $b['blood_type'] ?: $b['donor_blood_type'] ?: 'O+',
                'comp'   => $b['component'] ?: 'Red cells',
                'date'   => $b['collection_date'] ?: date('Y-m-d'),
                'status' => $b['status'] ?: 'Stored'
            ];
        }, $bagRows);

        echo json_encode(['success' => true, 'donors' => $donors, 'bags' => $bags]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 2. Add New Entry
if ($action === 'add_entry' || $action === 'add_unified_entry') {
    try {
        $pdo->beginTransaction();

        $fullName  = trim($request['name'] ?? $request['full_name'] ?? 'Anonymous Donor');
        $bloodType = trim($request['type'] ?? $request['blood_type'] ?? 'O+');
        $component = trim($request['comp'] ?? $request['component'] ?? 'Red cells');
        $dateColl  = trim($request['date'] ?? $request['collection_date'] ?? date('Y-m-d'));
        $status    = trim($request['status'] ?? 'Stored');

        $nameParts = explode(' ', $fullName, 2);
        $firstName = $nameParts[0];
        $lastName  = $nameParts[1] ?? 'Donor';

        $baseTime = strtotime($dateColl) ?: time();
        if ($component === 'Platelets') {
            $expiry = date('Y-m-d', strtotime('+5 days', $baseTime));
        } elseif ($component === 'Plasma') {
            $expiry = date('Y-m-d', strtotime('+365 days', $baseTime));
        } else {
            $expiry = date('Y-m-d', strtotime('+35 days', $baseTime));
        }

        $stmtDonor = $pdo->prepare("
            INSERT INTO donors (name, first_name, last_name, blood_type, type, last_donation) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtDonor->execute([$fullName, $firstName, $lastName, $bloodType, $bloodType, $dateColl]);
        $donorId = $pdo->lastInsertId();

        $stmtBag = $pdo->prepare("
            INSERT INTO blood_bags (donor_id, blood_type, type, component, volume_ml, status, collection_date, expiry_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtBag->execute([$donorId, $bloodType, $bloodType, $component, 450, $status, $dateColl, $expiry]);

        $pdo->commit();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 3. Edit Existing Donor or Bag
if ($action === 'add_donor' || $action === 'edit_donor') {
    try {
        $rawId     = $request['id'] ?? '';
        $numericId = (int)preg_replace('/\D/', '', $rawId);
        $name      = trim($request['name'] ?? 'Anonymous');
        $type      = trim($request['type'] ?? 'O+');
        $last      = trim($request['last'] ?? date('Y-m-d'));

        if ($numericId > 0) {
            $stmt = $pdo->prepare("UPDATE donors SET name = ?, blood_type = ?, type = ?, last_donation = ? WHERE id = ?");
            $stmt->execute([$name, $type, $type, $last, $numericId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO donors (name, blood_type, type, last_donation) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $type, $type, $last]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_bag' || $action === 'edit_bag') {
    try {
        $rawBagId   = $request['id'] ?? '';
        $numBagId   = (int)preg_replace('/\D/', '', $rawBagId);
        $rawDonorId = $request['donor'] ?? '';
        $numDonorId = (int)preg_replace('/\D/', '', $rawDonorId);
        $component  = trim($request['comp'] ?? 'Red cells');
        $date       = trim($request['date'] ?? date('Y-m-d'));
        $status     = trim($request['status'] ?? 'Stored');

        if ($numBagId > 0) {
            $stmt = $pdo->prepare("UPDATE blood_bags SET donor_id = ?, component = ?, collection_date = ?, status = ? WHERE id = ?");
            $stmt->execute([$numDonorId, $component, $date, $status, $numBagId]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 4. Delete Handlers
if ($action === 'delete_donor' || $action === 'delete_bag') {
    try {
        $formattedId = $request['id'] ?? '';
        $rawNum = (int)preg_replace('/\D/', '', $formattedId);

        if ($action === 'delete_donor') {
            $stmt = $pdo->prepare("UPDATE donors SET is_deleted = 1 WHERE id = ?");
            $stmt->execute([$rawNum]);
            $stmtBags = $pdo->prepare("UPDATE blood_bags SET is_deleted = 1 WHERE donor_id = ?");
            $stmtBags->execute([$rawNum]);
        } else {
            $stmt = $pdo->prepare("UPDATE blood_bags SET is_deleted = 1 WHERE id = ?");
            $stmt->execute([$rawNum]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 5. Dashboard Statistics
if ($action === 'get_stats') {
    try {
        $types = ["A+" => 0, "A-" => 0, "B+" => 0, "B-" => 0, "AB+" => 0, "AB-" => 0, "O+" => 0, "O-" => 0];
        $components = ["Red cells" => 0, "Plasma" => 0, "Platelets" => 0];

        $typeRows = $pdo->query("SELECT blood_type, COUNT(*) as total FROM blood_bags WHERE status = 'Stored' AND is_deleted = 0 GROUP BY blood_type")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($typeRows as $row) {
            if (isset($types[$row['blood_type']])) $types[$row['blood_type']] = (int)$row['total'];
        }

        $compRows = $pdo->query("SELECT component, COUNT(*) as total FROM blood_bags WHERE status = 'Stored' AND is_deleted = 0 GROUP BY component")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($compRows as $row) {
            if (isset($components[$row['component']])) $components[$row['component']] = (int)$row['total'];
        }

        echo json_encode(['success' => true, 'types' => $types, 'components' => $components]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>