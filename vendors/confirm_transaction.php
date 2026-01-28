<?php
session_start();
require_once("../config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_id']) && isset($_POST['action'])) {
    $transaction_id = $_POST['transaction_id'];
    $action = $_POST['action']; // 'confirm' or 'reject'
    $user_id = $_SESSION['user_id'];
    
    // Check if user is the buyer of this pending transaction
    $check_sql = "SELECT t.*, b.batch_id, b.current_quantity, c.commodity_name 
                  FROM transactions t
                  JOIN batches b ON t.batch_id = b.batch_id
                  JOIN commodities c ON b.commodity_id = c.commodity_id
                  WHERE t.transaction_id = ? AND t.buyer_id = ? AND t.status = 'Pending'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $transaction_id, $user_id);
    $check_stmt->execute();
    $transaction = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if ($transaction) {
        $conn->begin_transaction();
        
        try {
            if ($action === 'confirm') {
                $new_status = 'Completed';
                $reason = "Buyer confirmed the purchase";
                
                // Update batch quantity
                $update_batch_sql = "UPDATE batches 
                                     SET current_quantity = current_quantity - ? 
                                     WHERE batch_id = ?";
                $update_stmt = $conn->prepare($update_batch_sql);
                $update_stmt->bind_param("di", $transaction['quantity'], $transaction['batch_id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Check if batch is sold out
                $new_quantity = $transaction['current_quantity'] - $transaction['quantity'];
                if ($new_quantity <= 0) {
                    $status_sql = "UPDATE batches SET batch_status = 'Sold' WHERE batch_id = ?";
                    $status_stmt = $conn->prepare($status_sql);
                    $status_stmt->bind_param("i", $transaction['batch_id']);
                    $status_stmt->execute();
                    $status_stmt->close();
                }
            } else {
                $new_status = 'Cancelled';
                $reason = "Buyer rejected the offer";
            }
            
            // Update transaction status
            $update_txn_sql = "UPDATE transactions SET status = ? WHERE transaction_id = ?";
            $update_txn_stmt = $conn->prepare($update_txn_sql);
            $update_txn_stmt->bind_param("si", $new_status, $transaction_id);
            $update_txn_stmt->execute();
            $update_txn_stmt->close();
            
            // Log status change
            $log_sql = "INSERT INTO transaction_status_log (transaction_id, old_status, new_status, changed_by, reason) 
                        VALUES (?, 'Pending', ?, ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bind_param("isis", $transaction_id, $new_status, $user_id, $reason);
            $log_stmt->execute();
            $log_stmt->close();
            
            // Notify seller
            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                 SELECT seller_id, 'Sale Offer ' . UCASE(?), 
                                        CONCAT('Buyer has ', ?, ' your sale offer for ', 
                                               quantity, ' ', c.unit_type, ' of ', c.commodity_name),
                                        'transaction_update', ?
                                 FROM transactions t
                                 JOIN batches b ON t.batch_id = b.batch_id
                                 JOIN commodities c ON b.commodity_id = c.commodity_id
                                 WHERE t.transaction_id = ?";
            $notification_stmt = $conn->prepare($notification_sql);
            $action_text = ($action === 'confirm') ? 'confirmed' : 'rejected';
            $notification_stmt->bind_param("ssii", $action, $action_text, $transaction_id, $transaction_id);
            $notification_stmt->execute();
            $notification_stmt->close();
            
            $conn->commit();
            
            if ($action === 'confirm') {
                $_SESSION['success'] = "Purchase confirmed successfully!";
            } else {
                $_SESSION['success'] = "Sale offer rejected.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Failed to process transaction: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Transaction not found or cannot be processed.";
    }
    
    header("Location: notifications.php"); // Create this page for buyers
    exit();
} else {
    header("Location: vendorDashboard.php");
    exit();
}
?>