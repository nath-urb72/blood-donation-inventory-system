<?php
// CHANGED
// backend/api.php
error_reporting(E_ALL);
ini_set('display_errors', '0');      // errors go to the PHP log, not the browser
header('Content-Type: application/json');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
require_once __DIR__ . '/../config/db_connect.php';

const TYPES      = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const COMPONENTS = ['Red cells', 'Plasma', 'Platelets'];
const STATUSES   = ['Stored', 'Testing', 'Expired', 'Used'];

/* ---------- helpers ---------- */
function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}
function fail(string $msg, int $code = 400): void {
    respond(['success' => false, 'error' => $msg], $code);
}
function numId($raw): int {                  // "D-001" -> 1
    return (int) preg_replace('/\D/', '', (string) $raw);
}
function validDate(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}
function cleanDonor(array $b): array {
    $name = trim($b['name'] ?? '');
    $type = trim($b['type'] ?? '');
    if ($name === '' || strlen($name) > 100) fail('Enter a donor name (max 100 characters).');
    if (!in_array($type, TYPES, true))       fail('Invalid blood type.');
    return [$name, $type];
}
function cleanBag(array $b): array {
    $comp   = trim($b['comp'] ?? '');
    $status = trim($b['status'] ?? 'Stored');
    $date   = trim($b['date'] ?? '');
    if (!in_array($comp, COMPONENTS, true)) fail('Invalid component.');
    if (!in_array($status, STATUSES, true)) fail('Invalid status.');
    if (!validDate($date))                  fail('Invalid collection date.');
    if ($date > date('Y-m-d'))              fail('Collection date cannot be in the future.');
    return [$comp, $status, $date];
}
function donorExists(PDO $pdo, int $id): bool {
    $s = $pdo->prepare('SELECT 1 FROM donors WHERE id = ? AND is_deleted = 0');
    $s->execute([$id]);
    return (bool) $s->fetchColumn();
}

/* ---------- request ---------- */
$method = $_SERVER['REQUEST_METHOD'];
$body   = $method === 'POST' ? $_POST : [];
$action = $_GET['action'] ?? '';
$id     = $body['id'] ?? $_GET['id'] ?? '';

// Public action: lets the page ask "am I logged in?"
if ($action === 'check_session') {
    respond(['success' => true, 'logged_in' => !empty($_SESSION['user_id'])]);
}

// Everything below needs a logged-in admin
if (empty($_SESSION['user_id'])) fail('Please log in first.', 401);

// Anything that changes data must be a POST
$writes = ['add_entry', 'edit_donor', 'edit_bag', 'delete_donor', 'delete_bag'];
if (in_array($action, $writes, true) && $method !== 'POST') fail('This action requires POST.', 405);

try {
    // Auto-expire bags past their expiry date
    $pdo->prepare("UPDATE blood_bags SET status = 'Expired'
                   WHERE status IN ('Stored', 'Testing') AND expiry_date < ?")
        ->execute([date('Y-m-d')]);

    switch ($action) {

        case 'get_data':
            $donorRows = $pdo->query("
                SELECT d.id, d.name, d.blood_type,
                       (SELECT MAX(b.collection_date) FROM blood_bags b
                         WHERE b.donor_id = d.id AND b.is_deleted = 0) AS last_donation
                FROM donors d WHERE d.is_deleted = 0 ORDER BY d.id DESC")->fetchAll();
            $donors = array_map(fn($d) => [
                'id'   => 'D-' . str_pad((string) $d['id'], 3, '0', STR_PAD_LEFT),
                'name' => $d['name'],
                'type' => $d['blood_type'],
                'last' => $d['last_donation'],     // null if the donor has no bags
            ], $donorRows);

            $bagRows = $pdo->query("
                SELECT b.id, b.donor_id, b.component, b.collection_date, b.status, d.blood_type
                FROM blood_bags b JOIN donors d ON d.id = b.donor_id
                WHERE b.is_deleted = 0 ORDER BY b.id DESC")->fetchAll();
            $bags = array_map(fn($b) => [
                'id'     => 'B-' . str_pad((string) $b['id'], 4, '0', STR_PAD_LEFT),
                'donor'  => 'D-' . str_pad((string) $b['donor_id'], 3, '0', STR_PAD_LEFT),
                'type'   => $b['blood_type'],
                'comp'   => $b['component'],
                'date'   => $b['collection_date'],
                'status' => $b['status'],
            ], $bagRows);

            respond(['success' => true, 'donors' => $donors, 'bags' => $bags]);

        case 'add_entry':   // new donor + bag, OR a bag for an existing donor
            [$comp, $status, $date] = cleanBag($body);
            $donorRef = trim($body['donor'] ?? '');
            if ($donorRef === '') {
                [$name, $type] = cleanDonor($body);
            } else {
                $donorId = numId($donorRef);
                if (!donorExists($pdo, $donorId)) fail('Donor not found.', 404);
            }

            $pdo->beginTransaction();
            if ($donorRef === '') {
                $pdo->prepare('INSERT INTO donors (name, blood_type) VALUES (?, ?)')->execute([$name, $type]);
                $donorId = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('INSERT INTO blood_bags (donor_id, component, status, collection_date, expiry_date)
                           VALUES (?, ?, ?, ?, ?)')
                ->execute([$donorId, $comp, $status, $date, expiry_for($comp, $date)]);
            $pdo->commit();
            respond(['success' => true]);

        case 'edit_donor':
            [$name, $type] = cleanDonor($body);
            $st = $pdo->prepare('UPDATE donors SET name = ?, blood_type = ? WHERE id = ? AND is_deleted = 0');
            $st->execute([$name, $type, numId($id)]);
            if ($st->rowCount() === 0) fail('Donor not found.', 404);
            respond(['success' => true]);

        case 'edit_bag':
            [$comp, $status, $date] = cleanBag($body);
            $donorId = numId($body['donor'] ?? '');
            if (!donorExists($pdo, $donorId)) fail('Donor not found.', 404);
            $st = $pdo->prepare('UPDATE blood_bags
                                 SET donor_id = ?, component = ?, status = ?, collection_date = ?, expiry_date = ?
                                 WHERE id = ? AND is_deleted = 0');
            $st->execute([$donorId, $comp, $status, $date, expiry_for($comp, $date), numId($id)]);
            if ($st->rowCount() === 0) fail('Bag not found.', 404);
            respond(['success' => true]);

        case 'delete_donor':   // soft delete: donor and all of their bags
            $did = numId($id);
            $pdo->beginTransaction();
            $st = $pdo->prepare('UPDATE donors SET is_deleted = 1 WHERE id = ? AND is_deleted = 0');
            $st->execute([$did]);
            if ($st->rowCount() === 0) { $pdo->rollBack(); fail('Donor not found.', 404); }
            $pdo->prepare('UPDATE blood_bags SET is_deleted = 1 WHERE donor_id = ?')->execute([$did]);
            $pdo->commit();
            respond(['success' => true]);

        case 'delete_bag':
            $st = $pdo->prepare('UPDATE blood_bags SET is_deleted = 1 WHERE id = ? AND is_deleted = 0');
            $st->execute([numId($id)]);
            if ($st->rowCount() === 0) fail('Bag not found.', 404);
            respond(['success' => true]);

        case 'get_stats':
            $types      = array_fill_keys(TYPES, 0);
            $components = array_fill_keys(COMPONENTS, 0);

            foreach ($pdo->query("SELECT d.blood_type AS k, COUNT(*) AS n
                                  FROM blood_bags b JOIN donors d ON d.id = b.donor_id
                                  WHERE b.status = 'Stored' AND b.is_deleted = 0
                                  GROUP BY d.blood_type") as $r) {
                $types[$r['k']] = (int) $r['n'];
            }
            foreach ($pdo->query("SELECT component AS k, COUNT(*) AS n FROM blood_bags
                                  WHERE status = 'Stored' AND is_deleted = 0
                                  GROUP BY component") as $r) {
                $components[$r['k']] = (int) $r['n'];
            }
            $total = (int) $pdo->query('SELECT COUNT(*) FROM blood_bags WHERE is_deleted = 0')->fetchColumn();

            respond(['success' => true, 'types' => $types, 'components' => $components, 'total' => $total]);

        default:
            fail('Unknown action.', 404);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[api] ' . $e->getMessage());
    fail('Server error. Please try again.', 500);
}