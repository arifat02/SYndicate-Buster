<?php
session_start();
require_once "../config.php";

// 1. Authentication Check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = $error = "";

// --- Handle Purchase Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy_item') {
    $batch_id = intval($_POST['batch_id']);
    $seller_id = intval($_POST['seller_id']);
    $commodity_id = intval($_POST['commodity_id']);
    $buy_qty = floatval($_POST['quantity']);
    
    // We get the price from the HIDDEN field (which comes from the DB), not user input
    $price_per_unit = floatval($_POST['fixed_price']); 
    
    if ($buy_qty <= 0) {
        $error = "Invalid quantity.";
    } elseif ($seller_id == $user_id) {
        $error = "You cannot buy your own items.";
    } else {
        $conn->begin_transaction();
        try {
            // Check Availability
            $check_sql = "SELECT current_quantity, production_date, expiry_date, selling_price FROM batches WHERE batch_id = ? FOR UPDATE";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("i", $batch_id);
            $stmt->execute();
            $batch_data = $stmt->get_result()->fetch_assoc();
            
            // Double check price hasn't changed
            if(abs($batch_data['selling_price'] - $price_per_unit) > 0.01) {
                throw new Exception("Price has changed. Please refresh.");
            }
            
            if ($batch_data['current_quantity'] < $buy_qty) {
                throw new Exception("Not enough stock available.");
            }

            // Check Price Cap Compliance
            $cap_sql = "SELECT max_price_per_unit FROM price_caps 
                        WHERE commodity_id = ? AND effective_date <= CURDATE() 
                        ORDER BY effective_date DESC LIMIT 1";
            $cap_stmt = $conn->prepare($cap_sql);
            $cap_stmt->bind_param("i", $commodity_id);
            $cap_stmt->execute();
            $cap_result = $cap_stmt->get_result()->fetch_assoc();
            $max_price = $cap_result['max_price_per_unit'] ?? 999999;
            
            $violation = ($price_per_unit > $max_price) ? 1 : 0;

            // Record Transaction
            $trans_sql = "INSERT INTO transactions (batch_id, seller_id, buyer_id, unit_price, quantity, status, violation_flag) 
                          VALUES (?, ?, ?, ?, ?, 'Completed', ?)";
            $stmt = $conn->prepare($trans_sql);
            $stmt->bind_param("iiiddi", $batch_id, $seller_id, $user_id, $price_per_unit, $buy_qty, $violation);
            $stmt->execute();
            
            // Update Seller Inventory
            $update_sql = "UPDATE batches SET current_quantity = current_quantity - ? WHERE batch_id = ?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("di", $buy_qty, $batch_id);
            $stmt->execute();
            
            // Close empty batch
            $conn->query("UPDATE batches SET batch_status = 'Sold' WHERE batch_id = $batch_id AND current_quantity = 0");

            // Add to Buyer Inventory (Recursive Traceability)
            $new_batch_sql = "INSERT INTO batches (commodity_id, owner_id, initial_quantity, current_quantity, production_date, expiry_date, parent_batch_id, selling_price) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, 0.00)"; // Buyer's selling price starts at 0
            $stmt = $conn->prepare($new_batch_sql);
            $stmt->bind_param("iiddssi", $commodity_id, $user_id, $buy_qty, $buy_qty, $batch_data['production_date'], $batch_data['expiry_date'], $batch_id);
            $stmt->execute();

            $conn->commit();
            $message = "✅ Purchase successful!";
            
            // Auto-Report Violation if high price
            if ($violation) {
                $v_sql = "INSERT INTO violations (reporter_id, reported_user_id, violation_type, description, violation_date, status) 
                          VALUES (5, ?, 'PRICE_CAP', 'System Detected: High Price Sale', CURDATE(), 'CONFIRMED')";
                $stmt = $conn->prepare($v_sql);
                $stmt->bind_param("i", $seller_id);
                $stmt->execute();
            }

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}

// --- Fetch Market Data (Joining Multiple Tables) ---
$search = $_GET['search'] ?? '';
$filter_loc = $_GET['location'] ?? '';

$sql = "SELECT 
            b.batch_id, b.current_quantity, b.selling_price, b.production_date, b.expiry_date, b.owner_id,
            c.commodity_id, c.commodity_name, c.unit_type,
            u.username as seller_name, u.location, u.trust_score,
            (SELECT max_price_per_unit FROM price_caps pc WHERE pc.commodity_id = b.commodity_id ORDER BY effective_date DESC LIMIT 1) as govt_cap
        FROM batches b 
        JOIN commodities c ON b.commodity_id = c.commodity_id
        JOIN users u ON b.owner_id = u.user_id
        WHERE b.owner_id != ? 
        AND b.current_quantity > 0 
        AND b.batch_status = 'Active'
        AND b.selling_price > 0"; // Only show items that have a price set

if ($search) {
    $sql .= " AND c.commodity_name LIKE '%" . $conn->real_escape_string($search) . "%'";
}
if ($filter_loc) {
    $sql .= " AND u.location = '" . $conn->real_escape_string($filter_loc) . "'";
}

$sql .= " ORDER BY b.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$items = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Marketplace - Buy Products</title>
    <link rel="stylesheet" href="../css/page.css">
    <link rel="stylesheet" href="../css/text.css">
    <link rel="stylesheet" href="../css/cards.css">
    <link rel="stylesheet" href="../css/button.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/form.css">
    <style>
        .market-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .item-card { background: white; border: 1px solid #eee; border-radius: 8px; padding: 20px; transition: transform 0.2s; position: relative; }
        .item-card:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        
        .price-badge {
            font-size: 18px; font-weight: bold; color: #214332;
            background: #e8f5e9; padding: 5px 12px; border-radius: 20px;
        }
        .warning-badge {
            background: #ffebee; color: #c62828; font-size: 12px; padding: 2px 8px; border-radius: 4px;
        }
        
        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 10% auto; padding: 25px; width: 400px; border-radius: 8px; }
        
        .back-link { display: inline-block; color: #214332; text-decoration: none; font-weight: bold; margin-bottom: 20px; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Marketplace</div>
        <div class="dashboard">
            
            <div style="padding: 10px 0;">
                <a href="vendorDashboard.php" class="back-link">← Back to Dashboard</a>
            </div>

            <?php if ($message): ?>
                <div class="alert success" style="background:#d4edda; color:#155724; padding:15px; margin:20px 0; border-radius:5px;"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert error" style="background:#f8d7da; color:#721c24; padding:15px; margin:20px 0; border-radius:5px;"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="gridCard">
                <form method="GET" style="display: flex; gap: 15px;">
                    <input type="text" name="search" placeholder="Search item..." class="inputAreaText" value="<?php echo htmlspecialchars($search); ?>">
                    <select name="location" class="inputAreaText">
                        <option value="">All Locations</option>
                        <option value="Dhaka">Dhaka</option>
                        <option value="Chittagong">Chittagong</option>
                        <option value="Bogura">Bogura</option>
                    </select>
                    <button type="submit" class="greenBtn">Filter</button>
                </form>
            </div>

            <div class="market-grid">
                <?php while($item = $items->fetch_assoc()): 
                    $price = $item['selling_price'];
                    $cap = $item['govt_cap'] ?? 999999;
                    $is_overpriced = ($price > $cap);
                ?>
                    <div class="item-card">
                        <div style="display:flex; justify-content:space-between; align-items: flex-start;">
                            <h2 class="GreenTextLarge"><?php echo htmlspecialchars($item['commodity_name']); ?></h2>
                            <span class="price-badge">৳<?php echo $price; ?></span>
                        </div>
                        
                        <?php if($is_overpriced): ?>
                            <div style="margin-top:5px;">
                                <span class="warning-badge">⚠️ Above Govt Cap (৳<?php echo $cap; ?>)</span>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 12px; color: #28a745; margin-top:5px;">✅ Fair Price (Cap: ৳<?php echo $cap; ?>)</div>
                        <?php endif; ?>
                        
                        <div style="margin: 15px 0; color: #555; font-size: 14px;">
                            <p><strong>Stock:</strong> <?php echo $item['current_quantity']; ?> <?php echo $item['unit_type']; ?></p>
                            <p><strong>Seller:</strong> <?php echo htmlspecialchars($item['seller_name']); ?> (<?php echo $item['location']; ?>)</p>
                            <p><strong>Trust Score:</strong> <?php echo $item['trust_score']; ?>/100</p>
                        </div>

                        <button onclick="openBuyModal(<?php echo htmlspecialchars(json_encode($item)); ?>)" class="cardBtn Cyan" style="width: 100%;">
                            Buy Now
                        </button>
                    </div>
                <?php endwhile; ?>
            </div>
            
            <?php if ($items->num_rows == 0): ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    No active listings found. Sellers need to set a "Selling Price" in their inventory to appear here.
                </div>
            <?php endif; ?>

        </div>
    </div>

    <div id="buyModal" class="modal">
        <div class="modal-content">
            <span onclick="document.getElementById('buyModal').style.display='none'" style="float:right; cursor:pointer; font-size:24px;">&times;</span>
            <h2 class="GreenTextLarge" style="margin-bottom: 20px;">Confirm Purchase</h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="buy_item">
                <input type="hidden" name="batch_id" id="modal_batch_id">
                <input type="hidden" name="seller_id" id="modal_seller_id">
                <input type="hidden" name="commodity_id" id="modal_commodity_id">
                <input type="hidden" name="fixed_price" id="modal_fixed_price_input">
                
                <div class="form-group">
                    <label>Item Details:</label>
                    <input type="text" id="modal_item_details" class="inputAreaText" readonly style="background: #f1f1f1; font-weight: bold;">
                </div>

                <div class="form-group">
                    <label>Seller's Price (Per Unit):</label>
                    <input type="text" id="modal_display_price" class="inputAreaText" readonly style="background: #e8f5e9; color: #155724; font-weight: bold;">
                </div>

                <div class="form-group">
                    <label>Quantity to Buy (<span id="modal_unit"></span>):</label>
                    <input type="number" name="quantity" id="buy_qty" class="inputAreaText" step="0.01" required oninput="calcTotal()">
                    <small>Available: <span id="modal_max_qty"></span></small>
                </div>

                <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; text-align: center;">
                    Total Cost: <span style="font-size: 20px; font-weight: bold; color: #214332;">৳<span id="total_cost">0.00</span></span>
                </div>

                <button type="submit" class="greenBtn" style="width: 100%;">Confirm & Pay</button>
            </form>
        </div>
    </div>

    <script>
        let unitPrice = 0;

        function openBuyModal(item) {
            document.getElementById('buyModal').style.display = 'block';
            
            // Fill Hidden IDs
            document.getElementById('modal_batch_id').value = item.batch_id;
            document.getElementById('modal_seller_id').value = item.owner_id;
            document.getElementById('modal_commodity_id').value = item.commodity_id;
            
            // Fill Display Info
            document.getElementById('modal_item_details').value = item.commodity_name + ' from ' + item.seller_name;
            document.getElementById('modal_unit').innerText = item.unit_type;
            document.getElementById('modal_max_qty').innerText = item.current_quantity;
            
            // Handle Price (Fixed)
            unitPrice = parseFloat(item.selling_price);
            document.getElementById('modal_fixed_price_input').value = unitPrice;
            document.getElementById('modal_display_price').value = "৳ " + unitPrice;
            
            // Reset Inputs
            document.getElementById('buy_qty').value = "";
            document.getElementById('buy_qty').max = item.current_quantity;
            document.getElementById('total_cost').innerText = "0.00";
        }

        function calcTotal() {
            let qty = parseFloat(document.getElementById('buy_qty').value) || 0;
            let total = qty * unitPrice;
            document.getElementById('total_cost').innerText = total.toFixed(2);
        }
    </script>
</body>
</html>