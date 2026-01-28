<?php
require_once "config.php";

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username']);
    $phone = trim($_POST['phone']); // Verify identity with Phone
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. Basic Validation
    if (empty($username) || empty($phone) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // 2. Verify User Exists (Username + Phone match)
        $sql = "SELECT user_id FROM users WHERE username = ? AND phone = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $username, $phone);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // 3. Update Password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $update->bind_param("si", $hashed_password, $user['user_id']);
            
            if ($update->execute()) {
                $success = "✅ Password reset successful. <a href='login.php' style='color:green;font-weight:bold; text-decoration:underline;'>Login Now</a>";
            } else {
                $error = "Database error: Could not update password.";
            }
            $update->close();
        } else {
            $error = "No account found matching that Username and Phone number.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Syndicate Buster</title>
<link rel="stylesheet" href="css/page.css">
<link rel="stylesheet" href="css/text.css">
<link rel="stylesheet" href="css/cards.css">
<link rel="stylesheet" href="css/error.css">
<link rel="stylesheet" href="css/button.css">

</head>
<body>

<div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
    <div class="registerCard">
        <?php if(!empty($error)) echo "<div class='alert-error' style='text-align:center; margin-bottom:15px;'>$error</div>"; ?>
        <?php if(!empty($success)) echo "<div class='alert-success' style='text-align:center; margin-bottom:15px;'>$success</div>"; ?>
               <form action="" method="post">
 <div class="registerBody">

        <div class="sub_title">Log In</div>

                <input type="text" name="username" class="inputAreaText" placeholder="Username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
            
                <input type="text" name="phone" class="inputAreaText" placeholder="Registered Phone Number" required value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                <input type="password" name="new_password" class="inputAreaText" placeholder="New Password" required minlength="6">
            
                <input type="password" name="confirm_password" class="inputAreaText" placeholder="Confirm New Password" required minlength="6">
            <button type="submit" class="greenBtn" style="width: 100%; padding: 12px; margin-top: 10px;">Reset Password</button>

                <a href="login.php" class="greenBtn Lime" style="color: #666; text-decoration: none;">Back to Login</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>