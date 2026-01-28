<?php
session_start();
require_once("../config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$user_sql = "SELECT u.*, r.role_name FROM users u 
             JOIN roles r ON u.role_id = r.role_id 
             WHERE u.user_id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$users_sql = "SELECT user_id, username, role_name 
              FROM users u
              JOIN roles r ON u.role_id = r.role_id
              WHERE u.user_id != ? and r.role_id!=5
              AND u.account_status = 'Active'
              ORDER BY username";
$users_stmt = $conn->prepare($users_sql);
$users_stmt->bind_param("i", $user_id);
$users_stmt->execute();
$all_users = $users_stmt->get_result();

$commodities_sql = "SELECT commodity_id, commodity_name FROM commodities WHERE status = 'Active' ORDER BY commodity_name";
$commodities_result = $conn->query($commodities_sql);
$commodities = $commodities_result->fetch_all(MYSQLI_ASSOC);

$price_caps_sql = "SELECT c.commodity_id, c.commodity_name, pc.max_price_per_unit, c.unit_type
                   FROM price_caps pc
                   JOIN commodities c ON pc.commodity_id = c.commodity_id
                   WHERE (pc.expiry_date >= CURDATE() OR pc.expiry_date IS NULL)
                   ORDER BY c.commodity_name";
$price_caps_result = $conn->query($price_caps_sql);
$price_caps = [];
while($row = $price_caps_result->fetch_assoc()) {
    $price_caps[$row['commodity_id']] = $row;
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reported_user_id = $_POST['reported_user_id'] ?? 0;
    $violation_type = $_POST['violation_type'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $violation_date = $_POST['violation_date'] ?? date('Y-m-d');
    
    $commodity_id = $_POST['commodity_id'] ?? 0;
    $reported_price = $_POST['reported_price'] ?? 0;
    $quantity = $_POST['quantity'] ?? 0;
    
    $errors = [];
    
    if (empty($reported_user_id)) {
        $errors[] = "Please select a user to report";
    }
    
    if (empty($violation_type)) {
        $errors[] = "Please select violation type";
    }
    
    if (empty($description)) {
        $errors[] = "Please provide a description";
    }
    
    if ($reported_user_id == $user_id) {
        $errors[] = "You cannot report yourself";
    }
    
    $check_user_sql = "SELECT account_status FROM users WHERE user_id = ?";
    $check_user_stmt = $conn->prepare($check_user_sql);
    $check_user_stmt->bind_param("i", $reported_user_id);
    $check_user_stmt->execute();
    $reported_user = $check_user_stmt->get_result()->fetch_assoc();
    $check_user_stmt->close();
    
    if (!$reported_user) {
        $errors[] = "Reported user not found";
    } elseif ($reported_user['account_status'] === 'Blacklisted') {
        $errors[] = "This user is already blacklisted";
    }
    
    if ($violation_type === 'PRICE_CAP') {
        if (empty($commodity_id)) {
            $errors[] = "Please select a commodity";
        }
        if ($reported_price <= 0) {
            $errors[] = "Please enter a valid price";
        }
        
        if (!isset($price_caps[$commodity_id])) {
            $errors[] = "No active price cap found for this commodity";
        } elseif ($reported_price <= $price_caps[$commodity_id]['max_price_per_unit']) {
            $errors[] = "Reported price must be higher than the price cap (৳" . 
                       number_format($price_caps[$commodity_id]['max_price_per_unit'], 2) . ")";
        }
    }
    
    // Additional validation for hoarding
    if ($violation_type === 'HOARDING') {
        if (empty($commodity_id)) {
            $errors[] = "Please select a commodity";
        }
        if ($quantity <= 0) {
            $errors[] = "Please enter estimated hoarding quantity";
        }
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Insert into violations table
            $violation_sql = "INSERT INTO violations (reporter_id, reported_user_id, 
                                violation_type, description, violation_date, status) 
                              VALUES (?, ?, ?, ?, ?, 'PENDING')";
            $violation_stmt = $conn->prepare($violation_sql);
            $violation_stmt->bind_param("iisss", $user_id, $reported_user_id, 
                                       $violation_type, $description, $violation_date);
            
            if ($violation_stmt->execute()) {
                $violation_id = $conn->insert_id;
                
                // If price cap violation, create price_cap_violations record
                if ($violation_type === 'PRICE_CAP' && $commodity_id > 0) {
                    // Find active transaction (if any) for this violation
                    $find_tx_sql = "SELECT t.transaction_id 
                                   FROM transactions t
                                   JOIN batches b ON t.batch_id = b.batch_id
                                   WHERE b.owner_id = ? 
                                   AND b.commodity_id = ?
                                   AND t.unit_price >= ?
                                   ORDER BY t.transaction_date DESC 
                                   LIMIT 1";
                    $find_tx_stmt = $conn->prepare($find_tx_sql);
                    $find_tx_stmt->bind_param("iid", $reported_user_id, $commodity_id, $reported_price);
                    $find_tx_stmt->execute();
                    $tx_result = $find_tx_stmt->get_result();
                    
                    if ($tx_result->num_rows > 0) {
                        $transaction_id = $tx_result->fetch_assoc()['transaction_id'];
                        
                        // Get price cap ID
                        $pc_sql = "SELECT price_cap_id FROM price_caps 
                                  WHERE commodity_id = ? 
                                  AND (expiry_date >= CURDATE() OR expiry_date IS NULL)
                                  ORDER BY effective_date DESC LIMIT 1";
                        $pc_stmt = $conn->prepare($pc_sql);
                        $pc_stmt->bind_param("i", $commodity_id);
                        $pc_stmt->execute();
                        $pc_result = $pc_stmt->get_result()->fetch_assoc();
                        $pc_stmt->close();
                        
                        if ($pc_result) {
                            $pc_violation_sql = "INSERT INTO price_cap_violations 
                                                (violation_id, transaction_id, price_cap_id, reported_price) 
                                                VALUES (?, ?, ?, ?)";
                            $pc_violation_stmt = $conn->prepare($pc_violation_sql);
                            $pc_violation_stmt->bind_param("iiid", $violation_id, $transaction_id, 
                                                          $pc_result['price_cap_id'], $reported_price);
                            $pc_violation_stmt->execute();
                            $pc_violation_stmt->close();
                        }
                    }
                    $find_tx_stmt->close();
                }
                
                // Create notification for admin
                $admin_notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                          VALUES (?, ?, ?, 'violation', ?)";
                $admin_notification_stmt = $conn->prepare($admin_notification_sql);
                $admin_id = 5; // Admin ID
                $title = "New Violation Reported";
                $message = "User " . $username . " reported " . $reported_user_id . " for " . 
                          strtolower(str_replace('_', ' ', $violation_type)) . " violation";
                $admin_notification_stmt->bind_param("issi", $admin_id, $title, $message, $violation_id);
                $admin_notification_stmt->execute();
                $admin_notification_stmt->close();
                
                $conn->commit();
                $success = "✅ Violation report submitted successfully! Case ID: #" . $violation_id . 
                          ". Our admin team will review it within 3-5 business days.";
            } else {
                throw new Exception("Error submitting report: " . $conn->error);
            }
            
            $violation_stmt->close();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "❌ Error submitting report: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Violation - Syndicate Buster</title>
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
                <div>
                    <h1>Report Violation</h1>
                    <p class="tablesmallText">Help maintain fair market practices by reporting violations</p>
                </div>
                <div>
                    <a href="userViolation.php" class="smallBtn Cyan">Back to Violations</a>
                </div>
            </div>
          
            
            <?php if($success): ?>
                <div class="alert-success" style="margin: 20px;">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert-error" style="margin: 20px;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">⚠️ Report a Market Violation</h2>
                
                <div class="alert warning" style="margin-bottom: 20px;">
                    <strong>Important:</strong> Please provide accurate information. False reports may affect your trust score.
                    All reports are confidential and will be reviewed by our admin team.
                </div>
                
                <form method="POST" action="" class="form-container">
                    <div class="form-group">
                        <label for="reported_user_id">User to Report *</label>
                        <select id="reported_user_id" name="reported_user_id" required class="form-control">
                            <option value="">-- Select User --</option>
                            <?php while($user_row = $all_users->fetch_assoc()): ?>
                                <option value="<?php echo $user_row['user_id']; ?>">
                                    <?php echo htmlspecialchars($user_row['username']); ?> (<?php echo $user_row['role_name']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small>Select the user you want to report for violation</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="violation_type">Violation Type *</label>
                        <select id="violation_type" name="violation_type" required class="form-control" onchange="toggleAdditionalFields()">
                            <option value="">-- Select Type --</option>
                            <option value="PRICE_CAP">Selling Above Price Cap</option>
                            <option value="HOARDING">Hoarding (Excessive Stockpiling)</option>
                            <option value="FRAUD">Fraud or Misrepresentation</option>
                            <option value="OTHER">Other Violation</option>
                        </select>
                        <small>Choose the type of violation you observed</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="violation_date">Date of Violation *</label>
                        <input type="date" id="violation_date" name="violation_date" 
                               value="<?php echo date('Y-m-d'); ?>" required class="form-control">
                        <small>When did this violation occur?</small>
                    </div>
                    
                    <!-- Additional fields for PRICE_CAP violation -->
                    <div id="price_cap_fields" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <h3 style="color: #214332; margin-bottom: 15px;">Price Cap Violation Details</h3>
                        
                        <div class="form-group">
                            <label for="commodity_id">Commodity *</label>
                            <select id="commodity_id" name="commodity_id" class="form-control" onchange="updatePriceCapInfo()">
                                <option value="">-- Select Commodity --</option>
                                <?php foreach($commodities as $commodity): ?>
                                    <option value="<?php echo $commodity['commodity_id']; ?>">
                                        <?php echo htmlspecialchars($commodity['commodity_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="price_cap_info" style="background: #d4edda; padding: 10px; border-radius: 4px; margin: 10px 0; display: none;">
                            <div class="tablesmallText">
                                Current Price Cap: <strong id="current_cap">৳0.00</strong> per <span id="cap_unit">kg</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="reported_price">Price Charged (৳) *</label>
                            <input type="number" id="reported_price" name="reported_price" 
                                   min="0.01" step="0.01" class="form-control" placeholder="Enter the price charged">
                            <small>The price at which the user sold above the cap</small>
                        </div>
                    </div>
                    
                    <div id="hoarding_fields" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <h3 style="color: #214332; margin-bottom: 15px;">Hoarding Details</h3>
                        
                        <div class="form-group">
                            <label for="hoarding_commodity_id">Commodity *</label>
                            <select id="hoarding_commodity_id" name="commodity_id" class="form-control">
                                <option value="">-- Select Commodity --</option>
                                <?php foreach($commodities as $commodity): ?>
                                    <option value="<?php echo $commodity['commodity_id']; ?>">
                                        <?php echo htmlspecialchars($commodity['commodity_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantity">Estimated Hoarding Quantity *</label>
                            <input type="number" id="quantity" name="quantity" 
                                   min="0.01" step="0.01" class="form-control" placeholder="Enter quantity">
                            <small>Estimated quantity being hoarded</small>
                        </div>
                    </div>
                    
                    <div id="fraud_fields" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <h3 style="color: #214332; margin-bottom: 15px;">Fraud Details</h3>
                        <div class="tablesmallText">
                            Please describe the fraudulent activity in detail below. Include any misleading information,
                            false claims, or deceptive practices you observed.
                        </div>
                    </div>
                    
                    <!-- Additional fields for OTHER violation -->
                    <div id="other_fields" style="display: none; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <h3 style="color: #214332; margin-bottom: 15px;">Other Violation Details</h3>
                        <div class="tablesmallText">
                            Please specify the exact nature of the violation. Include any relevant details about
                            market manipulation, unethical practices, or other violations not covered above.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Detailed Description *</label>
                        <textarea id="description" name="description" rows="4" required 
                                  class="form-control" placeholder="Provide detailed description of the violation..."></textarea>
                        <small>Include specific details, location, time, and any evidence if available</small>
                    </div>
                    <div style="display: flex; gap: 15px; margin-top: 20px;">
                        <button type="reset" class="smallBtn Gray" style="flex: 1;">Clear Form</button>
                        <button type="submit" class="smallBtn Red" style="flex: 2;">Submit Report</button>
                    </div>
                </form>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 15px;">📊 Current Price Caps (For Reference)</h2>
                
                <?php if(!empty($price_caps)): ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th>Commodity</th>
                            <th>Max Price</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($price_caps as $cap): ?>
                        <tr>
                            <td>
                                <div class="tableBoldText"><?php echo htmlspecialchars($cap['commodity_name']); ?></div>
                            </td>
                            <td>
                                <div class="tableBoldText" style="color: #28a745;">
                                    ৳<?php echo number_format($cap['max_price_per_unit'], 2); ?>
                                </div>
                            </td>
                            <td>
                                <div class="tablesmallText"><?php echo $cap['unit_type']; ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 20px; color: #666;">
                        No active price caps available.
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>
    
    <script>
    // Price caps data from PHP
    const priceCaps = <?php echo json_encode($price_caps); ?>;
    
    function toggleAdditionalFields() {
        const violationType = document.getElementById('violation_type').value;
        
        // Hide all additional fields
        document.getElementById('price_cap_fields').style.display = 'none';
        document.getElementById('hoarding_fields').style.display = 'none';
        document.getElementById('fraud_fields').style.display = 'none';
        document.getElementById('other_fields').style.display = 'none';
        
        // Show relevant fields based on violation type
        switch(violationType) {
            case 'PRICE_CAP':
                document.getElementById('price_cap_fields').style.display = 'block';
                break;
            case 'HOARDING':
                document.getElementById('hoarding_fields').style.display = 'block';
                break;
            case 'FRAUD':
                document.getElementById('fraud_fields').style.display = 'block';
                break;
            case 'OTHER':
                document.getElementById('other_fields').style.display = 'block';
                break;
        }
    }
    
    function updatePriceCapInfo() {
        const commodityId = document.getElementById('commodity_id').value;
        const priceCapInfo = document.getElementById('price_cap_info');
        
        if (commodityId && priceCaps[commodityId]) {
            const cap = priceCaps[commodityId];
            document.getElementById('current_cap').textContent = '৳' + parseFloat(cap.max_price_per_unit).toFixed(2);
            document.getElementById('cap_unit').textContent = cap.unit_type;
            priceCapInfo.style.display = 'block';
            
            // Set minimum price for reported price
            const reportedPriceInput = document.getElementById('reported_price');
            reportedPriceInput.min = parseFloat(cap.max_price_per_unit) + 0.01;
            reportedPriceInput.placeholder = 'Must be above ৳' + parseFloat(cap.max_price_per_unit).toFixed(2);
        } else {
            priceCapInfo.style.display = 'none';
        }
    }
    
    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const violationType = document.getElementById('violation_type').value;
        const description = document.getElementById('description').value;
        const reportedUser = document.getElementById('reported_user_id').value;
        
        if (!reportedUser) {
            e.preventDefault();
            alert('Please select a user to report');
            return false;
        }
        
        if (!violationType) {
            e.preventDefault();
            alert('Please select violation type');
            return false;
        }
        
        if (description.length < 20) {
            e.preventDefault();
            alert('Please provide a more detailed description (minimum 20 characters)');
            return false;
        }
        
        // Additional validation for price cap violations
        if (violationType === 'PRICE_CAP') {
            const commodityId = document.getElementById('commodity_id').value;
            const reportedPrice = parseFloat(document.getElementById('reported_price').value);
            
            if (!commodityId) {
                e.preventDefault();
                alert('Please select a commodity for price cap violation');
                return false;
            }
            
            if (!reportedPrice || reportedPrice <= 0) {
                e.preventDefault();
                alert('Please enter a valid price');
                return false;
            }
            
            if (priceCaps[commodityId] && reportedPrice <= priceCaps[commodityId].max_price_per_unit) {
                e.preventDefault();
                alert('Reported price must be higher than the price cap (৳' + 
                      priceCaps[commodityId].max_price_per_unit.toFixed(2) + ')');
                return false;
            }
        }
        
        // Additional validation for hoarding
        if (violationType === 'HOARDING') {
            const commodityId = document.getElementById('hoarding_commodity_id').value;
            const quantity = parseFloat(document.getElementById('quantity').value);
            
            if (!commodityId) {
                e.preventDefault();
                alert('Please select a commodity for hoarding violation');
                return false;
            }
            
            if (!quantity || quantity <= 0) {
                e.preventDefault();
                alert('Please enter estimated hoarding quantity');
                return false;
            }
        }
        
        if (!confirm('Submit this violation report?\n\nAll reports are reviewed for accuracy. False reports may affect your trust score.')) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
    
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-success, .alert-error');
        alerts.forEach(alert => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => {
                if (alert.parentNode) alert.parentNode.removeChild(alert);
            }, 500);
        });
    }, 5000);
    </script>
</body>
</html>