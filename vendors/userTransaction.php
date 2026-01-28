<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$sales_sql = "SELECT 
    COUNT(*) as total_no_of_sales,
    COALESCE(SUM(unit_price * quantity), 0) as total_revenue,
    COALESCE(AVG(unit_price * quantity), 0) as avg_sale_amount
FROM transactions 
WHERE seller_id = ?";
$sales_stmt = $conn->prepare($sales_sql);
$sales_stmt->bind_param("i", $user_id);
$sales_stmt->execute();
$sales_result = $sales_stmt->get_result();
$sales_stats = $sales_result->fetch_assoc();
$sales_stmt->close();

$purchase_sql = "SELECT 
    COUNT(*) as total_no_of_purchases,
    COALESCE(SUM(unit_price * quantity), 0) as total_spent,
    COALESCE(AVG(unit_price * quantity), 0) as avg_purchase_amount
FROM transactions 
WHERE buyer_id = ?";
$purchase_stmt = $conn->prepare($purchase_sql);
$purchase_stmt->bind_param("i", $user_id);
$purchase_stmt->execute();
$purchase_result = $purchase_stmt->get_result();
$purchase_stats = $purchase_result->fetch_assoc();
$purchase_stmt->close();

$stat_sql = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE 
        WHEN DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        THEN 1 
        ELSE 0 
    END) as this_month_transactions
FROM transactions 
WHERE seller_id = ? OR buyer_id = ?";
$stat_stmt = $conn->prepare($stat_sql);
$stat_stmt->bind_param("ii", $user_id, $user_id);
$stat_stmt->execute();
$stat_result = $stat_stmt->get_result();
$stat_stats = $stat_result->fetch_assoc();
$stat_stmt->close();

// All 
$transaction_sql = "
    SELECT 
        t.transaction_date,
        c.commodity_name,
        t.quantity,
        t.unit_price,
        t.seller_id,
        t.buyer_id,
        seller.username as seller_name,
        buyer.username as buyer_name
    FROM transactions t
    INNER JOIN batches b ON t.batch_id = b.batch_id
    INNER JOIN commodities c ON b.commodity_id = c.commodity_id
    LEFT JOIN users seller ON t.seller_id = seller.user_id
    LEFT JOIN users buyer ON t.buyer_id = buyer.user_id
    WHERE t.seller_id = ? OR t.buyer_id = ?
    ORDER BY t.transaction_date DESC
";
$transaction_stmt = $conn->prepare($transaction_sql);
$transaction_stmt->bind_param("ii", $user_id, $user_id);
$transaction_stmt->execute();
$transaction_result = $transaction_stmt->get_result();
$transactions = $transaction_result->fetch_all(MYSQLI_ASSOC);
$total_transactions = count($transactions);

// Sales 
$transaction_sales_sql = "
    SELECT 
        t.transaction_date,
        c.commodity_name,
        t.quantity,
        t.unit_price,
        t.seller_id,
        t.buyer_id,
        buyer.username as buyer_name
    FROM transactions t
    INNER JOIN batches b ON t.batch_id = b.batch_id
    INNER JOIN commodities c ON b.commodity_id = c.commodity_id
    LEFT JOIN users buyer ON t.buyer_id = buyer.user_id
    WHERE t.seller_id = ?
    ORDER BY t.transaction_date DESC
";
$transaction_sales_stmt = $conn->prepare($transaction_sales_sql);
$transaction_sales_stmt->bind_param("i", $user_id);
$transaction_sales_stmt->execute();
$transaction_sales_result = $transaction_sales_stmt->get_result();
$transaction_sales = $transaction_sales_result->fetch_all(MYSQLI_ASSOC);
$total_transaction_sales = count($transaction_sales);

// Purchase 
$transaction_purchases_sql = "
    SELECT 
        t.transaction_date,
        c.commodity_name,
        t.quantity,
        t.unit_price,
        t.seller_id,
        t.buyer_id,
        seller.username as seller_name
    FROM transactions t
    INNER JOIN batches b ON t.batch_id = b.batch_id
    INNER JOIN commodities c ON b.commodity_id = c.commodity_id
    LEFT JOIN users seller ON t.seller_id = seller.user_id
    WHERE t.buyer_id = ?
    ORDER BY t.transaction_date DESC
";
$transaction_purchases_stmt = $conn->prepare($transaction_purchases_sql);
$transaction_purchases_stmt->bind_param("i", $user_id);
$transaction_purchases_stmt->execute();
$transaction_purchases_result = $transaction_purchases_stmt->get_result();
$transaction_purchases = $transaction_purchases_result->fetch_all(MYSQLI_ASSOC);
$total_transaction_purchases = count($transaction_purchases);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Transactions - Syndicate Buster</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="model.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/model.css?v=<?php echo time(); ?>">
    
 
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            <div class="userDetailsCard">
                <h1>My Transactions</h1>
                <div>
                    <a href="../logout.php" class="smallBtn Red">Logout</a>
                </div>
            </div>
          
              <div class="navCard">
                <a href="../vendors/vendorDashboard.php">Dashboard</a>
                <a href="../vendors/userProductReg.php">Product Registration</a>
                <a href="../vendors/userCreateListing.php">Create Listing</a>
                <a href="../vendors/userMarketplace.php">Marketplace</a>
                <a href="../vendors/userTransaction.php"  style="background: rgba(255,255,255,0.1);">Transactions logs</a>
                <a href="../vendors/userViolation.php">Policy & Violation</a>
            </div>

            <div class="grid">
                <div class="gridCard">
                    <h2>Sales Summary</h2>
                    <div class="gridCard-item">
                        <span>Total Sold</span>
                        <span><strong>৳ <?= number_format($sales_stats['total_revenue'], 2) ?></strong></span>
                    </div>
                    <div class="gridCard-item">
                        <span>Total Batches</span>
                        <span><strong><?= $sales_stats['total_no_of_sales'] ?></strong></span>
                    </div>
                    <div class="gridCard-item">
                        <span>Avg. Price</span>
                        <span><strong>৳ <?= number_format($sales_stats['avg_sale_amount'], 2) ?></strong></span>
                    </div>
                </div>
                
                <div class="gridCard">
                    <h2>Purchase Summary</h2>
                    <div class="gridCard-item">
                        <span>Total Bought</span>
                        <span><strong>৳ <?= number_format($purchase_stats['total_spent'], 2) ?></strong></span>
                    </div>
                    <div class="gridCard-item">
                        <span>Total Batches</span>
                        <span><strong><?= $purchase_stats['total_no_of_purchases'] ?></strong></span>
                    </div>
                    <div class="gridCard-item">
                        <span>Avg. Price</span>
                        <span><strong>৳ <?= number_format($purchase_stats['avg_purchase_amount'], 2) ?></strong></span>
                    </div>
                </div>
                
                <div class="gridCard">
                    <h2>Quick Stats</h2>
                    <div class="gridCard-item">
                        <span>Total Transactions</span>
                        <span><strong><?= $stat_stats['total_transactions'] ?></strong></span>
                    </div>
                    <div class="gridCard-item">
                        <span>Net Balance</span>
                        <span><strong>৳ <?= number_format($sales_stats['total_revenue'] - $purchase_stats['total_spent'], 2) ?></strong></span>
                    </div>
                    <div class="gridCard-item">
                        <span>This Month</span>
                        <span><strong><?= $stat_stats['this_month_transactions'] ?> transactions</strong></span>
                    </div>
                </div>
            </div>
            
            <!--all-->
            <div id="allTab" class="gridCard transaction-card active">
                <div class="table-top">
                    <h2 class="GreenTextLarge">All Transactions</h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="tablesmallText">Show:</span>
                        <select class="filterText" id="allLimit">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
                 <div class="transaction-cards">
                <button id="allBtn" class="cardBtn Red tab-btn " onclick="showTab('all',this)">All Transactions</button>
                <button id="sellBtn" class="cardBtn tab-btn" onclick="showTab('sell',this)">My Sales</button>
                <button id="buyBtn" class="cardBtn tab-btn" onclick="showTab('buy',this)">My Purchases</button>
            </div>

                
                <?php if($total_transactions > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr class="tableBoldText">
                            <th>Date</th>
                            <th>Type</th>
                            <th>Commodity</th>
                            <th>Partner</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody class="tablesmallText">
                        <?php foreach($transactions as $transaction): 
                            $is_seller = ($transaction['seller_id'] == $user_id);
                            $partner_name = $is_seller ? $transaction['buyer_name'] : $transaction['seller_name'];
                        ?>
                        <tr class="tablesmallText">
                            <td><?= date('Y-m-d', strtotime($transaction['transaction_date'])) ?></td>
                            <td>
                                <?php if($is_seller): ?>
                                    <span class="status-badge active">SOLD</span>
                                <?php else: ?>
                                    <span class="status-badge suspended">BOUGHT</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($transaction['commodity_name']) ?></td>
                            <td><?= htmlspecialchars($partner_name) ?></td>
                            <td><?= $transaction['quantity'] ?></td>
                            <td>৳ <?= number_format($transaction['unit_price'], 2) ?></td>
                            <td>৳ <?= number_format($transaction['quantity'] * $transaction['unit_price'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No transactions found.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sales Tab -->
            <div id="sellTab" class="gridCard transaction-card">
                <div class="table-top">
                    <h2 class="GreenTextLarge">My Sales</h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="tablesmallText">Show:</span>
                        <select class="filterText" id="sellLimit">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
                 <div class="transaction-cards">
                <button id="allBtn"class="cardBtn tab-btn " onclick="showTab('all',this)">All Transactions</button>
                <button id="sellBtn"class="cardBtn LightGreen tab-btn" onclick="showTab('sell',this)">My Sales</button>
                <button id="buyBtn"class="cardBtn  tab-btn" onclick="showTab('buy',this)">My Purchases</button>
            </div>

                
                <?php if($total_transaction_sales > 0): ?>
                <table class="data-table">
                    <thead >
                        <tr class="tableBoldText">
                            <th>Date</th>
                            <th>Commodity</th>
                            <th>Buyer</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($transaction_sales as $transaction): ?>
                        <tr class="tablesmallText">
                            <td><?= date('Y-m-d', strtotime($transaction['transaction_date'])) ?></td>
                            <td><?= htmlspecialchars($transaction['commodity_name']) ?></td>
                            <td><?= htmlspecialchars($transaction['buyer_name']) ?></td>
                            <td><?= $transaction['quantity'] ?></td>
                            <td>৳ <?= number_format($transaction['unit_price'], 2) ?></td>
                            <td>৳ <?= number_format($transaction['quantity'] * $transaction['unit_price'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No sales transactions found.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Purchases Tab -->
            <div id="buyTab" class="gridCard transaction-card">
                <div class="table-top">
                    <h2 class="GreenTextLarge">My Purchases</h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <span class="tablesmallText">Show:</span>
                        <select class="filterText" id="buyLimit">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
                 <div class="transaction-cards">
                <button id="allBtn"class="cardBtn tab-btn" onclick="showTab('all',this)">All Transactions</button>
                <button id="sellBtn"class="cardBtn  tab-btn" onclick="showTab('sell',this)">My Sales</button>
                <button id="buyBtn"class="cardBtn Cyan tab-btn" onclick="showTab('buy',this)">My Purchases</button>
            </div>

                
                <?php if($total_transaction_purchases > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr class="tableBoldText">
                            <th>Date</th>
                            <th>Commodity</th>
                            <th>Seller</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($transaction_purchases as $transaction): ?>
                        <tr class="tablesmallText">
                            <td><?= date('Y-m-d', strtotime($transaction['transaction_date'])) ?></td>
                            <td><?= htmlspecialchars($transaction['commodity_name']) ?></td>
                            <td><?= htmlspecialchars($transaction['seller_name']) ?></td>
                            <td><?= $transaction['quantity'] ?></td>
                            <td>৳ <?= number_format($transaction['unit_price'], 2) ?></td>
                            <td>৳ <?= number_format($transaction['quantity'] * $transaction['unit_price'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No purchase transactions found.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <p>Syndicate Buster Admin Panel © 2024</p>
        </div>
    </div>
<script>
function showTab(tabName, btn) {
    document.querySelectorAll('.transaction-card').forEach(tab => {
        tab.classList.remove('active');
    });

    document.querySelectorAll('.tab-btn').forEach(button => {
        button.classList.remove('active');
    });

    document.getElementById(tabName + 'Tab').classList.add('active');
    btn.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('allTab').classList.add('active');
    document.getElementById('allBtn').classList.add('active');
});
</script>


</body>
</html>