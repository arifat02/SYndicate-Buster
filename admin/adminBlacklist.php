<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Syndicate Blacklist - Admin Dashboard</title>
      <link rel="stylesheet" href="../css/page.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/text.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/cards.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/error.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/button.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/table.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/model.css?v=<?php echo time(); ?>">
        <link rel="stylesheet" href="../css/form.css?v=<?php echo time(); ?>">

</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        <div class="dashboard">
            <div class="userDetailsCard">
                <h2>Syndicate Blacklist Management</h2>
                <a href="../admin/adminViolation.php" class="smallBtn Cyan"><- Violations Dashboard</a>
                
            </div>
            
           
            
    <div class="form-container">
        <div class="form-row">
            <div>
                 <h2 style="color: #214332; margin-bottom: 15px;">Add Entity to Blacklist</h2>
            </div>
            <form">
                <div class="form-blacklist">
                    <input type="text" id="entityName" class="form-control" placeholder="Enter entity name" required>
                </div>
                
                <div class="form-blacklist">
                    <input type="text" id="entityId" class="form-control" placeholder="Enter user ID or business ID">
                </div>
                
                <div class="form-blacklist">
                    <select id="entityRole" class="form-control" required>
                        <option value="">Select role</option>
                        <option value="farmer">Farmer</option>
                        <option value="middleman">Middleman</option>
                        <option value="wholesaler">Wholesaler</option>
                        <option value="retailer">Retailer</option>
                        <option value="organization">Organization</option>
                    </select>
                </div>
                
                <div class="form-blacklist">
                    <input type="text" id="location" class="form-control" placeholder="Enter location" required>
                </div>
                
                <div class="form-blacklist">
                    <select id="blacklistReason" class="form-control" required>
                        <option value="">Select reason</option>
                        <option value="price_manipulation">Price Manipulation</option>
                        <option value="hoarding">Hoarding Commodities</option>
                        <option value="false_reporting">False Reporting</option>
                        <option value="multiple_violations">Multiple Violations</option>
                        <option value="syndicate_activity">Syndicate Activity</option>
                        <option value="other">Other</option>
                    </select>
                </div>
               
                <div class="form-blacklist">
                    <select id="blacklistDuration" class="form-control" required>
                        <option value="30">30 days</option>
                        <option value="90">90 days</option>
                        <option value="180">6 months</option>
                        <option value="365">1 year</option>
                        <option value="permanent">Permanent</option>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px ">
                    <button type="submit" class="greenBtn"style="height:40px;">Add to Blacklist</button>
                    <a href="../admin/adminBlacklist.php" class="limebtn"  >Cancel</a>
                </div>
            </form>
            </div>
        </div>
            
            <div class="gridCard">
                <h2 style="color: #214332; margin-bottom: 15px;">Search & Filter</h2>
                    <div class="filter-grid">
                        <div class="search-box">
                            <input type="text" placeholder="Search by name, location, or reason...">
                            <button class="search-btn">Search</button>
                        </div>
                        <select class="filterText">
                            <option value="">All Status</option>
                            <option value="blacklisted">Blacklisted</option>
                            <option value="watchlist">Watchlist</option>
                            <option value="removed">Removed</option>
                        </select>
                        <select class="filterText">
                            <option value="">All Roles</option>
                            <option value="farmer">Farmer</option>
                            <option value="middleman">Middleman</option>
                            <option value="wholesaler">Wholesaler</option>
                            <option value="retailer">Retailer</option>
                        </select>
                        <select class="filterText">
                            <option value="">All Regions</option>
                            <option value="dhaka">Dhaka</option>
                            <option value="chittagong">Chittagong</option>
                            <option value="rajshahi">Rajshahi</option>
                            <option value="khulna">Khulna</option>
                        </select>
                </div>
            </div>
                
                <div class="card">
                    <div class="table-box">
                                <h2 style="color: #214332; margin-bottom: 15px;">Blacklist Records</h2>
                    <table >
                        <thead>
                            <tr>
                                <th style="width: 50px;">
                                </th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Location</th>
                                <th>Blacklisted Since</th>
                                <th>Status</th>
                                <th>Violations</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                                            </table>
                    
                    
                </div>
            </div>
            
            <div class="footer">
                <p>Syndicate Buster Admin Panel © 2024</p>
            </div>
        </div>
    </div>

    </body>
</html>