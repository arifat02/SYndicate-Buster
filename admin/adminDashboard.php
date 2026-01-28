<?php
session_start();    
require_once "../config.php";
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$total_users_result = $conn->query("SELECT COUNT(*) as total FROM users");
$total_users = $total_users_result->fetch_assoc()['total'];

$vendors_result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role_id IN (1,2,3,4)");
$vendors = $vendors_result->fetch_assoc()['count'];

$today_trans_result  = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE DATE(transaction_date) = CURDATE()");
$today_transactions = $today_trans_result->fetch_assoc()['count'];

$blacklisted_result = $conn->query("SELECT COUNT(*) as count FROM violations");
$blacklisted_count = $blacklisted_result->fetch_assoc()['count'];

$revenue_result = $conn->query("SELECT SUM(unit_price*quantity) as total_revenue FROM transactions WHERE DATE(transaction_date) = CURDATE()");
$today_revenue = $revenue_result->fetch_assoc()['total_revenue'];


$recent_users = $conn->query(
"SELECT *
FROM users
JOIN roles ON users.role_id = roles.role_id
where users.role_id != 5
ORDER BY users.created_at DESC
LIMIT 5;
");
$recent_transactions = $conn->query("SELECT t.transaction_date,
    seller.username AS seller_name,
    buyer.username AS buyer_name,
    c.commodity_name AS commodity_name,
    (t.unit_price * t.quantity) AS total_amount
FROM transactions t
JOIN users seller ON t.seller_id = seller.user_id
JOIN users buyer ON t.buyer_id = buyer.user_id
join batches b On t.batch_id=b.batch_id
JOIN commodities c ON b.commodity_id = c.commodity_id

ORDER BY t.transaction_date DESC, t.transaction_id DESC
LIMIT 5");

$price_caps_sql = "
    SELECT 
        pc.price_cap_id,  -- Changed from c.commodity_id to pc.price_cap_id
        c.commodity_name,
        c.unit_type,
        pc.max_price_per_unit,
        pc.effective_date,
        pc.expiry_date
    FROM price_caps pc
    JOIN commodities c ON pc.commodity_id = c.commodity_id
    ORDER BY pc.effective_date DESC
    LIMIT 5
";

$price_caps_result = $conn->query($price_caps_sql);



?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
        <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/model.css?v=<?php echo time(); ?>">
    </head>
<body>
    <div class="container">

        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            <div class="userDetailsCard">
                 <h2 class="GreenTextLarge">Welcome, <?php echo htmlspecialchars($username); ?></h2>
                     <div style="display:flex; flex-direction:column; align-items:flex-end;">
                    <a href="../logout.php" class="greenBtn Red">Logout</a>
                </div>  
            </div>
    
             <div class="navCard">
                   <a href="adminDashboard.php">Dashboard</a>
                <a href="adminManageUsers.php">Manage Users</a>
                <a href="adminPriceCap.php" >Price Caps</a>
                <a href="../admin/adminViolation.php">Violations</a>
            </div>
<div class="stats">
        <div class="card">
            <h3>Total Users </h3>
            <div class="number"><?php echo $total_users; ?></div>
        </div>
        <div class="card">
            <h3>Vendors</h3>
            <div class="number"><?php echo $vendors; ?></div>
        </div>
        <div class="card">
            <h3>Today's Transactions</h3>
            <div class="number"><?php echo $today_transactions; ?></div>
        </div>
        <div class="card">
            <h3>Blacklisted</h3>
            <div class="number"><?php echo $blacklisted_count; ?></div>
        </div>
   
        <div class="card">
            <h3>Today's Revenue</h3>
            <div class="number">৳ <?php echo $today_revenue ? number_format($today_revenue, 2) : '0.00'; ?></div>
        </div>
     </div>

  
    
    <div class="quick-actions">
        <h2>Quick Actions</h2>
        <div class="actions-grid">
            <a class="action-btn" href="../admin/admin_user_approval.php">Approve Pending Requests</a>
            <a class="action-btn" href="../admin/adminBlacklist.php">View Blocklist</a>
        </div>
    </div>
    
    <div class="tables-section">
        <div class="table-box">
            <h2>Recent Users</h2>
            <table>
            <thead>
            <tr>
                <th>Username</th>
                <th>Role</th>
                <th>Location</th>
                <th>Status</th>
            </tr>
            </thead>          
               <tbody>
                      <?php if($recent_users && $recent_users->num_rows > 0): ?>
                            <?php while($user = $recent_users->fetch_assoc()): 
                                $status_class =strtolower($user['account_status']);
                            ?>

                            <tr>
                                <td class="tableBoldText"><?php echo htmlspecialchars($user['username']); ?></td>
                                <td class="tablesmallText"><?php echo htmlspecialchars($user['role_name']); ?></td>
                                <td class="tablesmallText"><?php echo htmlspecialchars($user['location']); ?></td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $user['account_status']; ?></span></td>

                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px;">No users found</td>
                            </tr>
                        <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="table-box">
                <h2>Recent Transactions</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Commodity</th>
                            <th>Seller</th>
                            <th>Buyer</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($recent_transactions && $recent_transactions->num_rows > 0): ?>
                            <?php while($trans = $recent_transactions->fetch_assoc()): ?>
                            <tr>
                                <td><div class="tableBoldText"><?php echo htmlspecialchars($trans['commodity_name'] ?? 'Unknown'); ?></div></td>
                                <td><div class="tablesmallText"><?php echo htmlspecialchars($trans['seller_name'] ?? 'Unknown'); ?></div></td>
                                <td><div class="tablesmallText"><?php echo htmlspecialchars($trans['buyer_name'] ?? 'Unknown'); ?></div></td>
                                <td><div class="tableBoldText">৳ <?php echo number_format(($trans['total_amount'] ?? 0), 2); ?></div></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px;">No recent transactions</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>       
    
    
<div class="priceCaps-Card">
    <h2 style="color:#214332; margin-bottom:20px;">Recent Price Caps</h2>

    <?php if ($price_caps_result && $price_caps_result->num_rows > 0): ?>
        <?php while ($cap = $price_caps_result->fetch_assoc()): ?>
            <div class="price-item">
                <div class="price-header">
                    <div class="tableBoldText" style="font-size:18px;">
                        <?= htmlspecialchars($cap['commodity_name']) ?>
                    </div>
                    <a href="../admin/edit_price_cap.php?id=<?php echo $cap['price_cap_id']; ?>"
                    class="cardLinkBtn Cyan">Edit</a>
                </div>

                <div class="price-value">
                    ৳ <?= number_format($cap['max_price_per_unit'], 2) ?>
                    <span class="tablesmallText">/ <?= htmlspecialchars($cap['unit_type']) ?></span>
                </div>

                <div class="tablesmallText">
                      Effective:
                <?= date('d M Y', strtotime($cap['effective_date'])) ?>
                |
                Expiry:
                <?= $cap['expiry_date']
                    ? date('d M Y', strtotime($cap['expiry_date']))
                    : 'No expiry' ?>
            </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="tablesmallText" style="padding: 10px;">No price caps found</div>
    <?php endif; ?>
    <a href="adminPriceCap.php" class="linktxt" style="font-size:15px;">show all</a>
</div>

    
    <div class="footer">
        <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
    </div>
    
 
    </div>
</div>
</body>
</html>
[file content end]