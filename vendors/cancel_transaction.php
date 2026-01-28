<?php
session_start();
require_once("../config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_id'])) {
    $transaction_id = $_POST['transaction_id'];
    $user_id = $_SESSION['user_id'];
    
    // Check if user owns this transaction
    $check_sql = "SELECT transaction_id FROM transactions WHERE transaction_id = ? AND seller_id = ? AND status = 'Pending'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $transaction_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $conn->begin_transaction();
        
        try {
            // Update transaction status
            $update_sql = "UPDATE transactions SET status = 'Cancelled' WHERE transaction_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("i", $transaction_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Log status change
            $log_sql = "INSERT INTO transaction_status_log (transaction_id, old_status, new_status, changed_by, reason) 
                        VALUES (?, 'Pending', 'Cancelled', ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_reason = "Seller cancelled the sale offer";
            $log_stmt->bind_param("iis", $transaction_id, $user_id, $log_reason);
            $log_stmt->execute();
            $log_stmt->close();
            
            // Notify buyer
            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                 SELECT buyer_id, 'Sale Offer Cancelled', 
                                        'The seller has cancelled the sale offer for transaction #' . ?,
                                        'transaction_update', ?
                                 FROM transactions WHERE transaction_id = ?";
            $notification_stmt = $conn->prepare($notification_sql);
            $notification_stmt->bind_param("iii", $transaction_id, $transaction_id, $transaction_id);
            $notification_stmt->execute();
            $notification_stmt->close();
            
            $conn->commit();
            $_SESSION['success'] = "Sale offer cancelled successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Failed to cancel transaction: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Transaction not found or cannot be cancelled.";
    }
    
    header("Location: sell_product.php");
    exit();
} else {
    header("Location: sell_product.php");
    exit();
}
?>