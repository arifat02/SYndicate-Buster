<?php
require_once "config.php";

$market_sql = "
    SELECT 
        c.commodity_name, 
        c.unit_type,
        AVG(t.unit_price) as avg_market_price,
        (SELECT max_price_per_unit FROM price_caps pc 
         WHERE pc.commodity_id = c.commodity_id 
         AND pc.effective_date <= CURDATE() 
         ORDER BY pc.effective_date DESC LIMIT 1) as govt_cap
    FROM transactions t
    JOIN batches b ON t.batch_id = b.batch_id
    JOIN commodities c ON b.commodity_id = c.commodity_id
    WHERE t.transaction_date >= CURDATE()
    GROUP BY c.commodity_id";

$market_data = $conn->query($market_sql);

$shame_sql = "
    SELECT u.username, u.location, v.violation_type, v.violation_date 
    FROM violations v
    JOIN users u ON v.reported_user_id = u.user_id
    WHERE v.status = 'CONFIRMED'
    ORDER BY v.violation_date DESC LIMIT 5";

$shame_data = $conn->query($shame_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Govt Market Monitor - Home</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/page.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/table.css">
    <link rel="stylesheet" href="css/button.css">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #214332, #2e5c45);
            display:; flex; flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            padding: 60px 20px;
            text-align: center;
            border-radius: 0 0 20px 20px;
            margin-bottom: 30px;
        }
        .live-badge {
            background: #e74c3c; color: white; padding: 5px 10px; 
            border-radius: 5px; font-weight: bold; font-size: 12px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
        
        .price-card {
            background: white; padding: 20px; border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center;
            border-top: 4px solid #214332;
        }
        .diff-high { color: #e74c3c; font-weight: bold; } /* Bad (High Price) */
        .diff-good { color: #27ae60; font-weight: bold; } /* Good (Low Price) */
    </style>
</head>
<body>

    <div class="hero-section">
        <h1 style="font-size: 2.5em; margin-bottom: 10px;">🛡️ Syndicate Buster</h1>
        <p style="font-size: 1.2em; opacity: 0.9;">Govt Market Monitor</p>
        <div style="display:flex; flex-direction: row;  gap: 20px; padding-top:10px;">
            <a href="login.php" class="greenBtn Lime" >Login to Portal</a>
            <a href="signup.php" class="greenBtn GreenBorder" #a5d6a7; margin-left: 10px;">Register Business</a>
        </div>
    </div>

    <div class="container">
        
        <div class="gridCard">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="color: #214332;">📉 Daily Market Report</h2>
                <span class="live-badge">LIVE UPDATES</span>
            </div>

            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <?php if ($market_data->num_rows > 0): ?>
                    <?php while($row = $market_data->fetch_assoc()): 
                        $avg = $row['avg_market_price'];
                        $cap = $row['govt_cap'];
                        $diff_percent = $cap > 0 ? (($avg - $cap) / $cap) * 100 : 0;
                        $status_class = $diff_percent > 0 ? 'diff-high' : 'diff-good';
                        $arrow = $diff_percent > 0 ? '▲' : '▼';
                    ?>
                        <div class="price-card">
                            <h3 style="margin:0;"><?php echo $row['commodity_name']; ?></h3>
                            <p style="color:#777; font-size:14px;"><?php echo $row['unit_type']; ?></p>
                            
                            <div style="font-size: 24px; font-weight: bold; margin: 10px 0;">
                                ৳<?php echo number_format($avg, 2); ?>
                            </div>
                            
                            <div style="font-size: 13px; border-top: 1px solid #eee; padding-top: 10px;">
                                Govt Cap: <strong>৳<?php echo number_format($cap, 2); ?></strong><br>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo $arrow . ' ' . number_format(abs($diff_percent), 1); ?>%
                                </span> vs Cap
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="padding: 20px; color: #666;">No sales data recorded today.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid" style="margin-top: 30px;">
            
            <div class="gridCard" style="border-left: 5px solid #e74c3c;">
                <h2 style="color: #c0392b;">🚫 Syndicate Watchlist</h2>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    Businesses recently flagged for price manipulation or hoarding.
                </p>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Store Name</th>
                            <th>Location</th>
                            <th>Violation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($shame_data->num_rows > 0): ?>
                            <?php while($row = $shame_data->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight:bold;"><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td><?php echo htmlspecialchars($row['location']); ?></td>
                                    <td><span style="color:#c0392b;"><?php echo $row['violation_type']; ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align:center;">No active syndicates detected.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- <div class="gridCard" style="background: #f8f9fa;">
                <h2 style="color: #214332;">🔍 Trace a Product</h2>
                <p style="margin-bottom: 20px;">Scan a QR code or enter Batch ID to see the full supply chain history.</p>
                
                <form action="trace.php" method="GET" style="display: flex; gap: 10px;">
                    <input type="number" name="batch_id" placeholder="Enter Batch ID (e.g. 29)" class="inputAreaText" required style="width: 100%; padding: 12px;">
                    <button type="submit" class="greenBtn">Trace</button>
                </form>
                
                <div style="margin-top: 20px; font-size: 13px; color: #666;">
                    * Proves origin from Farmer to Retailer.
                </div>
            </div> -->

        </div>

         <div class="footer">
        <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
    </div>

    </div>
</body>
</html>