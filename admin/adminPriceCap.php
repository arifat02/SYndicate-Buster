<?php
session_start();    
require_once "../config.php";
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$message = "";
$error = "";
// ... [Previous code remains the same until POST handling] ...

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commodity_id = $_POST['commodity_id'];
    $max_price = $_POST['max_price'];
    $effective_date = $_POST['effective_date'];
    $region = $_POST['region'] ?? 'National';
    $expiry_date = $_POST['expiry_date'] ?? null;
    
    // Check if price cap already exists for this commodity in the same region
    $check_sql = "SELECT * FROM price_caps 
                  WHERE commodity_id = ? AND region = ? AND effective_date = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("iss", $commodity_id, $region, $effective_date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "Price cap already exists for this commodity in this region on selected date.";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Calculate expiry date for old caps
            $today = date('Y-m-d');
            
            if ($effective_date <= $today) {
                // If effective date is today or in the past, expire old caps immediately
                $old_expiry_date = date('Y-m-d', strtotime('-1 day'));
            } else {
                // If effective date is in the future, expire old caps one day before new cap starts
                $old_expiry_date = date('Y-m-d', strtotime($effective_date . ' -1 day'));
            }
            
            // 1. Expire any active price caps for the same commodity and region
            $expire_sql = "UPDATE price_caps 
                           SET expiry_date = ? 
                           WHERE commodity_id = ? 
                           AND region = ? 
                           AND (expiry_date IS NULL OR expiry_date >= CURDATE())";
            $expire_stmt = $conn->prepare($expire_sql);
            
            $expire_stmt->bind_param("sis", $old_expiry_date, $commodity_id, $region);
            $expire_stmt->execute();
            $expired_count = $expire_stmt->affected_rows;
            $expire_stmt->close();
            
            // 2. Insert new price cap
            if ($expiry_date) {
                $sql = "INSERT INTO price_caps (commodity_id, max_price_per_unit, effective_date, expiry_date, region) 
                        VALUES (?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("idsss", $commodity_id, $max_price, $effective_date, $expiry_date, $region);
            } else {
                $sql = "INSERT INTO price_caps (commodity_id, max_price_per_unit, effective_date, region) 
                        VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("idss", $commodity_id, $max_price, $effective_date, $region);
            }
            
            if ($stmt->execute()) {
                $conn->commit();
                $message = "Price cap set successfully!";
                if ($expired_count > 0) {
                    $message .= " " . $expired_count . " previous price cap(s) have been expired.";
                }
            } else {
                throw new Exception("Failed to set price cap: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// ... [Rest of the code remains the same] ...
if (isset($_GET['delete'])) {
    $cap_id = intval($_GET['delete']);
    $conn->query("DELETE FROM price_caps WHERE price_cap_id = $cap_id");
    $message = "Price cap deleted successfully!";
}

// Get filter parameters
$filter_commodity = isset($_GET['commodity']) ? intval($_GET['commodity']) : 0;
$filter_region = isset($_GET['region']) ? $_GET['region'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'effective_date';
$sort_order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$param_types = '';

if ($filter_commodity > 0) {
    $where_conditions[] = "pc.commodity_id = ?";
    $params[] = $filter_commodity;
    $param_types .= 'i';
}

if (!empty($filter_region)) {
    $where_conditions[] = "pc.region = ?";
    $params[] = $filter_region;
    $param_types .= 's';
}
if (!empty($search_term)) {
    $where_conditions[] = "(c.commodity_name LIKE ? OR pc.region LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $param_types .= 'ss';
}

// Status filter
$today = date('Y-m-d');
if ($filter_status !== 'all') {
    switch($filter_status) {
        case 'active':
            $where_conditions[] = "(pc.expiry_date >= ? OR pc.expiry_date IS NULL) AND pc.effective_date <= ?";
            $params[] = $today;
            $params[] = $today;
            $param_types .= 'ss';
            break;
        case 'expired':
            $where_conditions[] = "pc.expiry_date < ?";
            $params[] = $today;
            $param_types .= 's';
            break;
        case 'upcoming':
            $where_conditions[] = "pc.effective_date > ?";
            $params[] = $today;
            $param_types .= 's';
            break;
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total counts for stats
$stats_sql = "SELECT 
                SUM(CASE WHEN (expiry_date >= CURDATE() OR expiry_date IS NULL) AND effective_date <= CURDATE() THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN effective_date > CURDATE() THEN 1 ELSE 0 END) as upcoming,
                COUNT(*) as total
              FROM price_caps";

$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();


$commodities = $conn->query("SELECT * FROM commodities WHERE status = 'Active' ORDER BY commodity_name");

$regions_result = $conn->query("SELECT DISTINCT region FROM price_caps ORDER BY region");
$regions = [];
while($row = $regions_result->fetch_assoc()) {
    $regions[] = $row['region'];
}

$query = "
    SELECT pc.*, c.commodity_name, c.unit_type,
           CASE 
               WHEN pc.effective_date > CURDATE() THEN 'Upcoming'
               WHEN pc.expiry_date < CURDATE() THEN 'Expired'
               ELSE 'Active'
           END as status_display,
           (SELECT COUNT(*) FROM price_cap_violations pcv WHERE pcv.price_cap_id = pc.price_cap_id) as violation_count
    FROM price_caps pc
    JOIN commodities c ON pc.commodity_id = c.commodity_id
    $where_clause
    ORDER BY $sort_by $sort_order";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $price_caps = $stmt->get_result();
} else {
    $price_caps = $conn->query($query);
}

function buildSortLink($column) {
    global $filter_commodity, $filter_region, $filter_status, $search_term, $sort_by, $sort_order;
    
    $params = [];
    if ($filter_commodity) $params[] = "commodity=$filter_commodity";
    if ($filter_region) $params[] = "region=" . urlencode($filter_region);
    if ($filter_status != 'all') $params[] = "status=$filter_status";
    if ($search_term) $params[] = "search=" . urlencode($search_term);
    
    $new_sort_order = ($sort_by == $column && $sort_order == 'DESC') ? 'ASC' : 'DESC';
    $params[] = "sort=$column";
    $params[] = "order=$new_sort_order";
    
    return implode('&', $params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Caps Management - Admin</title>
    <link rel="stylesheet" href="../css/page.css">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/form.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    
        .violation-badge {
            background-color: #dc3545;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 5px;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
     
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .quick-action-btn {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px 15px;
            text-align: center;
            text-decoration: none;
            color: #214332;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .quick-action-btn:hover {
            background: #f8f9fa;
            border-color: #214332;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
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
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .close-modal {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #666;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .quick-actions-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); ?>
            <?php unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); ?>
            <?php unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        
        <div class="dashboard">
            <div class="userDetailsCard">
                <div>
                    <h2>Price Caps Management</h2>
                   
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end;">
                                            <a href="../logout.php" class="greenBtn Red">Logout</a>

                </div>
            </div>
            
            <div class="navCard">
                <a href="adminDashboard.php"> Dashboard</a>
                <a href="adminManageUsers.php">Manage Users</a>
                <a href="adminPriceCap.php" style="background: rgba(255,255,255,0.1);">
                    Price Caps
                </a>
                <a href="adminViolation.php">Violations</a>
            </div>
            
            <?php if($message): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 20px;">
                     Price Caps Overview
                </h2>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
                    <div class="summary-box">
                        <div class="tablesmallText"><i class="fas fa-tags"></i> Total Caps</div>
                        <div class="tableBoldText" style="font-size: 24px; color: #214332;">
                            <?php echo $stats['total'] ?? 0; ?>
                        </div>
                    </div>
                    <div class="summary-box">
                        <div class="tablesmallText"><i class="fas fa-check-circle"></i> Active</div>
                        <div class="tableBoldText" style="font-size: 24px; color: #28a745;">
                            <?php echo $stats['active'] ?? 0; ?>
                        </div>
                    </div>
                    <div class="summary-box">
                        <div class="tablesmallText"><i class="fas fa-clock"></i> Upcoming</div>
                        <div class="tableBoldText" style="font-size: 24px; color: #ffc107;">
                            <?php echo $stats['upcoming'] ?? 0; ?>
                        </div>
                    </div>
                    <div class="summary-box">
                        <div class="tablesmallText"><i class="fas fa-times-circle"></i> Expired</div>
                        <div class="tableBoldText" style="font-size: 24px; color: #dc3545;">
                            <?php echo $stats['expired'] ?? 0; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="gridCard" id="priceCapForm">
                <h2 style="color: #214332; margin-bottom: 20px;">
                    Set New Price Cap
                </h2>
                <div style="background: #e7f3ff; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <p style="margin: 0; color: #0c5460;">
                        <i class="fas fa-info-circle"></i> <strong>Note:</strong> When you set a new price cap, any existing active price caps for the same commodity and region will be automatically expired.
                    </p>
                </div>
                <form method="POST" action="" class="form-container">
                    <div class="form-group">
                        <label for="commodity_id">Commodity</label>
                        <select name="commodity_id" id="commodity_id" class="form-control" required 
                                onchange="checkExistingCaps()">
                            <option value="">-- Select Commodity --</option>
                            <?php while($commodity = $commodities->fetch_assoc()): ?>
                                <option value="<?php echo $commodity['commodity_id']; ?>">
                                    <?php echo htmlspecialchars($commodity['commodity_name']); ?> (per <?php echo $commodity['unit_type']; ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div id="existingCapsInfo" style="display: none; margin-top: 5px; padding: 8px; background: #fff3cd; border-radius: 4px;"></div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="max_price">Maximum Price (৳) </label>
                            <input type="number" name="max_price" id="max_price" class="form-control" 
                                   placeholder="Enter maximum retail price" step="0.01" min="0" required>
                            <small>Maximum retail price per unit</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="region">Region </label>
                            <select name="region" id="region" class="form-control" required 
                                    onchange="checkExistingCaps()">
                                <option value="National">National</option>
                                <?php foreach($regions as $reg): ?>
                                    <?php if($reg != 'National'): ?>
                                        <option value="<?php echo htmlspecialchars($reg); ?>"><?php echo htmlspecialchars($reg); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <option value="Dhaka">Dhaka</option>
                                <option value="Chittagong">Chittagong</option>
                                <option value="Sylhet">Sylhet</option>
                                <option value="Rajshahi">Rajshahi</option>
                                <option value="Khulna">Khulna</option>
                                <option value="Barisal">Barisal</option>
                                <option value="Rangpur">Rangpur</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="effective_date"> Effective Date </label>
                            <input type="date" name="effective_date" id="effective_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required 
                                   onchange="checkExistingCaps()">
                            <small>The date when this price cap becomes active</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="expiry_date">Expiry Date (Optional)</label>
                            <input type="date" name="expiry_date" id="expiry_date" class="form-control">
                            <small>Leave empty for no expiry. Old caps will expire automatically when new ones are set.</small>
                        </div>
                    </div>
                    
                    <div class="form-group" style="display: grid;grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
                        <button type="reset" class="smallBtn Gray" style="flex: 1;">
                            Clear Form
                        </button>
                        <button type="submit" class="smallBtn Green" style="flex: 2;">
                            Set Price Cap
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="gridCard">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="color: #214332; margin: 0;">
                        Current Price Caps
                        <span class="badge" style="background: #214332; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px;">
                            <?php echo $price_caps->num_rows; ?> records
                        </span>
                    </h2>
                    <div style="display: flex; gap: 10px;">
                        <button onclick="toggleFilters()" class="smallBtn LightGreen">
                             Filters
                        </button>
                        
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div id="filterSection" class="filter-section" style="display: none;">
                    <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div class="form-grid">
                            <input type="text" name="search" class="form-control" placeholder="Search commodity or region..." 
                                   value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Commodity</label>
                            <select name="commodity" class="form-control">
                                <option value="0">All Commodities</option>
                                <?php 
                                $commodities->data_seek(0);
                                while($commodity = $commodities->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $commodity['commodity_id']; ?>" 
                                            <?php echo $filter_commodity == $commodity['commodity_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($commodity['commodity_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Region</label>
                            <select name="region" class="form-control">
                                <option value="">All Regions</option>
                                <?php foreach($regions as $reg): ?>
                                    <option value="<?php echo htmlspecialchars($reg); ?>" 
                                            <?php echo $filter_region == $reg ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($reg); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="upcoming" <?php echo $filter_status == 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                <option value="expired" <?php echo $filter_status == 'expired' ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </div>
                        
                        <div class="form-group" style="grid-column: 1 / -1; display: flex; gap: 10px;">
                            <button type="submit" class="smallBtn Green">
                                 Apply Filters
                            </button>
                            <a href="adminPriceCap.php" class="smallBtn Cyan">
                                 Clear Filters
                            </a>
                        </div>
                    </form>
                </div>
                
           
                
                <?php if($price_caps->num_rows > 0): ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                   
                                    <th>
                                        <a href="?<?php echo buildSortLink('commodity_name'); ?>" style="color: inherit; text-decoration: none;">
                                            Commodity
                                            <?php if($sort_by == 'commodity_name'): ?>
                                                <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo buildSortLink('max_price_per_unit'); ?>" style="color: inherit; text-decoration: none;">
                                            Max Price
                                            <?php if($sort_by == 'max_price_per_unit'): ?>
                                                <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo buildSortLink('effective_date'); ?>" style="color: inherit; text-decoration: none;">
                                            Dates
                                            <?php if($sort_by == 'effective_date'): ?>
                                                <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo buildSortLink('region'); ?>" style="color: inherit; text-decoration: none;">
                                            Region
                                            <?php if($sort_by == 'region'): ?>
                                                <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?<?php echo buildSortLink('status_display'); ?>" style="color: inherit; text-decoration: none;">
                                            Status
                                            <?php if($sort_by == 'status_display'): ?>
                                                <i class="fas fa-sort-<?php echo $sort_order == 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $price_caps->data_seek(0);
                                while($cap = $price_caps->fetch_assoc()): 
                                    $status = $cap['status_display'];
                                    $status_class = 'status-' . strtolower($status);
                                ?>
                                <tr class="<?php echo strtolower($status); ?>-row">
                                   
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div>
                                                <div class="tableBoldText"><?php echo htmlspecialchars($cap['commodity_name']); ?></div>
                                                <div class="tablesmallText">Unit: <?php echo $cap['unit_type']; ?></div>
                                                <?php if($cap['violation_count'] > 0): ?>
                                                    <small style="color: #dc3545;">
                                                        <i class="fas fa-exclamation-triangle"></i> <?php echo $cap['violation_count']; ?> violation(s)
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="tableBoldText" style="color: #28a745; font-size: 18px;">
                                            ৳ <?php echo number_format($cap['max_price_per_unit'], 2); ?>
                                        </div>
                                        <div class="tablesmallText">per <?php echo $cap['unit_type']; ?></div>
                                    </td>
                                    <td>
                                        <div class="tableBoldText">
                                            <i class="fas fa-calendar-day"></i> <?php echo $cap['effective_date']; ?>
                                        </div>
                                        <div class="tablesmallText">
                                            <?php if($cap['expiry_date']): ?>
                                                <i class="fas fa-calendar-times"></i> Expires: <?php echo $cap['expiry_date']; ?>
                                            <?php else: ?>
                                                <i class="fas fa-infinity"></i> No expiry
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="region-badge">
                                            <?php echo $cap['region']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php if($status == 'Active'): ?>
                                            <?php elseif($status == 'Upcoming'): ?>
                                            <?php else: ?>
                                            <?php endif; ?>
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px;">
                                            <a href="../admin/edit_price_cap.php?id=<?php echo $cap['price_cap_id']; ?>" 
                                               class="cardLinkBtn Cyan">Edit </a>
                                            <a href="adminPriceCap.php?delete=<?php echo $cap['price_cap_id']; ?>" 
                                               class="cardLinkBtn DarkRed" title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this price cap?')">
                                                Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <h3 style="color: #666; margin-bottom: 10px;">No Price Caps Found</h3>
                        <p style="color: #999;">Try adjusting your filters or add a new price cap.</p>
                        <a href="#priceCapForm" class="limebtn" style="margin-top: 20px;">
                         Add New Price Cap
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        
        <div class="footer">
            <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
        </div>
    </div>
    
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle">Price Cap Details</h3>
            <div id="modalContent">Loading...</div>
        </div>
    </div>
    
    <script>
    function checkExistingCaps() {
        const commodityId = document.getElementById('commodity_id').value;
        const region = document.getElementById('region').value;
        const effectiveDate = document.getElementById('effective_date').value;
        const infoDiv = document.getElementById('existingCapsInfo');
        
        if (!commodityId || !region || !effectiveDate) {
            infoDiv.style.display = 'none';
            return;
        }
        
        fetch(`check_existing_caps.php?commodity_id=${commodityId}&region=${region}`)
            .then(response => response.json())
            .then(data => {
                if (data.count > 0) {
                    infoDiv.innerHTML = `
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Note:</strong> There are ${data.count} active price cap(s) for this commodity in ${region}. 
                        They will be automatically expired when you save this new price cap.
                    `;
                    infoDiv.style.display = 'block';
                } else {
                    infoDiv.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error checking existing caps:', error);
            });
    }
    
    function toggleFilters() {
        const filterSection = document.getElementById('filterSection');
        filterSection.style.display = filterSection.style.display === 'none' ? 'block' : 'none';
    }
    
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-success, .alert-error');
        alerts.forEach(alert => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
    
    document.addEventListener('DOMContentLoaded', function() {
        const effectiveDate = document.getElementById('effective_date');
        const expiryDate = document.getElementById('expiry_date');
        
        effectiveDate.addEventListener('change', function() {
            if (this.value) {
                const effective = new Date(this.value);
                const expiry = new Date(effective);
                expiry.setDate(expiry.getDate() + 30);
                const expiryStr = expiry.toISOString().split('T')[0];
                if (!expiryDate.value) {
                    expiryDate.value = expiryStr;
                }
                checkExistingCaps();
            }
        });
        
        window.onclick = function(event) {
            const modal = document.getElementById('detailsModal');
            if (event.target === modal) {
                closeModal();
            }
        };
        
        document.getElementById('commodity_id').addEventListener('change', checkExistingCaps);
        document.getElementById('region').addEventListener('change', checkExistingCaps);
    });
    
    document.querySelector('form').addEventListener('submit', function(e) {
        const effectiveDate = new Date(document.getElementById('effective_date').value);
        const expiryDate = document.getElementById('expiry_date').value ? new Date(document.getElementById('expiry_date').value) : null;
        const today = new Date();
        
        if (effectiveDate < today) {
            if (!confirm('Effective date is in the past. Do you want to proceed?')) {
                e.preventDefault();
                return false;
            }
        }
        
        if (expiryDate && expiryDate < effectiveDate) {
            alert('Expiry date cannot be before effective date!');
            e.preventDefault();
            return false;
        }
        
        return true;
    });
    </script>
</body>
</html>