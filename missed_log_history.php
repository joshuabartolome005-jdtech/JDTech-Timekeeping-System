<?php
session_start(); 
include 'config.php';

// Proteksyon: Dapat naka-login muna
if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];
$user_role = $_SESSION['user']['role'] ?? null;

// --- LOGIC PARA SA PAG-CANCEL ---
if(isset($_POST['cancel_ml'])){
    $app_id = $_POST['app_id'];

    // AUDIT: kunin muna ang detalye ng application bago i-cancel para kumpleto ang log
    $stmt_chk = $conn->prepare("SELECT log_missed_type, target_date, estimated_time FROM applications WHERE id = ? AND employee_id = ? AND type = 'MissedLog' AND status LIKE 'Pending%'");
    $stmt_chk->bind_param("is", $app_id, $user_id);
    $stmt_chk->execute();
    $app_details = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();

    // Siguraduhin na ang ika-cancel ay Pending at pagmamay-ari ng user
    $cancel_stmt = $conn->prepare("UPDATE applications SET status = 'Cancelled' WHERE id = ? AND employee_id = ? AND status LIKE 'Pending%'");
    $cancel_stmt->bind_param("is", $app_id, $user_id);
    
    if($cancel_stmt->execute()){
        if($cancel_stmt->affected_rows > 0){
            // AUDIT: talagang na-cancel ng may-ari ang kanyang pending Missed Log application
            $ml_info = '';
            if($app_details){
                $ml_info = "Log type: " . ($app_details['log_missed_type'] ?? '-') . " | Date: " . $app_details['target_date'];
                if(!empty($app_details['estimated_time'])){
                    $ml_info .= " | Time: " . $app_details['estimated_time'];
                }
            }
            audit_log($conn, 'CANCEL', 'APPLICATION', $app_id, "Cancelled by owner | Type: MissedLog | $ml_info", $user_id, $user_role);

            $_SESSION['ml_alert_message'] = 'Missed Log application successfully cancelled.';
            $_SESSION['ml_alert_type'] = 'success';
        } else {
            // AUDIT: walang nagbago (hindi na Pending, hindi sa kanya, o hindi umiiral)
            audit_log($conn, 'CANCEL_REJECTED', 'APPLICATION', $app_id, 'Type: MissedLog | No row changed: not found, not owned by this user, or no longer Pending', $user_id, $user_role);

            $_SESSION['ml_alert_message'] = 'This application can no longer be cancelled. It may have already been processed.';
            $_SESSION['ml_alert_type'] = 'error';
        }
    } else {
        // AUDIT: pumalya ang pag-cancel sa database query
        audit_log($conn, 'CANCEL_FAILED', 'APPLICATION', $app_id, 'Type: MissedLog | DB error during user cancellation: ' . $cancel_stmt->error, $user_id, $user_role);

        $_SESSION['ml_alert_message'] = 'Error cancelling application.';
        $_SESSION['ml_alert_type'] = 'error';
    }
    $cancel_stmt->close();

    // --- POST-REDIRECT-GET: para hindi mag-resubmit ang form pag nag-refresh ---
    header("Location: missed_log_history.php");
    exit();
}

// I-kuha ang message mula sa session (kung meron), tapos agad na i-clear
$alert_message = $_SESSION['ml_alert_message'] ?? '';
$alert_type = $_SESSION['ml_alert_type'] ?? '';
unset($_SESSION['ml_alert_message']);
unset($_SESSION['ml_alert_type']);

// 1. KUNIN ANG DATA NG USER (Para sa Sidebar)
$stmt_user = $conn->prepare("SELECT fullname, role, department, is_head FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

// 2. KUNIN ANG LAHAT NG MISSED LOG APPLICATIONS
$query = "SELECT a.*, u.fullname as approver_name 
          FROM applications a
          LEFT JOIN users u ON a.approver_id = u.employee_id
          WHERE a.employee_id = ? 
          AND a.type = 'MissedLog' 
          ORDER BY a.date_filed DESC";
$stmt_ml = $conn->prepare($query);
$stmt_ml->bind_param("s", $user_id);
$stmt_ml->execute();
$result = $stmt_ml->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Missed Log History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-dark: #2c3e50;
            --secondary-dark: #34495e;
            --accent-orange: #f39c12;
            --text-light: #bdc3c7;
            --bg-gray: #f4f7f6;
            --ml-blue: #3498db;
            --danger-red: #e74c3c;
        }

        body { font-family: 'Segoe UI', sans-serif; display: flex; margin: 0; background-color: var(--bg-gray); }

        .content { flex: 1; margin-left: 260px; padding: 40px; box-sizing: border-box; min-height: 100vh; }

        .container-card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 5px solid var(--ml-blue); }
        h2 { color: var(--primary-dark); margin-top: 0; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }

        .history-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        .history-table th, .history-table td { border-bottom: 1px solid #eee; padding: 15px; text-align: left; vertical-align: middle; }
        .history-table th { background: #f8f9fa; color: #34495e; font-weight: bold; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        
        .badge { padding: 6px 12px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block; }
        
        .pending-unit { background: #fff4e5; color: #d35400; border: 1px solid #ffe5cc; }
        .pending-dept { background: #e1f5fe; color: #0288d1; border: 1px solid #b3e5fc; }
        .pending-pres { background: #f3e5f5; color: #8e24aa; border: 1px solid #e1bee7; }
        .approved { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .rejected { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .cancelled { background: #f5f5f5; color: #7f8c8d; border: 1px solid #ddd; }

        /* Cancel Button Style */
        .btn-cancel {
            background-color: var(--danger-red);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            font-weight: bold;
            transition: 0.3s;
            text-transform: uppercase;
        }
        .btn-cancel:hover { background-color: #c0392b; transform: scale(1.05); }

        .reason-box { 
            margin-top: 8px; 
            padding: 8px; 
            background: #fffafa; 
            border-left: 3px solid #e74c3c; 
            font-size: 11px; 
            color: #c0392b; 
            max-width: 200px;
            font-style: italic;
        }

        .time-text { color: var(--ml-blue); font-weight: bold; }
        .log-type-tag { font-size: 11px; padding: 2px 6px; background: #eee; border-radius: 4px; color: #555; font-weight: bold; }
        .approver-info { font-size: 13px; line-height: 1.4; }
        .approver-name { font-weight: bold; color: var(--secondary-dark); display: block; }
        .approval-time { font-size: 11px; color: #95a5a6; display: block; }
        .date-filed { color: #7f8c8d; font-size: 12px; }

        /* --- RESPONSIVE FIX: alisin ang fixed margin-left sa mobile --- */
        @media (max-width: 768px) {
            .content { margin-left: 0 !important; padding: 20px !important; padding-top: 70px !important; }
            .container-card { padding: 15px; overflow-x: auto; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="container-card">
            <h2>My Missed Log History</h2>

            <table class="history-table">
                <thead>
                    <tr>
                        <th>Target Date</th>
                        <th>Type / Time</th>
                        <th>Reason / Details</th>
                        <th>Current Status</th>
                        <th>Last Action By</th>
                        <th>Date Filed</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <?php 
                                $status = $row['status'];
                                $app_id = $row['id'];
                                
                                switch ($status) {
                                    case 'Pending Unit Head': $badge_class = 'pending-unit'; break;
                                    case 'Pending Dept. Head': $badge_class = 'pending-dept'; break;
                                    case 'Pending President': $badge_class = 'pending-pres'; break;
                                    case 'Approved': $badge_class = 'approved'; break;
                                    case 'Rejected': $badge_class = 'rejected'; break;
                                    case 'Cancelled': $badge_class = 'cancelled'; break;
                                    default: $badge_class = 'pending-unit';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo date('M d, Y', strtotime($row['target_date'])); ?></strong>
                                    <br><small style="color:#999;"><?php echo date('l', strtotime($row['target_date'])); ?></small>
                                </td>
                                <td>
                                    <span class="log-type-tag"><?php echo $row['log_missed_type']; ?></span>
                                    <br>
                                    <span class="time-text">
                                        <?php echo (!empty($row['estimated_time'])) ? date('h:i A', strtotime($row['estimated_time'])) : '---'; ?>
                                    </span>
                                </td>
                                <td style="max-width: 250px;">
                                    <small style="color: #555;"><?php echo htmlspecialchars($row['details']); ?></small>
                                </td>
                                <td>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo $status; ?>
                                    </span>
                                    
                                    <?php if($status == 'Rejected' && !empty($row['reject_reason'])): ?>
                                        <div class="reason-box">
                                            <strong>Reject Reason:</strong><br>
                                            <?php echo htmlspecialchars($row['reject_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="approver-info">
                                    <?php if(!empty($row['approver_name'])): ?>
                                        <span class="approver-name"><?php echo $row['approver_name']; ?></span>
                                        <span class="approval-time">
                                            <?php echo date('M d, Y | h:i A', strtotime($row['approved_at'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <i style="color: #bdc3c7; font-size: 12px;">Waiting for Review</i>
                                    <?php endif; ?>
                                </td>
                                <td class="date-filed">
                                    <?php echo date('M d, Y', strtotime($row['date_filed'])); ?>
                                </td>
                                <td>
                                    <?php if(strpos($status, 'Pending') !== false): ?>
                                        <form method="POST" class="cancel-form">
                                            <input type="hidden" name="app_id" value="<?php echo $app_id; ?>">
                                            <input type="hidden" name="cancel_ml" value="1">
                                            <button type="submit" class="btn-cancel">
                                                <i class="fa-solid fa-xmark"></i> Cancel
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #bdc3c7; font-size: 11px;">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="padding: 60px; text-align: center; color: #95a5a6;">
                                 <i class="fa-solid fa-folder-open" style="font-size: 40px; opacity: 0.2; margin-bottom: 15px;"></i><br>
                                You don't have any Missed Log history records yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    // --- SweetAlert2 confirmation bago mag-submit ang Cancel form ---
    document.querySelectorAll('.cancel-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const currentForm = this;

            Swal.fire({
                title: 'Cancel Missed Log Application?',
                text: "Are you sure you want to cancel this missed log application?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#95a5a6',
                confirmButtonText: 'Yes, cancel it!',
                cancelButtonText: 'No, go back'
            }).then((result) => {
                if (result.isConfirmed) {
                    currentForm.submit();
                }
            });
        });
    });

    <?php if(!empty($alert_message)): ?>
    // --- SweetAlert2 popup para sa resulta ng cancel action ---
    Swal.fire({
        icon: '<?php echo ($alert_type === "success") ? "success" : "error"; ?>',
        title: '<?php echo ($alert_type === "success") ? "Success!" : "Oops!"; ?>',
        text: '<?php echo addslashes($alert_message); ?>',
        confirmButtonColor: '#3498db',
        confirmButtonText: 'OK'
    });
    <?php endif; ?>
    </script>

</body>
</html>