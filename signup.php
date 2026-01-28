<?php
require_once "config.php";
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $email    = $_POST['email'];
    $phone    = $_POST['phone'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // Hash password
    $role_id  = $_POST['role_id'];
    $location = $_POST['location'];
    $address = $_POST['address'];

    // Check if username, email or phone already exists
    $check_sql = "SELECT user_id FROM users WHERE username = ? OR email = ? OR phone = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("sss", $username, $email, $phone);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "Username, email or phone number already exists!";
    } else {
        // Insert with account_status='Under_Review' for admin approval
        $sql = "INSERT INTO users (username, email, phone, password, role_id, address, location, account_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Under_Review')";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssiss", $username, $email, $phone, $password, $role_id, $address, $location);

        if ($stmt->execute()) {
            $success = "Registration successful! Please wait for admin approval. You'll receive an email when your account is activated.";
        } else {
            $error = "Signup failed: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signup - Syndicate Buster</title>
    <link rel="stylesheet" href="css/page.css">
    <link rel="stylesheet" href="css/button.css">
    <link rel="stylesheet" href="css/text.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/error.css">
    <link rel="stylesheet" href="css/success.css">
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="registerCard">
            <?php if(!empty($error)) echo "<p class='error'>$error</p>"; ?>
            <?php if(!empty($success)) echo "<p class='success'>$success</p>"; ?>
            
            <?php if(empty($success)): ?>
            <form action="signup.php" method="post">  
                <div class="registerBody">
                    <p class="sub_title">Register New Account</p>
                    <input type="text" name="username" class="inputArea" placeholder="Name" required>
                    <select name="role_id" class="inputArea" required>
                        <option value="" disabled selected>Select Role</option>
                        <option value="1">Farmer</option>
                        <option value="2">Middleman</option>
                        <option value="3">Wholesaler</option>
                        <option value="4">Retailer</option>
                    </select>
                    <input type="email" name="email" class="inputArea" placeholder="Email" required>
                    <input type="text" name="phone" class="inputArea" placeholder="Phone number (11 digits)" pattern="[0-9]{11}" required>
                    <select name="location" class="inputArea" required>
                        <option value="" disabled selected>Select your location</option>
                        <option value="Dhaka">Dhaka</option>
                        <option value="Chittagong">Chittagong</option>
                        <option value="Sylhet">Sylhet</option>
                        <option value="Rajshahi">Rajshahi</option>
                        <option value="Rangpur">Rangpur</option>
                        <option value="Khulna">Khulna</option>
                        <option value="Barisal">Barisal</option>
                        <option value="Mymensingh">Mymensingh</option>
                    </select>
                    <input type="text" name="address" class="inputArea" placeholder="Address" required>
                    <input type="password" name="password" class="inputArea" placeholder="Password" required>
                    <button type="submit" class="greenBtn">Register</button>
                    <a href="login.php" class="linktxt">Already have an account?</a>
                </div>
            </form>
            <?php else: ?>
                <div class="registerBody">
                    <p class="success"><?php echo $success; ?></p>
                    <a href="login.php" class="linktxt">Go to Login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>