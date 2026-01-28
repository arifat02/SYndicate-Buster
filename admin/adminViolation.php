<?php
session_start();    
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// --- 1. Statistics Queries ---
$total_violations_result = $conn->query("SELECT COUNT(*) as total FROM price_cap_violations");
$total_violations = $total_violations_result->fetch_assoc()['total'];

// Fetch User Reports (The table we are modifying)
$user_reported_violations_sql = "
    SELECT 
        v.violation_id,
        v.violation_date,
        v.violation_type,
        v.description,
        v.status,
        reporter.username as reporter_name,
        reported.username as reported_name,
        r_reporter.role_name as reporter_role,
        r_reported.role_name as reported_role
    FROM violations v
    JOIN users reporter ON v.reporter_id = reporter.user_id
    JOIN roles r_reporter ON reporter.role_id = r_reporter.role_id
    JOIN users reported ON v.reported_user_id = reported.user_id
    JOIN roles r_reported ON reported.role_id = r_reported.role_id
    ORDER BY v.violation_date DESC
    LIMIT 10
";
$user_reported_violations_result = $conn->query($user_reported_violations_sql);
$user_reported_count = $user_reported_violations_result ? $user_reported_violations_result->num_rows : 0;

// Stats for Cards
$violation_stats_sql = "
    SELECT 
        COUNT(*) as total_reports,
        SUM(CASE WHEN status = 'CONFIRMED' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'UNDER_REVIEW' THEN 1 ELSE 0 END) as under_review,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as rejected
    FROM violations
";
$violation_stats_result = $conn->query($violation_stats_sql);
$violation_stats = $violation_stats_result->fetch_assoc();

$this_week_violations_result = $conn->query("SELECT COUNT(*) as count FROM price_cap_violations WHERE WEEK(created_at) = WEEK(CURDATE())");
$this_week_violations = $this_week_violations_result->fetch_assoc()['count'];

$this_week_reports_result = $conn->query("SELECT COUNT(*) as count FROM violations WHERE WEEK(violation_date) = WEEK(CURDATE())");
$this_week_reports = $this_week_reports_result->fetch_assoc()['count'];

$avg_excess_result = $conn->query("SELECT AVG(pcv.reported_price - pc.max_price_per_unit) as avg_excess FROM price_cap_violations pcv JOIN price_caps pc ON pcv.price_cap_id = pc.price_cap_id");
$avg_excess = $avg_excess_result->fetch_assoc()['avg_excess'] ?? 0;

// Top Violators
$top_violators_sql = "
    SELECT u.user_id, u.username, r.role_name, COUNT(pcv.pc_violation_id) as violation_count, AVG(pcv.reported_price - pc.max_price_per_unit) as avg_excess
    FROM price_cap_violations pcv
    JOIN transactions t ON pcv.transaction_id = t.transaction_id
    JOIN users u ON t.seller_id = u.user_id
    JOIN roles r ON u.role_id = r.role_id
    JOIN price_caps pc ON pcv.price_cap_id = pc.price_cap_id
    GROUP BY u.user_id, u.username, r.role_name
    ORDER BY violation_count DESC LIMIT 5";
$top_violators_result = $conn->query($top_violators_sql);

// Top Reported Users
$top_reported_sql = "
    SELECT u.user_id, u.username, r.role_name, COUNT(v.violation_id) as report_count
    FROM violations v
    JOIN users u ON v.reported_user_id = u.user_id
    JOIN roles r ON u.role_id = r.role_id
    GROUP BY u.user_id, u.username, r.role_name
    ORDER BY report_count DESC LIMIT 5";
$top_reported_result = $conn->query($top_reported_sql);

// Price Cap Violations List (Bottom Table)
$recent_violations_sql = "
    SELECT pcv.*, t.transaction_date as detected_date, 
           c.commodity_name, u.username as seller_name, b_user.username as buyer_name,
           pc.max_price_per_unit,
           (pcv.reported_price - pc.max_price_per_unit) as excess_amount,
           t.seller_id, pcv.price_cap_id
    FROM price_cap_violations pcv
    JOIN transactions t ON pcv.transaction_id = t.transaction_id
    JOIN batches b ON t.batch_id = b.batch_id
    JOIN commodities c ON b.commodity_id = c.commodity_id
    JOIN users u ON t.seller_id = u.user_id
    LEFT JOIN users b_user ON t.buyer_id = b_user.user_id
    JOIN price_caps pc ON pcv.price_cap_id = pc.price_cap_id
    ORDER BY pcv.created_at DESC LIMIT 20";
$recent_violations_result = $conn->query($recent_violations_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Violations - Admin</title>
    <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        
        <div class="dashboard">
            <div class="userDetailsCard">
                <h2>Violations Dashboard</h2>
           <div style="display:flex; flex-direction:column; align-items:flex-end;">
                                            <a href="../logout.php" class="greenBtn Red">Logout</a>

                </div>            </div>
            
            <div class="navCard">
                <a href="adminDashboard.php">Dashboard</a>
                <a href="adminManageUsers.php">Manage Users</a>
                <a href="adminPriceCap.php">Price Caps</a>
                <a href="adminViolation.php" style="background: rgba(255,255,255,0.1);">Violations</a>
            </div>
            
            <?php if ($total_violations == 0): ?>
            <div class="alert-info">
                <strong>Note:</strong> No confirmed price cap violations found. Showing potential violations from transaction data.
            </div>
            <?php endif; ?>
            
            <div class="stats">
                <div class="card">
                    <h3>Price Violations</h3>
                    <div class="number"><?php echo $total_violations; ?></div>
                </div>
                <div class="card">
                    <h3>User Reports</h3>
                    <div class="number"><?php echo $violation_stats['total_reports'] ?? 0; ?></div>
                </div>
                <div class="card">
                    <h3>This Week</h3>
                    <div class="number"><?php echo $this_week_violations + $this_week_reports; ?></div>
                </div>
                <div class="card">
                    <h3>Avg Excess</h3>
                    <div class="number">৳ <?php echo number_format($avg_excess, 2); ?></div>
                </div>
            </div>
            <div class="quick-actions" style="padding-bottom:15px;">
        <div class="actions-grid">
                <a href="../admin/adminBlacklist.php" class="greenBtn">Blacklist</a>
            </div>
            </div>
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 15px;">User-Reported Violations</h2>
                
                <?php if ($user_reported_count > 0): ?>
                <table style="width: 100%; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reporter</th>
                            <th>Reported User</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($report = $user_reported_violations_result->fetch_assoc()): 
                            $status_class = strtolower($report['status']);
                            $type_class = strtolower(str_replace('_', '-', $report['violation_type']));
                        ?>
                        <tr>
                            <td>R#<?php echo $report['violation_id']; ?></td>
                            <td><?php echo date('M d, Y', strtotime($report['violation_date'])); ?></td>
                            <td>
                                <span class="status-badge <?php echo $type_class; ?>">
                                    <?php echo $report['violation_type']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="tableBoldText"><?php echo htmlspecialchars($report['reporter_name']); ?></div>
                                <div class="tablesmallText"><?php echo $report['reporter_role']; ?></div>
                            </td>
                            <td>
                                <div class="tableBoldText"><?php echo htmlspecialchars($report['reported_name']); ?></div>
                                <div class="tablesmallText"><?php echo $report['reported_role']; ?></div>
                            </td>
                            <td style="max-width: 200px;">
                                <div class="tablesmallText" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars($report['description']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $report['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if($report['status'] == 'PENDING' || $report['status'] == 'UNDER_REVIEW'): ?>
                                    <a href="adminViolationDecision.php?id=<?php echo $report['violation_id']; ?>" 
                                       class="smallBtn Cyan" 
                                       style="text-decoration: none; display: inline-block; padding: 5px 15px;">
                                       Take Action ⚖️
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 12px;">Resolved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #666;">
                    <p>No user-reported violations found.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="tables-section">
                <div class="table-box">
                    <h2>Top Price Violators</h2>
                    <?php if ($top_violators_result && $top_violators_result->num_rows > 0): ?>
                        <?php while ($violator = $top_violators_result->fetch_assoc()): ?>
                        <div class="batchCardItem">
                            <div>
                                <div class="tableBoldText"><?php echo htmlspecialchars($violator['username']); ?></div>
                                <div class="tablesmallText"><?php echo htmlspecialchars($violator['role_name']); ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div class="tableBoldText redText"><?php echo $violator['violation_count']; ?> violations</div>
                                <?php if ($violator['avg_excess'] > 0): ?>
                                <div class="tablesmallText">Avg excess: ৳<?php echo number_format($violator['avg_excess'], 2); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: #666;">No price violators found.</div>
                    <?php endif; ?>
                </div>
                
                <div class="table-box">
                    <h2>Most Reported Users</h2>
                    <?php if ($top_reported_result && $top_reported_result->num_rows > 0): ?>
                        <?php while ($reported = $top_reported_result->fetch_assoc()): ?>
                        <div class="batchCardItem">
                            <div>
                                <div class="tableBoldText"><?php echo htmlspecialchars($reported['username']); ?></div>
                                <div class="tablesmallText"><?php echo htmlspecialchars($reported['role_name']); ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div class="tableBoldText redText"><?php echo $reported['report_count']; ?> reports</div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: #666;">No user reports found.</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <div class="table-box">
                    <div class="table-top">
                        <h2>Price Cap Violations</h2>
                    </div>
                    <?php if ($recent_violations_result && $recent_violations_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Commodity</th>
                                <th>Seller</th>
                                <th>Reported Price</th>
                                <th>Excess</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($violation = $recent_violations_result->fetch_assoc()): ?>
                            <tr>
                                <td>V#<?php echo $violation['pc_violation_id']; ?></td>
                                <td><div class="tableBoldText"><?php echo htmlspecialchars($violation['commodity_name']); ?></div></td>
                                <td><div class="tableBoldText"><?php echo htmlspecialchars($violation['seller_name']); ?></div></td>
                                <td><div class="tableBoldText">৳ <?php echo number_format($violation['reported_price'], 2); ?></div></td>
                                <td><div class="tableBoldText redText">+৳ <?php echo number_format($violation['excess_amount'], 2); ?></div></td>
                                <td><div class="tablesmallText"><?php echo date('M d, Y', strtotime($violation['detected_date'])); ?></div></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align: center; padding: 40px;"><p>No price violations found.</p></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>
</body>
</html>