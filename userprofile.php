<?php
session_start();
require_once "config.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
$role_id=isset($_SESSION['role_id']);
$user_id = $_SESSION['user_id'];
$message = $error = "";
switch($role_id) {
    case 1:
        $dashboard_link = 'farmers/vendorDashboard.php';
        break;
    case 5: 
        $dashboard_link = 'admin/adminDashboard.php';
        break;
    case 6: 
        $dashboard_link = 'inspectors/inspectorDashboard.php';
        break;
    default:
        $dashboard_link = 'vendors/vendorDashboard.php';
        break;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $new_name = trim($_POST['username']);
        $new_email = trim($_POST['email']);
        $new_address = trim($_POST['address']);
        $new_location = $_POST['location'];
        
        if (empty($new_name) || empty($new_email)) {
            $error = "Name and Email are required.";
        } else {
            $update_sql = "UPDATE users SET username = ?, email = ?, address = ?, location = ? WHERE user_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ssssi", $new_name, $new_email, $new_address, $new_location, $user_id);
            
            if ($stmt->execute()) {
                $message = "✅ Profile updated successfully!";
                $_SESSION['username'] = $new_name; 
            } else {
                $error = "❌ Error updating profile: " . $conn->error;
            }
            $stmt->close();
        }
    }

    // --- Change Password ---
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $current_pass = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];
        
        $pwd_sql = "SELECT password FROM users WHERE user_id = ?";
        $pwd_stmt = $conn->prepare($pwd_sql);
        $pwd_stmt->bind_param("i", $user_id);
        $pwd_stmt->execute();
        $stored_hash = $pwd_stmt->get_result()->fetch_assoc()['password'];
        $pwd_stmt->close();
        
        if (password_verify($current_pass, $stored_hash)) {
            if ($new_pass === $confirm_pass) {
                if (strlen($new_pass) >= 6) {
                    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                    $update_pwd_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                    $stmt = $conn->prepare($update_pwd_sql);
                    $stmt->bind_param("si", $new_hash, $user_id);
                    
                    if ($stmt->execute()) {
                        $message = "✅ Password changed successfully!";
                    } else {
                        $error = "❌ Database error.";
                    }
                    $stmt->close();
                } else {
                    $error = "❌ New password must be at least 6 characters.";
                }
            } else {
                $error = "❌ New passwords do not match.";
            }
        } else {
            $error = "❌ Current password is incorrect.";
        }
    }
}

$sql = "SELECT u.*, r.role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        WHERE u.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$locations = ['Dhaka', 'Chittagong', 'Sylhet', 'Rajshahi', 'Rangpur', 'Khulna', 'Barisal', 'Mymensingh'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Syndicate Buster</title>
    
    <link rel="stylesheet" href="css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/userdetail.css?v=<?php echo time(); ?>">
    
    <style>
        .edit-section {
            display: none; 
            margin-top: 20px;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
   
        .back-link {
            display: inline-block;
            color: #214332;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 20px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            
           <div style="padding: 10px 20px;">
    <a href="<?php echo $dashboard_link; ?>" class="back-link">
        ← Back to Dashboard
    </a>
</div>

            <?php if ($message): ?>
                <div class="alert success" style="background:#d4edda; color:#155724; padding:15px; margin:0 20px 20px 20px; border-radius:5px;">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert error" style="background:#f8d7da; color:#721c24; padding:15px; margin:0 20px 20px 20px; border-radius:5px;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="user-header">
                <div class="user-avatar-large">
                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                </div>
                <div class="user-basic-info">
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-role"><?php echo htmlspecialchars($user['role_name']); ?></div>
                    <div style="margin-top: 5px;">
                        <span class="status status-<?php echo strtolower($user['account_status']); ?>" 
                              style="padding: 3px 8px; border-radius: 4px; font-size: 12px; background: #e8f5e9; color: #28a745;">
                            ● <?php echo $user['account_status']; ?>
                        </span>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div class="tablesmallText">Member Since</div>
                    <div class="tableBoldText"><?php echo date('M Y', strtotime($user['created_at'])); ?></div>
                    <div class="tablesmallText" style="margin-top:5px;">Trust Score</div>
                    <div class="tableBoldText" style="color: <?php echo ($user['trust_score'] > 80 ? '#28a745' : 'orange'); ?>;">
                        <?php echo $user['trust_score']; ?>/100
                    </div>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                        <h3 style="margin:0; color:#214332;">Contact Information</h3>
                        <button class="smallBtn Cyan" onclick="toggleEdit('editProfile')">Edit</button>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['phone']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">District</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['location']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Address</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['address']); ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <div style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                        <h3 style="margin:0; color:#214332;">Security</h3>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Password</span>
                        <button class="smallBtn Gray" onclick="toggleEdit('changePassword')">Change Password</button>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Last Login</span>
                        <span class="info-value">
                            <?php echo $user['last_login'] ? date('d M Y, h:i A', strtotime($user['last_login'])) : 'Never'; ?>
                        </span>
                    </div>
                    <div style="margin-top: 20px; text-align: center;">
                        <form action="../logout.php" method="POST">
                            <button type="submit" class="cardBtn Red" style="width:100%;">Sign Out</button>
                        </form>
                    </div>
                </div>
            </div>

            <div id="editProfile" class="gridCard edit-section">
                <h2 style="color: #214332; margin-bottom: 20px;">Edit Profile Details</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label class="info-label">Full Name</label>
                            <input type="text" name="username" class="inputAreaText" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="info-label">Email Address</label>
                            <input type="email" name="email" class="inputAreaText" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="info-label">District</label>
                            <select name="location" class="inputAreaText">
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo $loc; ?>" <?php echo ($user['location'] == $loc) ? 'selected' : ''; ?>>
                                        <?php echo $loc; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="info-label">Full Address</label>
                            <input type="text" name="address" class="inputAreaText" value="<?php echo htmlspecialchars($user['address']); ?>">
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" class="greenBtn">Save Changes</button>
                        <button type="button" class="smallBtn Gray" onclick="toggleEdit('editProfile')">Cancel</button>
                    </div>
                </form>
            </div>

            <div id="changePassword" class="gridCard edit-section">
                <h2 style="color: #214332; margin-bottom: 20px;">Change Password</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div style="max-width: 500px;">
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label class="info-label">Current Password</label>
                            <input type="password" name="current_password" class="inputAreaText" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label class="info-label">New Password</label>
                            <input type="password" name="new_password" class="inputAreaText" required minlength="6">
                        </div>
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label class="info-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="inputAreaText" required minlength="6">
                        </div>
                        
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="submit" class="greenBtn">Update Password</button>
                            <button type="button" class="smallBtn Gray" onclick="toggleEdit('changePassword')">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>

        </div>
        <div class="footer">
            <p>Syndicate Buster Admin Panel © 2024</p>
        </div>
    </div>

    <script>
        function toggleEdit(id) {
            document.querySelectorAll('.edit-section').forEach(el => {
                if (el.id !== id) el.style.display = 'none';
            });
            const el = document.getElementById(id);
            if (el.style.display === 'block') {
                el.style.display = 'none';
            } else {
                el.style.display = 'block';
                el.scrollIntoView({ behavior: 'smooth' });
            }
        }
    </script>
</body>
</html>