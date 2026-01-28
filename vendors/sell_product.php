<?php
session_start();
require_once("../config.php");
require_once("price_calculator.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$priceCalculator = new PriceCalculator($conn);

$user_check_sql = "SELECT account_status, trust_score FROM users WHERE user_id = ?";
$user_check_stmt = $conn->prepare($user_check_sql);
$user_check_stmt->bind_param("i", $user_id);
$user_check_stmt->execute();
$user_result = $user_check_stmt->get_result()->fetch_assoc();
$user_check_stmt->close();

if ($user_result['account_status'] === 'Blacklisted' || $user_result['account_status'] === 'Suspended') {
    $_SESSION['error'] = "Your account is restricted. You cannot sell products.";
    header("Location: vendorDashboard.php");
    exit();
}

$role_sql = "SELECT role_name FROM roles WHERE role_id = ?";
$role_stmt = $conn->prepare($role_sql);
$role_stmt->bind_param("i", $_SESSION['role_id']);
$role_stmt->execute();
$user_role = $role_stmt->get_result()->fetch_assoc()['role_name'];
$role_stmt->close();

// Get user's available batches
$batches_sql = "SELECT b.batch_id, b.initial_quantity, b.current_quantity, 
                       b.production_date, b.expiry_date,
                       c.commodity_name, c.unit_type, c.commodity_id,
                       pc.max_price_per_unit as retail_price_cap
                FROM batches b
                JOIN commodities c ON b.commodity_id = c.commodity_id
                LEFT JOIN price_caps pc ON c.commodity_id = pc.commodity_id 
                    AND (pc.expiry_date >= CURDATE() OR pc.expiry_date IS NULL)
                WHERE b.owner_id = ? 
                AND b.current_quantity > 0 
                AND b.batch_status = 'Active'
                ORDER BY c.commodity_name, b.production_date";
$batches_stmt = $conn->prepare($batches_sql);
$batches_stmt->bind_param("i", $user_id);
$batches_stmt->execute();
$available_batches = $batches_stmt->get_result();

// Get all buyers (excluding self)
$buyers_sql = "SELECT user_id, username, role_name 
               FROM users u
               JOIN roles r ON u.role_id = r.role_id
               WHERE u.user_id != ? 
               AND u.account_status = 'Active'
               AND u.role_id IN (2, 3, 4) 
               ORDER BY username";
$buyers_stmt = $conn->prepare($buyers_sql);
$buyers_stmt->bind_param("i", $user_id);
$buyers_stmt->execute();
$buyers = $buyers_stmt->get_result();

// Get price caps for reference
$price_caps_sql = "SELECT c.commodity_name, c.unit_type, 
                          pc.max_price_per_unit, pc.effective_date, c.commodity_id
                   FROM price_caps pc
                   JOIN commodities c ON pc.commodity_id = c.commodity_id
                   WHERE pc.expiry_date >= CURDATE() OR pc.expiry_date IS NULL
                   ORDER BY c.commodity_name";
$price_caps_result = $conn->query($price_caps_sql);
$price_caps = [];
while($row = $price_caps_result->fetch_assoc()) {
    $price_caps[] = $row;
}

// Prepare batches with smart price calculations
$batches_with_prices = [];
$hoarding_warnings = [];
while($batch = $available_batches->fetch_assoc()) {
    // Calculate smart price
    $price_info = $priceCalculator->calculateSmartPrice(
        $user_role, 
        $batch['retail_price_cap'], 
        $batch['commodity_id'],
        $batch['commodity_name'],
        $user_id,
        $batch['batch_id']
    );
    
    $batch['price_info'] = $price_info;
    
    // Check for hoarding violations
    $hoarding_check = $priceCalculator->checkHoardingViolation($user_id, $batch['commodity_id']);
    if ($hoarding_check['violation']) {
        $batch['hoarding_warning'] = $hoarding_check;
        $hoarding_warnings[$batch['commodity_id']] = $hoarding_check;
    }
    
    $batches_with_prices[] = $batch;
}

// Get market summary
$market_summary = $priceCalculator->getMarketSummary();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_id = $_POST['batch_id'];
    $buyer_id = $_POST['buyer_id'];
    $quantity = floatval($_POST['quantity']);
    $unit_price = floatval($_POST['unit_price']);
    $notes = $_POST['notes'] ?? '';
    $transaction_type = $_POST['transaction_type'] ?? 'direct_sale';
    
    $errors = [];
    $warnings = [];
    
    if (empty($batch_id)) {
        $errors[] = "Please select a batch";
    }
    
    if (empty($buyer_id)) {
        $errors[] = "Please select a buyer";
    }
    
    if ($quantity <= 0) {
        $errors[] = "Quantity must be greater than 0";
    }
    
    if ($unit_price <= 0) {
        $errors[] = "Price must be greater than 0";
    }
    
    $check_batch_sql = "SELECT b.current_quantity, b.batch_id, c.commodity_id,
                               pc.max_price_per_unit as retail_price_cap,
                               c.commodity_name, c.unit_type,
                               pc.price_cap_id
                        FROM batches b
                        JOIN commodities c ON b.commodity_id = c.commodity_id
                        LEFT JOIN price_caps pc ON c.commodity_id = pc.commodity_id 
                            AND (pc.expiry_date >= CURDATE() OR pc.expiry_date IS NULL)
                        WHERE b.batch_id = ? AND b.owner_id = ?";
    $check_stmt = $conn->prepare($check_batch_sql);
    $check_stmt->bind_param("ii", $batch_id, $user_id);
    $check_stmt->execute();
    $batch_check = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if (!$batch_check) {
        $errors[] = "Batch not found or access denied";
    } elseif ($quantity > $batch_check['current_quantity']) {
        $errors[] = "Quantity exceeds available stock. Available: " . $batch_check['current_quantity'] . " " . $batch_check['unit_type'];
    }
    
    // Check for price violations using price calculator
    $price_violation = $priceCalculator->checkPriceViolation($user_role, $unit_price, $batch_check['retail_price_cap']);
    $violation_flag = $price_violation['violation'];
    $violation_reason = $price_violation['violation'] ? $price_violation['reason'] : '';
    $trust_penalty = $price_violation['violation'] ? $price_violation['penalty'] : 0;
    
    // Check price deviation
    $price_info = $priceCalculator->calculateSmartPrice(
        $user_role,
        $batch_check['retail_price_cap'] ?? 0,
        $batch_check['commodity_id'],
        $batch_check['commodity_name'],
        $user_id,
        $batch_id
    );
    
    $deviation_warning = $priceCalculator->getPriceDeviationWarning($unit_price, $price_info['recommended']);
    if ($deviation_warning) {
        $warnings[] = $deviation_warning['message'];
        if ($deviation_warning['level'] === 'danger') {
            $warnings[] = "⚠️ " . $deviation_warning['action'];
        }
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Determine initial status based on transaction type
            $initial_status = ($transaction_type === 'direct_sale') ? 'Completed' : 'Pending';
            
            // 1. Insert transaction
            $transaction_sql = "INSERT INTO transactions (batch_id, seller_id, buyer_id, unit_price, quantity, notes, status, violation_flag) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $transaction_stmt = $conn->prepare($transaction_sql);
            $transaction_stmt->bind_param("iiiddssi", $batch_id, $user_id, $buyer_id, $unit_price, $quantity, $notes, $initial_status, $violation_flag);
            $transaction_stmt->execute();
            $transaction_id = $conn->insert_id;
            $transaction_stmt->close();
            
            // 2. Log initial status
            $log_sql = "INSERT INTO transaction_status_log (transaction_id, old_status, new_status, changed_by, reason) 
                        VALUES (?, NULL, ?, ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_reason = "Transaction created as " . ($transaction_type === 'direct_sale' ? "Direct Sale" : "Sale Offer");
            $log_stmt->bind_param("isis", $transaction_id, $initial_status, $user_id, $log_reason);
            $log_stmt->execute();
            $log_stmt->close();
            
            // 3. If direct sale, update batch quantity immediately
            if ($initial_status === 'Completed') {
                $update_batch_sql = "UPDATE batches 
                                     SET current_quantity = current_quantity - ? 
                                     WHERE batch_id = ?";
                $update_stmt = $conn->prepare($update_batch_sql);
                $update_stmt->bind_param("di", $quantity, $batch_id);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Check if batch is sold out
                $check_remaining_sql = "SELECT current_quantity FROM batches WHERE batch_id = ?";
                $check_stmt = $conn->prepare($check_remaining_sql);
                $check_stmt->bind_param("i", $batch_id);
                $check_stmt->execute();
                $remaining = $check_stmt->get_result()->fetch_assoc()['current_quantity'];
                $check_stmt->close();
                
                if ($remaining <= 0) {
                    $status_sql = "UPDATE batches SET batch_status = 'Sold' WHERE batch_id = ?";
                    $status_stmt = $conn->prepare($status_sql);
                    $status_stmt->bind_param("i", $batch_id);
                    $status_stmt->execute();
                    $status_stmt->close();
                }
            }
            
            // 4. Create notification for buyer
            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                 VALUES (?, ?, ?, 'sale_offer', ?)";
            $notification_stmt = $conn->prepare($notification_sql);
            
            if ($initial_status === 'Completed') {
                $title = "Direct Purchase Completed";
                $message = "You have purchased " . $quantity . " " . $batch_check['unit_type'] . " of " . $batch_check['commodity_name'] . " at ৳" . $unit_price . "/unit from " . $username;
            } else {
                $title = "New Sale Offer Received";
                $message = "You have received a sale offer for " . $quantity . " " . $batch_check['unit_type'] . " of " . $batch_check['commodity_name'] . " at ৳" . $unit_price . "/unit from " . $username . ". Please confirm or reject.";
            }
            
            $notification_stmt->bind_param("issi", $buyer_id, $title, $message, $transaction_id);
            $notification_stmt->execute();
            $notification_stmt->close();
            
            // 5. If violation detected, create violation record
            if ($violation_flag) {
                $price_cap_sql = "SELECT price_cap_id FROM price_caps 
                                  WHERE commodity_id = ? 
                                  AND (expiry_date >= CURDATE() OR expiry_date IS NULL)
                                  ORDER BY effective_date DESC LIMIT 1";
                $pc_stmt = $conn->prepare($price_cap_sql);
                $pc_stmt->bind_param("i", $batch_check['commodity_id']);
                $pc_stmt->execute();
                $price_cap_result = $pc_stmt->get_result()->fetch_assoc();
                $pc_stmt->close();
                
                if ($price_cap_result) {
                    // Create violation record
                    $violation_sql = "INSERT INTO violations (reporter_id, reported_user_id, violation_type, description, violation_date, status) 
                                      VALUES (?, ?, 'PRICE_CAP', ?, CURDATE(), 'CONFIRMED')";
                    $violation_stmt = $conn->prepare($violation_sql);
                    $system_id = 5; // Admin ID
                    $violation_stmt->bind_param("iis", $system_id, $user_id, $violation_reason);
                    $violation_stmt->execute();
                    $violation_id = $conn->insert_id;
                    $violation_stmt->close();
                    
                    // Link to price cap violation table
                    $pc_violation_sql = "INSERT INTO price_cap_violations (violation_id, transaction_id, price_cap_id, reported_price) 
                                         VALUES (?, ?, ?, ?)";
                    $pc_violation_stmt = $conn->prepare($pc_violation_sql);
                    $pc_violation_stmt->bind_param("iiid", $violation_id, $transaction_id, $price_cap_result['price_cap_id'], $unit_price);
                    $pc_violation_stmt->execute();
                    $pc_violation_stmt->close();
                    
                    // Reduce trust score
                    $get_trust_sql = "SELECT trust_score FROM users WHERE user_id = ?";
                    $get_trust_stmt = $conn->prepare($get_trust_sql);
                    $get_trust_stmt->bind_param("i", $user_id);
                    $get_trust_stmt->execute();
                    $old_trust = $get_trust_stmt->get_result()->fetch_assoc()['trust_score'];
                    $get_trust_stmt->close();
                    
                    $new_trust = max(0, $old_trust - $trust_penalty);
                    
                    $trust_update_sql = "UPDATE users SET trust_score = ? WHERE user_id = ?";
                    $trust_stmt = $conn->prepare($trust_update_sql);
                    $trust_stmt->bind_param("ii", $new_trust, $user_id);
                    $trust_stmt->execute();
                    $trust_stmt->close();
                    
                    // Log trust score change
                    $trust_log_sql = "INSERT INTO trust_score_log (user_id, old_score, new_score, reason, related_transaction_id, related_violation_id) 
                                      VALUES (?, ?, ?, ?, ?, ?)";
                    $trust_log_stmt = $conn->prepare($trust_log_sql);
                    $reason = "Retail price cap violation: Charged ৳" . number_format($unit_price, 2) . " per " . $batch_check['unit_type'] . " exceeding retail cap of ৳" . number_format($batch_check['retail_price_cap'], 2);
                    $trust_log_stmt->bind_param("iiisii", $user_id, $old_trust, $new_trust, $reason, $transaction_id, $violation_id);
                    $trust_log_stmt->execute();
                    $trust_log_stmt->close();
                    
                    // Check for auto-blacklist
                    if ($new_trust <= 20) {
                        $blacklist_sql = "UPDATE users SET account_status = 'Blacklisted' WHERE user_id = ?";
                        $blacklist_stmt = $conn->prepare($blacklist_sql);
                        $blacklist_stmt->bind_param("i", $user_id);
                        $blacklist_stmt->execute();
                        $blacklist_stmt->close();
                    }
                }
            }
            
            $conn->commit();
            
            // Success message
            $total_amount = $unit_price * $quantity;
            
            if ($initial_status === 'Completed') {
                $_SESSION['success'] = "Direct sale completed successfully! Transaction ID: $transaction_id, Amount: ৳" . number_format($total_amount, 2);
            } else {
                $_SESSION['success'] = "Sale offer sent to buyer! Transaction ID: $transaction_id. Waiting for buyer confirmation.";
            }
            
            if ($violation_flag) {
                $_SESSION['warning'] = "⚠️ Retail price cap violation detected! Trust score reduced by $trust_penalty points.";
            }
            
            if (!empty($warnings)) {
                $_SESSION['warning'] = (isset($_SESSION['warning']) ? $_SESSION['warning'] . "<br>" : "") . implode("<br>", $warnings);
            }
            
            header("Location: sell_product.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Transaction failed: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
        if (!empty($warnings)) {
            $_SESSION['warning'] = implode("<br>", $warnings);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sell Product - Syndicate Buster</title>
    <link rel="stylesheet" href="../css/page.css">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/model.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/form.css?v=<?php echo time(); ?>">
    <style>
        .price-info-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            margin: 0 3px;
        }
        .badge-high-demand { background: #ff6b6b; color: white; }
        .badge-low-demand { background: #4dabf7; color: white; }
        .badge-peak-season { background: #ffd43b; color: #333; }
        .badge-off-season { background: #9775fa; color: white; }
        .badge-high-trust { background: #51cf66; color: white; }
        .badge-market-avg { background: #ff922b; color: white; }
        
        .confidence-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .confidence-high { background: #51cf66; }
        .confidence-medium { background: #ffd43b; }
        .confidence-low { background: #ff6b6b; }
        
        .hoarding-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 10px;
            margin: 10px 0;
        }
        
        .market-insights {
            background: #e7f5ff;
            border: 1px solid #4dabf7;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .seasonal-info {
            background: #fff9db;
            border: 1px solid #ffd43b;
            border-radius: 4px;
            padding: 10px;
            margin: 10px 0;
        }
        
        .price-breakdown {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .factor-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
        }
        
        .factor-item:last-child {
            border-bottom: none;
        }
        
        .factor-label {
            color: #666;
        }
        
        .factor-value {
            font-weight: bold;
        }
        
        .positive { color: #2b8a3e; }
        .negative { color: #c92a2a; }
        .neutral { color: #495057; }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success">
            <?php echo htmlspecialchars($_SESSION['success']); ?>
            <?php unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error">
            <?php echo htmlspecialchars($_SESSION['error']); ?>
            <?php unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['warning'])): ?>
        <div class="alert warning">
            <?php echo htmlspecialchars($_SESSION['warning']); ?>
            <?php unset($_SESSION['warning']); ?>
        </div>
    <?php endif; ?>
    
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            <div class="userDetailsCard">
                <div>
                    <h1 class="GreenTextLarge">Sell Product (<?php echo $user_role; ?>)</h1>
                    <p style="color: #666;">
                        Smart Pricing Enabled | Trust Score: 
                        <strong style="color: <?php echo $user_result['trust_score'] >= 70 ? '#2b8a3e' : ($user_result['trust_score'] >= 40 ? '#e67700' : '#c92a2a'); ?>;">
                            <?php echo $user_result['trust_score']; ?>
                        </strong>
                    </p>
                </div>
                <div>
                    <a href="vendorDashboard.php" class="smallBtn Gray">Back to Dashboard</a>
                </div>
            </div>
            
               <div class="navCard">
                <a href="../vendors/vendorDashboard.php" >Dashboard</a>
                <a href="../vendors/userInventory.php">Product Registration</a>
                <a href="../vendors/sell_product.php"style="background: rgba(255,255,255,0.1);">Make Sale</a>
                <a href="../vendors/userTransaction.php">Transactions logs</a>
                <a href="../vendors/userViolation.php">Policy & Violation</a>
            </div>
            
            <div class="gridCard">
                <h3 style="margin-top: 0; color: #1864ab;">📊 Market Overview</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 10px;">
                    <div class="insight-card">
                    <div style="font-weight: bold;">Active Commodities</div>                        <div class="insight-value"><?php echo $market_summary['total_commodities']; ?></div>
                    </div>
                    <div class="insight-card">
                        <div style="font-weight: bold;">Price Caps</div>
                        <div class="insight-value"><?php echo $market_summary['active_caps']; ?></div>
                    </div>
                    <div class="insight-card">
                        <div style="font-weight: bold;">Weekly Transactions</div>
                        <div class="insight-value"><?php echo $market_summary['recent_transactions']; ?></div>
                    </div>
                    <div class="insight-card">
                        <div style="font-weight: bold;">Avg Price Trend</div>
                        <div class="insight-value">৳<?php echo $market_summary['avg_price_trend']; ?></div>
                    </div>
                </div>
                <div style="margin-top: 10px; font-size: 12px; color: #666;">
                    <span class="confidence-indicator confidence-high"></span> High confidence 
                    <span class="confidence-indicator confidence-medium"></span> Medium confidence 
                    <span class="confidence-indicator confidence-low"></span> Low confidence
                </div>
            </div>
            
            <!-- Hoarding Warnings -->
            <?php if (!empty($hoarding_warnings)): ?>
            <div class="hoarding-warning">
                <h4 style="margin-top: 0; color: #e67700;">⚠️ Hoarding Alert</h4>
                <?php foreach($hoarding_warnings as $commodity_id => $warning): ?>
                    <p style="margin: 5px 0;">
                        <strong><?php echo $warning['reason']; ?></strong><br>
                        <?php if (isset($warning['details'])): ?>
                            Stock: <?php echo $warning['details']['current_stock']; ?> units | 
                            Daily sales: <?php echo $warning['details']['avg_daily_sales']; ?> | 
                            Supply: <?php echo $warning['details']['days_supply']; ?> days
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
                <small style="color: #666;">Consider selling excess stock to avoid penalties.</small>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($price_caps)): ?>
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">📈 Retail Price Caps & Market Reference</h2>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th>Commodity</th>
                                <th>Retail Cap</th>
                                <th>Smart Price for <?php echo $user_role; ?></th>
                                <th>Market Factors</th>
                                <th>Confidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($price_caps as $cap): 
                                $price_info = $priceCalculator->calculateSmartPrice(
                                    $user_role, 
                                    $cap['max_price_per_unit'], 
                                    $cap['commodity_id'],
                                    $cap['commodity_name'],
                                    $user_id
                                );
                                
                                // Get seasonality info
                                $seasonality = $priceCalculator->getSeasonalityFactor($cap['commodity_name'], date('n'));
                            ?>
                            <tr>
                                <td>
                                    <div class="tableBoldText"><?php echo htmlspecialchars($cap['commodity_name']); ?></div>
                                    <div class="tablesmallText"><?php echo $cap['unit_type']; ?></div>
                                </td>
                                <td>
                                    <div class="tableBoldText" style="color: #28a745;">
                                        ৳<?php echo number_format($cap['max_price_per_unit'], 2); ?>
                                    </div>
                                    <div class="tablesmallText">Since <?php echo $cap['effective_date']; ?></div>
                                </td>
                                <td>
                                    <div class="tableBoldText" style="color: #214332;">
                                        ৳<?php echo number_format($price_info['recommended'], 2); ?>
                                    </div>
                                    <div class="tablesmallText">
                                        ৳<?php echo number_format($price_info['min'], 2); ?>-৳<?php echo number_format($price_info['max'], 2); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                                        <?php if ($price_info['market_condition'] !== 'normal'): ?>
                                            <span class="price-info-badge badge-<?php echo str_replace('_', '-', $price_info['market_condition']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $price_info['market_condition'])); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($seasonality['type'] !== 'normal'): ?>
                                            <span class="price-info-badge badge-<?php echo str_replace('_', '-', $seasonality['type']); ?>-season">
                                                <?php echo $seasonality['description']; ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($price_info['market_avg'] > 0): ?>
                                            <span class="price-info-badge badge-market-avg">
                                                Market Data
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($price_info['trust_factor']['score'] > 70): ?>
                                            <span class="price-info-badge badge-high-trust">
                                                Good Rep
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="confidence-indicator confidence-<?php echo $price_info['confidence']; ?>"></span>
                                    <?php echo ucfirst($price_info['confidence']); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="grid">
                <div class="gridCard">
                    <h2 style="color: #214332; margin-bottom: 20px;">📦 Your Available Batches</h2>
                    <?php if (!empty($batches_with_prices)): ?>
                        <div style="max-height: 500px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 10px;">
                            <?php foreach($batches_with_prices as $batch): 
                                $price_info = $batch['price_info'];
                                $has_hoarding = isset($batch['hoarding_warning']);
                            ?>
                            <div class="batchCard <?php echo $has_hoarding ? 'hoarding-alert' : ''; ?>" 
                                 onclick="selectBatchWithPrice(
                                    '<?php echo $batch['batch_id']; ?>',
                                    '<?php echo htmlspecialchars(addslashes($batch['commodity_name'])); ?>',
                                    <?php echo $batch['current_quantity']; ?>,
                                    '<?php echo $batch['unit_type']; ?>',
                                    <?php echo $batch['retail_price_cap'] ? $batch['retail_price_cap'] : 'null'; ?>,
                                    <?php echo $price_info['recommended']; ?>,
                                    <?php echo $price_info['min']; ?>,
                                    <?php echo $price_info['max']; ?>,
                                    '<?php echo $price_info['confidence']; ?>',
                                    '<?php echo addslashes(json_encode($price_info['insights']['factors'])); ?>'
                                )" 
                                style="cursor: pointer; margin-bottom: 10px; border-left: 4px solid <?php echo $has_hoarding ? '#ff922b' : '#51cf66'; ?>;">
                                <div class="batchCardItem">
                                    <div style="flex: 2;">
                                        <div class="tableBoldText"><?php echo htmlspecialchars($batch['commodity_name']); ?></div>
                                        <div class="tablesmallText">
                                            Batch ID: <?php echo $batch['batch_id']; ?> | 
                                            Available: <strong><?php echo $batch['current_quantity']; ?> <?php echo $batch['unit_type']; ?></strong> | 
                                            Harvest: <?php echo $batch['production_date']; ?>
                                        </div>
                                        <?php if ($has_hoarding): ?>
                                            <div style="color: #e67700; font-size: 12px; margin-top: 5px;">
                                                ⚠️ Hoarding alert
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex: 1; text-align: right;">
                                        <div class="tableBoldText" style="color: #214332;">
                                            ৳<?php echo number_format($price_info['recommended'], 2); ?>
                                        </div>
                                        <div class="tablesmallText">
                                            <span class="confidence-indicator confidence-<?php echo $price_info['confidence']; ?>"></span>
                                            <?php echo ucfirst($price_info['confidence']); ?>
                                        </div>
                                        <?php if ($batch['retail_price_cap']): ?>
                                            <div class="tablesmallText" style="color: #28a745;">
                                                Cap: ৳<?php echo number_format($batch['retail_price_cap'], 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <p>No available batches to sell.</p>
                            <a href="../vendors/userInventory.php" class="limebtn" style="margin-top: 15px;">Add New Batch</a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="gridCard">
                    <h2 style="color: #214332; margin-bottom: 20px;">💰 Sell Product Form</h2>
                    <form method="POST" action="" id="sellForm" class="form-container">
                        <div class="form-group">
                            <label for="transaction_type">Transaction Type *</label>
                            <select id="transaction_type" name="transaction_type" required class="form-control">
                                <option value="direct_sale">Direct Sale (Instant)</option>
                                <option value="offer_sale">Make Offer (Requires Buyer Confirmation)</option>
                            </select>
                            <small id="typeHelp" class="tablesmallText">
                                Direct Sale: Instant completion | Offer Sale: Buyer must confirm
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="batch_id">Select Batch *</label>
                            <select id="batch_id" name="batch_id" required class="form-control" onchange="updateBatchInfo()">
                                <option value="">-- Choose a batch --</option>
                                <?php foreach($batches_with_prices as $batch): 
                                    $price_info = $batch['price_info'];
                                ?>
                                    <option value="<?php echo $batch['batch_id']; ?>"
                                            data-quantity="<?php echo $batch['current_quantity']; ?>"
                                            data-retail-cap="<?php echo $batch['retail_price_cap'] ? $batch['retail_price_cap'] : ''; ?>"
                                            data-commodity="<?php echo htmlspecialchars($batch['commodity_name']); ?>"
                                            data-unit="<?php echo $batch['unit_type']; ?>"
                                            data-recommended-price="<?php echo $price_info['recommended']; ?>"
                                            data-min-price="<?php echo $price_info['min']; ?>"
                                            data-max-price="<?php echo $price_info['max']; ?>"
                                            data-confidence="<?php echo $price_info['confidence']; ?>"
                                            data-market-avg="<?php echo $price_info['market_avg']; ?>"
                                            data-insights='<?php echo json_encode($price_info['insights']['factors']); ?>'>
                                        <?php echo htmlspecialchars($batch['commodity_name']); ?> (ID: <?php echo $batch['batch_id']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="batchDetails" class="price-breakdown" style="display: none;">
                            <h4 style="margin-top: 0; color: #214332;">Price Breakdown</h4>
                            <div id="breakdownContent"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="buyer_id">Select Buyer *</label>
                            <select id="buyer_id" name="buyer_id" required class="form-control">
                                <option value="">-- Choose a buyer --</option>
                                <?php while($buyer = $buyers->fetch_assoc()): ?>
                                    <option value="<?php echo $buyer['user_id']; ?>"
                                            data-buyer-role="<?php echo $buyer['role_name']; ?>">
                                        <?php echo htmlspecialchars($buyer['username']); ?> (<?php echo $buyer['role_name']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="quantity">Quantity *</label>
                                <input type="number" id="quantity" name="quantity" 
                                       min="0.01" step="0.01" required 
                                       class="form-control" placeholder="Enter quantity"
                                       oninput="calculateTotal()">
                                <small>Max available: <span id="maxQuantity">0</span> <span id="unitType"></span></small>
                            </div>
                            
                            <div class="form-group">
                                <label for="unit_price">Your Price per Unit (৳) *</label>
                                <input type="number" id="unit_price" name="unit_price" 
                                       min="0.01" step="0.01" required 
                                       class="form-control" placeholder="Enter your selling price"
                                       oninput="checkPrice(); calculateTotal()">
                                <small>
                                    Recommended: ৳<span id="recommendedPrice">0.00</span> 
                                    (<span id="confidenceLevel">-</span> confidence)
                                </small>
                            </div>
                        </div>
                        
                        <div id="priceAnalysis" style="margin: 15px 0; padding: 10px; background: #f8f9fa; border-radius: 4px; display: none;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong id="priceStatusText"></strong>
                                    <div id="priceStatusDetails" class="tablesmallText"></div>
                                </div>
                                <div id="priceStatusIcon"></div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Notes (Optional)</label>
                            <textarea id="notes" name="notes" rows="3" 
                                      class="form-control" 
                                      placeholder="Add any notes about this sale..."></textarea>
                        </div>
                        
                        <div class="calculation-box" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                            <div class="calculation-row">
                                <span>Quantity:</span>
                                <span id="calcQuantity">0</span>
                            </div>
                            <div class="calculation-row">
                                <span>Your Price per Unit:</span>
                                <span>৳ <span id="calcPrice">0.00</span></span>
                            </div>
                            <div class="calculation-row">
                                <span>Total Sale Amount:</span>
                                <span class="tableBoldText">৳ <span id="calcTotal">0.00</span></span>
                            </div>
                        </div>
                        
                        <div class="form-group" style="display: flex; gap: 15px; margin-top: 20px;">
                            <button type="reset" class="smallBtn Gray" style="flex: 1;" onclick="clearForm()">Clear Form</button>
                            <button type="submit" id="submitBtn" class="smallBtn Green" style="flex: 2;">Complete Direct Sale</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">⏳ Pending Sale Offers</h2>
                <?php
                $pending_sql = "SELECT t.transaction_id, t.transaction_date, t.quantity, t.unit_price, 
                                       t.status, c.commodity_name, c.unit_type,
                                       u.username as buyer_name, r.role_name as buyer_role
                                FROM transactions t
                                JOIN batches b ON t.batch_id = b.batch_id
                                JOIN commodities c ON b.commodity_id = c.commodity_id
                                JOIN users u ON t.buyer_id = u.user_id
                                JOIN roles r ON u.role_id = r.role_id
                                WHERE t.seller_id = ? AND t.status = 'Pending'
                                ORDER BY t.transaction_date DESC
                                LIMIT 5";
                $pending_stmt = $conn->prepare($pending_sql);
                $pending_stmt->bind_param("i", $user_id);
                $pending_stmt->execute();
                $pending_sales = $pending_stmt->get_result();
                
                if($pending_sales->num_rows > 0): ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Commodity</th>
                                <th>Buyer</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($sale = $pending_sales->fetch_assoc()): 
                                $total = $sale['quantity'] * $sale['unit_price'];
                            ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($sale['transaction_date'])); ?></td>
                                <td><?php echo htmlspecialchars($sale['commodity_name']); ?></td>
                                <td><?php echo htmlspecialchars($sale['buyer_name']); ?> (<?php echo $sale['buyer_role']; ?>)</td>
                                <td><?php echo $sale['quantity']; ?> <?php echo $sale['unit_type']; ?></td>
                                <td>৳<?php echo number_format($sale['unit_price'], 2); ?></td>
                                <td class="tableBoldText">৳<?php echo number_format($total, 2); ?></td>
                                <td>
                                    <form method="POST" action="cancel_transaction.php" style="display: inline;">
                                        <input type="hidden" name="transaction_id" value="<?php echo $sale['transaction_id']; ?>">
                                        <button type="submit" class="cardBtn DarkRed" onclick="return confirm('Cancel this sale offer?')">Cancel</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No pending sale offers.</p>
                <?php endif; 
                $pending_stmt->close();
                $batches_stmt->close();
                $buyers_stmt->close();
                ?>
            </div>
        </div>
        <div class="footer">
            <p>Syndicate Buster Admin Panel © 2026</p>
        </div>
    </div>
    
    <script>
    function selectBatchWithPrice(batchId, commodity, quantity, unit, retailCap, recommendedPrice, minPrice, maxPrice, confidence, insights) {
        document.getElementById('batch_id').value = batchId;
        
        // Auto-fill the recommended price
        document.getElementById('unit_price').value = parseFloat(recommendedPrice).toFixed(2);
        document.getElementById('recommendedPrice').textContent = parseFloat(recommendedPrice).toFixed(2);
        document.getElementById('confidenceLevel').textContent = confidence;
        
        updateBatchInfo();
        checkPrice();
        calculateTotal();
        
        // Show price breakdown
        showPriceBreakdown(batchId, commodity, unit, retailCap, recommendedPrice, minPrice, maxPrice, confidence, insights);
    }
    
    function showPriceBreakdown(batchId, commodity, unit, retailCap, recommendedPrice, minPrice, maxPrice, confidence, insights) {
        const breakdown = document.getElementById('batchDetails');
        const content = document.getElementById('breakdownContent');
        
        // Parse insights if it's a JSON string
        let insightsArray = [];
        try {
            insightsArray = JSON.parse(insights);
        } catch (e) {
            insightsArray = insights.split(',');
        }
        
        // Create HTML for breakdown
        let html = `
            <div class="factor-item">
                <span class="factor-label">Commodity:</span>
                <span class="factor-value">${commodity}</span>
            </div>
            <div class="factor-item">
                <span class="factor-label">Unit:</span>
                <span class="factor-value">${unit}</span>
            </div>`;
        
        if (retailCap && retailCap !== 'null') {
            html += `
                <div class="factor-item">
                    <span class="factor-label">Retail Price Cap:</span>
                    <span class="factor-value positive">৳${parseFloat(retailCap).toFixed(2)}</span>
                </div>`;
        }
        
        html += `
            <div class="factor-item">
                <span class="factor-label">Recommended Price:</span>
                <span class="factor-value">৳${parseFloat(recommendedPrice).toFixed(2)}</span>
            </div>
            <div class="factor-item">
                <span class="factor-label">Price Range:</span>
                <span class="factor-value">৳${parseFloat(minPrice).toFixed(2)} - ৳${parseFloat(maxPrice).toFixed(2)}</span>
            </div>
            <div class="factor-item">
                <span class="factor-label">Confidence:</span>
                <span class="factor-value">
                    <span class="confidence-indicator confidence-${confidence}"></span>
                    ${capitalizeFirstLetter(confidence)}
                </span>
            </div>`;
        
        if (insightsArray.length > 0) {
            html += `
                <div class="factor-item">
                    <span class="factor-label">Price Factors:</span>
                    <span class="factor-value">
                        ${insightsArray.join(', ')}
                    </span>
                </div>`;
        }
        
        content.innerHTML = html;
        breakdown.style.display = 'block';
    }
    
    function updateBatchInfo() {
        const batchSelect = document.getElementById('batch_id');
        const selectedOption = batchSelect.options[batchSelect.selectedIndex];
        const userRole = "<?php echo $user_role; ?>";
        
        if (selectedOption.value) {
            const maxQuantity = selectedOption.getAttribute('data-quantity');
            const retailCap = selectedOption.getAttribute('data-retail-cap');
            const unitType = selectedOption.getAttribute('data-unit');
            const recommendedPrice = selectedOption.getAttribute('data-recommended-price');
            const minPrice = selectedOption.getAttribute('data-min-price');
            const maxPrice = selectedOption.getAttribute('data-max-price');
            const confidence = selectedOption.getAttribute('data-confidence');
            const marketAvg = selectedOption.getAttribute('data-market-avg');
            const insights = selectedOption.getAttribute('data-insights');
            
            document.getElementById('maxQuantity').textContent = maxQuantity;
            document.getElementById('unitType').textContent = unitType;
            document.getElementById('recommendedPrice').textContent = parseFloat(recommendedPrice).toFixed(2);
            document.getElementById('confidenceLevel').textContent = confidence;
            
            // Set max attribute on quantity input
            document.getElementById('quantity').max = maxQuantity;
            
            // Show breakdown
            const commodity = selectedOption.getAttribute('data-commodity');
            showPriceBreakdown(
                selectedOption.value,
                commodity,
                unitType,
                retailCap,
                recommendedPrice,
                minPrice,
                maxPrice,
                confidence,
                insights
            );
            
            // Update calculation display
            calculateTotal();
            checkPrice();
        } else {
            document.getElementById('batchDetails').style.display = 'none';
        }
    }
    
    function checkPrice() {
        const priceInput = document.getElementById('unit_price');
        const retailCapText = document.getElementById('batchDetails').querySelector('.factor-value.positive');
        const priceAnalysis = document.getElementById('priceAnalysis');
        const priceStatusText = document.getElementById('priceStatusText');
        const priceStatusDetails = document.getElementById('priceStatusDetails');
        const priceStatusIcon = document.getElementById('priceStatusIcon');
        const userRole = "<?php echo $user_role; ?>";
        
        if (!priceInput.value) {
            priceAnalysis.style.display = 'none';
            return;
        }
        
        const enteredPrice = parseFloat(priceInput.value);
        const recommendedPrice = parseFloat(document.getElementById('recommendedPrice').textContent);
        const minPrice = document.querySelector('.factor-item:nth-child(4) .factor-value')?.textContent.replace('৳', '').split(' - ')[0];
        const maxPrice = document.querySelector('.factor-item:nth-child(4) .factor-value')?.textContent.replace('৳', '').split(' - ')[1];
        
        priceAnalysis.style.display = 'block';
        
        // Check for retailer price cap violation
        if (userRole === 'Retailer' && retailCapText) {
            const retailCap = parseFloat(retailCapText.textContent.replace('৳', ''));
            
            if (enteredPrice > retailCap) {
                const excessPercent = ((enteredPrice - retailCap) / retailCap) * 100;
                priceStatusText.innerHTML = '⚠️ <span style="color: #c92a2a;">PRICE CAP VIOLATION</span>';
                priceStatusDetails.innerHTML = `
                    Your price: <strong>৳${enteredPrice.toFixed(2)}</strong><br>
                    Retail cap: <strong>৳${retailCap.toFixed(2)}</strong><br>
                    Excess: <strong>৳${(enteredPrice - retailCap).toFixed(2)} (${excessPercent.toFixed(1)}%)</strong>
                `;
                priceStatusIcon.innerHTML = '🚫';
                priceAnalysis.style.background = '#fff5f5';
                priceAnalysis.style.borderLeft = '4px solid #c92a2a';
            } else if (enteredPrice >= retailCap * 0.95) {
                priceStatusText.innerHTML = '⚡ <span style="color: #e67700;">Close to Price Cap</span>';
                priceStatusDetails.innerHTML = `Your price is ${((retailCap - enteredPrice) / retailCap * 100).toFixed(1)}% below retail cap`;
                priceStatusIcon.innerHTML = '⚠️';
                priceAnalysis.style.background = '#fff9db';
                priceAnalysis.style.borderLeft = '4px solid #ffd43b';
            } else {
                priceStatusText.innerHTML = '✅ <span style="color: #2b8a3e;">Within Price Cap</span>';
                priceStatusDetails.innerHTML = `Your price is ${((retailCap - enteredPrice) / retailCap * 100).toFixed(1)}% below retail cap`;
                priceStatusIcon.innerHTML = '👍';
                priceAnalysis.style.background = '#ebfbee';
                priceAnalysis.style.borderLeft = '4px solid #51cf66';
            }
        } else {
            // Check deviation from recommended price
            const deviation = ((enteredPrice - recommendedPrice) / recommendedPrice) * 100;
            
            if (Math.abs(deviation) > 50) {
                priceStatusText.innerHTML = '⚠️ <span style="color: #e67700;">High Deviation</span>';
                priceStatusDetails.innerHTML = `
                    ${deviation > 0 ? 'Above' : 'Below'} recommended by <strong>${Math.abs(deviation).toFixed(1)}%</strong><br>
                    Recommended: <strong>৳${recommendedPrice.toFixed(2)}</strong>
                `;
                priceStatusIcon.innerHTML = '📈';
                priceAnalysis.style.background = '#fff9db';
                priceAnalysis.style.borderLeft = '4px solid #ffd43b';
            } else if (Math.abs(deviation) > 20) {
                priceStatusText.innerHTML = '📊 <span style="color: #4dabf7;">Moderate Deviation</span>';
                priceStatusDetails.innerHTML = `
                    ${deviation > 0 ? 'Above' : 'Below'} recommended by <strong>${Math.abs(deviation).toFixed(1)}%</strong><br>
                    Recommended: <strong>৳${recommendedPrice.toFixed(2)}</strong>
                `;
                priceStatusIcon.innerHTML = '📊';
                priceAnalysis.style.background = '#e7f5ff';
                priceAnalysis.style.borderLeft = '4px solid #4dabf7';
            } else {
                priceStatusText.innerHTML = '✅ <span style="color: #2b8a3e;">Good Price</span>';
                priceStatusDetails.innerHTML = `
                    Close to recommended price<br>
                    Deviation: <strong>${Math.abs(deviation).toFixed(1)}%</strong>
                `;
                priceStatusIcon.innerHTML = '👍';
                priceAnalysis.style.background = '#ebfbee';
                priceAnalysis.style.borderLeft = '4px solid #51cf66';
            }
        }
    }
    
    function calculateTotal() {
        const quantity = parseFloat(document.getElementById('quantity').value) || 0;
        const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
        const total = quantity * unitPrice;
        
        document.getElementById('calcQuantity').textContent = quantity.toFixed(2);
        document.getElementById('calcPrice').textContent = unitPrice.toFixed(2);
        document.getElementById('calcTotal').textContent = total.toFixed(2);
        
        // Update price check
        checkPrice();
    }
    
    function clearForm() {
        document.getElementById('batchDetails').style.display = 'none';
        document.getElementById('priceAnalysis').style.display = 'none';
        document.getElementById('maxQuantity').textContent = '0';
        document.getElementById('unitType').textContent = '';
        document.getElementById('recommendedPrice').textContent = '0.00';
        document.getElementById('confidenceLevel').textContent = '-';
        calculateTotal();
    }
    
    // Helper function
    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
    
    // Handle transaction type change
    document.getElementById('transaction_type').addEventListener('change', function() {
        const type = this.value;
        const submitBtn = document.getElementById('submitBtn');
        const typeHelp = document.getElementById('typeHelp');
        
        if (type === 'direct_sale') {
            submitBtn.textContent = 'Complete Direct Sale';
            submitBtn.className = 'smallBtn Green';
            typeHelp.textContent = 'Direct Sale: Sale will be completed instantly. Batch quantity will be reduced immediately.';
        } else {
            submitBtn.textContent = 'Send Sale Offer';
            submitBtn.className = 'smallBtn Cyan';
            typeHelp.textContent = 'Offer Sale: Buyer must confirm before sale completes. Batch quantity remains reserved until confirmation.';
        }
    });
    
    // Form validation
    document.getElementById('sellForm').addEventListener('submit', function(e) {
        const quantity = parseFloat(document.getElementById('quantity').value);
        const maxQuantity = parseFloat(document.getElementById('maxQuantity').textContent);
        const unitPrice = parseFloat(document.getElementById('unit_price').value);
        const userRole = "<?php echo $user_role; ?>";
        const transactionType = document.getElementById('transaction_type').value;
        
        if (quantity > maxQuantity) {
            e.preventDefault();
            alert('Quantity exceeds available stock. Available: ' + maxQuantity);
            return false;
        }
        
        // Special validation for Retailers
        const retailCapText = document.querySelector('.factor-value.positive');
        if (userRole === 'Retailer' && retailCapText) {
            const retailCap = parseFloat(retailCapText.textContent.replace('৳', ''));
            if (unitPrice > retailCap) {
                const excessPercent = ((unitPrice - retailCap) / retailCap) * 100;
                if (!confirm(`⚠️ RETAIL PRICE CAP VIOLATION!\n\nAs a Retailer, you MUST NOT exceed retail price cap!\n\nYour Price: ৳${unitPrice.toFixed(2)}\nRetail Cap: ৳${retailCap.toFixed(2)}\nExcess: ৳${(unitPrice - retailCap).toFixed(2)} (${excessPercent.toFixed(1)}%)\n\nProceeding will:\n• Reduce trust score by ${excessPercent > 20 ? '25' : '20'} points\n• Create violation record\n• Risk account suspension\n\nProceed anyway?`)) {
                    e.preventDefault();
                    return false;
                }
            }
        }
        
        // Confirmation message based on transaction type
        const commodity = document.querySelector('.factor-item:nth-child(1) .factor-value')?.textContent || 'product';
        const totalAmount = document.getElementById('calcTotal').textContent;
        
        if (transactionType === 'direct_sale') {
            if (!confirm(`Complete Direct Sale?\n\n${quantity} units of ${commodity}\nPrice: ৳${unitPrice.toFixed(2)} per unit\nTotal: ৳${totalAmount}\n\nThis will:\n• Transfer ownership immediately\n• Reduce batch quantity\n• Cannot be undone`)) {
                e.preventDefault();
                return false;
            }
        } else {
            if (!confirm(`Send Sale Offer?\n\n${quantity} units of ${commodity}\nPrice: ৳${unitPrice.toFixed(2)} per unit\nTotal: ৳${totalAmount}\n\nThis will:\n• Send offer to buyer for confirmation\n• Reserve the quantity\n• You can cancel before buyer confirms`)) {
                e.preventDefault();
                return false;
            }
        }
        
        return true;
    });
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateBatchInfo();
        calculateTotal();
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-success, .alert-error, .alert.warning');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);
    });
    </script>
</body>
</html>