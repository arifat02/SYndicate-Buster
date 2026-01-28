<?php
session_start();    
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$error = '';
$success = '';
$form_data = [
    'username' => '',
    'email' => '',
    'phone' => '',
    'role_id' => '',
    'location' => '',
    'address' => '',
    'account_status' => 'Active'
];

$roles_result = $conn->query("SELECT * FROM roles WHERE role_id != 5 ORDER BY role_name");
$roles = $roles_result->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role_id = intval($_POST['role_id']);
    $location = trim($_POST['location']);
    $address = trim($_POST['address']);
    $account_status = $_POST['status'];
    
    if (empty($username) || empty($email) || empty($phone) || empty($password)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (!preg_match('/^[0-9]{11}$/', $phone)) {
        $error = 'Phone number must be 11 digits';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? OR phone = ?");
        $check_stmt->bind_param("sss", $username, $email, $phone);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = 'Username, email or phone number already exists';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_stmt = $conn->prepare("INSERT INTO users 
                (username, email, phone, password, role_id, address, location, account_status, trust_score, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 100, NOW())");
            
            $insert_stmt->bind_param("ssssisss", 
                $username, 
                $email, 
                $phone, 
                $hashed_password, 
                $role_id, 
                $address, 
                $location, 
                $account_status
            );
            
            if ($insert_stmt->execute()) {
                $_SESSION['flash_success'] = 'User added successfully!';
                header("Location: adminAddUser.php");
                exit();
            } else {
                $_SESSION['flash_error'] = 'Error adding user: ' . $conn->error;
                header("Location: adminAddUser.php");
                exit();
            }
            
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
    
    if ($error) {
        $form_data = [
            'username' => $username,
            'email' => $email,
            'phone' => $phone,
            'role_id' => $role_id,
            'location' => $location,
            'address' => $address,
            'account_status' => $account_status
        ];
    }
}

// Get flash messages
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - Admin Dashboard</title>
    <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/form.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            <div class="userDetailsCard">
                <h2>Add New User</h2>
                <div style="display: flex; gap: 10px;">
                <button class="smallBtn Cyan" onclick="window.location.href='adminManageUsers.php'">← Back to Manage Users</button>
                <button class="smallBtn Red" onclick="window.location.href='logout.php'">Logout</button>
                </div>
            </div>
            
           <div class="form-container">
                <?php if ($error): ?>
                    <div class="error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">Username *</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['username']); ?>" 
                                   required maxlength="50">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number *</label>
                            <input type="text" id="phone" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($form_data['phone']); ?>" 
                                   pattern="[0-9]{11}" 
                                   placeholder="11 digits (e.g., 01711111111)"
                                   required>
                            <small>Must be 11 digits</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password *</label>
                            <input type="password" id="password" name="password" class="form-control" 
                                   required minlength="6">
                            <small>At least 6 characters</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" 
                                   class="form-control" required minlength="6">
                        </div>
                    
                        <div class="form-group">
                            <label for="role_id">Role *</label>
                            <select id="role_id" name="role_id" class="form-control" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>"
                                        <?php echo $form_data['role_id'] == $role['role_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="location">Location *</label>
                            <select id="location" name="location" class="form-control" required>
                                <option value="">Select Location</option>
                                <option value="Dhaka" <?php echo $form_data['location'] == 'Dhaka' ? 'selected' : ''; ?>>Dhaka</option>
                                <option value="Chittagong" <?php echo $form_data['location'] == 'Chittagong' ? 'selected' : ''; ?>>Chittagong</option>
                                <option value="Sylhet" <?php echo $form_data['location'] == 'Sylhet' ? 'selected' : ''; ?>>Sylhet</option>
                                <option value="Rajshahi" <?php echo $form_data['location'] == 'Rajshahi' ? 'selected' : ''; ?>>Rajshahi</option>
                                <option value="Rangpur" <?php echo $form_data['location'] == 'Rangpur' ? 'selected' : ''; ?>>Rangpur</option>
                                <option value="Khulna" <?php echo $form_data['location'] == 'Khulna' ? 'selected' : ''; ?>>Khulna</option>
                                <option value="Barisal" <?php echo $form_data['location'] == 'Barisal' ? 'selected' : ''; ?>>Barisal</option>
                                <option value="Mymensingh" <?php echo $form_data['location'] == 'Mymensingh' ? 'selected' : ''; ?>>Mymensingh</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Address *</label>
                            <textarea id="address" name="address" class="form-control" 
                                      rows="3" required><?php echo htmlspecialchars($form_data['address']); ?></textarea>
                        </div>
                    
                        <div class="form-group">
                            <label for="status">Account Status *</label>
                            <select id="status" name="status" class="form-control" required>
                                <option value="Active" <?php echo $form_data['account_status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $form_data['account_status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="Suspended" <?php echo $form_data['account_status'] == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="Banned" <?php echo $form_data['account_status'] == 'Banned' ? 'selected' : ''; ?>>Banned</option>
                                <option value="Blacklisted" <?php echo $form_data['account_status'] == 'Blacklisted' ? 'selected' : ''; ?>>Blacklisted</option>
                            </select>
                        </div>
                    
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px ">
                            <button type="submit" class="greenBtn" style="height:40px;">Add User</button>
                            <a href="../admin/adminManageUsers.php" class="limebtn">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © 2026</p>
            </div>
        </div>
    </div>
</body>
</html>