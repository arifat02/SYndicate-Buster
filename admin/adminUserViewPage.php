<?php
session_start();    
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    header("Location: adminManageUsers.php");
    exit();
}

$sql = "SELECT u.*, r.role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        WHERE u.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: adminManageUsers.php");
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

$transactions_sql = "SELECT t.*, c.commodity_name 
                     FROM transactions t
                     LEFT JOIN batches b ON t.batch_id = b.batch_id
                     LEFT JOIN commodities c ON b.commodity_id = c.commodity_id
                     WHERE t.seller_id = ? OR t.buyer_id = ?
                     ORDER BY t.transaction_date DESC
                     LIMIT 10";
$transactions_stmt = $conn->prepare($transactions_sql);
$transactions_stmt->bind_param("ii", $user_id, $user_id);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();
$transactions_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details - Admin</title>
    <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/userdetail.css?v=<?php echo time(); ?>">

</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        
        <div class="dashboard">
            <div class="userDetailsCard">
                <h2>User Details</h2>
                <div style="display: flex; gap: 10px;">
                    <button class="smallBtn Cyan" onclick="window.location.href='adminManageUsers.php'">← Back to Manage Users</button>
                    <button class="smallBtn Red" onclick="window.location.href='logout.php'">Logout</button>
                </div>
            </div>
            
              <div class="user-header">
                <div class="user-avatar-large">
                    <?php 
                    $names = explode(" ", $user['username']);
                    echo strtoupper($names[0][0] . ($names[1][0] ?? ""));
                    ?>
                </div>
                <div class="user-basic-info">
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($user['role_name']); ?></div>
                    <div style="margin-top: 10px;">
                        <span class="status-badge <?php echo strtolower($user['account_status']); ?>">
                            <?php echo htmlspecialchars($user['account_status']); ?>
                        </span>
                        <span style="margin-left: 10px; color: #666;">
                            Trust Score: <?php echo $user['trust_score']; ?>/100
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="info-grid">
                <div class="info-card">
                    <h3 style="color: #214332; margin-bottom: 15px;">Basic Information</h3>
                    <div class="info-row">
                        <span class="info-label">User ID:</span>
                        <span class="info-value">#<?php echo $user['user_id']; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['phone']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Location:</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['location']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Address:</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['address']); ?></span>
                    </div>
                </div>
                
                <!-- Account Information Card -->
                <div class="info-card">
                    <h3 style="color: #214332; margin-bottom: 15px;">Account Information</h3>
                    <div class="info-row">
                        <span class="info-label">Role:</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['role_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Status:</span>
                        <span class="info-value">
                            <span class="status-badge <?php echo strtolower($user['account_status']); ?>">
                                <?php echo htmlspecialchars($user['account_status']); ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Trust Score:</span>
                        <span class="info-value"><?php echo $user['trust_score']; ?>/100</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Account Created:</span>
                        <span class="info-value"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Login:</span>
                        <span class="info-value">
                            <?php 
                            if ($user['last_login']) {
                                echo date('M d, Y h:i A', strtotime($user['last_login']));
                            } else {
                                echo 'Never';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Updated:</span>
                        <span class="info-value"><?php echo date('M d, Y', strtotime($user['updated_at'])); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Recent Transactions -->
            <div class="card" style="margin-top: 20px;">
                <div class="table-box">
                    <h2 style="color: #214332; margin-bottom: 15px;">Recent Transactions</h2>
                    
                    <?php if ($transactions_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Commodity</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($transaction = $transactions_result->fetch_assoc()): ?>
                            <tr>
                                <td>#<?php echo $transaction['transaction_id']; ?></td>
                                <td><?php echo htmlspecialchars($transaction['commodity_name'] ?? 'N/A'); ?></td>
                                <td><?php echo $transaction['quantity']; ?></td>
                                <td>৳<?php echo number_format($transaction['unit_price'], 2); ?></td>
                                <td>৳<?php echo number_format($transaction['unit_price'] * $transaction['quantity'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?></td>
                                <td>
                                    <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; 
                                          background: <?php echo $transaction['status'] == 'Completed' ? '#d4edda' : '#fff3cd'; ?>;
                                          color: <?php echo $transaction['status'] == 'Completed' ? '#155724' : '#856404'; ?>;">
                                        <?php echo $transaction['status']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align: center; padding: 20px; color: #666;">
                        No transactions found for this user.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div style="display: flex; gap: 10px; margin: 20px 0; justify-content: center;">
                <?php if($user['account_status'] == 'Under_Review'): ?>
                    <a href="adminManageUsers.php?action=approve&user_id=<?php echo $user['user_id']; ?>" 
                       class="greenBtn"
                       onclick="return confirm('Approve this user?')">Approve User</a>
                    <a href="adminManageUsers.php?action=reject&user_id=<?php echo $user['user_id']; ?>" 
                       class="smallBtn Red"
                       onclick="return confirm('Reject this registration?')">Reject</a>
                <?php else: ?>
                    <?php if($user['account_status'] != 'Active'): ?>
                        <a href="adminManageUsers.php?action=activate&user_id=<?php echo $user['user_id']; ?>" 
                           class="greenBtn"
                           onclick="return confirm('Activate this user?')">Activate User</a>
                    <?php endif; ?>
                    
                    <?php if($user['account_status'] != 'Suspended'): ?>
                        <a href="adminManageUsers.php?action=suspend&user_id=<?php echo $user['user_id']; ?>" 
                           class="smallBtn Orange"
                           onclick="return confirm('Suspend this user?')">Suspend User</a>
                    <?php endif; ?>
                    
                    <?php if($user['account_status'] != 'Banned'): ?>
                        <a href="adminManageUsers.php?action=ban&user_id=<?php echo $user['user_id']; ?>" 
                           class="smallBtn Red"
                           onclick="return confirm('Ban this user?')">Ban User</a>
                    <?php endif; ?>
                    
                    <?php if($user['account_status'] != 'Blacklisted'): ?>
                        <a href="adminManageUsers.php?action=blacklist&user_id=<?php echo $user['user_id']; ?>" 
                           class="smallBtn Purple"
                           onclick="return confirm('Blacklist this user?')">Blacklist User</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © 2026</p>
            </div>
        </div>
    </div>
</body>
</html>