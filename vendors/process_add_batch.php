<?php
session_start();
require_once("../config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commodity_id = $_POST['commodity'] ?? 0;
    $quantity = floatval($_POST['quantity'] ?? 0);
    $production_date = $_POST['production_date'] ?? date('Y-m-d');
    $expiry_date = $_POST['expiry_date'] ?? null;
    $parent_batch_id = $_POST['parent_batch_id'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if ($commodity_id <= 0 || $quantity <= 0) {
        $response['message'] = "Please select a commodity and enter valid quantity";
    } else {
        if ($parent_batch_id) {
            $check_parent_sql = "SELECT batch_id FROM batches WHERE batch_id = ? AND owner_id = ?";
            $check_stmt = $conn->prepare($check_parent_sql);
            $check_stmt->bind_param("ii", $parent_batch_id, $user_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                $response['message'] = "Parent batch not found or access denied";
                echo json_encode($response);
                exit();
            }
            $check_stmt->close();
        }
                $insert_sql = "INSERT INTO batches (commodity_id, owner_id, initial_quantity, current_quantity, 
                                            production_date, expiry_date, parent_batch_id) 
                       VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iiddssi", $commodity_id, $user_id, $quantity, $quantity, 
                                $production_date, $expiry_date, $parent_batch_id);
        
        if ($insert_stmt->execute()) {
            $response['success'] = true;
            $response['message'] = "Batch added successfully!";
            $_SESSION['success'] = "Batch added successfully!";
        } else {
            $response['message'] = "Error adding batch: " . $conn->error;
        }
        $insert_stmt->close();
    }
} else {
    $response['message'] = "Invalid request method";
}

header('Content-Type: application/json');
echo json_encode($response);
exit();
?>