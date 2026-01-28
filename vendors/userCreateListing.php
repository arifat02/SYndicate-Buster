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

// Get active listings for the user
$listings_sql = "SELECT l.listing_id, l.listing_quantity, l.remaining_quantity, l.unit_price, 
                        l.status, l.created_at,
                        c.commodity_name, c.unit_type,
                        b.batch_id
                 FROM listings l
                 JOIN batches b ON l.batch_id = b.batch_id
                 JOIN commodities c ON b.commodity_id = c.commodity_id
                 WHERE l.seller_id = ? 
                 AND l.status IN ('Active', 'Partially_Filled')
                 ORDER BY l.created_at DESC";
$listings_stmt = $conn->prepare($listings_sql);
$listings_stmt->bind_param("i", $user_id);
$listings_stmt->execute();
$active_listings = $listings_stmt->get_result();

// Process form submission for creating listing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_listing') {
    $batch_id = $_POST['batch_id'];
    $listing_quantity = floatval($_POST['listing_quantity']);
    $unit_price = floatval($_POST['unit_price']);
    $notes = $_POST['notes'] ?? '';
    $listing_type = $_POST['listing_type'] ?? 'fixed_price';
    
    $errors = [];
    $warnings = [];
    
    if (empty($batch_id)) {
        $errors[] = "Please select a batch";
    }
    
    if ($listing_quantity <= 0) {
        $errors[] = "Quantity must be greater than 0";
    }
    
    if ($unit_price <= 0) {
        $errors[] = "Price must be greater than 0";
    }
    
    $check_batch_sql = "SELECT b.current_quantity, b.batch_id, c.commodity_id,
                               pc.max_price_per_unit as retail_price_cap,
                               c.commodity_name, c.unit_type,
                               b.owner_id
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
    } elseif ($listing_quantity > $batch_check['current_quantity']) {
        $errors[] = "Quantity exceeds available stock. Available: " . $batch_check['current_quantity'] . " " . $batch_check['unit_type'];
    }
    
    // Check for existing listing from same batch
    $existing_listing_sql = "SELECT listing_id FROM listings 
                             WHERE batch_id = ? 
                             AND seller_id = ? 
                             AND status IN ('Active', 'Partially_Filled')";
    $existing_stmt = $conn->prepare($existing_listing_sql);
    $existing_stmt->bind_param("ii", $batch_id, $user_id);
    $existing_stmt->execute();
    if ($existing_stmt->get_result()->num_rows > 0) {
        $errors[] = "You already have an active listing from this batch.";
    }
    $existing_stmt->close();
    
    // Check for price violations using price calculator
    $price_violation = $priceCalculator->checkPriceViolation($user_role, $unit_price, $batch_check['retail_price_cap']);
    $violation_flag = $price_violation['violation'] ? 1 : 0;
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
            $listing_code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
            
            $listing_sql = "INSERT INTO listings (listing_code, batch_id, seller_id, 
                                                  listing_quantity, remaining_quantity, unit_price, 
                                                  listing_type, notes, status, violation_flag) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)";
            $listing_stmt = $conn->prepare($listing_sql);
            $listing_stmt->bind_param("siidddssi", $listing_code, $batch_id, $user_id, 
                                      $listing_quantity, $listing_quantity, $unit_price, 
                                      $listing_type, $notes, $violation_flag);
            $listing_stmt->execute();
            $listing_id = $conn->insert_id;
            $listing_stmt->close();
            
            // 2. Update batch's reserved quantity
            $update_batch_sql = "UPDATE batches 
                                 SET reserved_quantity = reserved_quantity + ? 
                                 WHERE batch_id = ?";
            $update_stmt = $conn->prepare($update_batch_sql);
            $update_stmt->bind_param("di", $listing_quantity, $batch_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // 3. Create batch reservation record
            $reservation_sql = "INSERT INTO batch_reservations (batch_id, listing_id, quantity, status)
                                VALUES (?, ?, ?, 'Reserved')";
            $reservation_stmt = $conn->prepare($reservation_sql);
            $reservation_stmt->bind_param("iid", $batch_id, $listing_id, $listing_quantity);
            $reservation_stmt->execute();
            $reservation_stmt->close();
            
            // 4. If violation detected, create violation record
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
                    
                    // Link to listing violation
                    $listing_violation_sql = "INSERT INTO price_cap_violations (violation_id, listing_id, price_cap_id, reported_price) 
                                             VALUES (?, ?, ?, ?)";
                    $listing_violation_stmt = $conn->prepare($listing_violation_sql);
                    $listing_violation_stmt->bind_param("iiid", $violation_id, $listing_id, $price_cap_result['price_cap_id'], $unit_price);
                    $listing_violation_stmt->execute();
                    $listing_violation_stmt->close();
                    
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
                    $trust_log_sql = "INSERT INTO trust_score_log (user_id, old_score, new_score, reason, related_listing_id, related_violation_id) 
                                      VALUES (?, ?, ?, ?, ?, ?)";
                    $trust_log_stmt = $conn->prepare($trust_log_sql);
                    $reason = "Retail price cap violation in listing: Listed at ৳" . number_format($unit_price, 2) . " per " . $batch_check['unit_type'] . " exceeding retail cap of ৳" . number_format($batch_check['retail_price_cap'], 2);
                    $trust_log_stmt->bind_param("iiisii", $user_id, $old_trust, $new_trust, $reason, $listing_id, $violation_id);
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
            $_SESSION['success'] = "Listing created successfully! Listing Code: $listing_code";
            
            if ($violation_flag) {
                $_SESSION['warning'] = "⚠️ Retail price cap violation detected! Trust score reduced by $trust_penalty points.";
            }
            
            if (!empty($warnings)) {
                $_SESSION['warning'] = (isset($_SESSION['warning']) ? $_SESSION['warning'] . "<br>" : "") . implode("<br>", $warnings);
            }
            
            header("Location: create_listing.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Failed to create listing: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
        if (!empty($warnings)) {
            $_SESSION['warning'] = implode("<br>", $warnings);
        }
    }
}

// Process listing cancellation
if (isset($_GET['action']) && $_GET['action'] === 'cancel_listing' && isset($_GET['id'])) {
    $listing_id = $_GET['id'];
    
    $conn->begin_transaction();
    try {
        // Get listing details
        $get_listing_sql = "SELECT l.*, b.batch_id 
                            FROM listings l
                            JOIN batches b ON l.batch_id = b.batch_id
                            WHERE l.listing_id = ? AND l.seller_id = ? AND l.status = 'Active'";
        $get_stmt = $conn->prepare($get_listing_sql);
        $get_stmt->bind_param("ii", $listing_id, $user_id);
        $get_stmt->execute();
        $listing = $get_stmt->get_result()->fetch_assoc();
        $get_stmt->close();
        
        if ($listing) {
            // Update batch reserved quantity
            $update_batch_sql = "UPDATE batches 
                                 SET reserved_quantity = reserved_quantity - ? 
                                 WHERE batch_id = ?";
            $update_stmt = $conn->prepare($update_batch_sql);
            $update_stmt->bind_param("di", $listing['remaining_quantity'], $listing['batch_id']);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Delete batch reservations
            $delete_reservations_sql = "DELETE FROM batch_reservations WHERE listing_id = ?";
            $del_res_stmt = $conn->prepare($delete_reservations_sql);
            $del_res_stmt->bind_param("i", $listing_id);
            $del_res_stmt->execute();
            $del_res_stmt->close();
            
            // Update listing status
            $update_listing_sql = "UPDATE listings SET status = 'Cancelled', cancelled_at = NOW() WHERE listing_id = ?";
            $update_listing_stmt = $conn->prepare($update_listing_sql);
            $update_listing_stmt->bind_param("i", $listing_id);
            $update_listing_stmt->execute();
            $update_listing_stmt->close();
            
            $conn->commit();
            $_SESSION['success'] = "Listing cancelled successfully.";
        } else {
            $_SESSION['error'] = "Listing not found or cannot be cancelled.";
        }
        
        header("Location: create_listing.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Failed to cancel listing: " . $e->getMessage();
        header("Location: create_listing.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create - Syndicate Buster</title>
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
                    <h1 >Create Product Listing (<?php echo $user_role; ?>)</h1>              
                    <div>
                    <a href="../logout.php" class="smallBtn Red">Logout</a>
                </div>
                </div>
            
              <div class="navCard">
                <a href="../vendors/vendorDashboard.php">Dashboard</a>
                <a href="../vendors/userProductReg.php">Product Registration</a>
                <a href="../vendors/userCreateListing.php"  style="background: rgba(255,255,255,0.1);">Create Listing</a>
                <a href="../vendors/userMarketplace.php">Marketplace</a>
                <a href="../vendors/userTransaction.php">Transactions logs</a>
                <a href="../vendors/userViolation.php">Policy & Violation</a>
            </div>
            
            <div class="gridCard">
                <h3 class="GreenTextLarge">📊 Market Overview</h3>
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
                <small style="color: #666;">Consider creating listings for excess stock to avoid penalties.</small>
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
                                                                $available_for_listing = $batch['current_quantity'];
                            ?>
                            <div class="batchCard <?php echo $has_hoarding ? 'hoarding-alert' : ''; ?>" 
                                 onclick="selectBatchWithPrice(
                                    '<?php echo $batch['batch_id']; ?>',
                                    '<?php echo htmlspecialchars(addslashes($batch['commodity_name'])); ?>',
                                    <?php echo $available_for_listing; ?>,
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
                                            Available: <strong><?php echo $available_for_listing; ?> <?php echo $batch['unit_type']; ?></strong> | 
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
                            <p>No available batches to create listings.</p>
                            <a href="../vendors/userInventory.php" class="limebtn" style="margin-top: 15px;">Add New Batch</a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="gridCard">
                    <h2 style="color: #214332; margin-bottom: 20px;">💰 Create Product Listing</h2>
                    <form method="POST" action="" id="listingForm" class="form-container">
                        <input type="hidden" name="action" value="create_listing">
                        
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
                            <label for="listing_type">Listing Type *</label>
                            <select id="listing_type" name="listing_type" required class="form-control">
                                <option value="fixed_price">Fixed Price</option>
                                <option value="negotiable">Negotiable Price</option>
                            </select>
                            <small>Fixed: Price is set | Negotiable: Buyers can make offers</small>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="listing_quantity">Quantity to List *</label>
                                <input type="number" id="listing_quantity" name="listing_quantity" 
                                       min="0.01" step="0.01" required 
                                       class="form-control" placeholder="Enter quantity"
                                       oninput="calculateTotal()">
                                <small>Max available: <span id="maxQuantity">0</span> <span id="unitType"></span></small>
                            </div>
                            
                            <div class="form-group">
                                <label for="unit_price">Price per Unit (৳) *</label>
                                <input type="number" id="unit_price" name="unit_price" 
                                       min="0.01" step="0.01" required 
                                       class="form-control" placeholder="Enter your listing price"
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
                                      placeholder="Add any notes about this listing..."></textarea>
                        </div>
                        
                        <div class="calculation-box" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                            <div class="calculation-row">
                                <span>Quantity to List:</span>
                                <span id="calcQuantity">0</span> <span id="calcUnit"></span>
                            </div>
                            <div class="calculation-row">
                                <span>Your Price per Unit:</span>
                                <span>৳ <span id="calcPrice">0.00</span></span>
                            </div>
                            <div class="calculation-row">
                                <span>Total Listing Value:</span>
                                <span class="tableBoldText">৳ <span id="calcTotal">0.00</span></span>
                            </div>
                        </div>
                        
                        <div class="form-group" style="display: flex; gap: 15px; margin-top: 20px;">
                            <button type="reset" class="smallBtn Gray" style="flex: 1;" onclick="clearForm()">Clear Form</button>
                            <button type="submit" id="submitBtn" class="smallBtn Green" style="flex: 2;">Create Listing</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">📋 Your Active Listings</h2>
                <?php if($active_listings->num_rows > 0): ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Commodity</th>
                                    <th>Listed Quantity</th>
                                    <th>Remaining</th>
                                    <th>Price</th>
                                    <th>Total Value</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($listing = $active_listings->fetch_assoc()): 
                                    $total_value = $listing['listing_quantity'] * $listing['unit_price'];
                                    $remaining_value = $listing['remaining_quantity'] * $listing['unit_price'];
                                ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($listing['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($listing['commodity_name']); ?></td>
                                    <td><?php echo $listing['listing_quantity']; ?> <?php echo $listing['unit_type']; ?></td>
                                    <td>
                                        <div><?php echo $listing['remaining_quantity']; ?> <?php echo $listing['unit_type']; ?></div>
                                        <div class="tablesmallText">৳<?php echo number_format($remaining_value, 2); ?></div>
                                    </td>
                                    <td>৳<?php echo number_format($listing['unit_price'], 2); ?></td>
                                    <td class="tableBoldText">৳<?php echo number_format($total_value, 2); ?></td>
                                    <td>
                                        <span style="padding: 2px 8px; border-radius: 10px; font-size: 11px; 
                                              background: <?php echo $listing['listing_type'] === 'fixed_price' ? '#d3f9d8' : '#fff3bf'; ?>;">
                                            <?php echo ucfirst(str_replace('_', ' ', $listing['listing_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="listing-status status-<?php echo strtolower($listing['status']); ?>">
                                            <?php echo str_replace('_', ' ', $listing['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="create_listing.php?action=cancel_listing&id=<?php echo $listing['listing_id']; ?>" 
                                           class="cardBtn DarkRed" 
                                           onclick="return confirm('Cancel this listing? This will release the reserved quantity.')">
                                           Cancel
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: #666; padding: 20px;">No active listings. Create your first listing above!</p>
                <?php endif; 
                $listings_stmt->close();
                $batches_stmt->close();
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
            document.getElementById('calcUnit').textContent = unitType;
            document.getElementById('recommendedPrice').textContent = parseFloat(recommendedPrice).toFixed(2);
            document.getElementById('confidenceLevel').textContent = confidence;
            
            // Set max attribute on quantity input
            document.getElementById('listing_quantity').max = maxQuantity;
            
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
        const retailCapText = document.getElementById('batchDetails')?.querySelector('.factor-value.positive');
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
        const quantity = parseFloat(document.getElementById('listing_quantity').value) || 0;
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
        document.getElementById('calcUnit').textContent = '';
        document.getElementById('recommendedPrice').textContent = '0.00';
        document.getElementById('confidenceLevel').textContent = '-';
        calculateTotal();
    }
    
    // Helper function
    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
    
    // Form validation
    document.getElementById('listingForm').addEventListener('submit', function(e) {
        const quantity = parseFloat(document.getElementById('listing_quantity').value);
        const maxQuantity = parseFloat(document.getElementById('maxQuantity').textContent);
        const unitPrice = parseFloat(document.getElementById('unit_price').value);
        const userRole = "<?php echo $user_role; ?>";
        
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
        
        // Confirmation message
        const commodity = document.querySelector('.factor-item:nth-child(1) .factor-value')?.textContent || 'product';
        const totalAmount = document.getElementById('calcTotal').textContent;
        const listingType = document.getElementById('listing_type').value;
        const typeText = listingType === 'fixed_price' ? 'Fixed Price' : 'Negotiable';
        
        if (!confirm(`Create ${typeText} Listing?\n\n${quantity} units of ${commodity}\nPrice: ৳${unitPrice.toFixed(2)} per unit\nTotal Value: ৳${totalAmount}\n\nThis will:\n• Create a public listing\n• Reserve ${quantity} units from the batch\n• Be visible to all buyers`)) {
            e.preventDefault();
            return false;
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