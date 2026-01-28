<?php
session_start();
require_once "../config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] > 4) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_email = $_SESSION['email'] ?? '';
$user_phone = $_SESSION['phone'] ?? '';

// Get user's penalties eligible for appeal
$penalties_sql = "SELECT 
                    p.penalty_id,
                    p.penalty_type,
                    v.violation_date,
                    v.description as violation_reason,
                    v.status as violation_status,
                    p.fine_amount,
                    p.suspension_days,
                    p.status as penalty_status,
                    p.issued_date,
                    c.commodity_name,
                    t.unit_price as your_price,
                    pc.max_price_per_unit as price_cap,
                    ROUND(((t.unit_price - pc.max_price_per_unit) / pc.max_price_per_unit * 100), 2) as excess_percent
                FROM violations v
                LEFT JOIN price_cap_violations pcv ON v.violation_id = pcv.violation_id
                LEFT JOIN transactions t ON pcv.transaction_id = t.transaction_id
                LEFT JOIN batches b ON t.batch_id = b.batch_id
                LEFT JOIN commodities c ON b.commodity_id = c.commodity_id
                LEFT JOIN price_caps pc ON c.commodity_id = pc.commodity_id 
                    AND v.violation_date BETWEEN pc.effective_date AND COALESCE(pc.expiry_date, '9999-12-31')
                JOIN penalties p ON v.violation_id = p.violation_id
                WHERE v.reported_user_id = ?
                AND p.status = 'ISSUED'
                AND v.violation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ORDER BY v.violation_date DESC";

$penalties_stmt = $conn->prepare($penalties_sql);
$penalties_stmt->bind_param("i", $user_id);
$penalties_stmt->execute();
$penalties = $penalties_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$penalties_stmt->close();

// Get user's existing appeals (last 3)
$existing_appeals_sql = "SELECT 
                            a.appeal_id,
                            a.appeal_date,
                            a.status,
                            a.review_date,
                            a.review_notes,
                            v.description as violation_reason,
                            p.penalty_type
                        FROM appeals a
                        JOIN violations v ON a.violation_id = v.violation_id
                        LEFT JOIN penalties p ON v.violation_id = p.violation_id
                        WHERE a.user_id = ?
                        ORDER BY a.appeal_date DESC
                        LIMIT 3";
$existing_appeals_stmt = $conn->prepare($existing_appeals_sql);
$existing_appeals_stmt->bind_param("i", $user_id);
$existing_appeals_stmt->execute();
$existing_appeals = $existing_appeals_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$existing_appeals_stmt->close();

// Get user's trust score
$trust_sql = "SELECT trust_score FROM users WHERE user_id = ?";
$trust_stmt = $conn->prepare($trust_sql);
$trust_stmt->bind_param("i", $user_id);
$trust_stmt->execute();
$trust_result = $trust_stmt->get_result()->fetch_assoc();
$trust_score = $trust_result['trust_score'] ?? 100;
$trust_stmt->close();

// Process form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $penalty_id = $_POST['penalty_id'] ?? '';
    $appeal_reason = trim($_POST['appeal_reason'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $agree_terms = isset($_POST['agree_terms']);
    
    // Validation
    $errors = [];
    
    if (empty($penalty_id)) {
        $errors[] = "Please select a penalty to appeal";
    }
    
    if (empty($appeal_reason) || strlen($appeal_reason) < 20) {
        $errors[] = "Please provide a detailed appeal reason (at least 20 characters)";
    }
    
    if (empty($contact_email) || !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please provide a valid email address";
    }
    
    if (empty($contact_phone) || !preg_match('/^[0-9]{10,11}$/', $contact_phone)) {
        $errors[] = "Please provide a valid phone number";
    }
    
    if (!$agree_terms) {
        $errors[] = "You must agree to the terms and conditions";
    }
    
    if (empty($errors)) {
        // Get violation_id from penalty
        $violation_sql = "SELECT v.violation_id 
                         FROM penalties p
                         JOIN violations v ON p.violation_id = v.violation_id
                         WHERE p.penalty_id = ? AND v.reported_user_id = ?";
        $violation_stmt = $conn->prepare($violation_sql);
        $violation_stmt->bind_param("ii", $penalty_id, $user_id);
        $violation_stmt->execute();
        $violation_result = $violation_stmt->get_result()->fetch_assoc();
        $violation_stmt->close();
        
        if ($violation_result) {
            $violation_id = $violation_result['violation_id'];
            
            // Check if appeal already exists
            $check_appeal_sql = "SELECT appeal_id FROM appeals WHERE violation_id = ? AND user_id = ?";
            $check_appeal_stmt = $conn->prepare($check_appeal_sql);
            $check_appeal_stmt->bind_param("ii", $violation_id, $user_id);
            $check_appeal_stmt->execute();
            $check_appeal = $check_appeal_stmt->get_result()->fetch_assoc();
            $check_appeal_stmt->close();
            
            if ($check_appeal) {
                $error_message = "You have already submitted an appeal for this violation.";
            } else {
                // Handle file uploads
                $supporting_docs = [];
                if (!empty($_FILES['supporting_docs']['name'][0])) {
                    $upload_dir = "../uploads/appeals/" . $user_id . "/";
                    
                    // Create directory if it doesn't exist
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    foreach ($_FILES['supporting_docs']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['supporting_docs']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_name = basename($_FILES['supporting_docs']['name'][$key]);
                            $file_size = $_FILES['supporting_docs']['size'][$key];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            
                            // Validate file type
                            $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                            if (!in_array($file_ext, $allowed_ext)) {
                                continue; // Skip invalid files
                            }
                            
                            // Validate file size (5MB max)
                            if ($file_size > 5 * 1024 * 1024) {
                                continue; // Skip oversized files
                            }
                            
                            // Generate unique filename
                            $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
                            $upload_path = $upload_dir . $unique_name;
                            
                            if (move_uploaded_file($tmp_name, $upload_path)) {
                                $supporting_docs[] = [
                                    'original_name' => $file_name,
                                    'stored_name' => $unique_name,
                                    'path' => $upload_path
                                ];
                            }
                        }
                    }
                }
                
                // Convert supporting docs to JSON
                $supporting_docs_json = !empty($supporting_docs) ? json_encode($supporting_docs) : null;
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Insert appeal
                    $appeal_sql = "INSERT INTO appeals (violation_id, user_id, appeal_reason, supporting_docs, 
                                  appeal_date, status, contact_email, contact_phone, reviewed_by, review_date, review_notes) 
                                  VALUES (?, ?, ?, ?, CURDATE(), 'Submitted', ?, ?, NULL, NULL, NULL)";
                    $appeal_stmt = $conn->prepare($appeal_sql);
                    $appeal_stmt->bind_param("iissss", $violation_id, $user_id, $appeal_reason, 
                                            $supporting_docs_json, $contact_email, $contact_phone);
                    
                    if ($appeal_stmt->execute()) {
                        $appeal_id = $conn->insert_id;
                        $appeal_stmt->close();
                        
                        // Update penalty status
                        $update_penalty_sql = "UPDATE penalties SET status = 'APPEALED' WHERE penalty_id = ?";
                        $update_penalty_stmt = $conn->prepare($update_penalty_sql);
                        $update_penalty_stmt->bind_param("i", $penalty_id);
                        $update_penalty_stmt->execute();
                        $update_penalty_stmt->close();
                        
                        // Create notification
                        $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_id, is_read) 
                                             VALUES (?, ?, ?, 'system', ?, 0)";
                        $notification_stmt = $conn->prepare($notification_sql);
                        
                        // Notification for user
                        $user_title = "Appeal Submitted Successfully";
                        $user_message = "Your appeal #{$appeal_id} has been submitted. We will review it within 3-5 business days.";
                        $notification_stmt->bind_param("issi", $user_id, $user_title, $user_message, $appeal_id);
                        $notification_stmt->execute();
                        
                        $admin_title = "New Appeal Submitted";
                        $admin_message = "User {$username} (ID: {$user_id}) submitted an appeal for penalty #{$penalty_id}.";
                        $admin_user_id = 5;
                        $notification_stmt->bind_param("issi", $admin_user_id, $admin_title, $admin_message, $appeal_id);
                        $notification_stmt->execute();
                        
                        $notification_stmt->close();
                        
                        
                        $conn->commit();
                        
                        $success_message = "Appeal submitted successfully! Your appeal ID is #{$appeal_id}. We will contact you at {$contact_email} within 3-5 business days.";
                        
                        header("Location: appeal_submit.php?success=" . urlencode($success_message));
                        exit();
                    } else {
                        throw new Exception("Failed to insert appeal: " . $conn->error);
                    }
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Submission failed: " . $e->getMessage();
                }
            }
        } else {
            $error_message = "Invalid penalty selected.";
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Appeal - Syndicate Buster</title>
    <link rel="stylesheet" href="../css/page.css">
    <link rel="stylesheet" href="../css/text.css">
    <link rel="stylesheet" href="../css/cards.css">
    <link rel="stylesheet" href="../css/error.css">
    <link rel="stylesheet" href="../css/button.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/table.css">
    <link rel="stylesheet" href="../css/form.css">
    <style>
        .appeal-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .appeal-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .appeal-steps {
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
            position: relative;
        }
        
        .appeal-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #dee2e6;
            z-index: 1;
        }
        
        .step {
            text-align: center;
            position: relative;
            z-index: 2;
            flex: 1;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            background: #6c757d;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
        }
        
        .step.active .step-number {
            background: #214332;
        }
        
        .step.completed .step-number {
            background: #28a745;
        }
        
        .penalty-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .penalty-card:hover {
            border-color: #214332;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .penalty-card.selected {
            border-color: #214332;
            background: #f8f9fa;
            box-shadow: 0 2px 8px rgba(33, 67, 50, 0.2);
        }
        
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .file-upload-area:hover {
            border-color: #214332;
            background: #f8f9fa;
        }
        
        .file-upload-area.dragover {
            border-color: #28a745;
            background: #e8f5e9;
        }
        
        .file-list {
            margin-top: 20px;
        }
        
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        
        .file-remove {
            color: #dc3545;
            cursor: pointer;
            font-weight: bold;
        }
        
        .existing-appeal {
            background: #e8f5e9;
            border: 1px solid #28a745;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .appeal-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-submitted { background: #ffc107; color: #212529; }
        .status-under_review { background: #17a2b8; color: white; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        
        .trust-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .trust-excellent { background: #d4edda; color: #155724; }
        .trust-good { background: #fff3cd; color: #856404; }
        .trust-poor { background: #f8d7da; color: #721c24; }
        
        @media (max-width: 768px) {
            .appeal-steps {
                flex-direction: column;
                align-items: center;
                gap: 20px;
            }
            
            .appeal-steps::before {
                display: none;
            }
            
            .step {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Govt Market Monitor - Syndicate-Buster Portal</div>
        
        <div class="dashboard">
            <div class="userDetailsCard">
                <div>
                    <h1>Submit Appeal</h1>
                    <p class="tablesmallText">
                        <span class="trust-badge <?php 
                            echo $trust_score >= 80 ? 'trust-excellent' : ($trust_score >= 60 ? 'trust-good' : 'trust-poor');
                        ?>">
                            Trust Score: <?php echo $trust_score; ?>
                        </span>
                    </p>
                </div>
                <div>
                    <a href="userViolation.php" class="smallBtn Cyan">Back to Violations</a>
                </div>
            </div>
          
            
            <?php if ($success_message): ?>
            <div class="alert-success">
                ✅ <?php echo $success_message; ?>
                <div style="margin-top: 10px;">
                    <a href="userViolation.php" class="smallBtn Green">View Violations</a>
                    <a href="appeal_submit.php" class="smallBtn Cyan">Submit Another Appeal</a>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert-error">
                ❌ <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <div class="appeal-container">
                <div class="appeal-steps">
                    <div class="step active">
                        <div class="step-number">1</div>
                        <div class="tablesmallText">Select Penalty</div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="tablesmallText">Provide Details</div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="tablesmallText">Upload Evidence</div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="tablesmallText">Submit</div>
                    </div>
                </div>
                
                <?php if (!empty($existing_appeals)): ?>
                <div class="gridCard">
                    <h3 style="color: #214332; margin-bottom: 15px;">📋 Your Recent Appeals</h3>
                    <?php foreach($existing_appeals as $appeal): ?>
                    <div class="existing-appeal">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                            <div>
                                <div class="tableBoldText">Appeal #<?php echo $appeal['appeal_id']; ?></div>
                                <div class="tablesmallText">
                                    Submitted: <?php echo date('M d, Y', strtotime($appeal['appeal_date'])); ?>
                                    | Penalty: <?php echo htmlspecialchars($appeal['penalty_type']); ?>
                                </div>
                            </div>
                            <span class="appeal-status status-<?php echo strtolower($appeal['status']); ?>">
                                <?php echo htmlspecialchars($appeal['status']); ?>
                            </span>
                        </div>
                        <div class="tablesmallText">
                            <strong>Reason:</strong> <?php echo htmlspecialchars($appeal['violation_reason']); ?>
                        </div>
                        <?php if ($appeal['review_notes']): ?>
                        <div class="tablesmallText" style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px;">
                            <strong>Admin Response:</strong> <?php echo htmlspecialchars($appeal['review_notes']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="userViolation.php#appeals" class="smallBtn Gray">View All Appeals</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" enctype="multipart/form-data" id="appealForm">
                    <div class="gridCard">
                        <h2 style="color: #214332; margin-bottom: 20px;">Step 1: Select Penalty to Appeal</h2>
                        
                        <?php if (empty($penalties)): ?>
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <div style="font-size: 48px; margin-bottom: 20px;">✅</div>
                            <h3 style="color: #28a745;">No Penalties to Appeal</h3>
                            <p>You don't have any recent penalties eligible for appeal.</p>
                            <div style="margin-top: 20px;">
                                <a href="userViolation.php" class="smallBtn Green">View Violation History</a>
                                <a href="../vendors/vendorDashboard.php" class="smallBtn Cyan">Return to Dashboard</a>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="tablesmallText" style="margin-bottom: 15px;">
                            Select one penalty from the last 30 days to appeal:
                        </div>
                        
                        <div id="penaltySelection">
                            <?php foreach($penalties as $index => $penalty): 
                                $penalty_text = '';
                                if ($penalty['penalty_type'] === 'FINE' && $penalty['fine_amount'] > 0) {
                                    $penalty_text = 'Fine: ৳' . number_format($penalty['fine_amount'], 2);
                                } elseif ($penalty['penalty_type'] === 'SUSPENSION' && $penalty['suspension_days'] > 0) {
                                    $penalty_text = 'Suspension: ' . $penalty['suspension_days'] . ' days';
                                } else {
                                    $penalty_text = 'Warning';
                                }
                                
                                $excess_percent = $penalty['excess_percent'] ?? 0;
                                $severity_color = '#28a745';
                                if ($excess_percent > 50) $severity_color = '#dc3545';
                                elseif ($excess_percent > 25) $severity_color = '#fd7e14';
                                elseif ($excess_percent > 10) $severity_color = '#ffc107';
                            ?>
                            <div class="penalty-card" onclick="selectPenalty(<?php echo $penalty['penalty_id']; ?>)"
                                 id="penalty-<?php echo $penalty['penalty_id']; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start;">
                                    <div>
                                        <div class="tableBoldText">
                                            Penalty #<?php echo $penalty['penalty_id']; ?> - 
                                            <?php echo $penalty_text; ?>
                                        </div>
                                        <div class="tablesmallText">
                                            Date: <?php echo date('M d, Y', strtotime($penalty['violation_date'])); ?> | 
                                            Status: <span style="color: #6c757d;"><?php echo $penalty['penalty_status']; ?></span>
                                        </div>
                                        <?php if ($penalty['commodity_name']): ?>
                                        <div class="tablesmallText">
                                            Commodity: <?php echo htmlspecialchars($penalty['commodity_name']); ?> | 
                                            Your Price: ৳<?php echo number_format($penalty['your_price'] ?? 0, 2); ?> | 
                                            Cap: ৳<?php echo number_format($penalty['price_cap'] ?? 0, 2); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($excess_percent > 0): ?>
                                    <div style="text-align: right;">
                                        <div style="color: <?php echo $severity_color; ?>; font-weight: bold;">
                                            <?php echo number_format($excess_percent, 2); ?>% excess
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="tablesmallText" style="margin-top: 10px; color: #666;">
                                    <strong>Reason:</strong> <?php echo htmlspecialchars($penalty['violation_reason']); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="penalty_id" id="selected_penalty" required>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($penalties)): ?>
                    <div class="gridCard">
                        <h2 style="color: #214332; margin-bottom: 20px;">Step 2: Appeal Details</h2>
                        
                        <div class="form-group">
                            <label for="appeal_reason" class="form-label">Appeal Reason *</label>
                            <textarea id="appeal_reason" name="appeal_reason" rows="6" class="form-control" required
                                      placeholder="Please explain in detail why you believe this penalty should be reconsidered. Be specific about dates, amounts, and provide clear arguments. Minimum 20 characters."></textarea>
                            <div class="tablesmallText" style="margin-top: 5px;">
                                <span id="charCount">0</span> characters (minimum 20)
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Contact Information *</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div>
                                    <input type="email" name="contact_email" class="form-control" 
                                           placeholder="Email address" required
                                           value="<?php echo htmlspecialchars($user_email); ?>">
                                    <div class="tablesmallText">We'll send updates to this email</div>
                                </div>
                                <div>
                                    <input type="tel" name="contact_phone" class="form-control" 
                                           placeholder="Phone number" required
                                           value="<?php echo htmlspecialchars($user_phone); ?>"
                                           pattern="[0-9]{10,11}">
                                    <div class="tablesmallText">For urgent contact</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="gridCard">
                        <h2 style="color: #214332; margin-bottom: 20px;">Step 3: Supporting Evidence</h2>
                        
                        <div class="form-group">
                            <label class="form-label">Upload Supporting Documents</label>
                            <div class="file-upload-area" id="fileUploadArea" 
                                 onclick="document.getElementById('supporting_docs').click();">
                                <div style="font-size: 48px; color: #6c757d;">📎</div>
                                <div style="color: #6c757d; margin-bottom: 10px;">
                                    <strong>Click or drag files here</strong>
                                </div>
                                <div class="tablesmallText">
                                    Upload PDF, JPG, PNG, DOC, DOCX files<br>
                                    Maximum 5MB per file, 20MB total
                                </div>
                            </div>
                            
                            <input type="file" id="supporting_docs" name="supporting_docs[]" multiple 
                                   style="display: none;" 
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            
                            <div class="file-list" id="fileList" style="display: none;">
                                <div class="tablesmallText" style="margin-bottom: 10px;">Selected files:</div>
                                <div id="selectedFiles"></div>
                                <div class="tablesmallText" style="margin-top: 10px;">
                                    Total size: <span id="totalSize">0 MB</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Additional Information</label>
                            <textarea name="additional_info" rows="3" class="form-control"
                                      placeholder="Any additional information that might help your case..."></textarea>
                        </div>
                    </div>
                    
                    <div class="gridCard">
                        <h2 style="color: #214332; margin-bottom: 20px;">Step 4: Review & Submit</h2>
                        
                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" id="agree_terms" name="agree_terms" class="form-check-input" required>
                                <label for="agree_terms" class="form-check-label">
                                    <strong>I confirm that:</strong>
                                    <ul style="margin: 10px 0 10px 20px; color: #666;">
                                        <li>All information provided is accurate and truthful</li>
                                        <li>I understand that false appeals may lead to additional penalties</li>
                                        <li>I agree to the appeal process terms and conditions</li>
                                        <li>I will be contacted at the provided email and phone</li>
                                    </ul>
                                </label>
                            </div>
                        </div>
                        
                        <div style="background: #e8f5e9; padding: 15px; border-radius: 6px; margin: 20px 0;">
                            <h4 style="color: #155724; margin-top: 0;">📋 Appeal Process Information</h4>
                            <ul style="margin: 10px 0; color: #666;">
                                <li>Appeals are reviewed within <strong>3-5 business days</strong></li>
                                <li>You will receive email updates on your appeal status</li>
                                <li>Additional information may be requested during review</li>
                                <li>Approved appeals may result in penalty reduction or removal</li>
                                <li>Rejected appeals will maintain original penalties</li>
                            </ul>
                        </div>
                        
                        <div class="form-group" style="display: flex; gap: 15px; margin-top: 25px;">
                            <button type="button" class="smallBtn Gray" onclick="window.history.back()" style="flex: 1;">
                                ← Back
                            </button>
                            <button type="submit" class="smallBtn Red" style="flex: 2;" id="submitBtn">
                                <span id="submitText">Submit Appeal</span>
                                <span id="loadingSpinner" style="display: none;">⏳ Processing...</span>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
                
                <div class="gridCard">
                    <h3 style="color: #214332; margin-bottom: 15px;">📚 Appeal Guidelines</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                        <div>
                            <h4 style="color: #214332;">Valid Reasons for Appeal</h4>
                            <ul style="color: #666;">
                                <li>Incorrect price cap calculation</li>
                                <li>System error or technical glitch</li>
                                <li>Extenuating circumstances</li>
                                <li>First-time minor violation</li>
                                <li>Evidence of price cap misunderstanding</li>
                            </ul>
                        </div>
                        <div>
                            <h4 style="color: #214332;">Required Evidence</h4>
                            <ul style="color: #666;">
                                <li>Receipts or invoices</li>
                                <li>Market price comparisons</li>
                                <li>Communication records</li>
                                <li>Witness statements</li>
                                <li>Photographic evidence</li>
                            </ul>
                        </div>
                        <div>
                            <h4 style="color: #214332;">What Happens Next</h4>
                            <ul style="color: #666;">
                                <li>Review by admin team (3-5 days)</li>
                                <li>Possible additional information request</li>
                                <li>Decision notification via email</li>
                                <li>Penalty adjustment if approved</li>
                                <li>Option to re-appeal if rejected</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>Syndicate Buster Admin Panel © <?php echo date('Y'); ?></p>
        </div>
    </div>
    
    <script>
    let selectedPenaltyId = null;
    
    function selectPenalty(penaltyId) {
        selectedPenaltyId = penaltyId;
        document.getElementById('selected_penalty').value = penaltyId;
        
        document.querySelectorAll('.penalty-card').forEach(card => {
            card.classList.remove('selected');
        });
        document.getElementById('penalty-' + penaltyId).classList.add('selected');
        
        updateSteps(2);
    }
    
    document.getElementById('appeal_reason').addEventListener('input', function() {
        const charCount = this.value.length;
        document.getElementById('charCount').textContent = charCount;
        
        if (charCount >= 20) {
            document.getElementById('charCount').style.color = '#28a745';
        } else {
            document.getElementById('charCount').style.color = '#dc3545';
        }
    });
    
    const fileInput = document.getElementById('supporting_docs');
    const fileUploadArea = document.getElementById('fileUploadArea');
    const fileList = document.getElementById('fileList');
    const selectedFiles = document.getElementById('selectedFiles');
    const totalSize = document.getElementById('totalSize');
    
    let files = [];
    let totalFileSize = 0;
    
    fileUploadArea.addEventListener('click', () => fileInput.click());
    
    fileUploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileUploadArea.classList.add('dragover');
    });
    
    fileUploadArea.addEventListener('dragleave', () => {
        fileUploadArea.classList.remove('dragover');
    });
    
    fileUploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        fileUploadArea.classList.remove('dragover');
        
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            handleFiles(fileInput.files);
        }
    });
    
    fileInput.addEventListener('change', () => {
        handleFiles(fileInput.files);
    });
    
    function handleFiles(fileList) {
        files = Array.from(fileList);
        totalFileSize = 0;
        selectedFiles.innerHTML = '';
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            
            if (file.size > 5 * 1024 * 1024) {
                alert(`File "${file.name}" exceeds 5MB limit. Please upload smaller files.`);
                fileInput.value = '';
                return;
            }
            
            const allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            const fileExt = file.name.split('.').pop().toLowerCase();
            if (!allowedTypes.includes(fileExt)) {
                alert(`File "${file.name}" has unsupported format. Please upload PDF, JPG, PNG, DOC, or DOCX files.`);
                fileInput.value = '';
                return;
            }
            
            totalFileSize += file.size;
            
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            fileItem.innerHTML = `
                <span>${file.name} (${formatFileSize(file.size)})</span>
                <span class="file-remove" onclick="removeFile(${i})">×</span>
            `;
            selectedFiles.appendChild(fileItem);
        }
        
        if (totalFileSize > 20 * 1024 * 1024) {
            alert('Total file size exceeds 20MB limit. Please reduce the number or size of files.');
            fileInput.value = '';
            files = [];
            selectedFiles.innerHTML = '';
            totalFileSize = 0;
        }
        
        if (files.length > 0) {
            fileList.style.display = 'block';
            totalSize.textContent = formatFileSize(totalFileSize);
            fileUploadArea.innerHTML = `
                <div style="font-size: 48px; color: #28a745;">✓</div>
                <div style="color: #28a745; margin-bottom: 10px;">
                    <strong>${files.length} file(s) selected</strong>
                </div>
                <div class="tablesmallText">
                    Click or drag to change files
                </div>
            `;
        } else {
            fileList.style.display = 'none';
            fileUploadArea.innerHTML = `
                <div style="font-size: 48px; color: #6c757d;">📎</div>
                <div style="color: #6c757d; margin-bottom: 10px;">
                    <strong>Click or drag files here</strong>
                </div>
                <div class="tablesmallText">
                    Upload PDF, JPG, PNG, DOC, DOCX files<br>
                    Maximum 5MB per file, 20MB total
                </div>
            `;
        }
    }
    
    function removeFile(index) {
        files.splice(index, 1);
        
        const dataTransfer = new DataTransfer();
        files.forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;
        
        handleFiles(fileInput.files);
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function updateSteps(activeStep) {
        const steps = document.querySelectorAll('.step');
        steps.forEach((step, index) => {
            step.classList.remove('active', 'completed');
            if (index + 1 < activeStep) {
                step.classList.add('completed');
            } else if (index + 1 === activeStep) {
                step.classList.add('active');
            }
        });
    }
    
    document.getElementById('appealForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!selectedPenaltyId) {
            alert('Please select a penalty to appeal.');
            return;
        }
        
        const appealReason = document.getElementById('appeal_reason').value.trim();
        if (appealReason.length < 20) {
            alert('Please provide a detailed appeal reason (at least 20 characters).');
            return;
        }
        
        const contactEmail = document.querySelector('input[name="contact_email"]').value;
        const contactPhone = document.querySelector('input[name="contact_phone"]').value;
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const phoneRegex = /^[0-9]{10,11}$/;
        
        if (!emailRegex.test(contactEmail)) {
            alert('Please enter a valid email address.');
            return;
        }
        
        if (!phoneRegex.test(contactPhone)) {
            alert('Please enter a valid phone number (10-11 digits).');
            return;
        }
        
        if (!document.getElementById('agree_terms').checked) {
            alert('You must agree to the terms and conditions.');
            return;
        }
        
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const loadingSpinner = document.getElementById('loadingSpinner');
        
        submitText.style.display = 'none';
        loadingSpinner.style.display = 'inline';
        submitBtn.disabled = true;
        
        this.submit();
    });
    
    document.addEventListener('DOMContentLoaded', function() {
        const formInputs = document.querySelectorAll('#appealForm input, #appealForm textarea');
        formInputs.forEach(input => {
            input.addEventListener('input', function() {
                if (selectedPenaltyId) {
                    updateSteps(2);
                }
            });
        });
        
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                updateSteps(3);
            }
        });
        
        document.getElementById('agree_terms').addEventListener('change', function() {
            if (this.checked) {
                updateSteps(4);
            }
        });
    });
    
    setTimeout(function() {
        document.querySelectorAll('.alert-success, .alert-error').forEach(alert => {
            alert.style.display = 'none';
        });
    }, 5000);
    </script>
</body>
</html>