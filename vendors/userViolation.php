<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$users_sql = "SELECT account_status as current_status, trust_score FROM users WHERE user_id = ?";
$users_stmt = $conn->prepare($users_sql);
$users_stmt->bind_param("i", $user_id);
$users_stmt->execute();
$users_result = $users_stmt->get_result();
$users_status = $users_result->fetch_assoc();
$users_stmt->close();

$statusColors = [
    'Active' => '#28a745',    
    'Inactive' => '#6c757d',  
    'Suspended' => '#ffc107', 
    'Blacklisted' => '#dc3545',
    'Under_Review' => '#6f42c1',
    'Banned' => '#dc3545'
];
$backgroundColors = [
    'Active' => '#d4edda',    
    'Inactive' => '#e2e3e5',  
    'Suspended' => '#fff3cd', 
    'Blacklisted' => '#f8d7da',
    'Under_Review' => '#e2d9f3',
    'Banned' => '#f8d7da'
];

$current_status = $users_status['current_status'] ?? 'Active';
$borderColor = $statusColors[$current_status] ?? '#dc3545';
$backgroundColor = $backgroundColors[$current_status] ?? '#f8d7da';
$statusDisplay = ucfirst(str_replace('_', ' ', $current_status));

$violations_count_sql = "SELECT COUNT(*) AS violation_count 
                         FROM violations 
                         WHERE reported_user_id = ?";
$violations_count_stmt = $conn->prepare($violations_count_sql);
$violations_count_stmt->bind_param("i", $user_id);
$violations_count_stmt->execute();
$violations_count = $violations_count_stmt->get_result()->fetch_assoc();
$violations_count_stmt->close();


$fines_sql = "SELECT SUM(p.fine_amount) AS total_fines
              FROM violations v
              JOIN penalties p ON v.violation_id = p.violation_id
              WHERE v.reported_user_id = ? 
              AND p.status = 'ISSUED'";
$fines_stmt = $conn->prepare($fines_sql);
$fines_stmt->bind_param("i", $user_id);
$fines_stmt->execute();
$fines_result = $fines_stmt->get_result()->fetch_assoc();
$total_fines = $fines_result['total_fines'] ?? 0;
$fines_stmt->close();

$suspension_sql = "SELECT us.end_date 
                   FROM user_suspensions us
                   JOIN penalties p ON us.penalty_id = p.penalty_id
                   JOIN violations v ON p.violation_id = v.violation_id
                   WHERE us.user_id = ? 
                   AND us.status = 'ACTIVE'
                   AND us.end_date >= CURDATE()
                   ORDER BY us.end_date DESC 
                   LIMIT 1";
$suspension_stmt = $conn->prepare($suspension_sql);
$suspension_stmt->bind_param("i", $user_id);
$suspension_stmt->execute();
$suspension_result = $suspension_stmt->get_result()->fetch_assoc();
$suspension_stmt->close();

$remaining_days = 0;
if ($suspension_result && isset($suspension_result['end_date'])) {
    $end_date = new DateTime($suspension_result['end_date']);
    $today = new DateTime();
    if ($end_date > $today) {
        $remaining_days = $today->diff($end_date)->days + 1;
    }
}

$penalties_sql = "SELECT 
                  p.penalty_id AS penalty_id,
                  p.penalty_type AS penalty_type,
                  v.violation_date AS violation_date,
                  v.description AS reason,
                  p.fine_amount AS fine_amount,
                  p.suspension_days AS suspension_days,
                  p.status AS penalty_status,
                  v.status AS violation_status
              FROM violations v
              JOIN penalties p ON v.violation_id = p.violation_id
              WHERE v.reported_user_id = ?
              ORDER BY v.violation_date DESC";
$penalties_stmt = $conn->prepare($penalties_sql);
$penalties_stmt->bind_param("i", $user_id);
$penalties_stmt->execute();
$penalties = $penalties_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total_penalties = count($penalties);
$penalties_stmt->close();

$total_due_fines = 0;
foreach ($penalties as $penalty) {
    if ($penalty['penalty_status'] === 'ISSUED' && $penalty['fine_amount'] > 0) {
        $total_due_fines += $penalty['fine_amount'];
    }
}

$violations_history_sql = "SELECT 
    DATE(v.violation_date) as violation_date,
    c.commodity_name as commodity,
    t.unit_price as your_price,
    pc.max_price_per_unit as price_cap,
    ROUND(((t.unit_price - pc.max_price_per_unit) / pc.max_price_per_unit * 100), 2) as excess_percent,
    v.description as reason,
    v.status as violation_status,
    p.fine_amount,
    p.suspension_days,
    p.penalty_type,
    v.violation_type,
    reporter.username as reported_by,
    v.violation_id
FROM violations v
LEFT JOIN price_cap_violations pcv ON v.violation_id = pcv.violation_id
LEFT JOIN transactions t ON pcv.transaction_id = t.transaction_id
LEFT JOIN batches b ON t.batch_id = b.batch_id
LEFT JOIN commodities c ON b.commodity_id = c.commodity_id
LEFT JOIN price_caps pc ON c.commodity_id = pc.commodity_id 
    AND v.violation_date BETWEEN pc.effective_date AND COALESCE(pc.expiry_date, '9999-12-31')
LEFT JOIN penalties p ON v.violation_id = p.violation_id
LEFT JOIN users reporter ON v.reporter_id = reporter.user_id
WHERE v.reported_user_id = ?
AND v.violation_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
ORDER BY v.violation_date DESC";
$violations_history_stmt = $conn->prepare($violations_history_sql);
$violations_history_stmt->bind_param("i", $user_id);
$violations_history_stmt->execute();
$violations_history = $violations_history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total_violations_history = count($violations_history);
$violations_history_stmt->close();

$appeals_sql = "SELECT 
                a.appeal_id,
                a.appeal_date,
                a.status as appeal_status,
                a.review_date,
                a.review_notes,
                a.appeal_reason,
                v.description as violation_reason,
                u.username as reviewed_by_name
            FROM appeals a
            JOIN violations v ON a.violation_id = v.violation_id
            LEFT JOIN users u ON a.reviewed_by = u.user_id
            WHERE a.user_id = ?
            ORDER BY a.appeal_date DESC
            LIMIT 1";
$appeals_stmt = $conn->prepare($appeals_sql);
$appeals_stmt->bind_param("i", $user_id);
$appeals_stmt->execute();
$appeals_result = $appeals_stmt->get_result();
$appeals_status = $appeals_result->fetch_assoc();
$appeals_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Violations - Syndicate Buster</title> 
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
                <div>
                    <h1>My Violations & Penalties</h1>
                </div>
                <div>
                    <a href="../logout.php" class="smallBtn Red">Logout</a>
                </div>
            </div>
            
              <div class="navCard">
                <a href="../vendors/vendorDashboard.php">Dashboard</a>
                <a href="../vendors/userProductReg.php">Product Registration</a>
                <a href="../vendors/userCreateListing.php">Create Listing</a>
                <a href="../vendors/userMarketplace.php">Marketplace</a>
                <a href="../vendors/userTransaction.php">Transactions logs</a>
                <a href="../vendors/userViolation.php"  style="background: rgba(255,255,255,0.1);">Policy & Violation</a>
            </div>
           <?php if ($current_status === 'Blacklisted' || $current_status === 'Suspended'): ?>
            <div class="alert" style="background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; padding: 15px; margin: 15px 0; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px;">⚠</span>
                    <div>
                        <strong style="font-size: 18px;">ACCOUNT RESTRICTED: <?php echo $statusDisplay; ?></strong>
                        <p>
                            <?php if ($current_status === 'Suspended'): ?>
                                Your account is suspended for <?php echo $remaining_days; ?> more days. You cannot make new sales during this period.
                            <?php else: ?>
                                Your account is blacklisted. All selling privileges are suspended. Contact support to appeal.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">Account Status Summary</h2>
                <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 15px; background: <?php echo $backgroundColor; ?>; border-radius: 8px;">
                        <div style="font-size: 32px; font-weight: bold; color: <?php echo $borderColor; ?>;"><?php echo htmlspecialchars($statusDisplay); ?></div>
                        <div class="tablesmallText">Current Status</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #fff3cd; border-radius: 8px;">
                        <div style="font-size: 28px; font-weight: bold; color: #856404;"><?php echo $violations_count['violation_count'] ?? 0; ?></div>
                        <div class="tablesmallText">Total Violations</div>
                    </div>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           
                    <div style="text-align: center; padding: 15px; background: #cce5ff; border-radius: 8px;">
                        <div style="font-size: 28px; font-weight: bold; color: #004085;">৳ <?php echo number_format($total_fines, 2); ?></div>
                        <div class="tablesmallText">Total Fines</div>
                    </div>
                    <?php if ($remaining_days > 0): ?>
                    <div style="text-align: center; padding: 15px; background: #f8d7da; border-radius: 8px;">
                        <div style="font-size: 28px; font-weight: bold; color: #721c24;">
                            <?php echo $remaining_days; ?> days
                        </div>
                        <div class="tablesmallText">Suspension Remaining</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="button-grid" style="padding:10px;">
                <a href="../vendors/userViolationReport.php" class="smallBtn Cyan">
                    🚨 Report a Violation
                </a>
                    </div>
            <div class="gridCard">
                <h2 class="GreenTextLarge">Fines & Penalties</h2>
                
                <?php if ($total_due_fines > 0): ?>
                <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin: 15px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div class="tableBoldText">Total Due: ৳ <?php echo number_format($total_due_fines, 2); ?></div>
                            <div class="tablesmallText">Pay fines to restore trust score</div>
                        </div>
                        <button class="btn btn-pay" onclick="payFines()">Pay Now</button>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if($total_penalties > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr class="tableBoldText">
                            <th>Penalty ID</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reason</th>
                            <th>Amount/Days</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody class="tablesmallText">
                        <?php foreach($penalties as $penalty): 
                            $penalty_text = '';
                            if ($penalty['penalty_type'] === 'FINE' && $penalty['fine_amount'] > 0) {
                                $penalty_text = '৳ ' . number_format($penalty['fine_amount'], 2);
                            } elseif ($penalty['penalty_type'] === 'SUSPENSION' && $penalty['suspension_days'] > 0) {
                                $penalty_text = $penalty['suspension_days'] . ' days';
                            } else {
                                $penalty_text = 'Warning';
                            }
                        ?>
                        <tr>
                            <td>#<?php echo htmlspecialchars($penalty['penalty_id']); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($penalty['violation_date'])); ?></td>
                            <td><?php echo htmlspecialchars($penalty['penalty_type']); ?></td>
                            <td><?php echo htmlspecialchars($penalty['reason']); ?></td>
                            <td><?php echo $penalty_text; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($penalty['penalty_status']); ?>">
                                    <?php echo htmlspecialchars($penalty['penalty_status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($penalty['penalty_type'] === 'FINE' && $penalty['penalty_status'] === 'ISSUED'): ?>
                                    <button class="btn btn-pay" onclick="payFine(<?php echo $penalty['penalty_id']; ?>)">Pay</button>
                                <?php else: ?>
                                    <span class="tablesmallText">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No penalties found. Keep following the rules! 👍
                    </div>
                <?php endif; ?>
                
                <?php if ($total_due_fines > 0): ?>
                <div style="text-align: center; margin-top: 20px;">
                    <button class="smallBtn Green" style="padding: 12px 30px;" onclick="payAllFines()">
                        Pay All Fines (৳ <?php echo number_format($total_due_fines, 2); ?>)
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Violation History -->
            <div class="gridCard">
                <div class="table-top">
                    <h2 class="GreenTextLarge">Violation History</h2>
                    <span class="tablesmallText">Last 12 months</span>
                </div>
                
                <?php if($total_violations_history > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr class="tableBoldText">
                            <th>Date</th>
                            <th>Type</th>
                            <th>Commodity</th>
                            <th>Your Price</th>
                            <th>Price Cap</th>
                            <th>Excess %</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Penalty</th>
                        </tr>
                    </thead>
                    <tbody class="tablesmallText">
                        <?php foreach($violations_history as $history): 
                            $excess_percentage = $history['excess_percent'] ?? 0;
                            $severity = 'Low';
                            $severity_color = 'low';
                            
                            if($excess_percentage > 50) {
                                $severity = 'Critical';
                                $severity_color = 'critical';
                            } elseif($excess_percentage > 25) {
                                $severity = 'High';
                                $severity_color = 'high';
                            } elseif($excess_percentage > 10) {
                                $severity = 'Medium';
                                $severity_color = 'medium';
                            }
                            
                            $penalty_text = 'No Penalty';
                            if($history['fine_amount'] > 0) {
                                $penalty_text = 'Fine: ৳' . number_format($history['fine_amount'], 2);
                            } elseif($history['suspension_days'] > 0) {
                                $penalty_text = 'Suspension: ' . $history['suspension_days'] . ' days';
                            } elseif($history['penalty_type'] === 'WARNING') {
                                $penalty_text = 'Warning';
                            }
                        ?>                     
                        <tr>      
                            <td><?php echo date('Y-m-d', strtotime($history['violation_date'])); ?></td>
                            <td><?php echo htmlspecialchars($history['violation_type']?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($history['commodity'] ?? 'N/A'); ?></td>
                            <td>৳ <?php echo number_format($history['your_price'] ?? 0, 2); ?></td>
                            <td>৳ <?php echo number_format($history['price_cap'] ?? 0, 2); ?></td>
                            <td>
                                <span style="color: <?php echo $excess_percentage > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: bold;">
                                    <?php echo number_format($excess_percentage, 2); ?>%
                                </span>
                            </td>
                            <td>
                                <span class="severity-badge severity-<?php echo $severity_color; ?>">
                                    <?php echo $severity; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($history['violation_status'] ?? 'pending'); ?>">
                                    <?php echo htmlspecialchars($history['violation_status'] ?? 'Pending'); ?>
                                </span>
                            </td>
                            <td><?php echo $penalty_text; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    No violation history found in the last 12 months. Good job! 🎉
                </div>
                <?php endif; ?>
            </div>
            
            <div class="gridCard">
                <h2>Submit Appeal</h2>
                <div style="background: #e8f5e9; padding: 20px; border-radius: 8px; margin: 15px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <h3 style="color: #155724; margin-bottom: 10px;">Appeal Process</h3>
                            <p style="color: #666; margin-bottom: 15px;">
                                If you believe a penalty was issued unfairly, you can submit an appeal. 
                                Our admin team will review your appeal within 3-5 business days.
                            </p>
                            <div class="tablesmallText">
                                <strong>Note:</strong> Include supporting documents for better chances.
                            </div>
                        </div>
                        <a href="../vendors/submitAppeal.php" class="smallBtn Red" style="padding: 12px 24px;">Submit New Appeal</a>
                    </div>
                </div>
                
                <?php if ($appeals_status): ?>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 20px;">
                    <h3 style="color: #214332; margin-bottom: 10px;">Current Appeal Status</h3>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                        <div>
                            <div class="tablesmallText">Appeal ID</div>
                            <div class="tableBoldText">#<?php echo htmlspecialchars($appeals_status['appeal_id']); ?></div>
                        </div>
                        <div>
                            <div class="tablesmallText">Submitted On</div>
                            <div class="tableBoldText"><?php echo date('d M Y', strtotime($appeals_status['appeal_date'])); ?></div>
                        </div>
                        <div>
                            <div class="tablesmallText">Status</div>
                            <span class="status-badge status-<?php echo strtolower($appeals_status['appeal_status']); ?>">
                                <?php echo htmlspecialchars($appeals_status['appeal_status']); ?>
                            </span>
                        </div>
                        <div>
                            <div class="tablesmallText">Last Reviewed</div>
                            <div class="tableBoldText">
                                <?php echo $appeals_status['review_date'] ? date('d M Y', strtotime($appeals_status['review_date'])) : 'Pending'; ?>
                            </div>
                        </div>
                        <div style="grid-column: span 2;">
                            <div class="tablesmallText">Appeal Reason</div>
                            <div class="tableBoldText"><?php echo htmlspecialchars($appeals_status['appeal_reason']); ?></div>
                        </div>
                        <div style="grid-column: span 2;">
                            <div class="tablesmallText">Admin Remarks</div>
                            <div class="tableBoldText">
                                <?php echo $appeals_status['review_notes'] ? htmlspecialchars($appeals_status['review_notes']) : 'Awaiting review'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-top: 20px; text-align: center;">
                    <p class="tablesmallText">No active appeals. Submit an appeal if you need to contest a penalty.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="gridCard" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <div class="card">
                    <h2>Trust Score Impact</h2>
                    <div style="text-align: center; margin: 20px 0;">
                        <?php 
                        $trust_score = $users_status['trust_score'] ?? 100;
                        $trust_class = 'trust-excellent';
                        if($trust_score < 60) {
                            $trust_class = 'trust-poor';
                        } elseif($trust_score < 80) {
                            $trust_class = 'trust-good';
                        }
                        ?>
                        <div class="trust-score <?php echo $trust_class; ?>">
                            <?php echo $trust_score; ?>
                        </div>
                        <div class="tablesmallText">out of 100</div>
                    </div>
                    <div class="inventory-item">
                        <span>Violations Impact</span>
                        <span><strong>-<?php echo 100 - $trust_score; ?> points</strong></span>
                    </div>
                    <div class="inventory-item">
                        <span>Clean Record Bonus</span>
                        <span><strong>+5 points/month</strong></span>
                    </div>
                    <div class="inventory-item">
                        <span>Recovery Time</span>
                        <span><strong><?php echo ceil((100 - $trust_score) / 5); ?> months</strong></span>
                    </div>
                </div>
                 
                <div class="card">
                    <h2>Avoid Future Violations</h2>
                    <div style="margin-top: 15px;">
                        <div style="display: flex; align-items: start; gap: 10px; margin-bottom: 10px;">
                            <span style="color: #28a745;">✓</span>
                            <span>Always check current price caps before selling</span>
                        </div>
                        <div style="display: flex; align-items: start; gap: 10px; margin-bottom: 10px;">
                            <span style="color: #28a745;">✓</span>
                            <span>Keep prices within 10% of government caps</span>
                        </div>
                    </div>
                </div>
            </div>  
        <div class="footer">
            <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
        </div>
    </div>
    <script>
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.display = 'none';
        });
    }, 5000);
    </script>
</body>
</html>