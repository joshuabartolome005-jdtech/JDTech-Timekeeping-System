<?php
session_start(); // Mahalaga para makuha ang employee_id mula sa session
include 'config.php';

// Proteksyon: Dapat naka-login muna
if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];
$user_role = $_SESSION['user']['role'] ?? null;

// --- LOGIC PARA SA PAG-CANCEL ---
if(isset($_POST['cancel_app'])){
    $app_id = $_POST['app_id'];

    // AUDIT: kunin muna ang detalye ng application bago i-cancel para kumpleto ang log
    $stmt_chk = $conn->prepare("SELECT ot_type, target_date, time_from, time_to FROM applications WHERE id = ? AND employee_id = ? AND type = 'OT' AND status LIKE 'Pending%'");
    $stmt_chk->bind_param("is", $app_id, $user_id);
    $stmt_chk->execute();
    $app_details = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();

    // Siguraduhin na ang ika-cancel ay Pending at pagmamay-ari ng user
    $cancel_stmt = $conn->prepare("UPDATE applications SET status = 'Cancelled' WHERE id = ? AND employee_id = ? AND status LIKE 'Pending%'");
    $cancel_stmt->bind_param("is", $app_id, $user_id);
    
    if($cancel_stmt->execute()){
        if($cancel_stmt->affected_rows > 0){
            // AUDIT: talagang na-cancel ng may-ari ang kanyang pending OT application
            $ot_info = '';
            if($app_details){
                $ot_info = "Category: " . ($app_details['ot_type'] ?? '-') . " | Date: " . $app_details['target_date'];
                if(!empty($app_details['time_from']) && !empty($app_details['time_to'])){
                    $ot_info .= " | " . $app_details['time_from'] . " to " . $app_details['time_to'];
                }
            }
            audit_log($conn, 'CANCEL', 'APPLICATION', $app_id, "Cancelled by owner | Type: OT | $ot_info", $user_id, $user_role);

            $_SESSION['ot_alert_message'] = 'Application successfully cancelled.';
            $_SESSION['ot_alert_type'] = 'success';
        } else {
            // AUDIT: walang nagbago (hindi na Pending, hindi sa kanya, o hindi umiiral)
            audit_log($conn, 'CANCEL_REJECTED', 'APPLICATION', $app_id, 'Type: OT | No row changed: not found, not owned by this user, or no longer Pending', $user_id, $user_role);

            $_SESSION['ot_alert_message'] = 'This application can no longer be cancelled. It may have already been processed.';
            $_SESSION['ot_alert_type'] = 'error';
        }
    } else {
        // AUDIT: pumalya ang pag-cancel sa database query
        audit_log($conn, 'CANCEL_FAILED', 'APPLICATION', $app_id, 'Type: OT | DB error during user cancellation: ' . $cancel_stmt->error, $user_id, $user_role);

        $_SESSION['ot_alert_message'] = 'Error cancelling application.';
        $_SESSION['ot_alert_type'] = 'error';
    }
    $cancel_stmt->close();

    // --- POST-REDIRECT-GET: para hindi mag-resubmit ang form pag nag-refresh ---
    header("Location: ot_history.php");
    exit();
}

// I-kuha ang message mula sa session (kung meron), tapos agad na i-clear
$alert_message = $_SESSION['ot_alert_message'] ?? '';
$alert_type = $_SESSION['ot_alert_type'] ?? '';
unset($_SESSION['ot_alert_message']);
unset($_SESSION['ot_alert_type']);

// 1. KUNIN ANG DATA NG USER (Para sa Sidebar)
$stmt_user = $conn->prepare("SELECT fullname, role, department, is_head FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

// 2. KUNIN ANG LAHAT NG OT APPLICATIONS WITH TIMEDIFF (Pending man o Approved/Rejected)
// Ginamit ang TIMEDIFF para makuha ang total hours ng OT direkta mula sa database
$query = "SELECT a.*, u.fullname as approver_name, TIMEDIFF(a.time_to, a.time_from) as ot_hours
          FROM applications a
          LEFT JOIN users u ON a.approver_id = u.employee_id
          WHERE a.employee_id = ? 
          AND a.type = 'OT' 
          ORDER BY a.date_filed DESC";
$stmt_ot = $conn->prepare($query);
$stmt_ot->bind_param("s", $user_id);
$stmt_ot->execute();
$result = $stmt_ot->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - OT History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-dark: #2c3e50;
            --secondary-dark: #34495e;
            --accent-orange: #f39c12;
            --text-light: #bdc3c7;
            --bg-gray: #f4f7f6;
            --ot-green: #2ecc71;
            --danger-red: #e74c3c;
        }

        body { font-family: 'Segoe UI', sans-serif; display: flex; margin: 0; background-color: var(--bg-gray); }

        .content { flex: 1; margin-left: 260px; padding: 40px; box-sizing: border-box; min-height: 100vh; }

        .container-card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 5px solid var(--ot-green); }
        h2 { color: var(--primary-dark); margin-top: 0; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }

        .history-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        .history-table th, .history-table td { border-bottom: 1px solid #eee; padding: 15px; text-align: left; vertical-align: middle; }
        .history-table th { background: #f8f9fa; color: #34495e; font-weight: bold; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        
        .badge { padding: 6px 12px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block; }
        
        /* Sequential Status Badges */
        .pending-unit { background: #fff4e5; color: #d35400; border: 1px solid #ffe5cc; }
        .pending-dept { background: #e1f5fe; color: #0288d1; border: 1px solid #b3e5fc; }
        .pending-pres { background: #f3e5f5; color: #8e24aa; border: 1px solid #e1bee7; }
        .approved { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .rejected { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .cancelled { background: #f5f5f5; color: #7f8c8d; border: 1px solid #ddd; }

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

        .time-text { color: var(--ot-green); font-weight: bold; }
        .total-hours-text { font-size: 11px; color: #7f8c8d; margin-top: 4px; display: block; }
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
            <h2>My Overtime (OT) History</h2>

            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date of OT</th>
                        <th>Est. Time / Duration</th>
                        <th>Task / Reason</th>
                        <th>Current Status</th>
                        <th>Last Action By</th>
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
                                    <span class="time-text">
                                        <?php echo (!empty($row['estimated_time'])) ? date('h:i A', strtotime($row['estimated_time'])) : '---'; ?>
                                    </span>
                                    <?php if(!empty($row['ot_hours'])): ?>
                                        <span class="total-hours-text">
                                            <i class="fa-regular fa-clock"></i> 
                                            <strong>
                                                <?php 
                                                    // Hatiin ang hh:mm:ss mula sa TIMEDIFF ng database
                                                    $time_parts = explode(':', $row['ot_hours']);
                                                    $hours = (int)$time_parts[0];
                                                    $minutes = (int)$time_parts[1];

                                                    if ($hours === 0) {
                                                        // Kapag wala pang isang oras (00), ipakita lang ay minuto at "mins"
                                                        echo $minutes . ' mins';
                                                    } else {
                                                        // Kapag may oras, panatilihin ang standard H:i format na may "hrs"
                                                        echo date('H:i', strtotime($row['ot_hours'])) . ' hrs'; 
                                                    }
                                                ?>
                                            </strong>
                                        </span>
                                    <?php endif; ?>
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
                                <td>
                                    <?php if(strpos($status, 'Pending') !== false): ?>
                                        <form method="POST" class="cancel-form">
                                            <input type="hidden" name="app_id" value="<?php echo $app_id; ?>">
                                            <input type="hidden" name="cancel_app" value="1">
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
                            <td colspan="6" style="padding: 60px; text-align: center; color: #95a5a6;">
                                <i class="fa-solid fa-folder-open" style="font-size: 40px; opacity: 0.2; margin-bottom: 15px;"></i><br>
                                You don't have any OT history records yet.
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
                title: 'Cancel OT Application?',
                text: "Are you sure you want to cancel this OT application?",
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
        confirmButtonColor: '#2ecc71',
        confirmButtonText: 'OK'
    });
    <?php endif; ?>
    </script>

</body>
</html>