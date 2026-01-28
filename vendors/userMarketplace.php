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


$priceCalculator = new PriceCalculator($conn);

$role_sql = "SELECT role_name FROM roles WHERE role_id = ?";
$role_stmt = $conn->prepare($role_sql);
$role_stmt->bind_param("i", $_SESSION['role_id']);
$role_stmt->execute();
$user_role = $role_stmt->get_result()->fetch_assoc()['role_name'];
$role_stmt->close();

$where_conditions = ["l.status = 'Active'"];
$params = [];
$param_types = "";

if (isset($_GET['commodity_id']) && !empty($_GET['commodity_id'])) {
    $where_conditions[] = "c.commodity_id = ?";
    $params[] = $_GET['commodity_id'];
    $param_types .= "i";
}

if (isset($_GET['seller_role']) && !empty($_GET['seller_role'])) {
    $where_conditions[] = "r.role_id = ?";
    $params[] = $_GET['seller_role'];
    $param_types .= "i";
}

if (isset($_GET['min_price']) && !empty($_GET['min_price'])) {
    $where_conditions[] = "l.unit_price >= ?";
    $params[] = $_GET['min_price'];
    $param_types .= "d";
}
if (isset($_GET['max_price']) && !empty($_GET['max_price'])) {
    $where_conditions[] = "l.unit_price <= ?";
    $params[] = $_GET['max_price'];
    $param_types .= "d";
}

if (isset($_GET['min_quantity']) && !empty($_GET['min_quantity'])) {
    $where_conditions[] = "l.remaining_quantity >= ?";
    $params[] = $_GET['min_quantity'];
    $param_types .= "d";
}

$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

$listings_sql = "SELECT l.*, c.commodity_name, c.unit_type,
                        b.production_date, b.expiry_date,
                        u.username as seller_name, u.trust_score as seller_trust_score,
                        r.role_name as seller_role,
                        pc.max_price_per_unit as retail_price_cap,
                        (SELECT COUNT(*) FROM listing_offers lo WHERE lo.listing_id = l.listing_id AND lo.status = 'Pending') as pending_offers
                 FROM listings l
                 JOIN batches b ON l.batch_id = b.batch_id
                 JOIN commodities c ON b.commodity_id = c.commodity_id
                 JOIN users u ON l.seller_id = u.user_id
                 JOIN roles r ON u.role_id = r.role_id
                 LEFT JOIN price_caps pc ON c.commodity_id = pc.commodity_id 
                    AND (pc.expiry_date >= CURDATE() OR pc.expiry_date IS NULL)
                 $where_clause
                 ORDER BY l.created_at DESC";
$listings_stmt = $conn->prepare($listings_sql);
if (!empty($params)) {
    $listings_stmt->bind_param($param_types, ...$params);
}
$listings_stmt->execute();
$all_listings = $listings_stmt->get_result();

$commodities_sql = "SELECT commodity_id, commodity_name FROM commodities WHERE status = 'Active' ORDER BY commodity_name";
$commodities_result = $conn->query($commodities_sql);
$commodities = [];
while($row = $commodities_result->fetch_assoc()) {
    $commodities[] = $row;
}

$seller_roles_sql = "SELECT role_id, role_name FROM roles WHERE role_id IN (1,2,3,4) ORDER BY role_id";
$seller_roles_result = $conn->query($seller_roles_sql);
$seller_roles = [];
while($row = $seller_roles_result->fetch_assoc()) {
    $seller_roles[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'purchase') {
    $listing_id = $_POST['listing_id'];
    $purchase_quantity = floatval($_POST['purchase_quantity']);
    $offer_price = isset($_POST['offer_price']) ? floatval($_POST['offer_price']) : null;
    
    $errors = [];
    
    if ($purchase_quantity <= 0) {
        $errors[] = "Quantity must be greater than 0";
    }
    
    $listing_check_sql = "SELECT l.*, c.commodity_name, c.unit_type, c.commodity_id,
                                 u.username as seller_name, u.user_id as seller_id,
                                 pc.max_price_per_unit as retail_price_cap,
                                 b.batch_id, b.current_quantity, b.reserved_quantity
                          FROM listings l
                          JOIN batches b ON l.batch_id = b.batch_id
                          JOIN commodities c ON b.commodity_id = c.commodity_id
                          JOIN users u ON l.seller_id = u.user_id
                          LEFT JOIN price_caps pc ON c.commodity_id = pc.commodity_id 
                            AND (pc.expiry_date >= CURDATE() OR pc.expiry_date IS NULL)
                          WHERE l.listing_id = ? AND l.status = 'Active'";
    $listing_check_stmt = $conn->prepare($listing_check_sql);
    $listing_check_stmt->bind_param("i", $listing_id);
    $listing_check_stmt->execute();
    $listing = $listing_check_stmt->get_result()->fetch_assoc();
    $listing_check_stmt->close();
    
    if (!$listing) {
        $errors[] = "Listing not found or not available";
    } elseif ($purchase_quantity > $listing['remaining_quantity']) {
        $errors[] = "Quantity exceeds available stock. Available: " . $listing['remaining_quantity'] . " " . $listing['unit_type'];
    } elseif ($user_id == $listing['seller_id']) {
        $errors[] = "You cannot purchase from your own listing";
    }
    
    if ($listing['listing_type'] === 'fixed_price') {
        $total_price = $listing['unit_price'] * $purchase_quantity;
        
        $price_violation = $priceCalculator->checkPriceViolation($user_role, $listing['unit_price'], $listing['retail_price_cap']);
        $violation_flag = $price_violation['violation'];
        $violation_reason = $price_violation['violation'] ? $price_violation['reason'] : '';
        
        if ($violation_flag && $user_role === 'Retailer') {
            $errors[] = "This listing violates retail price cap. As a retailer, you cannot purchase above the price cap.";
        }
    } else {
        if (!$offer_price || $offer_price <= 0) {
            $errors[] = "Please enter an offer price for negotiable listings";
        } else {
            $total_price = $offer_price * $purchase_quantity;
            
            $recommended_price = $priceCalculator->calculateSmartPrice(
                $user_role,
                $listing['retail_price_cap'] ?? 0,
                $listing['commodity_id'],
                $listing['commodity_name'],
                $user_id
            )['recommended'];
            
            if (abs($offer_price - $recommended_price) / $recommended_price > 0.5) {
                $warnings[] = "Your offer price is significantly different from the recommended price";
            }
        }
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            if ($listing['listing_type'] === 'fixed_price') {
                
                $transaction_sql = "INSERT INTO transactions (batch_id, seller_id, buyer_id, unit_price, quantity, status, violation_flag) 
                                    VALUES (?, ?, ?, ?, ?, 'Completed', ?)";
                $transaction_stmt = $conn->prepare($transaction_sql);
                $transaction_stmt->bind_param("iiiddi", $listing['batch_id'], $listing['seller_id'], $user_id, 
                                              $listing['unit_price'], $purchase_quantity, $violation_flag);
                $transaction_stmt->execute();
                $transaction_id = $conn->insert_id;
                $transaction_stmt->close();
                
                // 2. Log transaction status
                $log_sql = "INSERT INTO transaction_status_log (transaction_id, old_status, new_status, changed_by, reason) 
                            VALUES (?, NULL, 'Completed', ?, 'Direct purchase from marketplace')";
                $log_stmt = $conn->prepare($log_sql);
                $log_stmt->bind_param("ii", $transaction_id, $user_id);
                $log_stmt->execute();
                $log_stmt->close();
                
                $update_batch_sql = "UPDATE batches 
                                     SET current_quantity = current_quantity - ?,
                                         reserved_quantity = reserved_quantity - ?
                                     WHERE batch_id = ?";
                $update_stmt = $conn->prepare($update_batch_sql);
                $update_stmt->bind_param("ddi", $purchase_quantity, $purchase_quantity, $listing['batch_id']);
                $update_stmt->execute();
                $update_stmt->close();
                
                // 4. Update listing remaining quantity
                $new_remaining = $listing['remaining_quantity'] - $purchase_quantity;
                $listing_status = $new_remaining > 0 ? 'Active' : 'Completed';
                
                $update_listing_sql = "UPDATE listings 
                                       SET remaining_quantity = ?, 
                                           status = ?
                                       WHERE listing_id = ?";
                $update_listing_stmt = $conn->prepare($update_listing_sql);
                $update_listing_stmt->bind_param("dsi", $new_remaining, $listing_status, $listing_id);
                $update_listing_stmt->execute();
                $update_listing_stmt->close();
                
                $update_reservation_sql = "UPDATE batch_reservations 
                                           SET quantity = quantity - ?,
                                               status = CASE WHEN quantity - ? <= 0 THEN 'Converted' ELSE 'Reserved' END
                                           WHERE listing_id = ?";
                $update_reservation_stmt = $conn->prepare($update_reservation_sql);
                $update_reservation_stmt->bind_param("ddi", $purchase_quantity, $purchase_quantity, $listing_id);
                $update_reservation_stmt->execute();
                $update_reservation_stmt->close();
                
                $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                     VALUES (?, ?, ?, 'transaction_update', ?)";
                $notification_stmt = $conn->prepare($notification_sql);
                $title = "Product Sold!";
                $message = $username . " purchased " . $purchase_quantity . " " . $listing['unit_type'] . 
                          " of " . $listing['commodity_name'] . " for ৳" . number_format($total_price, 2);
                $notification_stmt->bind_param("issi", $listing['seller_id'], $title, $message, $transaction_id);
                $notification_stmt->execute();
                $notification_stmt->close();
                
                if ($violation_flag) {
                    $price_cap_sql = "SELECT price_cap_id FROM price_caps 
                                      WHERE commodity_id = ? 
                                      AND (expiry_date >= CURDATE() OR pc.expiry_date IS NULL)
                                      ORDER BY effective_date DESC LIMIT 1";
                    $pc_stmt = $conn->prepare($price_cap_sql);
                    $pc_stmt->bind_param("i", $listing['commodity_id']);
                    $pc_stmt->execute();
                    $price_cap_result = $pc_stmt->get_result()->fetch_assoc();
                    $pc_stmt->close();
                    
                    if ($price_cap_result) {
                        $violation_sql = "INSERT INTO violations (reporter_id, reported_user_id, violation_type, description, violation_date, status) 
                                          VALUES (?, ?, 'PRICE_CAP', ?, CURDATE(), 'CONFIRMED')";
                        $violation_stmt = $conn->prepare($violation_sql);
                        $system_id = 5; 
                        $violation_stmt->bind_param("iis", $system_id, $listing['seller_id'], $violation_reason);
                        $violation_stmt->execute();
                        $violation_id = $conn->insert_id;
                        $violation_stmt->close();
                        
                        $pc_violation_sql = "INSERT INTO price_cap_violations (violation_id, transaction_id, price_cap_id, reported_price) 
                                             VALUES (?, ?, ?, ?)";
                        $pc_violation_stmt = $conn->prepare($pc_violation_sql);
                        $pc_violation_stmt->bind_param("iiid", $violation_id, $transaction_id, $price_cap_result['price_cap_id'], $listing['unit_price']);
                        $pc_violation_stmt->execute();
                        $pc_violation_stmt->close();
                        
                        $get_trust_sql = "SELECT trust_score FROM users WHERE user_id = ?";
                        $get_trust_stmt = $conn->prepare($get_trust_sql);
                        $get_trust_stmt->bind_param("i", $listing['seller_id']);
                        $get_trust_stmt->execute();
                        $old_trust = $get_trust_stmt->get_result()->fetch_assoc()['trust_score'];
                        $get_trust_stmt->close();
                        
                        $new_trust = max(0, $old_trust - 20); // 20 points penalty for violation
                        
                        $trust_update_sql = "UPDATE users SET trust_score = ? WHERE user_id = ?";
                        $trust_stmt = $conn->prepare($trust_update_sql);
                        $trust_stmt->bind_param("ii", $new_trust, $listing['seller_id']);
                        $trust_stmt->execute();
                        $trust_stmt->close();
                        
                        $trust_log_sql = "INSERT INTO trust_score_log (user_id, old_score, new_score, reason, related_transaction_id, related_violation_id) 
                                          VALUES (?, ?, ?, ?, ?, ?)";
                        $trust_log_stmt = $conn->prepare($trust_log_sql);
                        $reason = "Retail price cap violation in listing purchase";
                        $trust_log_stmt->bind_param("iiisii", $listing['seller_id'], $old_trust, $new_trust, $reason, $transaction_id, $violation_id);
                        $trust_log_stmt->execute();
                        $trust_log_stmt->close();
                    }
                }
                
                $_SESSION['success'] = "Purchase completed successfully! Transaction ID: $transaction_id, Total: ৳" . number_format($total_price, 2);
                
            } else {
                
                $offer_sql = "INSERT INTO listing_offers (listing_id, buyer_id, offered_price, offered_quantity, status) 
                              VALUES (?, ?, ?, ?, 'Pending')";
                $offer_stmt = $conn->prepare($offer_sql);
                $offer_stmt->bind_param("iidd", $listing_id, $user_id, $offer_price, $purchase_quantity);
                $offer_stmt->execute();
                $offer_id = $conn->insert_id;
                $offer_stmt->close();
                
                $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id) 
                                     VALUES (?, ?, ?, 'sale_offer', ?)";
                $notification_stmt = $conn->prepare($notification_sql);
                $title = "New Offer Received";
                $message = $username . " made an offer for " . $purchase_quantity . " " . $listing['unit_type'] . 
                          " of " . $listing['commodity_name'] . " at ৳" . number_format($offer_price, 2) . " per unit";
                $notification_stmt->bind_param("issi", $listing['seller_id'], $title, $message, $offer_id);
                $notification_stmt->execute();
                $notification_stmt->close();
                
                $_SESSION['success'] = "Offer submitted successfully! The seller will review your offer.";
            }
            
            $conn->commit();
            header("Location: marketplace.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Purchase failed: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketplace - Syndicate Buster</title>
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
        .listing-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: white;
            transition: all 0.3s ease;
        }
        .listing-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .listing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .listing-type {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .type-fixed { background: #d3f9d8; color: #2b8a3e; }
        .type-negotiable { background: #fff3bf; color: #e67700; }
        .seller-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .seller-trust {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
        }
        .trust-high { background: #d3f9d8; color: #2b8a3e; }
        .trust-medium { background: #fff3bf; color: #e67700; }
        .trust-low { background: #ffe3e3; color: #c92a2a; }
        .price-cap-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 10px;
            margin: 10px 0;
            color: #e67700;
        }
        .filter-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        .listing-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }
        .stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .expiry-warning {
            color: #e67700;
            font-weight: bold;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 500px;
        }
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
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
    
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            <div class="userDetailsCard">
                    <h1>Marketplace (<?php echo $user_role; ?>)</h1>
                    <div>
                    <a href="../logout.php" class="smallBtn Red">Logout</a>
                </div>
                
            </div>
            
             <div class="navCard">
                <a href="../vendors/vendorDashboard.php">Dashboard</a>
                <a href="../vendors/userProductReg.php">Product Registration</a>
                <a href="../vendors/userCreateListing.php">Create Listing</a>
                <a href="../vendors/userMarketplace.php"  style="background: rgba(255,255,255,0.1);">Marketplace</a>
                <a href="../vendors/userTransaction.php">Transactions logs</a>
                <a href="../vendors/userViolation.php">Policy & Violation</a>
            </div>
            
            <!-- Filters Section -->
            <div class="filter-section">
                <h3 style="margin-top: 0; color: #1864ab;">🔍 Filter Listings</h3>
                <form method="GET" action="" class="filter-grid">
                    <div class="form-group">
                        <label for="commodity_id">Commodity</label>
                        <select id="commodity_id" name="commodity_id" class="form-control">
                            <option value="">All Commodities</option>
                            <?php foreach($commodities as $commodity): ?>
                                <option value="<?php echo $commodity['commodity_id']; ?>"
                                    <?php echo isset($_GET['commodity_id']) && $_GET['commodity_id'] == $commodity['commodity_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($commodity['commodity_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="seller_role">Seller Type</label>
                        <select id="seller_role" name="seller_role" class="form-control">
                            <option value="">All Sellers</option>
                            <?php foreach($seller_roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>"
                                    <?php echo isset($_GET['seller_role']) && $_GET['seller_role'] == $role['role_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="min_price">Min Price (৳)</label>
                        <input type="number" id="min_price" name="min_price" class="form-control" 
                               placeholder="Min price" 
                               value="<?php echo $_GET['min_price'] ?? ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="max_price">Max Price (৳)</label>
                        <input type="number" id="max_price" name="max_price" class="form-control" 
                               placeholder="Max price" 
                               value="<?php echo $_GET['max_price'] ?? ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="min_quantity">Min Quantity</label>
                        <input type="number" id="min_quantity" name="min_quantity" class="form-control" 
                               placeholder="Min quantity" 
                               value="<?php echo $_GET['min_quantity'] ?? ''; ?>">
                    </div>
                    
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="smallBtn Green">Apply Filters</button>
                            <a href="marketplace.php" class="smallBtn Cyan">Clear Filters</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">
                    📋 Available Listings 
                    <span style="font-size: 14px; color: #666;">
                        (<?php echo $all_listings->num_rows; ?> listings found)
                    </span>
                </h2>
                
                <?php if($all_listings->num_rows > 0): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                        <?php while($listing = $all_listings->fetch_assoc()): 
                            $total_value = $listing['unit_price'] * $listing['remaining_quantity'];
                            $days_to_expiry = $listing['expiry_date'] ? 
                                (strtotime($listing['expiry_date']) - time()) / (60 * 60 * 24) : null;
                            
                            // Calculate trust score class
                            $trust_class = 'trust-high';
                            if ($listing['seller_trust_score'] < 70) {
                                $trust_class = 'trust-medium';
                            }
                            if ($listing['seller_trust_score'] < 40) {
                                $trust_class = 'trust-low';
                            }
                            
                            // Check if price violates retail cap
                            $price_violation = false;
                            if ($listing['retail_price_cap'] && $listing['unit_price'] > $listing['retail_price_cap']) {
                                $price_violation = true;
                            }
                        ?>
                        <div class="listing-card">
                            <div class="listing-header">
                                <div>
                                    <h3 style="margin: 0; color: #214332;"><?php echo htmlspecialchars($listing['commodity_name']); ?></h3>
                                    <div class="tablesmallText">
                                        Listing ID: <?php echo $listing['listing_code']; ?> | 
                                        <?php echo $listing['remaining_quantity']; ?> <?php echo $listing['unit_type']; ?> available
                                    </div>
                                </div>
                                <span class="listing-type type-<?php echo $listing['listing_type']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $listing['listing_type'])); ?>
                                </span>
                            </div>
                            
                            <div class="seller-info">
                                <div>
                                    <strong><?php echo htmlspecialchars($listing['seller_name']); ?></strong>
                                    <div class="tablesmallText"><?php echo $listing['seller_role']; ?></div>
                                </div>
                                <span class="seller-trust <?php echo $trust_class; ?>">
                                    Trust: <?php echo $listing['seller_trust_score']; ?>
                                </span>
                            </div>
                            
                            <?php if ($price_violation && $user_role === 'Retailer'): ?>
                                <div class="price-cap-warning">
                                    ⚠️ Price exceeds retail cap (৳<?php echo number_format($listing['retail_price_cap'], 2); ?>)
                                </div>
                            <?php endif; ?>
                            
                            <div style="margin: 15px 0;">
                                <div class="tableBoldText" style="font-size: 24px; color: #214332;">
                                    ৳<?php echo number_format($listing['unit_price'], 2); ?>
                                    <span style="font-size: 14px; color: #666;">per <?php echo $listing['unit_type']; ?></span>
                                </div>
                                <?php if ($listing['retail_price_cap']): ?>
                                    <div class="tablesmallText" style="color: #28a745;">
                                        Retail cap: ৳<?php echo number_format($listing['retail_price_cap'], 2); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="listing-stats">
                                <div class="stat-item">
                                    📅 Production: <?php echo date('M d, Y', strtotime($listing['production_date'])); ?>
                                </div>
                                <?php if ($listing['expiry_date']): ?>
                                    <div class="stat-item <?php echo $days_to_expiry < 7 ? 'expiry-warning' : ''; ?>">
                                        ⏳ Expires: <?php echo date('M d, Y', strtotime($listing['expiry_date'])); ?>
                                        <?php if ($days_to_expiry < 7): ?>
                                            (<?php echo floor($days_to_expiry); ?> days)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($listing['pending_offers'] > 0 && $listing['listing_type'] === 'negotiable'): ?>
                                <div style="color: #4dabf7; font-size: 12px; margin-top: 5px;">
                                    📨 <?php echo $listing['pending_offers']; ?> pending offer(s)
                                </div>
                            <?php endif; ?>
                            
                            <div style="margin-top: 15px; display: flex; gap: 10px;">
                                <button type="button" class="smallBtn Green" 
                                        onclick="openPurchaseModal(<?php echo $listing['listing_id']; ?>, '<?php echo $listing['listing_type']; ?>', <?php echo $listing['remaining_quantity']; ?>, '<?php echo $listing['unit_type']; ?>', <?php echo $listing['unit_price']; ?>, '<?php echo htmlspecialchars(addslashes($listing['commodity_name'])); ?>')">
                                    Purchase
                                </button>
                                <button type="button" class="smallBtn Cyan" 
                                        onclick="openDetailsModal(<?php echo htmlspecialchars(json_encode($listing)); ?>)">
                                    Details
                                </button>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <p>No listings found matching your criteria.</p>
                        <p>Try adjusting your filters or check back later.</p>
                    </div>
                <?php endif; 
                $listings_stmt->close();
                ?>
            </div>
        </div>
        <div class="footer">
            <p>Syndicate Buster Admin Panel © 2026</p>
        </div>
    </div>
    
    <div id="purchaseModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('purchaseModal')">&times;</span>
            <h3 id="modalTitle" style="color: #214332; margin-top: 0;">Purchase Product</h3>
            <form id="purchaseForm" method="POST" action="">
                <input type="hidden" name="action" value="purchase">
                <input type="hidden" id="modalListingId" name="listing_id" value="">
                
                <div class="form-group">
                    <label for="modalCommodity">Commodity</label>
                    <input type="text" id="modalCommodity" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="modalMaxQuantity">Available Quantity</label>
                    <input type="text" id="modalMaxQuantity" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="purchase_quantity">Purchase Quantity *</label>
                    <input type="number" id="purchase_quantity" name="purchase_quantity" 
                           min="0.01" step="0.01" required 
                           class="form-control" placeholder="Enter quantity"
                           oninput="calculatePurchaseTotal()">
                </div>
                
                <div id="offerPriceSection" style="display: none;">
                    <div class="form-group">
                        <label for="offer_price">Your Offer Price per Unit (৳) *</label>
                        <input type="number" id="offer_price" name="offer_price" 
                               min="0.01" step="0.01" 
                               class="form-control" placeholder="Enter your offer price"
                               oninput="calculatePurchaseTotal()">
                        <small>Negotiate with the seller</small>
                    </div>
                </div>
                
                <div id="fixedPriceSection">
                    <div class="form-group">
                        <label>Listed Price per Unit</label>
                        <input type="text" id="modalUnitPrice" class="form-control" readonly>
                    </div>
                </div>
                
                <div class="calculation-box" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                    <div class="calculation-row">
                        <span>Quantity:</span>
                        <span id="calcPurchaseQuantity">0</span> <span id="calcPurchaseUnit"></span>
                    </div>
                    <div class="calculation-row">
                        <span>Price per Unit:</span>
                        <span>৳ <span id="calcPurchasePrice">0.00</span></span>
                    </div>
                    <div class="calculation-row">
                        <span>Total Amount:</span>
                        <span class="tableBoldText">৳ <span id="calcPurchaseTotal">0.00</span></span>
                    </div>
                </div>
                
                <?php if ($user_role === 'Retailer'): ?>
                    <div class="alert warning" style="margin: 15px 0;">
                        ⚠️ As a Retailer, you must comply with retail price caps. Purchases above price caps will be flagged.
                    </div>
                <?php endif; ?>
                
                <div class="form-group" style="display: flex; gap: 15px; margin-top: 20px;">
                    <button type="button" class="smallBtn Gray" style="flex: 1;" onclick="closeModal('purchaseModal')">Cancel</button>
                    <button type="submit" id="submitPurchaseBtn" class="smallBtn Green" style="flex: 2;">Confirm Purchase</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('detailsModal')">&times;</span>
            <h3 id="detailsTitle" style="color: #214332; margin-top: 0;">Listing Details</h3>
            <div id="detailsContent"></div>
        </div>
    </div>
    
    <script>
    let currentListingType = '';
    let currentUnitPrice = 0;
    let currentMaxQuantity = 0;
    let currentUnitType = '';
    
    function openPurchaseModal(listingId, listingType, maxQuantity, unitType, unitPrice, commodity) {
        currentListingType = listingType;
        currentUnitPrice = unitPrice;
        currentMaxQuantity = maxQuantity;
        currentUnitType = unitType;
        
        document.getElementById('modalListingId').value = listingId;
        document.getElementById('modalCommodity').value = commodity;
        document.getElementById('modalMaxQuantity').value = maxQuantity + ' ' + unitType;
        document.getElementById('modalUnitPrice').value = '৳' + unitPrice.toFixed(2);
        document.getElementById('calcPurchaseUnit').textContent = unitType;
        
        if (listingType === 'negotiable') {
            document.getElementById('offerPriceSection').style.display = 'block';
            document.getElementById('fixedPriceSection').style.display = 'none';
            document.getElementById('modalTitle').textContent = 'Make Offer';
            document.getElementById('submitPurchaseBtn').textContent = 'Submit Offer';
            document.getElementById('submitPurchaseBtn').className = 'smallBtn Cyan';
        } else {
            document.getElementById('offerPriceSection').style.display = 'none';
            document.getElementById('fixedPriceSection').style.display = 'block';
            document.getElementById('modalTitle').textContent = 'Purchase Product';
            document.getElementById('submitPurchaseBtn').textContent = 'Confirm Purchase';
            document.getElementById('submitPurchaseBtn').className = 'smallBtn Green';
        }
        
        document.getElementById('purchase_quantity').value = '';
        document.getElementById('offer_price').value = '';
        calculatePurchaseTotal();
        
        document.getElementById('purchase_quantity').max = maxQuantity;
        
        document.getElementById('purchaseModal').style.display = 'block';
    }
    
    function openDetailsModal(listing) {
        const content = document.getElementById('detailsContent');
        
        let html = `
            <div class="price-breakdown">
                <div class="factor-item">
                    <span class="factor-label">Commodity:</span>
                    <span class="factor-value">${listing.commodity_name}</span>
                </div>
                <div class="factor-item">
                    <span class="factor-label">Unit Type:</span>
                    <span class="factor-value">${listing.unit_type}</span>
                </div>
                <div class="factor-item">
                    <span class="factor-label">Listed Quantity:</span>
                    <span class="factor-value">${listing.listing_quantity} ${listing.unit_type}</span>
                </div>
                <div class="factor-item">
                    <span class="factor-label">Available Quantity:</span>
                    <span class="factor-value">${listing.remaining_quantity} ${listing.unit_type}</span>
                </div>
                <div class="factor-item">
                    <span class="factor-label">Price per Unit:</span>
                    <span class="factor-value">৳${parseFloat(listing.unit_price).toFixed(2)}</span>
                </div>
                <div class="factor-item">
                    <span class="factor-label">Listing Type:</span>
                    <span class="factor-value">${listing.listing_type === 'fixed_price' ? 'Fixed Price' : 'Negotiable'}</span>
                </div>
                ${listing.retail_price_cap ? `
                <div class="factor-item">
                    <span class="factor-label">Retail Price Cap:</span>
                    <span class="factor-value positive">৳${parseFloat(listing.retail_price_cap).toFixed(2)}</span>
                </div>
                ` : ''}
                <div class="factor-item">
                    <span class="factor-label">Seller:</span>
                    <span class="factor-value">${listing.seller_name} (${listing.seller_role})</span>
                </div>
                <div class="factor-item">
                    <span class="factor-label">Seller Trust Score:</span>
                    <span class="factor-value">${listing.seller_trust_score}</span>
                </div>
                <div class="factor-item">
                    <span class="factor-label">Production Date:</span>
                    <span class="factor-value">${new Date(listing.production_date).toLocaleDateString()}</span>
                </div>
                ${listing.expiry_date ? `
                <div class="factor-item">
                    <span class="factor-label">Expiry Date:</span>
                    <span class="factor-value">${new Date(listing.expiry_date).toLocaleDateString()}</span>
                </div>
                ` : ''}
                <div class="factor-item">
                    <span class="factor-label">Created:</span>
                    <span class="factor-value">${new Date(listing.created_at).toLocaleDateString()}</span>
                </div>
                ${listing.pending_offers > 0 ? `
                <div class="factor-item">
                    <span class="factor-label">Pending Offers:</span>
                    <span class="factor-value">${listing.pending_offers}</span>
                </div>
                ` : ''}
            </div>
            
            <div style="margin-top: 20px;">
                <h4 style="color: #214332;">Total Listing Value:</h4>
                <div style="font-size: 24px; color: #214332; font-weight: bold;">
                    ৳${(listing.unit_price * listing.remaining_quantity).toFixed(2)}
                </div>
            </div>
        `;
        
        content.innerHTML = html;
        document.getElementById('detailsTitle').textContent = listing.commodity_name + ' Details';
        document.getElementById('detailsModal').style.display = 'block';
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    function calculatePurchaseTotal() {
        const quantity = parseFloat(document.getElementById('purchase_quantity').value) || 0;
        let unitPrice = currentUnitPrice;
        
        if (currentListingType === 'negotiable') {
            unitPrice = parseFloat(document.getElementById('offer_price').value) || 0;
        }
        
        const total = quantity * unitPrice;
        
        document.getElementById('calcPurchaseQuantity').textContent = quantity.toFixed(2);
        document.getElementById('calcPurchasePrice').textContent = unitPrice.toFixed(2);
        document.getElementById('calcPurchaseTotal').textContent = total.toFixed(2);
    }
    
    document.getElementById('purchaseForm').addEventListener('submit', function(e) {
        const quantity = parseFloat(document.getElementById('purchase_quantity').value);
        const maxQuantity = currentMaxQuantity;
        const userRole = "<?php echo $user_role; ?>";
        const listingType = currentListingType;
        
        if (quantity > maxQuantity) {
            e.preventDefault();
            alert('Quantity exceeds available stock. Available: ' + maxQuantity + ' ' + currentUnitType);
            return false;
        }
        
        if (quantity <= 0) {
            e.preventDefault();
            alert('Quantity must be greater than 0');
            return false;
        }
        
        if (listingType === 'negotiable') {
            const offerPrice = parseFloat(document.getElementById('offer_price').value);
            if (!offerPrice || offerPrice <= 0) {
                e.preventDefault();
                alert('Please enter an offer price for negotiable listings');
                return false;
            }
        }
        
        const commodity = document.getElementById('modalCommodity').value;
        const totalAmount = document.getElementById('calcPurchaseTotal').textContent;
        
        if (listingType === 'fixed_price') {
            if (!confirm(`Confirm Purchase?\n\n${quantity} ${currentUnitType} of ${commodity}\nPrice: ৳${currentUnitPrice.toFixed(2)} per unit\nTotal: ৳${totalAmount}\n\nThis will complete the transaction immediately.`)) {
                e.preventDefault();
                return false;
            }
        } else {
            const offerPrice = parseFloat(document.getElementById('offer_price').value);
            if (!confirm(`Submit Offer?\n\n${quantity} ${currentUnitType} of ${commodity}\nYour Offer: ৳${offerPrice.toFixed(2)} per unit\nTotal: ৳${totalAmount}\n\nThis will send an offer to the seller for approval.`)) {
                e.preventDefault();
                return false;
            }
        }
        
        return true;
    });
    
    window.onclick = function(event) {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        });
    }
    
    document.addEventListener('DOMContentLoaded', function() {
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