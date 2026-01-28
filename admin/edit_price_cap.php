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

$price_cap_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($price_cap_id <= 0) {
    header("Location: adminPriceCap.php");
    exit();
}

$sql = "SELECT pc.*, c.commodity_name, c.unit_type 
        FROM price_caps pc
        JOIN commodities c ON pc.commodity_id = c.commodity_id
        WHERE pc.price_cap_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $price_cap_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: adminPriceCap.php");
    exit();
}

$price_cap = $result->fetch_assoc();
$stmt->close();

$commodities = $conn->query("SELECT * FROM commodities WHERE status = 'Active' ORDER BY commodity_name");

$regions_result = $conn->query("SELECT DISTINCT region FROM price_caps ORDER BY region");
$regions = [];
while($row = $regions_result->fetch_assoc()) {
    $regions[] = $row['region'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $commodity_id = intval($_POST['commodity_id']);
    $max_price = floatval($_POST['max_price']);
    $effective_date = $_POST['effective_date'];
    $region = $_POST['region'] ?? 'National';
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    
    if (empty($commodity_id) || empty($max_price) || empty($effective_date) || empty($region)) {
        $error = "Please fill in all required fields.";
    } elseif ($max_price <= 0) {
        $error = "Maximum price must be greater than 0.";
    } else {
        $check_sql = "SELECT price_cap_id FROM price_caps 
                      WHERE commodity_id = ? 
                      AND region = ? 
                      AND effective_date = ?
                      AND price_cap_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("issi", $commodity_id, $region, $effective_date, $price_cap_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = "A price cap already exists for this commodity in this region on the selected effective date.";
        } else {
            if ($expiry_date) {
                $update_sql = "UPDATE price_caps 
                               SET commodity_id = ?, 
                                   max_price_per_unit = ?, 
                                   effective_date = ?, 
                                   expiry_date = ?, 
                                   region = ?,
                                   updated_at = NOW()
                               WHERE price_cap_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("idsssi", $commodity_id, $max_price, $effective_date, $expiry_date, $region, $price_cap_id);
            } else {
                $update_sql = "UPDATE price_caps 
                               SET commodity_id = ?, 
                                   max_price_per_unit = ?, 
                                   effective_date = ?, 
                                   expiry_date = NULL, 
                                   region = ?,
                                   updated_at = NOW()
                               WHERE price_cap_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("idssi", $commodity_id, $max_price, $effective_date, $region, $price_cap_id);
            }
            
            if ($update_stmt->execute()) {
                $message = "Price cap updated successfully!";
                $sql = "SELECT pc.*, c.commodity_name, c.unit_type 
                        FROM price_caps pc
                        JOIN commodities c ON pc.commodity_id = c.commodity_id
                        WHERE pc.price_cap_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $price_cap_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $price_cap = $result->fetch_assoc();
                $stmt->close();
            } else {
                $error = "Error updating price cap: " . $conn->error;
            }
            $update_stmt->close();
        }
        $check_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Price Cap - Admin</title>
    <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/form.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        
        <div class="dashboard">
            <div class="userDetailsCard">
                <h2>Edit Price Cap</h2>
                <div style="display: flex; gap: 10px;">
                    <button class="smallBtn Cyan" onclick="window.location.href='adminPriceCap.php'">← Back to Price Caps</button>
                    <button class="smallBtn Red" onclick="window.location.href='logout.php'">Logout</button>
                </div>
            </div>
            
            <?php if($message): ?>
                <div class="success" style="margin: 15px;"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="error" style="margin: 15px;"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="form-container">
                <div style="background: #e7f3ff; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <p style="margin: 0; color: #0c5460;">
                        <strong>Note:</strong> Editing a price cap will update the existing record. If you need to set a new price cap with a different effective date, go back and create a new price cap instead.
                    </p>
                </div>
                
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="commodity_id">Commodity</label>
                            <input name="commodity_id" id="commodity_id" class="form-control"
                                  step="0.01" min="0.01" 
                                   value="<?php echo htmlspecialchars($price_cap['commodity_name']); ?>" required>
                                </input>
                        </div>
                    </div>
                    
                    <div class="form-row" style="margin-top: 20px;">
                        <div class="form-group">
                            <label for="max_price">Maximum Price (৳)</label>
                            <input type="number" name="max_price" id="max_price" class="form-control" 
                                   placeholder="Enter maximum retail price" step="0.01" min="0.01" 
                                   value="<?php echo htmlspecialchars($price_cap['max_price_per_unit']); ?>" required>
                            <small>Maximum retail price per unit</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="region">Region *</label>
                            <select name="region" id="region" class="form-control" required>
                                <option value="National" <?php echo $price_cap['region'] == 'National' ? 'selected' : ''; ?>>National</option>
                                <?php foreach($regions as $reg): ?>
                                    <?php if($reg != 'National'): ?>
                                        <option value="<?php echo htmlspecialchars($reg); ?>"
                                            <?php echo $price_cap['region'] == $reg ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($reg); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <option value="Dhaka" <?php echo $price_cap['region'] == 'Dhaka' ? 'selected' : ''; ?>>Dhaka</option>
                                <option value="Chittagong" <?php echo $price_cap['region'] == 'Chittagong' ? 'selected' : ''; ?>>Chittagong</option>
                                <option value="Sylhet" <?php echo $price_cap['region'] == 'Sylhet' ? 'selected' : ''; ?>>Sylhet</option>
                                <option value="Rajshahi" <?php echo $price_cap['region'] == 'Rajshahi' ? 'selected' : ''; ?>>Rajshahi</option>
                                <option value="Khulna" <?php echo $price_cap['region'] == 'Khulna' ? 'selected' : ''; ?>>Khulna</option>
                                <option value="Barisal" <?php echo $price_cap['region'] == 'Barisal' ? 'selected' : ''; ?>>Barisal</option>
                                <option value="Rangpur" <?php echo $price_cap['region'] == 'Rangpur' ? 'selected' : ''; ?>>Rangpur</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="effective_date">Effective Date *</label>
                            <input type="date" name="effective_date" id="effective_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($price_cap['effective_date']); ?>" required>
                            <small>The date when this price cap becomes active</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="expiry_date">Expiry Date (Optional)</label>
                            <input type="date" name="expiry_date" id="expiry_date" class="form-control"
                                   value="<?php echo htmlspecialchars($price_cap['expiry_date'] ?? ''); ?>">
                            <small>Leave empty for no expiry</small>
                        </div>
                    </div>
                    
                    <!-- Current Status Information -->
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin: 20px 0;">
                        <h4 style="color: #214332; margin-bottom: 10px;">Current Information</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div>
                                <strong>Commodity:</strong> <?php echo htmlspecialchars($price_cap['commodity_name']); ?>
                            </div>
                            <div>
                                <strong>Unit Type:</strong> <?php echo htmlspecialchars($price_cap['unit_type']); ?>
                            </div>
                            <div>
                                <strong>Created:</strong> <?php echo date('M d, Y', strtotime($price_cap['created_at'])); ?>
                            </div>
                            <div>
                                <strong>Last Updated:</strong> <?php echo date('M d, Y', strtotime($price_cap['updated_at'])); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="button-grid">
                        <a href="adminPriceCap.php" class="greenBtn Cyan">Cancel</a>
                        <button type="submit" class="greenBtn" >Update Price Cap</button>
                    </div>
                </form>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const effectiveDate = document.getElementById('effective_date');
        const expiryDate = document.getElementById('expiry_date');
        
        // Set minimum effective date to today
        const today = new Date().toISOString().split('T')[0];
        effectiveDate.min = today;
        
        effectiveDate.addEventListener('change', function() {
            if (this.value && expiryDate.value) {
                const effective = new Date(this.value);
                const expiry = new Date(expiryDate.value);
                
                if (expiry < effective) {
                    alert('Expiry date cannot be before effective date!');
                    expiryDate.value = '';
                }
            }
        });
        
        expiryDate.addEventListener('change', function() {
            if (this.value && effectiveDate.value) {
                const effective = new Date(effectiveDate.value);
                const expiry = new Date(this.value);
                
                if (expiry < effective) {
                    alert('Expiry date cannot be before effective date!');
                    this.value = '';
                }
            }
        });
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
