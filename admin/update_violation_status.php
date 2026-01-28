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
    $type = $_POST['type'] ?? '';
    
    if ($violation_id > 0 && in_array($status, ['PENDING', 'UNDER_REVIEW', 'CONFIRMED', 'REJECTED'])) {
        if ($type === 'user_report') {
            $sql = "UPDATE violations SET status = ? WHERE violation_id = ?";
        } else {
            $sql = "UPDATE violations v 
                    JOIN price_cap_violations pcv ON v.violation_id = pcv.violation_id
                    SET v.status = ? 
                    WHERE pcv.pc_violation_id = ?";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $status, $violation_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Violation status updated successfully!";
            
            // If confirmed, create penalty for price cap violations
            if ($status === 'CONFIRMED' && $type === 'price_cap') {
                // Get violation details
                $details_sql = "SELECT v.violation_id FROM price_cap_violations pcv
                               JOIN violations v ON pcv.violation_id = v.violation_id
                               WHERE pcv.pc_violation_id = ?";
                $details_stmt = $conn->prepare($details_sql);
                $details_stmt->bind_param("i", $violation_id);
                $details_stmt->execute();
                $result = $details_stmt->get_result()->fetch_assoc();
                $details_stmt->close();
                
                if ($result) {
                    // Check if penalty already exists
                    $check_penalty = "SELECT penalty_id FROM penalties WHERE violation_id = ?";
                    $check_stmt = $conn->prepare($check_penalty);
                    $check_stmt->bind_param("i", $result['violation_id']);
                    $check_stmt->execute();
                    
                    if ($check_stmt->get_result()->num_rows == 0) {
                        // Create penalty
                        $penalty_sql = "INSERT INTO penalties (violation_id, penalty_type, issued_by, issued_date, status) 
                                        VALUES (?, 'FINE', ?, CURDATE(), 'ISSUED')";
                        $penalty_stmt = $conn->prepare($penalty_sql);
                        $penalty_stmt->bind_param("ii", $result['violation_id'], $_SESSION['user_id']);
                        $penalty_stmt->execute();
                        $penalty_stmt->close();
                    }
                    $check_stmt->close();
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