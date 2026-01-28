<?php
session_start();
require_once("../config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$user_sql = "SELECT u.*, r.role_name,
             (SELECT COUNT(*) FROM violations WHERE reported_user_id = u.user_id AND status = 'pending') as pending_violations,
             (SELECT COUNT(*) FROM batches WHERE owner_id = u.user_id AND current_quantity > 0) as active_batches
             FROM users u 
             JOIN roles r ON u.role_id = r.role_id 
             WHERE u.user_id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$suspensionMessage = "";
if ($user['account_status'] === 'Suspended') {
    $sus_sql = "SELECT start_date, end_date FROM user_suspensions WHERE user_id = ? AND status = 'ACTIVE' LIMIT 1";
    $sus_stmt = $conn->prepare($sus_sql);
    $sus_stmt->bind_param("i", $user_id);
    $sus_stmt->execute();
    $suspensionInfo = $sus_stmt->get_result()->fetch_assoc();
    $sus_stmt->close();
    
    if ($suspensionInfo) {
        $now = new DateTime();
        $end = new DateTime($suspensionInfo['end_date']);
        
        if ($end > $now) {
            $diff = $now->diff($end);
            $suspensionMessage = "⛔ Your account is suspended.<br><strong>{$diff->days} days, {$diff->h} hours remaining</strong>";
        } else {
            $conn->query("UPDATE user_suspensions SET status = 'COMPLETED' WHERE user_id = $user_id AND status = 'ACTIVE'");
            $conn->query("UPDATE users SET account_status = 'Active' WHERE user_id = $user_id");
            $user['account_status'] = 'Active';
        }
    }
}

$is_blacklisted = ($user['account_status'] === 'Blacklisted');

$inventory_sql = "SELECT c.commodity_name as name, SUM(b.current_quantity) as total_quantity,
                  COUNT(DISTINCT b.commodity_id) as unique_items,
                  SUM(b.current_quantity) as total_kg
                  FROM batches b 
                  JOIN commodities c ON b.commodity_id = c.commodity_id 
                  WHERE b.owner_id = ? AND b.current_quantity > 0
                  GROUP BY c.commodity_id
                  limit 5";
$inventory_stmt = $conn->prepare($inventory_sql);
$inventory_stmt->bind_param("i", $user_id);
$inventory_stmt->execute();
$inventory_result = $inventory_stmt->get_result();

$inventory_items = [];
$total_quantity = 0;
$unique_items = 0;
while($item = $inventory_result->fetch_assoc()) {
    $inventory_items[] = $item;
    $total_quantity += $item['total_quantity'];
    $unique_items++;
}
$inventory_stmt->close();

$price_caps = $conn->query("
    SELECT c.commodity_name, c.unit_type, pc.max_price_per_unit, pc.effective_date,pc.expiry_date
    FROM price_caps pc 
    JOIN commodities c ON pc.commodity_id = c.commodity_id 
    WHERE pc.expiry_date >= CURDATE() OR pc.expiry_date IS NULL
    ORDER BY pc.effective_date DESC
");

$recent_sql = "SELECT t.transaction_id, t.transaction_date, t.quantity, t.unit_price, 
                      t.unit_price*t.quantity as total_amount, c.commodity_name, c.unit_type, 
                      t.status, t.violation_flag
               FROM transactions t
               JOIN batches b ON t.batch_id = b.batch_id
               JOIN commodities c ON b.commodity_id = c.commodity_id
               WHERE b.owner_id = ?
               ORDER BY t.transaction_date DESC
               LIMIT 5";
$recent_stmt = $conn->prepare($recent_sql);
$recent_stmt->bind_param("i", $user_id);
$recent_stmt->execute();
$recent_transactions = $recent_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Dashboard - Syndicate Buster</title>
    <link rel="stylesheet" href="../css/page.css">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    <style>
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr !important;
            }
            
            .userDetailsCard {
                flex-direction: column;
                text-align: center;
            }
            
            .userDetailsCard > div:last-child {
                align-items: center !important;
                margin-top: 15px;
            }
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success">
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <?php unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($_SESSION['error']); ?>
            <?php unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            <!-- User Header -->
            <div class="userDetailsCard">
                <div>
                    <h2 class="GreenTextLarge">Welcome, <?php echo htmlspecialchars($user['username']); ?>!</h2>
                    <p style="color: #666;">Role: <?php echo $user['role_name']; ?></p>
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end;">
                    <p>Trust Score: <strong><?php echo $user['trust_score']; ?>/100</strong></p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; padding-top:10px;">
                        <a href="../userprofile.php" class="greenBtn Green">Profile</a>
                        <a href="../logout.php" class="greenBtn Red">Logout</a>
                    </div>
                </div>
            </div>
            
            <!-- Status Alerts -->
            <?php if($is_blacklisted): ?>
                <div class="alert-error">
                    <strong>SUSPENDED:</strong> You are currently blacklisted due to price violations. Contact admin for appeal.
                </div>
            <?php else: ?>
                <?php if($suspensionMessage): ?>
                    <div class="alert-error"><?php echo $suspensionMessage; ?></div>
                <?php endif; ?>
                
                <?php if($user['trust_score'] < 60 && $user['trust_score'] > 0): ?>
                    <div class="alert warning">
                        <strong>WARNING:</strong> Your trust score is low (<?php echo $user['trust_score']; ?>/100). Maintain compliance to improve.
                    </div>
                <?php endif; ?>
                
                <?php if($user['pending_violations'] > 0): ?>
                    <div class="alert warning">
                        <strong>ACTION REQUIRED:</strong> You have <?php echo $user['pending_violations']; ?> pending violation(s).
                        <a href="userViolation.php" style="color: #214332; text-decoration: underline; margin-left: 10px;">Review Now</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Navigation -->
            <div class="navCard">
                <a href="../vendors/vendorDashboard.php" style="background: rgba(255,255,255,0.1);">Dashboard</a>
                <a href="../vendors/userProductReg.php">Product Registration</a>
                <a href="../vendors/userCreateListing.php">Create Listing</a>
                <a href="../vendors/userMarketplace.php">Marketplace</a>
                <a href="../vendors/userTransaction.php">Transactions</a>
                <a href="../vendors/userViolation.php">Policy & Violation</a>
            </div>
            
            <div class="grid">
                <div class="gridCard">
                    <h2 style="color: #214332; margin-bottom: 20px;">Trust Score</h2>
                    <?php 
                    $trust_class = 'green-card';
                    if($user['trust_score'] < 60) $trust_class = 'red-card';
                    elseif($user['trust_score'] < 80) $trust_class = 'yellow-card';
                    ?>
                    <div class="trust-score <?php echo $trust_class; ?>">
                        <?php echo $user['trust_score']; ?> / 100
                    </div>
                    <p style="text-align: center; margin-top: 10px; color: #666;">
                        Based on transaction compliance and history
                    </p>
                </div>
                
                <div class="gridCard">
                    <h2 style="color: #214332; margin-bottom: 20px;">Current Inventory</h2>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 20px;">
                        <div class="summary-box">
                            <div class="tablesmallText">Total Items</div>
                            <div class="tableBoldText" style="font-size: 24px; color: #214332;">
                                <?php echo $unique_items; ?>
                            </div>
                        </div>
                        <div class="summary-box">
                            <div class="tablesmallText">Total Quantity</div>
                            <div class="tableBoldText" style="font-size: 24px; color: #214332;">
                                <?php echo $total_quantity; ?> kg
                            </div>
                        </div>
                    </div>
                    
                    <?php if(!empty($inventory_items)): ?>
                        <?php foreach($inventory_items as $item): 
                            $percentage = ($item['total_quantity'] / ($total_quantity ?: 1)) * 100;
                        ?>
                            <div style="margin-bottom: 15px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <span class="tableBoldText"><?php echo $item['name']; ?></span>
                                    <span class="tablesmallText"><?php echo $item['total_quantity']; ?> kg</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min($percentage, 100); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No inventory found.</p>
                    <?php endif; ?>
                    
                    
                </div>
               
                <div class="gridCard">
                    <h2 style="color: #214332; margin-bottom: 20px;">Quick Stats</h2>
                    <div class="gridCard-item">
                        <span>Active Batches</span>
                        <span><strong><?php echo $user['active_batches']; ?></strong></span>
                    </div>
                    <div class="gridCard-item">
                        <span>Status</span>
                        <span style="color: <?php echo $user['account_status'] == 'Active' ? '#28a745' : '#dc3545'; ?>">
                            <strong><?php echo $user['account_status']; ?></strong>
                        </span>
                    </div>
                    <div class="gridCard-item">
                        <span>Pending Violations</span>
                        <span style="color: <?php echo $user['pending_violations'] > 0 ? '#dc3545' : '#28a745'; ?>">
                            <strong><?php echo $user['pending_violations']; ?></strong>
                        </span>
                    </div>
                    <div class="gridCard-item">
                                      <?php if ($user['account_status'] === 'Suspended'): ?>


                        <span>Remaining</span>
                        <strong>
                        <?php
                         $now = new DateTime();
                        $end = new DateTime($suspensionInfo['end_date']);
                        $diff = $now->diff($end);

                        echo $diff->days . " days, " . $diff->h . " hours ";
                        ?>
                        </strong>

                    <?php endif; ?>
                    </div>
                    <div class="gridCard-item">
                        <span>Inventory Items</span>
                        <span><strong><?php echo $unique_items; ?></strong></span>
                    </div>
                </div>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">📊 Current Price Caps</h2>
                <?php if($price_caps->num_rows > 0): ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 600px;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 12px; text-align: left;">Commodity</th>
                                    <th style="padding: 12px; text-align: left;">Max Price</th>
                                    <th style="padding: 12px; text-align: left;">Effective Date</th>
                                    <th style="padding: 12px; text-align: left;">Expiry Date</th>                                   
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($cap = $price_caps->fetch_assoc()): ?>
                                <tr style="border-bottom: 1px solid #dee2e6;">
                                    <td style="padding: 12px;">
                                        <div class="user-info">
                                            <div class="user-avatar" style="background-color: #28a745;">
                                                <?php echo strtoupper(substr($cap['commodity_name'], 0, 1)); ?>
                                            </div>
                                            <div class="user-details">
                                                <div class="tableBoldText"><?php echo htmlspecialchars($cap['commodity_name']); ?></div>
                                                <div class="tablesmallText">Unit: <?php echo $cap['unit_type']; ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div class="tableBoldText" style="color: #28a745; font-size: 18px;">
                                            ৳<?php echo number_format($cap['max_price_per_unit'], 2); ?>
                                        </div>
                                        <div class="tablesmallText">per <?php echo $cap['unit_type']; ?></div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div class="tableBoldText"><?php echo $cap['effective_date']; ?></div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div class="tableBoldText"><?php echo $cap['expiry_date']; ?></div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <p>No active price caps set.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">Recent Transactions</h2>
                <?php if($recent_transactions->num_rows > 0): ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 600px;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 12px; text-align: left;">Date</th>
                                    <th style="padding: 12px; text-align: left;">Commodity</th>
                                    <th style="padding: 12px; text-align: left;">Quantity</th>
                                    <th style="padding: 12px; text-align: left;">Amount</th>
                                    <th style="padding: 12px; text-align: left;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($txn = $recent_transactions->fetch_assoc()): 
                                    $status_color = $txn['status'] == 'Completed' ? '#28a745' : 
                                                 ($txn['status'] == 'Pending' ? '#ffc107' : '#dc3545');
                                ?>
                                <tr style="border-bottom: 1px solid #dee2e6; <?php echo $txn['violation_flag'] ? 'background: #fff5f5;' : ''; ?>">
                                    <td style="padding: 12px;">
                                        <div class="tablesmallText"><?php echo date('M d', strtotime($txn['transaction_date'])); ?></div>
                                        <div class="tablesmallText"><?php echo date('h:i A', strtotime($txn['transaction_date'])); ?></div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div class="tableBoldText"><?php echo htmlspecialchars($txn['commodity_name']); ?></div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div class="tableBoldText"><?php echo $txn['quantity']; ?></div>
                                        <div class="tablesmallText"><?php echo $txn['unit_type']; ?></div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <div class="tableBoldText">৳<?php echo number_format($txn['total_amount'], 2); ?></div>
                                        <div class="tablesmallText">৳<?php echo $txn['unit_price']; ?>/unit</div>
                                    </td>
                                    <td style="padding: 12px;">
                                        <span style="color: <?php echo $status_color; ?>; font-weight: bold;">
                                            <?php echo $txn['status']; ?>
                                        </span>
                                        <?php if($txn['violation_flag']): ?>
                                            <div class="tablesmallText" style="color: #dc3545; margin-top: 5px;">⚠️ Violation</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="userTransaction.php" class="limebtn">View All Transactions</a>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 30px; color: #666;">
                        <p>No transactions yet.</p>
                        <a href="../vendors/userCreateListing.php" class="limebtn">Create Listing</a>
                    </div>
                <?php endif; ?>
                <?php $recent_stmt->close(); ?>
            </div>
        </div>
        <div class="footer">
            <p>Syndicate Buster Admin Panel © 2026</p>
        </div>
    </div>
    
    <script>
    setTimeout(() => {
        document.querySelectorAll('.alert-success, .alert-error, .alert.warning').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
    </script>
</body>
</html>