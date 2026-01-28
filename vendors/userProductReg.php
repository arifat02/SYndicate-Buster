<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$message = $error = $success = '';
$modal_message = $modal_type = '';

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

// 1. PROCESS ADD BATCH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_batch') {
    $commodity_id = $_POST['commodity'] ?? 0;
    $quantity = floatval($_POST['quantity'] ?? 0);
    $production_date = $_POST['production_date'] ?? date('Y-m-d');
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $parent_batch_id = !empty($_POST['parent_batch_id']) ? $_POST['parent_batch_id'] : null;
    $notes = $_POST['notes'] ?? '';
    
    if ($commodity_id > 0 && $quantity > 0) {
        // Get commodity unit type
        $unit_sql = "SELECT unit_type FROM commodities WHERE commodity_id = ?";
        $unit_stmt = $conn->prepare($unit_sql);
        $unit_stmt->bind_param("i", $commodity_id);
        $unit_stmt->execute();
        $unit_result = $unit_stmt->get_result();
        
        if ($unit_result->num_rows > 0) {
            $unit_type = $unit_result->fetch_assoc()['unit_type'];
            
            // Insert batch
            if ($parent_batch_id) {
                $sql = "INSERT INTO batches (commodity_id, owner_id, initial_quantity, current_quantity, 
                                            production_date, expiry_date, parent_batch_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiddssi", $commodity_id, $user_id, $quantity, $quantity, 
                                 $production_date, $expiry_date, $parent_batch_id);
            } else {
                $sql = "INSERT INTO batches (commodity_id, owner_id, initial_quantity, current_quantity, 
                                            production_date, expiry_date) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiddss", $commodity_id, $user_id, $quantity, $quantity, 
                                 $production_date, $expiry_date);
            }
            
            if ($stmt->execute()) {
                $success = "✅ Batch added successfully!<br>Quantity: $quantity $unit_type";
            } else {
                $error = "❌ Error adding batch: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error = "❌ Commodity not found";
        }
        $unit_stmt->close();
    } else {
        $error = "❌ Please select a commodity and enter valid quantity";
    }
}

// 2. PROCESS ADD COMMODITY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_commodity') {
    $commodity_name = trim($_POST['name'] ?? '');
    $unit_type = $_POST['unit_type'] ?? '';
    $category_id = $_POST['category'] ?? 0;
    $perishable = isset($_POST['perishable']) ? 1 : 0;
    $shelf_life_days = $_POST['shelf_life_days'] ?? ($perishable ? 30 : NULL);
    $description = $_POST['description'] ?? '';
    
    if (!empty($commodity_name) && !empty($unit_type) && $category_id > 0) {
        // Check if commodity exists
        $check_sql = "SELECT commodity_id FROM commodities WHERE LOWER(commodity_name) = LOWER(?)";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $commodity_name);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $modal_message = "⚠️ Commodity <strong>'$commodity_name'</strong> already exists!<br><br>Please choose a different name.";
            $modal_type = 'warning';
        } else {
            // Insert commodity
            $sql = "INSERT INTO commodities (category_id, commodity_name, unit_type, 
                                            perishable, shelf_life_days, description) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("issiis", $category_id, $commodity_name, $unit_type, 
                             $perishable, $shelf_life_days, $description);
            
            if ($stmt->execute()) {
                $success = "✅ Commodity <strong>'$commodity_name'</strong> added successfully!<br>Unit Type: $unit_type";
            } else {
                $error = "❌ Error adding commodity: " . $conn->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    } else {
        $error = "❌ Please fill all required fields";
    }
}

// 3. PROCESS UPDATE BATCH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_batch') {
    $batch_id = $_POST['batch_id'] ?? 0;
    $new_quantity = floatval($_POST['quantity'] ?? 0);
    $reason = $_POST['reason'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if ($batch_id > 0 && $new_quantity >= 0) {
        // Verify ownership and get unit type
        $check_sql = "SELECT b.initial_quantity, c.unit_type 
                      FROM batches b 
                      JOIN commodities c ON b.commodity_id = c.commodity_id 
                      WHERE b.batch_id = ? AND b.owner_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $batch_id, $user_id);
        $check_stmt->execute();
        $batch = $check_stmt->get_result()->fetch_assoc();
        
        if ($batch) {
            // Validate quantity
            if ($new_quantity > $batch['initial_quantity']) {
                $error = "❌ New quantity cannot exceed initial quantity (" . $batch['initial_quantity'] . " " . $batch['unit_type'] . ")";
            } else {
                // Update batch
                $update_sql = "UPDATE batches SET current_quantity = ? WHERE batch_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("di", $new_quantity, $batch_id);
                
                if ($update_stmt->execute()) {
                    $success = "✅ Batch updated successfully!<br>New Quantity: $new_quantity " . $batch['unit_type'];
                    // Update status if quantity is 0
                    if ($new_quantity == 0) {
                        $status_sql = "UPDATE batches SET batch_status = 'Sold' WHERE batch_id = ?";
                        $status_stmt = $conn->prepare($status_sql);
                        $status_stmt->bind_param("i", $batch_id);
                        $status_stmt->execute();
                        $status_stmt->close();
                    }
                } else {
                    $error = "❌ Error updating batch: " . $conn->error;
                }
                $update_stmt->close();
            }
        } else {
            $error = "❌ Batch not found or access denied";
        }
        $check_stmt->close();
    } else {
        $error = "❌ Invalid batch ID or quantity";
    }
}

// 4. PROCESS DELETE BATCH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_batch') {
    $batch_id = $_POST['batch_id'] ?? 0;
    
    if ($batch_id > 0) {
        // Verify ownership
        $check_sql = "SELECT b.batch_id, b.current_quantity, c.commodity_name 
                      FROM batches b 
                      JOIN commodities c ON b.commodity_id = c.commodity_id 
                      WHERE b.batch_id = ? AND b.owner_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $batch_id, $user_id);
        $check_stmt->execute();
        $batch = $check_stmt->get_result()->fetch_assoc();
        
        if ($batch) {
            // Check if batch has transactions
            $tx_sql = "SELECT COUNT(*) as tx_count FROM transactions WHERE batch_id = ?";
            $tx_stmt = $conn->prepare($tx_sql);
            $tx_stmt->bind_param("i", $batch_id);
            $tx_stmt->execute();
            $tx_count = $tx_stmt->get_result()->fetch_assoc()['tx_count'];
            
            if ($tx_count > 0) {
                $error = "❌ Cannot delete batch with transaction history";
            } elseif ($batch['current_quantity'] > 0) {
                $error = "❌ Cannot delete batch with remaining quantity";
            } else {
                // Delete batch
                $delete_sql = "DELETE FROM batches WHERE batch_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                $delete_stmt->bind_param("i", $batch_id);
                
                if ($delete_stmt->execute()) {
                    $success = "✅ Batch <strong>#" . $batch_id . "</strong> for <strong>" . $batch['commodity_name'] . "</strong> deleted successfully!";
                } else {
                    $error = "❌ Error deleting batch: " . $conn->error;
                }
                $delete_stmt->close();
            }
            $tx_stmt->close();
        } else {
            $error = "❌ Batch not found or access denied";
        }
        $check_stmt->close();
    }
}

// ==================== FETCH DATA FOR DISPLAY ====================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$commodity_filter = isset($_GET['commodity']) ? $_GET['commodity'] : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'production_date';

$batches_sql = "
    SELECT b.batch_id, b.parent_batch_id, b.current_quantity, b.initial_quantity, 
           b.production_date, b.expiry_date, b.batch_status,
           c.commodity_name, c.unit_type
    FROM batches b
    INNER JOIN commodities c ON b.commodity_id = c.commodity_id
    WHERE b.owner_id = ?
";

if (!empty($search)) {
    $batches_sql .= " AND (c.commodity_name LIKE ? OR b.batch_id LIKE ?)";
}

if (!empty($commodity_filter)) {
    $batches_sql .= " AND c.commodity_id = ?";
}

switch ($sort_by) {
    case 'quantity':
        $batches_sql .= " ORDER BY b.current_quantity DESC";
        break;
    case 'name':
        $batches_sql .= " ORDER BY c.commodity_name ASC";
        break;
    case 'production_date':
    default:
        $batches_sql .= " ORDER BY b.production_date DESC";
        break;
}

$batches_stmt = $conn->prepare($batches_sql);

if (!empty($search)) {
    $search_param = "%$search%";
    if (!empty($commodity_filter)) {
        $batches_stmt->bind_param("issi", $user_id, $search_param, $search_param, $commodity_filter);
    } else {
        $batches_stmt->bind_param("iss", $user_id, $search_param, $search_param);
    }
} else {
    if (!empty($commodity_filter)) {
        $batches_stmt->bind_param("ii", $user_id, $commodity_filter);
    } else {
        $batches_stmt->bind_param("i", $user_id);
    }
}

$batches_stmt->execute();
$batches_result = $batches_stmt->get_result();
$batches = $batches_result->fetch_all(MYSQLI_ASSOC);
$total_batches = count($batches);

// Get commodities for filter dropdown
$commodities_sql = "SELECT commodity_id, commodity_name FROM commodities ORDER BY commodity_name";
$commodities_result = $conn->query($commodities_sql);
$commodities = $commodities_result->fetch_all(MYSQLI_ASSOC);

// Get categories for commodity form
$categories_sql = "SELECT category_id, category_name FROM commodity_categories ORDER BY category_name";
$categories_result = $conn->query($categories_sql);
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Registration - Syndicate Buster</title>
    <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .unit-display {
            display: inline-block;
            background: #e8f5e9;
            color: #155724;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 5px;
        }
        
        .unit-kg { background: #d4edda; color: #155724; }
        .unit-gram { background: #fff3cd; color: #856404; }
        .unit-liter { background: #cce5ff; color: #004085; }
        .unit-piece { background: #f8d7da; color: #721c24; }
        .unit-packet { background: #e2e3e5; color: #383d41; }
        
        .status-available {
            background: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        
        .status-sold {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        
        .status-expired {
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .message-modal .modal-content {
            max-width: 400px;
        }
        
        .message-icon {
            font-size: 48px;
            text-align: center;
            margin-bottom: 15px;
        }
        
        .success-icon { color: #28a745; }
        .warning-icon { color: #ffc107; }
        .error-icon { color: #dc3545; }
        .info-icon { color: #17a2b8; }
        
        .message-content {
            text-align: center;
            margin-bottom: 20px;
        }
        
        /* Confirmation Modal */
        .confirmation-modal .modal-content {
            max-width: 450px;
        }
        
        .confirmation-icon {
            font-size: 40px;
            text-align: center;
            margin-bottom: 15px;
            color: #17a2b8;
        }
        
        .confirmation-content {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .confirmation-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            text-align: left;
        }
        
        .confirmation-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .confirmation-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        /* Alert Messages */
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin: 20px;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin: 20px;
            border-left: 4px solid #dc3545;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 4px;
            margin: 20px;
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
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
            
            <div class="userDetailsCard">
                <h1>Product Registration</h1>
                <div>
                    <a href="../logout.php" class="smallBtn Red">Logout</a>
                </div>
            </div>
          
            <div class="navCard">
                <a href="../vendors/vendorDashboard.php">Dashboard</a>
                <a href="../vendors/userProductReg.php" style="background: rgba(255,255,255,0.1);">Product Registration</a>
                <a href="../vendors/userCreateListing.php">Create Listing</a>
                <a href="../vendors/userMarketplace.php">Marketplace</a>
                <a href="../vendors/userTransaction.php">Transactions logs</a>
                <a href="../vendors/userViolation.php">Policy & Violation</a>
            </div>
           
            <div class="gridCard">
                <div style="display: flex; gap: 15px; margin: 20px 0;">
                    <button class="greenBtn" onclick="showAddBatchConfirmation()">Add New Batch</button>
                    <button class="greenBtn secondary" onclick="showAddCommodityConfirmation()">Add New Commodity</button>
                </div>
            </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 15px;">Search & Filter</h2>
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="search-box">
                            <input type="text" name="search" placeholder="Search by commodity or batch code..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="smallBtn Green">Search</button>
                        </div>
                        
                        <div>
                            <select name="commodity" class="filterText">
                                <option value="">All Commodities</option>
                                <?php foreach($commodities as $commodity): ?>
                                    <option value="<?php echo $commodity['commodity_id']; ?>" 
                                        <?php echo $commodity_filter == $commodity['commodity_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($commodity['commodity_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <select name="sort" class="filterText">
                                <option value="production_date" <?php echo $sort_by == 'production_date' ? 'selected' : ''; ?>>Latest Harvest First</option>
                                <option value="quantity" <?php echo $sort_by == 'quantity' ? 'selected' : ''; ?>>Quantity: High to Low</option>
                                <option value="name" <?php echo $sort_by == 'name' ? 'selected' : ''; ?>>Commodity Name A-Z</option>
                            </select>
                        </div>
                        
                        <div>
                            <button type="submit" class="smallBtn Green" style="width: 100%;">Apply</button>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="gridCard">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 class="GreenTextLarge">📦 My Batches</h1>
                    <div class="tablesmallText">
                        Showing <?php echo $total_batches; ?> batch(es)
                    </div>
                </div>
                
                <?php if($total_batches > 0): ?>
                    <?php foreach($batches as $batch): 
                        if ($batch['current_quantity'] == 0) {
                            $status_class = 'status-sold';
                            $status_text = 'Sold';
                        } elseif ($batch['batch_status'] == 'Expired') {
                            $status_class = 'status-expired';
                            $status_text = 'Expired';
                        } else {
                            $status_class = 'status-available';
                            $status_text = 'Available';
                        }
                        
                        $unit_class = 'unit-' . strtolower($batch['unit_type']);
                    ?>
                    <div class="batchCard">
                        <div class="batchCardItem">
                            <div>
                                <div class="tableBoldText">
                                    <?php echo htmlspecialchars($batch['commodity_name']); ?>
                                    <span class="unit-display <?php echo $unit_class; ?>">
                                        <?php echo $batch['unit_type']; ?>
                                    </span>
                                </div>
                                <div class="tablesmallText">
                                    Batch ID: <?php echo htmlspecialchars($batch['batch_id']); ?> | 
                                    <?php if($batch['parent_batch_id']): ?>
                                        Parent Batch: <?php echo $batch['parent_batch_id']; ?> |
                                    <?php endif; ?>
                                    <?php if($batch['expiry_date']): ?>
                                        Expires: <?php echo date('M d, Y', strtotime($batch['expiry_date'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 10px 0;">
                            <div>
                                <div class="tablesmallText">Total Quantity</div>
                                <div class="tableBoldText">
                                    <?php echo number_format($batch['initial_quantity'], 2); ?> 
                                    <span class="unit-display <?php echo $unit_class; ?>">
                                        <?php echo $batch['unit_type']; ?>
                                    </span>
                                </div>
                            </div>
                            <div>
                                <div class="tablesmallText">Harvest Date</div>
                                <div class="tableBoldText"><?php echo date('M d, Y', strtotime($batch['production_date'])); ?></div>
                            </div>
                            <div>
                                <div class="tablesmallText">Remaining</div>
                                <div class="tableBoldText" style="color: <?php echo $batch['current_quantity'] > 0 ? '#214332' : '#dc3545'; ?>;">
                                    <?php echo number_format($batch['current_quantity'], 2); ?> 
                                    <span class="unit-display <?php echo $unit_class; ?>">
                                        <?php echo $batch['unit_type']; ?>
                                    </span>
                                </div>
                            </div>
                            <div>
                                <div class="tablesmallText">Status</div>
                                <div><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></div>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <?php if($batch['current_quantity'] > 0 && $batch['batch_status'] != 'Expired'): ?>
                            <button class="cardBtn Cyan" onclick="showEditBatchConfirmation(
                                '<?php echo $batch['batch_id']; ?>',
                                '<?php echo $batch['current_quantity']; ?>',
                                '<?php echo $batch['initial_quantity']; ?>',
                                '<?php echo htmlspecialchars($batch['commodity_name']); ?>',
                                '<?php echo htmlspecialchars($batch['unit_type']); ?>'
                            )">Edit</button>
                            <?php endif; ?>
                            
                            <?php if($batch['current_quantity'] == 0): ?>
                            <button class="cardBtn DarkRed" onclick="showDeleteBatchConfirmation(
                                '<?php echo $batch['batch_id']; ?>',
                                '<?php echo htmlspecialchars($batch['commodity_name']); ?>'
                            )">Remove</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No batches found. <a href="#" onclick="showAddBatchConfirmation()" style="color: #007bff;">Add your first batch</a>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © 2024</p>
            </div>
        </div>
    </div>
    
    <div id="confirmationModal" class="modal confirmation-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="confirmationTitle">Confirm Action</h2>
                <button class="close-btn" onclick="closeModal('confirmationModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">❓</div>
                <div class="confirmation-content">
                    <p id="confirmationMessage">Are you sure you want to proceed?</p>
                    <div id="confirmationDetails" class="confirmation-details"></div>
                </div>
                <form id="confirmationForm" method="POST" action="">
                    <input type="hidden" id="confirmationAction" name="action">
                    <div id="confirmationFormFields"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="smallBtn Gray" onclick="closeModal('confirmationModal')">Cancel</button>
                <button type="button" class="smallBtn Green" onclick="submitConfirmationForm()">Confirm</button>
            </div>
        </div>
    </div>
    
    <div id="messageModal" class="modal message-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="messageTitle">Message</h2>
                <button class="close-btn" onclick="closeModal('messageModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="message-icon" id="messageIcon">💬</div>
                <div class="message-content">
                    <p id="messageContent">Your message here.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="smallBtn Green" onclick="closeModal('messageModal')">OK</button>
            </div>
        </div>
    </div>
    
    <div id="addBatchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Batch</h2>
                <button class="close-btn" onclick="closeModal('addBatchModal')">&times;</button>
            </div>
            <form method="POST" action="" id="addBatchForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_batch">
                    
                    <div class="form-group">
                        <label class="form-label">Commodity *</label>
                        <select name="commodity" class="form-control" required onchange="updateUnitDisplay(this)">
                            <option value="">Select Commodity</option>
                            <?php foreach($commodities as $commodity): ?>
                                <option value="<?php echo $commodity['commodity_id']; ?>" data-unit="">
                                    <?php echo htmlspecialchars($commodity['commodity_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small id="selectedUnit" class="tablesmallText" style="display: none;">
                            Unit: <span id="unitValue"></span>
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Quantity *</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="number" name="quantity" class="form-control" 
                                   placeholder="Enter quantity" required min="0.01" step="0.01" 
                                   style="flex: 1;">
                            <span id="quantityUnit" class="unit-display" style="display: none;">kg</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Production/Harvest Date *</label>
                        <input type="date" name="production_date" class="form-control" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Expiry Date (Optional)</label>
                        <input type="date" name="expiry_date" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Parent Batch ID (Optional)</label>
                        <input type="number" name="parent_batch_id" class="form-control" 
                               placeholder="Enter parent batch ID if splitting">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" 
                                  placeholder="Add any notes about this batch..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="smallBtn Gray" onclick="closeModal('addBatchModal')">Cancel</button>
                    <button type="button" class="smallBtn Green" onclick="validateBatchForm()">Add Batch</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="addCommodityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Commodity</h2>
                <button class="close-btn" onclick="closeModal('addCommodityModal')">&times;</button>
            </div>
            <form method="POST" action="" id="addCommodityForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_commodity">
                    
                    <div class="form-group">
                        <label class="form-label">Commodity Name *</label>
                        <input type="text" name="name" class="form-control" 
                               placeholder="e.g., Tomato, Rice, Potato" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Unit Type *</label>
                        <select name="unit_type" class="form-control" required id="commodityUnitSelect">
                            <option value="">Select Unit</option>
                            <option value="kg">Kilogram (kg)</option>
                            <option value="gram">Gram (g)</option>
                            <option value="liter">Liter (L)</option>
                            <option value="piece">Piece</option>
                            <option value="packet">Packet</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach($categories as $category): ?>
                                <option value="<?php echo $category['category_id']; ?>">
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Is Perishable?</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="perishable" name="perishable" value="1" checked 
                                   onchange="toggleShelfLife()">
                            <label for="perishable">Yes (has expiry date)</label>
                        </div>
                    </div>
                    
                    <div class="form-group" id="shelfLifeGroup">
                        <label class="form-label">Shelf Life (Days)</label>
                        <input type="number" name="shelf_life_days" class="form-control" value="30" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description (Optional)</label>
                        <textarea name="description" class="form-control" rows="3" 
                                  placeholder="Brief description..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="smallBtn Gray" onclick="closeModal('addCommodityModal')">Cancel</button>
                    <button type="button" class="smallBtn Green" onclick="validateCommodityForm()">Add Commodity</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    // 1. Commodity Data Setup
    const unitDisplayMap = {
        'kg': { class: 'unit-kg', label: 'Kilogram (kg)' },
        'gram': { class: 'unit-gram', label: 'Gram (g)' },
        'liter': { class: 'unit-liter', label: 'Liter (L)' },
        'piece': { class: 'unit-piece', label: 'Piece' },
        'packet': { class: 'unit-packet', label: 'Packet' }
    };
    
    // PHP to JS Data Transfer (Safe Method)
    const commodityUnits = {
        <?php 
        $unit_sql = "SELECT commodity_id, unit_type FROM commodities";
        $unit_result = $conn->query($unit_sql);
        $unit_data = [];
        if ($unit_result) {
            while($row = $unit_result->fetch_assoc()) {
                // Ensure ID and Type are safe for JS
                $id = $row['commodity_id'];
                $type = htmlspecialchars($row['unit_type']); 
                $unit_data[] = "'$id': '$type'";
            }
        }
        echo implode(', ', $unit_data);
        ?>
    };
    
    // 2. Modal Logic (Consolidated & Fixed Z-Index)
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.zIndex = '100000'; // Very high to ensure it's on top
            modal.style.display = 'flex';
        } else {
            console.error("Modal not found: " + modalId);
        }
    }
    
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Close modal when clicking outside (Backdrop click)
    document.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            closeModal(event.target.id);
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const openModals = document.querySelectorAll('.modal[style*="display: flex"]');
            openModals.forEach(modal => {
                closeModal(modal.id);
            });
        }
    });

    // 3. Message & Confirmation Logic
    function showMessage(title, message, type = 'info') {
        const modal = document.getElementById('messageModal');
        const icon = modal.querySelector('#messageIcon');
        const titleEl = modal.querySelector('#messageTitle');
        const content = modal.querySelector('#messageContent');
        
        const icons = {
            'success': '✅',
            'error': '❌',
            'warning': '⚠️',
            'info': '💬'
        };
        
        icon.textContent = icons[type] || '💬';
        icon.className = 'message-icon ' + type + '-icon';
        titleEl.textContent = title;
        content.innerHTML = message;
        
        openModal('messageModal');
    }

    function showConfirmation(title, message, details, action, formFields = '') {
        const modal = document.getElementById('confirmationModal');
        modal.querySelector('#confirmationTitle').textContent = title;
        modal.querySelector('#confirmationMessage').innerHTML = message;
        modal.querySelector('#confirmationDetails').innerHTML = details;
        modal.querySelector('#confirmationAction').value = action;
        modal.querySelector('#confirmationFormFields').innerHTML = formFields;
        
        openModal('confirmationModal');
    }

    function submitConfirmationForm() {
        document.getElementById('confirmationForm').submit();
    }

    // 4. Feature Logic (Add/Edit)
    function showAddBatchConfirmation() {
        openModal('addBatchModal');
    }
    
    function showAddCommodityConfirmation() {
        openModal('addCommodityModal');
    }

    function updateUnitDisplay(select) {
        const commodityId = select.value;
        const unitSpan = document.getElementById('unitValue');
        const quantityUnit = document.getElementById('quantityUnit');
        const unitInfo = document.getElementById('selectedUnit');
        
        if (commodityId && commodityUnits[commodityId]) {
            const unitType = commodityUnits[commodityId];
            const unitInfoObj = unitDisplayMap[unitType] || { class: '', label: unitType };
            
            unitSpan.textContent = unitInfoObj.label;
            unitSpan.className = 'unit-display ' + unitInfoObj.class;
            quantityUnit.textContent = unitType;
            quantityUnit.className = 'unit-display ' + unitInfoObj.class;
            
            unitInfo.style.display = 'block';
            quantityUnit.style.display = 'inline-block';
        } else {
            unitInfo.style.display = 'none';
            quantityUnit.style.display = 'none';
        }
    }

    function toggleShelfLife() {
        const perishable = document.getElementById('perishable');
        const shelfLifeGroup = document.getElementById('shelfLifeGroup');
        if(perishable && shelfLifeGroup) {
            shelfLifeGroup.style.display = perishable.checked ? 'block' : 'none';
        }
    }

    // Form Validation Functions
    function validateBatchForm() {
        const form = document.getElementById('addBatchForm');
        if (form.checkValidity()) {
            form.submit(); // Submit directly if simple add
        } else {
            form.reportValidity();
        }
    }

    function validateCommodityForm() {
        const form = document.getElementById('addCommodityForm');
        if (form.checkValidity()) {
            form.submit();
        } else {
            form.reportValidity();
        }
    }

    // Edit Batch Confirmation
    function showEditBatchConfirmation(batchId, currentQty, initialQty, commodityName, unitType) {
        const unitInfo = unitDisplayMap[unitType] || { class: '', label: unitType };
        
        const details = `
            <div class="confirmation-item">
                <strong>Commodity:</strong>
                <span>${commodityName}</span>
            </div>
            <div class="confirmation-item">
                <strong>Current:</strong>
                <span>${currentQty} ${unitType}</span>
            </div>
            <div class="confirmation-item">
                <label class="form-label">New Quantity</label>
                <input type="number" name="quantity" class="form-control" 
                       value="${currentQty}" min="0" max="${initialQty}" step="0.01" required>
            </div>
            <div class="confirmation-item">
                <label class="form-label">Reason</label>
                <select name="reason" class="form-control">
                    <option value="correction">Correction</option>
                    <option value="spoilage">Spoilage</option>
                    <option value="other">Other</option>
                </select>
            </div>
        `;
        
        showConfirmation(
            'Edit Batch #' + batchId,
            'Update quantity for this batch',
            details,
            'update_batch',
            '<input type="hidden" name="batch_id" value="' + batchId + '">'
        );
    }

    function showDeleteBatchConfirmation(batchId, commodityName) {
        const details = `
            <div class="confirmation-item">
                <strong>Batch:</strong> #${batchId} (${commodityName})
            </div>
            <div class="confirmation-item" style="color: #dc3545;">
                This action cannot be undone!
            </div>
        `;
        
        showConfirmation(
            'Delete Batch',
            'Permanently delete this batch?',
            details,
            'delete_batch',
            '<input type="hidden" name="batch_id" value="' + batchId + '">'
        );
    }

    // 5. Page Initialization
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-success, .alert-error, .alert-warning');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => { if (alert.parentNode) alert.parentNode.removeChild(alert); }, 500);
            });
        }, 5000);
        
        // Initial setup
        toggleShelfLife();

        // Show server-side messages if any
        <?php if($modal_message && $modal_type): ?>
            showMessage(
                '<?php echo $modal_type === 'warning' ? 'Warning' : 'Notice'; ?>', 
                '<?php echo addslashes($modal_message); ?>', 
                '<?php echo $modal_type; ?>'
            );
        <?php endif; ?>
    });
    </script>
</body>
</html>