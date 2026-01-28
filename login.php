<?php
session_start();
require_once "config.php";

$error = ""; 
$showAdminNote = false; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT u.user_id, u.username, u.password, u.role_id, u.account_status, r.role_name
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.username = ?";
    $stmt = $conn->prepare($sql);

    if(!$stmt){
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        $password_valid = false;
        
        if (password_verify($password, $user['password'])) {
            $password_valid = true;
        } 
        elseif ($password === $user['password']) {
            $password_valid = true;
            
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $upgrade_sql = "UPDATE users SET password = ? WHERE user_id = ?";
            $upgrade_stmt = $conn->prepare($upgrade_sql);
            $upgrade_stmt->bind_param("si", $hashed_password, $user['user_id']);
            $upgrade_stmt->execute();
            $upgrade_stmt->close();
        }
        
        if ($password_valid) {
            switch ($user['account_status']) {
                case 'Under_Review':
                    $showAdminNote = true;
                    break;
                    
              
                case 'Active':
                case 'Blacklisted':
                case 'Suspended':

                    
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['role_name'] = $user['role_name'];
                    $_SESSION['account_status'] = $user['account_status'];
                    
                    $update = $conn->prepare(
                        "UPDATE users SET last_login = NOW() WHERE user_id = ?"
                    );
                    $update->bind_param("i", $user['user_id']);
                    $update->execute();
                    $update->close();

                    if ($user['role_id'] == 5) {
                        header("Location: admin/adminDashboard.php");
                    } else {
                        header("Location: vendors/vendorDashboard.php");
                    }
                    exit();
                    break;
                case 'Banned':
                $error= "🚫 You have been permanently banned from the portal. Contact support for appeal.";
                    break;

                default:
                    $error = "Account status issue. Contact administrator.";
                    break;
            }
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "Username not found.";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Syndicate Buster</title>
<link rel="stylesheet" href="css/page.css">
<link rel="stylesheet" href="css/text.css">
<link rel="stylesheet" href="css/cards.css">
<link rel="stylesheet" href="css/error.css">
<link rel="stylesheet" href="css/button.css">
<style>
    .note {
        margin-top: 15px;
        font-size: 12px;
        color: #666;
        text-align: center;
        padding: 10px;
        background-color: #f9f9f9;
        border-radius: 5px;
        border-left: 4px solid #ffa500;
    }
</style>
</head>
<body>
<div class="container">
    <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
    <div class="registerCard">
        <?php if(!empty($error)) echo "<p class='error'>$error</p>"; ?>
        <form action="login.php" method="post">  
            <div class="registerBody">
                <div class="sub_title">Log In</div>
                <input type="text" name="username" class="inputAreaText" placeholder="Username" required>
                <input type="password" name="password" class="inputAreaText" placeholder="Password" required>
                <button type="submit" class="greenBtn">Log In</button>
                <a href="forgotPassword.php" class="linktxt">Forgotten password?</a>
                <hr class="hrline">
                <a href="signup.php" class="limebtn">Create new account</a>
                <?php if($showAdminNote): ?>
                <p class="note">
                    New accounts require admin approval before login. You'll be able to login once an admin approves your account.
                </p>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>  
</body>
</html>