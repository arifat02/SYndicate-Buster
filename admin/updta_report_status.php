<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $violation_id = $_POST['violation_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    
    if ($violation_id > 0 && in_array($status, ['PENDING', 'UNDER_REVIEW', 'CONFIRMED', 'REJECTED'])) {
        $sql = "UPDATE violations SET status = ? WHERE violation_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $status, $violation_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Violation status updated successfully!";
            
            if ($status === 'CONFIRMED') {
                $check_sql = "SELECT violation_type, reported_user_id FROM violations WHERE violation_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("i", $violation_id);
                $check_stmt->execute();
                $violation = $check_stmt->get_result()->fetch_assoc();
                $check_stmt->close();
                
                if ($violation && $violation['violation_type'] === 'PRICE_CAP') {
                    // Create a penalty record
                    $penalty_sql = "INSERT INTO penalties (violation_id, penalty_type, issued_by, issued_date, status) 
                                    VALUES (?, 'FINE', ?, CURDATE(), 'ISSUED')";
                    $penalty_stmt = $conn->prepare($penalty_sql);
                    $penalty_stmt->bind_param("ii", $violation_id, $_SESSION['user_id']);
                    $penalty_stmt->execute();
                    $penalty_stmt->close();
                }
            }
        } else {
            $_SESSION['error'] = "Error updating status: " . $conn->error;
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "Invalid request parameters";
    }
}

header("Location: adminViolation.php");
exit();
?>