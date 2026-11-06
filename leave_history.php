<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}
$user_id = $_SESSION['user']['employee_id'];
$user_role = $_SESSION['user']['role'] ?? null;

if(isset($_POST['cancel_leave'])){
    $app_id = $_POST['app_id'];

    $stmt_chk = $conn->prepare("SELECT type, target_date, end_date FROM applications WHERE id = ? AND employee_id = ? AND status LIKE 'Pending%'");
    $stmt_chk->bind_param("is", $app_id, $user_id);
    $stmt_chk->execute();
    $app_details = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();

    $cancel_stmt = $conn->prepare("UPDATE applications SET status = 'Cancelled' WHERE id = ? AND employee_id = ? AND status LIKE 'Pending%'");
    $cancel_stmt->bind_param("is", $app_id, $user_id);
    
    if($cancel_stmt->execute()){
        if($cancel_stmt->affected_rows > 0){
            $leave_type_info = $app_details['type'] ?? 'Leave';
            $dates_info = '';
            if($app_details){
                $dates_info = $app_details['target_date'];
                if(!empty($app_details['end_date']) && $app_details['end_date'] != $app_details['target_date']){
                    $dates_info .= " to " . $app_details['end_date'];
                }
            }
            audit_log($conn, 'CANCEL', 'APPLICATION', $app_id, "Cancelled by owner | Type: $leave_type_info | Dates: $dates_info", $user_id, $user_role);

            $_SESSION['leave_alert_message'] = 'Leave application successfully cancelled.';
            $_SESSION['leave_alert_type'] = 'success';
        } else {

            audit_log($conn, 'CANCEL_REJECTED', 'APPLICATION', $app_id, 'No row changed: not found, not owned by this user, or no longer Pending', $user_id, $user_role);

            $_SESSION['leave_alert_message'] = 'This application can no longer be cancelled. It may have already been processed.';
            $_SESSION['leave_alert_type'] = 'error';
        }
    } else {

        audit_log($conn, 'CANCEL_FAILED', 'APPLICATION', $app_id, 'DB error during user cancellation: ' . $cancel_stmt->error, $user_id, $user_role);

        $_SESSION['leave_alert_message'] = 'Error cancelling application.';
        $_SESSION['leave_alert_type'] = 'error';
    }
    $cancel_stmt->close();

    header("Location: leave_history.php");
    exit();
}

$alert_message = $_SESSION['leave_alert_message'] ?? '';
$alert_type = $_SESSION['leave_alert_type'] ?? '';
unset($_SESSION['leave_alert_message']);
unset($_SESSION['leave_alert_type']);

$stmt_user = $conn->prepare("SELECT fullname, role, department, is_head, annual_leave, mandatory_leave FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

$query = "SELECT a.*, u.fullname as approver_name, TIMEDIFF(a.time_to, a.time_from) as undertime_hours
          FROM applications a
          LEFT JOIN users u ON a.approver_id = u.employee_id
          WHERE a.employee_id = ? 
          AND a.type IN ('Annual Leave', 'Mandatory Leave', 'Maternity Leave', 'Paternity Leave', 'Undertime','Solo Parent Leave', 'Excused Tardiness', 'Authorized Leave without Pay') 
          ORDER BY a.date_filed DESC";
$stmt_leaves = $conn->prepare($query);
$stmt_leaves->bind_param("s", $user_id);
$stmt_leaves->execute();
$result = $stmt_leaves->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Leave History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-dark: #2c3e50;
            --secondary-dark: #34495e;
            --accent-orange: #f39c12;
            --text-light: #bdc3c7;
            --bg-gray: #f4f7f6;
            --primary-blue: #4318ff;
            --danger-red: #e74c3c;
        }

        body { font-family: 'DM Sans', 'Segoe UI', sans-serif; display: flex; margin: 0; background-color: var(--bg-gray); }
        .content { flex: 1; padding: 40px; box-sizing: border-box; min-height: 100vh; margin-left:260px }
        .container-card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); max-width: 1150px; margin: 0 auto; }
        
        h2 { color: var(--primary-dark); margin-top: 0; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }

        .balance-container { display: flex; gap: 20px; margin-bottom: 30px; }
        .balance-card { flex: 1; padding: 20px; border-radius: 10px; color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .balance-card h4 { margin: 0; font-size: 11px; text-transform: uppercase; opacity: 0.9; letter-spacing: 1px; }
        .balance-card .count { font-size: 32px; font-weight: bold; margin-top: 5px; display: block; }
        .bg-annual { background: linear-gradient(135deg, #3498db, #2980b9); }
        .bg-mandatory { background: linear-gradient(135deg, #9b59b6, #8e44ad); }

        .history-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        .history-table th, .history-table td { border-bottom: 1px solid #eee; padding: 15px; text-align: left; }
        .history-table th { background: #f8f9fa; color: #34495e; font-weight: bold; text-transform: uppercase; font-size: 11px; }
        
        .badge { padding: 6px 12px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block; white-space: nowrap; }
        .pending-unit { background: #fff4e5; color: #d35400; border: 1px solid #ffe5cc; }
        .pending-dept { background: #e1f5fe; color: #0288d1; border: 1px solid #b3e5fc; }
        .pending-group { background: #ede7f6; color: #5e35b1; border: 1px solid #d1c4e9; }
        .pending-pres { background: #e0f2f1; color: #00796b; border: 1px solid #b2dfdb; }
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
            display: block; 
            margin-top: 8px; 
            font-size: 11.5px; 
            color: var(--danger-red); 
            line-height: 1.4;
            max-width: 180px;
            word-wrap: break-word;
        }
        .reason-label { font-weight: bold; text-transform: uppercase; font-size: 9px; opacity: 0.8; display: block; }

        .approver-info { font-size: 13px; }
        .approver-name { font-weight: bold; color: var(--secondary-dark); display: block; }
        .approval-time { font-size: 11px; color: #95a5a6; }
        .date-filed { color: #7f8c8d; font-size: 12px; }
        
        .type-tag { font-size: 11px; padding: 4px 8px; border-radius: 4px; background: #f0f2f5; color: #475467; font-weight: 700; text-transform: uppercase; border-left: 3px solid var(--primary-blue); }
        .total-hours-text { font-size: 11px; color: #2c3e50; font-weight: bold; margin-top: 4px; display: block; }
        .reason-text { font-size: 13px; color: #555; max-width: 180px; word-wrap: break-word; display: block; }
        @media (max-width: 768px) {
            .content { margin-left: 0 !important; padding: 20px !important; padding-top: 70px !important; }
            .balance-container { flex-direction: column; }
            .container-card { padding: 15px; overflow-x: auto; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="container-card">
            <h2><i class="fa-solid fa-calendar-check" style="color: var(--primary-blue)"></i> Leave History</h2>

            <div class="balance-container">
                <div class="balance-card bg-annual">
                    <h4>Annual Leave Balance</h4>
                    <span class="count"><?php echo number_format($user_data['annual_leave'], 1); ?></span>
                </div>
                <div class="balance-card bg-mandatory">
                    <h4>Mandatory Leave Balance</h4>
                    <span class="count"><?php echo number_format($user_data['mandatory_leave'], 1); ?></span>
                </div>
            </div>

            <table class="history-table">
                <thead>
                    <tr>
                        <th>Leave Details</th>
                        <th>Inclusive Dates</th>
                        <th>Duration</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Action By</th>
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
                                $badge_class = 'rejected'; 
                                
                                if (strpos($status, 'Pending') !== false) {
                                    if (strpos($status, 'Unit Head') !== false) $badge_class = 'pending-unit';
                                    elseif (strpos($status, 'Dept. Head') !== false) $badge_class = 'pending-dept';
                                    elseif (strpos($status, 'Group Head') !== false) $badge_class = 'pending-group';
                                    elseif (strpos($status, 'President') !== false) $badge_class = 'pending-pres';
                                }
                                elseif ($status == 'Approved') $badge_class = 'approved';
                                elseif ($status == 'Cancelled') $badge_class = 'cancelled';
                            ?>
                            <tr>
                                <td>
                                    <div class="type-tag"><?php echo htmlspecialchars($row['type']); ?></div>
                                    <div style="margin-top: 5px;">
                                        <small style="color:#a3aed0">Ref: #<?php echo $row['id']; ?></small>
                                    </div>
                                </td>
                                <td>
                                    <strong>
                                    <?php 
                                        echo date('M d, Y', strtotime($row['target_date'])); 
                                        if($row['end_date'] != $row['target_date']) {
                                            echo " - " . date('M d, Y', strtotime($row['end_date']));
                                        }
                                    ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['duration']); ?>
                                    <?php if(!empty($row['time_from'])): ?>
                                        <br><small style="color:#95a5a6"><?php echo date('h:i A', strtotime($row['time_from'])) . ' - ' . date('h:i A', strtotime($row['time_to'])); ?></small>
                                    <?php endif; ?>
                                    
                                    <?php  ?>
                                    <?php if(($row['type'] == 'Undertime' || $row['type'] == 'Excused Tardiness') && !empty($row['undertime_hours'])): ?>
                                        <span class="total-hours-text">
                                            <i class="fa-regular fa-clock"></i> 
                                            <?php 
                                                $time_parts = explode(':', $row['undertime_hours']);
                                                $hours = (int)$time_parts[0];
                                                $minutes = (int)$time_parts[1];

                                                if ($hours === 0) {
                                                    echo $minutes . ' mins';
                                                } else {
                                                    echo date('H:i', strtotime($row['undertime_hours'])) . ' hrs';
                                                }
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="reason-text"><?php echo htmlspecialchars($row['details'] ?? ''); ?></span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>

                                    <?php if($status == 'Rejected' && !empty($row['reject_reason'])): ?>
                                        <div class="reason-box">
                                            <span class="reason-label">Reason for Rejection:</span>
                                            <?php echo htmlspecialchars($row['reject_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="approver-info">
                                    <?php 
                                    if($status == 'Approved' && $user_data['role'] == 'President'): ?>
                                        <span class="approver-name" style="color: var(--primary-blue);">SYSTEM AUTO-APPROVED</span>
                                        <span class="approval-time">Self-filed Application</span>
                                    <?php elseif(!empty($row['approver_name'])): ?>
                                        <span class="approver-name"><?php echo htmlspecialchars($row['approver_name']); ?></span>
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
                                            <input type="hidden" name="cancel_leave" value="1">
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
                            <td colspan="8" style="padding: 60px; text-align: center; color: #95a5a6;">
                                <i class="fa-solid fa-folder-open" style="font-size: 40px; opacity: 0.2; margin-bottom: 15px;"></i><br>
                                No leave applications found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.querySelectorAll('.cancel-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const currentForm = this;

            Swal.fire({
                title: 'Cancel Leave Application?',
                text: "Are you sure you want to cancel this leave application?",
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
    Swal.fire({
        icon: '<?php echo ($alert_type === "success") ? "success" : "error"; ?>',
        title: '<?php echo ($alert_type === "success") ? "Success!" : "Oops!"; ?>',
        text: '<?php echo addslashes($alert_message); ?>',
        confirmButtonColor: '#4318ff',
        confirmButtonText: 'OK'
    });
    <?php endif; ?>
    </script>

</body>
</html>