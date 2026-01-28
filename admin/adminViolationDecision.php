<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: ../login.php");
    exit();
}

$message = "";
$error = "";
$violation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'];
    $v_id = intval($_POST['violation_id']);
    $u_id = intval($_POST['reported_user_id']);
    
    $conn->begin_transaction();
    try {
        if ($decision === 'ban') {
            $conn->query("UPDATE users SET account_status = 'Banned', trust_score = 0 WHERE user_id = $u_id");
            $conn->query("UPDATE violations SET status = 'CONFIRMED' WHERE violation_id = $v_id");
            
            $stmt = $conn->prepare("INSERT INTO penalties (violation_id, penalty_type, issued_by, issued_date, status) VALUES (?, 'SUSPENSION', ?, CURDATE(), 'ISSUED')");
            $stmt->bind_param("ii", $v_id, $_SESSION['user_id']);
            $stmt->execute();
            
            $message = "🚫 User has been PERMANENTLY BANNED.";

        }
         else if ($decision === 'blacklist') {
            $conn->query("UPDATE users SET account_status = 'Blacklisted', trust_score = 0 WHERE user_id = $u_id");
            $conn->query("UPDATE violations SET status = 'CONFIRMED' WHERE violation_id = $v_id");
            
            $stmt = $conn->prepare("INSERT INTO penalties (violation_id, penalty_type, issued_by, issued_date, status) VALUES (?, 'SUSPENSION', ?, CURDATE(), 'ISSUED')");
            $stmt->bind_param("ii", $v_id, $_SESSION['user_id']);
            $stmt->execute();
            
            $message = "🚫 User has been BLACKLISTED.";

        } elseif ($decision === 'suspend') {
            $days = intval($_POST['suspend_days']);
            $end_date = date('Y-m-d', strtotime("+$days days"));
            
            $conn->query("UPDATE users SET account_status = 'Suspended' WHERE user_id = $u_id");
            $conn->query("UPDATE violations SET status = 'CONFIRMED' WHERE violation_id = $v_id");
            
            $stmt = $conn->prepare("INSERT INTO penalties (violation_id, penalty_type, suspension_days, issued_by, issued_date) VALUES (?, 'SUSPENSION', ?, ?, CURDATE())");
            $stmt->bind_param("iii", $v_id, $days, $_SESSION['user_id']);
            $stmt->execute();
            
            $penalty_id = $conn->insert_id;
            
            $stmt = $conn->prepare("INSERT INTO user_suspensions (penalty_id, user_id, start_date, end_date) VALUES (?, ?, CURDATE(), ?)");
            $stmt->bind_param("iis", $penalty_id, $u_id, $end_date);
            $stmt->execute();
            
            $message = "⏳ User suspended for $days days (until $end_date).";

        } elseif ($decision === 'reject') {
            $conn->query("UPDATE violations SET status = 'REJECTED' WHERE violation_id = $v_id");
            $message = "✅ Report rejected. No action taken against user.";
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Action failed: " . $e->getMessage();
    }
    $violation_id = $v_id;
}

$sql = "SELECT v.*, u.username, u.trust_score, u.account_status, u.location, u.phone,
               r.username as reporter_name
        FROM violations v
        JOIN users u ON v.reported_user_id = u.user_id
        LEFT JOIN users r ON v.reporter_id = r.user_id
        WHERE v.violation_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $violation_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    echo "<div class='container'><div class='dashboard'><div class='alert error'>Violation ID not found. <a href='adminViolation.php'>Go Back</a></div></div></div>";
    exit();
}

$history_sql = "SELECT * FROM violations 
                WHERE reported_user_id = ? AND status = 'CONFIRMED' AND violation_id != ?";
$hist_stmt = $conn->prepare($history_sql);
$hist_stmt->bind_param("ii", $data['reported_user_id'], $violation_id);
$hist_stmt->execute();
$history = $hist_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Violation Decision - Admin</title>
    <link rel="stylesheet" href="../css/page.css">
    <link rel="stylesheet" href="../css/cards.css">
    <link rel="stylesheet" href="../css/button.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/form.css">
    <style>
        .decision-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .user-profile-card { background: white; border-top: 5px solid #214332; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .violation-card { background: #fff5f5; border: 1px solid #ffcdd2; padding: 20px; border-radius: 8px; }
        .score-box { font-size: 24px; font-weight: bold; padding: 10px; border-radius: 5px; text-align: center; margin-top: 10px;}
        .score-good { background: #d4edda; color: #155724; }
        .score-bad { background: #f8d7da; color: #721c24; }
        .action-panel { grid-column: 1 / -1; background: #e8f5e9; padding: 25px; border-radius: 10px; border: 1px solid #c8e6c9; margin-top: 20px; }
        .action-btn-group { display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; }
        .decision-form { background: white; padding: 20px; border-radius: 8px; text-align: center; flex: 1; max-width: 300px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>

        <div class="dashboard">
  <div class="userDetailsCard">
                 <h2 class="GreenTextLarge">Admin Decision Console</h2>
                     <div style="display:flex;     grid-gap:10px;">
                        <a href="../admin/adminViolation.php" type="submit" class="greenBtn Cyan">← Back to List</a>
                </div> 
            </div>

            <?php if ($message): ?>
                <div class="alert success" style="background:#d4edda; padding:15px; margin:20px 0; color:#155724; border-radius:5px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert error" style="background:#f8d7da; padding:15px; margin:20px 0; color:#721c24; border-radius:5px;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="decision-container">
                
                <div class="user-profile-card">
                    <h2 style="color: #214332; border-bottom: 1px solid #eee; padding-bottom: 10px;">👤 The Accused</h2>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($data['username']); ?></p>
                    <p><strong>Location:</strong> <?php echo htmlspecialchars($data['location']); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($data['phone']); ?></p>
                    <p><strong>Status:</strong> 
                        <span style="font-weight:bold; color: <?php echo $data['account_status']=='Active'?'green':'red'; ?>">
                            <?php echo $data['account_status']; ?>
                        </span>
                    </p>
                    
                    <div class="score-box <?php echo ($data['trust_score'] > 70) ? 'score-good' : 'score-bad'; ?>">
                        Trust Score: <?php echo $data['trust_score']; ?>/100
                    </div>

                    <h3 style="margin-top: 20px; font-size: 16px;">📜 Previous Offenses</h3>
                    <?php if ($history->num_rows > 0): ?>
                        <ul style="padding-left: 20px;">
                        <?php while($h = $history->fetch_assoc()): ?>
                            <li style="color: #c62828; margin-bottom: 5px;">
                                <strong><?php echo $h['violation_date']; ?>:</strong> <?php echo $h['violation_type']; ?>
                            </li>
                        <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color: #28a745; font-style: italic;">Clean Record (First Offense)</p>
                    <?php endif; ?>
                </div>

                <div class="violation-card">
                    <h2 style="color: #c62828; border-bottom: 1px solid #ffcdd2; padding-bottom: 10px;">🚨 Current Report</h2>
                    <p><strong>Type:</strong> <?php echo $data['violation_type']; ?></p>
                    <p><strong>Date:</strong> <?php echo $data['violation_date']; ?></p>
                    <p><strong>Reporter:</strong> <?php echo htmlspecialchars($data['reporter_name']); ?></p>
                    
                    <div style="background: white; padding: 15px; border-radius: 5px; margin-top: 15px; border: 1px dashed #c62828;">
                        <strong>Description:</strong><br>
                        <?php echo nl2br(htmlspecialchars($data['description'])); ?>
                    </div>
                </div>

                <?php if($data['status'] === 'PENDING' || $data['status'] === 'UNDER_REVIEW'): ?>
                <div class="action-panel">
                    <h2 style="text-align: center; color: #214332; margin-bottom: 20px;">⚖️ Take Action</h2>
                    
                    <div class="action-btn-group">
                        
                        <form method="POST" class="decision-form" style="display:flex; flex-direction:column;  justify-content:space-between;">
                            <input type="hidden" name="decision" value="suspend">
                            <input type="hidden" name="violation_id" value="<?php echo $data['violation_id']; ?>">
                            <input type="hidden" name="reported_user_id" value="<?php echo $data['reported_user_id']; ?>">
                            
                            <h3 style="color: #f39c12;">Temporary Suspension</h3>
                            <select name="suspend_days" class="inputAreaText" style="margin: 10px 0; width: 100%;">
                                <option value="7">7 Days</option>
                                <option value="15">15 Days</option>
                                <option value="30">30 Days</option>
                            </select>
                            <button type="submit" class="smallBtn" style="background: #f39c12; color: white; width: 100%;">Suspend User</button>
                        </form>
                        <form method="POST" class="decision-form" style="display:flex; flex-direction:column;  justify-content:space-between;">
                            <input type="hidden" name="decision" value="blacklist">
                            <input type="hidden" name="violation_id" value="<?php echo $data['violation_id']; ?>">
                            <input type="hidden" name="reported_user_id" value="<?php echo $data['reported_user_id']; ?>">
                            
                            <h3 style="color: #c62828;"> Blacklist </h3>
                            <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Disable account access.</p>
                            <button type="submit" class="smallBtn Red" onclick="return confirm('Are you sure you want to PERMANENTLY BLACKLIST this user?');" style="width: 100%;">Blacklist User</button>
                        </form>

                        <form method="POST" class="decision-form" style="display:flex; flex-direction:column;  justify-content:space-between;">
                            <input type="hidden" name="decision" value="ban">
                            <input type="hidden" name="violation_id" value="<?php echo $data['violation_id']; ?>">
                            <input type="hidden" name="reported_user_id" value="<?php echo $data['reported_user_id']; ?>">
                            
                            <h3 style="color: #821a1a;">Permanent Ban</h3>
                            <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Disable account access forever.</p>
                            <button type="submit" class="smallBtn DarkRed" onclick="return confirm('Are you sure you want to PERMANENTLY BAN this user?');" style="width: 100%;">Ban User</button>
                        </form>

                        <form method="POST" class="decision-form">
                            <input type="hidden" name="decision" value="reject">
                            <input type="hidden" name="violation_id" value="<?php echo $data['violation_id']; ?>">
                            
                            <h3 style="color: #214332;">Reject Report</h3>
                            <p style="font-size: 12px; color: #666; margin-bottom: 15px;">Dismiss report as invalid.</p>
                            <button type="submit" class="smallBtn Gray" style="width: 100%;">Dismiss</button>
                        </form>

                    </div>
                </div>
                <?php else: ?>
                    <div class="action-panel" style="text-align: center; background: #eee;">
                        <h3>This case is closed.</h3>
                        <p>Status: <strong style="color: <?php echo $data['status'] == 'CONFIRMED' ? '#c62828' : '#28a745'; ?>"><?php echo $data['status']; ?></strong></p>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>