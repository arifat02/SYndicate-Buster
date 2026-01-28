<?php
session_start();    
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}

if (isset($_POST['action']) && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    $action = $_POST['action'];
    
    if ($action == 'approve') {
        $status = 'Active';
        $message = "User approved successfully!";
    } elseif ($action == 'reject') {
        $status = 'Inactive';
        $message = "User registration rejected.";
    }
    
    $sql = "UPDATE users SET account_status = ?, updated_at = NOW() WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $user_id);
    
    if ($stmt->execute()) {
        $success = $message;
    } else {
        $error = "Error updating user status.";
    }
}

$sql = "SELECT u.*, r.role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        WHERE u.account_status = 'Under_Review' 
        ORDER BY u.created_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Users - Admin</title>
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
                <h2>Approve User Registrations</h2>
                <button class="smallBtn Red" onclick="window.location.href='logout.php'">Logout</button>
            </div>
         
            <?php if(isset($success)): ?>
                <div class="success" style="margin: 15px;"><?php echo $success; ?></div>
                

            <?php endif; ?>
            
            <?php if(isset($error)): ?>
                <div class="error" style="margin: 15px;"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <div class="table-box">
                    <h2 style="color: #214332; margin-bottom: 15px;">Pending User Registrations</h2>
                    
                    <?php if($result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Registered On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($user = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $user['user_id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo $user['phone']; ?></td>
                                <td><?php echo $user['role_name']; ?></td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <button type="submit" name="action" value="approve" class="smallBtn Green">Approve</button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <button type="submit" name="action" value="reject" class="smallBtn Red" 
                                                    onclick="return confirm('Reject this registration?')">Reject</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align: center; padding: 40px;">
                        <p style="font-size: 18px; color: #666; margin-bottom: 10px;">No pending registration requests.</p>
                        <p style="color: #999;">All users have been approved.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="text-align: center; margin: 20px;">
                <a href="adminManageUsers.php" class="limebtn" style="display: inline-block; padding: 10px 20px;">← Back to User Management</a>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © 2026</p>
            </div>
        </div>
    </div>
</body>
</html>