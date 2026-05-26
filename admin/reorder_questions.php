<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Niet ingelogd']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

include '../database_connect.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['order']) || !is_array($data['order'])) {
    echo json_encode(['ok' => false, 'error' => 'Ongeldige input']);
    exit;
}

$ids = array_map('intval', $data['order']);
if (empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'Lege volgorde']);
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("UPDATE questions SET display_order = ? WHERE id = ?");
    foreach ($ids as $idx => $id) {
        $order = $idx + 1;
        $stmt->bind_param("ii", $order, $id);
        $stmt->execute();
    }
    $conn->commit();
    echo json_encode(['ok' => true, 'updated' => count($ids)]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database fout']);
}
