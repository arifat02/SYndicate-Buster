<?php
session_start();    
require_once "../config.php";
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}

// Count totals
$total_users_result = $conn->query("SELECT COUNT(*) as total FROM users");
$total_users = $total_users_result->fetch_assoc()['total'];

$total_active_users_result = $conn->query("SELECT COUNT(*) as total FROM users WHERE account_status = 'Active'");
$total_active_users = $total_active_users_result->fetch_assoc()['total'];

$total_suspended_users_result = $conn->query("SELECT COUNT(*) as total FROM users WHERE account_status = 'Suspended'");
$total_suspended_users = $total_suspended_users_result->fetch_assoc()['total'];

$total_banned_users_result = $conn->query("SELECT COUNT(*) as total FROM users WHERE account_status = 'Banned'");
$total_banned_users = $total_banned_users_result->fetch_assoc()['total'];

$total_pending_result = $conn->query("SELECT COUNT(*) as total FROM users WHERE account_status = 'Under_Review'");
$total_pending_users = $total_pending_result->fetch_assoc()['total'];

$total_blacklisted_result = $conn->query("SELECT COUNT(*) as total FROM users WHERE account_status = 'Blacklisted'");
$total_blacklisted_users = $total_blacklisted_result->fetch_assoc()['total'];

// Handle actions
if (isset($_GET['action']) && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    $action = $_GET['action'];
    
    switch($action) {
        case 'activate':
            $status = 'Active';
            $message = "User activated successfully!";
            break;
        case 'suspend':
            $status = 'Suspended';
            $message = "User suspended!";
            break;
        case 'ban':
            $status = 'Banned';
            $message = "User banned!";
            break;
        case 'blacklist':
            $status = 'Blacklisted';
            $message = "User blacklisted!";
            break;
        case 'approve':
            $status = 'Active';
            $message = "User approved!";
            break;
        case 'reject':
            $status = 'Inactive';
            $message = "Registration rejected!";
            break;
        default:
            $status = 'Active';
            $message = "Status updated!";
    }
    
    $sql = "UPDATE users SET account_status = ?, updated_at = NOW() WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $user_id);
    
    if ($stmt->execute()) {
        $success = $message;
    } else {
        $error = "Error updating user.";
    }
}

// Get all users
$users_result = $conn->query(
    "SELECT *
    FROM users
    JOIN roles ON users.role_id = roles.role_id
    ORDER BY user_id ASC"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin</title>
    <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    <style>
        
      
      
        
       
       
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        
        <div class="dashboard">
            <div class="userDetailsCard">
                <h2>Manage Users</h2>
                <div style="display:flex; flex-direction:column; align-items:flex-end;">
                    <a href="../logout.php" class="greenBtn Red">Logout</a>
                </div>            
            </div>
            
              <div class="navCard">
                <a href="adminDashboard.php"> Dashboard</a>
                <a href="adminManageUsers.php"style="background: rgba(255,255,255,0.1);">Manage Users</a>
                <a href="adminPriceCap.php" >
                    Price Caps
                </a>
                <a href="adminViolation.php">Violations</a>
            </div>
            
            <?php if(isset($success)): ?>
                <div class="success" style="margin: 15px;"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
                <div class="error" style="margin: 15px;"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="user-actions-grid">
                <div class="action-card">
                    <h3>Add New User</h3>
                    <div style="font-size: 14px; color: #666; margin-bottom: 15px;">
                        Create new user accounts
                    </div>
                    <a href="../admin/adminAddUser.php" class="greenBtn" style="padding: 8px 20px;">+ Add User</a>
                </div>
                
                <div class="action-card">
                    <h3>Approve Registrations</h3>
                    <div class="action-number pending"><?php echo $total_pending_users; ?></div>
                    <div style="font-size: 14px; color: #666; margin-bottom: 10px;">
                        Pending approvals
                    </div>
                    <?php if($total_pending_users > 0): ?>
                        <a href="../admin/admin_user_approval.php" class="cardLinkBtn purple">View All</a>
                    <?php else: ?>
                    <div style="color: #4CAF50; font-size: 14px;">✓ All approved</div>
                    <?php endif; ?>
                </div>
                
                <div class="action-card">
                    <h3>Blacklisted Users</h3>
                    <div class="action-number blacklisted"><?php echo $total_blacklisted_users; ?></div>
                    <div style="font-size: 14px; color: #666; margin-bottom: 10px;">
                        Banned/Blacklisted users
                    </div>
                    <?php if($total_blacklisted_users > 0): ?>
                        <a href="?view=blacklisted" class="CardLinkBtn Red">Manage</a>
                    <?php else: ?>
                    <div style="color: #4CAF50; font-size: 14px;">✓ No blacklisted users</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="stats">
                <div class="card">
                    <h3>Total Users</h3>
                    <div class="number"><?php echo $total_users; ?></div>
                </div>
                <div class="card">
                    <h3>Active Users</h3>
                    <div class="number"><?php echo $total_active_users; ?></div>
                </div>
                <div class="card">
                    <h3>Suspended</h3>
                    <div class="number"><?php echo $total_suspended_users; ?></div>
                </div>
                <div class="card">
                    <h3>Banned</h3>
                    <div class="number"><?php echo $total_banned_users; ?></div>
                </div>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 15px;">Search & Filter</h2>
                <div class="filter-grid">
                    <div class="search-box">
                        <input type="text" placeholder="Search by name, email or phone..." id="searchInput">
                        <button class="smallBtn Green">Search</button>
                    </div>
                    
                    <select class="filterText" onchange="filterUsers()">
                        <option value="">All Roles</option>
                        <option value="1">Farmer</option>
                        <option value="2">Middleman</option>
                        <option value="3">Wholesaler</option>
                        <option value="4">Retailer</option>
                        <option value="5">Admin</option>
                    </select>
                    
                    <select onchange="filterUsers()" class="filterText">
                        <option value="">All Status</option>
                        <option value="Active">Active</option>
                        <option value="Suspended">Suspended</option>
                        <option value="Banned">Banned</option>
                        <option value="Blacklisted">Blacklisted</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Under_Review">Pending Review</option>
                    </select>
                </div>
            </div>
            
            <!-- Users Table -->
            <div class="card">
                <div class="table-box">
                    <h2 style="color: #214332; margin-bottom: 15px;">All Users</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Trust Score</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users_result->num_rows > 0): ?>
                                <?php while ($user = $users_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php 
                                                    $names = explode(" ", $user['username']);
                                                    echo strtoupper($names[0][0] . ($names[1][0] ?? ""));
                                                    ?>
                                                </div>
                                                <div class="tableFlexCol">
                                                    <div class="tableBoldText"><?php echo htmlspecialchars($user['username']); ?></div>
                                                    <div class="tablesmallText"><?php echo htmlspecialchars($user['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="tablesmallText">                     
                                                <?php echo htmlspecialchars($user['role_name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo strtolower($user['account_status']); ?>">
                                                <?php echo htmlspecialchars($user['account_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="table-trust-score trust-<?php echo ($user['trust_score'] >= 80) ? 'high' : 'low'; ?>">
                                                <?php echo $user['trust_score'] . '/100'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <?php if($user['account_status'] == 'Under_Review'): ?>
                                                    <a href="?action=approve&user_id=<?php echo $user['user_id']; ?>" 
                                                       class="cardLinkBtn LightGreen"
                                                       onclick="return confirm('Approve this user?')">Approve</a>
                                                    <a href="?action=reject&user_id=<?php echo $user['user_id']; ?>" 
                                                       class="cardLinkBtn Red"
                                                       onclick="return confirm('Reject this registration?')">Reject</a>
                                                <?php else: ?>
                                                    <?php if($user['account_status'] == 'Active'): ?>
                                                            <a href="../admin/adminUserViewPage.php?id=<?php echo $user['user_id']; ?>" 
                                                           class="cardLinkBtn Cyan"
                                                           >View</a>
                                                    <?php endif; ?>

                                                   
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">No users found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © 2026</p>
            </div>
        </div>
    </div>
</body>
</html>