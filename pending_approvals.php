<?php
session_start();
include 'config.php';

// Proteksyon: Dapat naka-login muna
if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];

// 1. KUNIN ANG DATA NG APPROVER
$stmt_user = $conn->prepare("SELECT fullname, role, department, group_department, is_head FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

// Security: Kung hindi head o special role, kick out
$allowed_roles = ['UnitHead', 'DeptHead', 'GroupHead', 'President', 'HR', 'Admin', 'CBD'];
if(!$user_data || (!in_array($user_data['role'], $allowed_roles) && $user_data['is_head'] != 1)) {
    header("Location: dashboard.php");
    exit();
}

$dept = $user_data['department'];
$user_role = $user_data['role']; 
$group_dept = $user_data['group_department'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Pending Approvals</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-light: #f4f7fe;
            --primary-blue: #4318ff;
            --text-dark: #1b254b;
            --text-muted: #a3aed0;
            --white: #ffffff;
            --shadow: 14px 17px 40px 4px rgba(112, 144, 176, 0.08);
            --success-green: #05cd99;
            --danger-red: #ee5d50;
            --warning-orange: #ffb547;
            --accent-orange: #f39c12;
        }

        body { margin: 0; padding: 0; font-family: 'DM Sans', sans-serif; background-color: var(--bg-light); display: flex; }
        .content { flex: 1; padding: 30px; box-sizing: border-box; min-height: 100vh; margin-left: 260px; }
        .container-card { background: var(--white); padding: 30px; border-radius: 20px; box-shadow: var(--shadow); }
        h2 { color: var(--text-dark); margin-top: 0; display: flex; align-items: center; gap: 12px; font-size: 24px; }
        .role-badge { background: #e9edf7; color: var(--primary-blue); padding: 6px 15px; border-radius: 10px; font-size: 13px; font-weight: 700; }
        .approval-table { width: 100%; border-collapse: collapse; margin-top: 25px; }
        .approval-table th { text-align: left; padding: 15px; color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #e9edf7; }
        .approval-table td { padding: 15px; border-bottom: 1px solid #e9edf7; vertical-align: middle; }
        .emp-name { font-weight: 700; color: var(--text-dark); display: block; font-size: 15px; }
        .type-tag { font-size: 11px; color: var(--primary-blue); background: #f4f7fe; padding: 4px 10px; border-radius: 6px; font-weight: 700; }
        .status-badge { font-size: 11px; padding: 5px 10px; border-radius: 8px; font-weight: 700; display: inline-block; margin-top: 5px; }
        .status-unit { background: #fff8e1; color: #ff8f00; }
        .status-dept { background: #e6fffa; color: #047857; }
        .status-group { background: #fff5f5; color: #c53030; }
        .status-president { background: #ebf4ff; color: #2b6cb0; }
        .btn-action { padding: 8px 16px; border-radius: 10px; text-decoration: none; font-size: 13px; font-weight: 700; transition: 0.3s; display: inline-block; cursor: pointer; border: none; }
        .btn-approve { background: var(--success-green); color: white; box-shadow: 0px 4px 12px rgba(5, 205, 153, 0.2); }
        .btn-approve:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .btn-reject { background: #fff5f5; color: var(--danger-red); margin-left: 5px; }
        .btn-reject:hover { background: var(--danger-red); color: white; }
        .view-link { color: var(--accent-orange); text-decoration: none; font-weight: 700; font-size: 13px; display: flex; align-items: center; gap: 5px; }
        .view-link:hover { text-decoration: underline; }
        .empty-state { padding: 60px; color: var(--text-muted); text-align: center; }
        .time-badge { font-size: 12px; color: #e67e22; font-weight: 700; background: #fff4e6; padding: 2px 8px; border-radius: 5px; display: inline-block; margin-top: 4px; }
        
        @media (max-width: 1024px) { .content { margin-left: 0; padding: 20px; } .approval-table { display: block; overflow-x: auto; } }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="container-card">
            <h2>
                <i class="fa-solid fa-clipboard-check" style="color: var(--primary-blue);"></i>
                Pending Approvals 
                <span class="role-badge"><?php echo (in_array($user_role, ['HR', 'Admin', 'CBD'])) ? "Administrator / Compliance" : "$user_role: $dept"; ?></span>
            </h2>

            <table class="approval-table">
                <thead>
                    <tr>
                        <th>Employee Details</th>
                        <th>Application Type</th>
                        <th>Schedule / Inclusive Dates</th>
                        <th>Reason / Proof</th> 
                        <th style="text-align: center;">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // QUERY LOGIC - Tugma dapat sa sidebar.php Badge Logic
                    if(in_array($user_role, ['HR', 'Admin', 'CBD'])) {
                        $sql = "SELECT a.*, u.fullname, u.department FROM applications a JOIN users u ON a.employee_id = u.employee_id WHERE (a.status LIKE 'Pending%') AND a.employee_id != ? ORDER BY a.date_filed ASC";
                        $stmt_list = $conn->prepare($sql);
                        $stmt_list->bind_param("s", $user_id);
                    } elseif($user_role == 'President') {
                        $sql = "SELECT a.*, u.fullname, u.department 
                                FROM applications a 
                                JOIN users u ON a.employee_id = u.employee_id 
                                INNER JOIN president_assignments pa ON u.employee_id = pa.group_head_id 
                                WHERE pa.president_id = ? AND a.status = 'Pending President' AND a.employee_id != ? 
                                ORDER BY a.date_filed ASC";
                        $stmt_list = $conn->prepare($sql);
                        $stmt_list->bind_param("ss", $user_id, $user_id);
                    } elseif($user_role == 'GroupHead') {
                        $sql = "SELECT a.*, u.fullname, u.department FROM applications a JOIN users u ON a.employee_id = u.employee_id INNER JOIN group_head_assignments gha ON u.group_department = gha.department_name WHERE gha.group_head_id = ? AND a.status = 'Pending Group Head' AND a.employee_id != ? ORDER BY a.date_filed ASC";
                        $stmt_list = $conn->prepare($sql);
                        $stmt_list->bind_param("ss", $user_id, $user_id);
                    } elseif($user_role == 'DeptHead') {
                        $sql = "SELECT a.*, u.fullname, u.department FROM applications a JOIN users u ON a.employee_id = u.employee_id INNER JOIN dept_head_assignments dha ON u.department = dha.unit_name WHERE dha.dept_head_id = ? AND a.status = 'Pending Dept. Head' AND a.employee_id != ? ORDER BY a.date_filed ASC";
                        $stmt_list = $conn->prepare($sql);
                        $stmt_list->bind_param("ss", $user_id, $user_id);
                    } elseif($user_role == 'UnitHead' || $user_data['is_head'] == 1) {
                        $sql = "SELECT a.*, u.fullname, u.department FROM applications a JOIN users u ON a.employee_id = u.employee_id WHERE u.department = ? AND a.status = 'Pending Unit Head' AND a.employee_id != ? ORDER BY a.date_filed ASC";
                        $stmt_list = $conn->prepare($sql);
                        $stmt_list->bind_param("ss", $dept, $user_id);
                    }
                    
                    if (isset($stmt_list)) {
                        $stmt_list->execute();
                        $res = $stmt_list->get_result();

                        if($res->num_rows > 0){
                            while($row = $res->fetch_assoc()){
                                $status_clean = $row['status'];
                                $status_class = '';
                                if ($status_clean == 'Pending Unit Head') $status_class = 'status-unit';
                                elseif ($status_clean == 'Pending Dept. Head') $status_class = 'status-dept';
                                elseif ($status_clean == 'Pending Group Head') $status_class = 'status-group';
                                elseif ($status_clean == 'Pending President') $status_class = 'status-president';
                                ?>
                                <tr>
                                    <td>
                                        <span class="emp-name"><?php echo htmlspecialchars($row['fullname']); ?></span>
                                        <small style="color: var(--text-muted);">
                                            ID: <?php echo htmlspecialchars($row['employee_id']); ?> 
                                            <?php if(isset($row['department'])) echo " | " . htmlspecialchars($row['department']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="type-tag"><?php echo htmlspecialchars($row['type']); ?></span><br>
                                        <span class="status-badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: var(--text-dark);">
                                            <i class="fa-regular fa-calendar-check"></i> 
                                            <?php 
                                                echo date('M d, Y', strtotime($row['target_date'])); 
                                                if(!empty($row['end_date']) && $row['end_date'] != $row['target_date']) {
                                                    echo " - " . date('M d, Y', strtotime($row['end_date']));
                                                }
                                            ?>
                                        </div>
                                        
                                        <?php if(!empty($row['estimated_time']) &&$row['estimated_time'] != '00:00:00'): ?>
                                            <div class="time-badge">
                                                <i class="fa-solid fa-clock"></i> 
                                                Punch: <?php echo date('h:i A', strtotime($row['estimated_time'])); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if(!empty($row['time_from']) &&$row['time_from'] != '00:00:00'): ?>
                                            <div style="font-size: 12px; color: var(--primary-blue); font-weight: 500; margin-top: 2px;">
                                                <i class="fa-regular fa-clock"></i> 
                                                <?php echo date('h:i A', strtotime($row['time_from'])) . " - " . date('h:i A', strtotime($row['time_to'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="max-width: 300px;">
                                        <div style="font-size: 13px; color: var(--text-dark); line-height: 1.5;">
                                            <?php 
                                            if($row['type'] == 'OB') {
                                                echo "<strong><i class='fa-solid fa-location-dot'></i> Destination:</strong> " . htmlspecialchars($row['destination']) . "<br>";
                                                
                                                // Pagpapakita ng Departure at Arrival Time para sa OB
                                                if(!empty($row['departure_time']) &&$row['departure_time'] != '00:00:00') {
                                                    echo "<strong><i class='fa-solid fa-plane-departure'></i> Departure:</strong> " . date('h:i A', strtotime($row['departure_time'])) . "<br>";
                                                }
                                                if(!empty($row['arrival_time']) &&$row['arrival_time'] != '00:00:00') {
                                                    echo "<strong><i class='fa-solid fa-plane-arrival'></i> Arrival:</strong> " . date('h:i A', strtotime($row['arrival_time'])) . "<br>";
                                                }
                                                
                                                echo "<strong>Details:</strong> <em>" . htmlspecialchars($row['details']) . "</em>";
                                                
                                                // Pagpapakita ng OB Attachment Link
                                                if (!empty($row['attachment'])) {
                                                    $ob_folder = file_exists('ob_attachment/' .$row['attachment']) ? 'ob_attachment/' : 'missed_logs_attachment/';
                                                    echo "<div style='margin-top: 8px;'>";
                                                    echo "<a href='" . $ob_folder .$row['attachment'] . "' target='_blank' class='view-link'>";
                                                    echo "<i class='fa-solid fa-image'></i> View Attached Proof</a>";
                                                    echo "</div>";
                                                }
                                                
                                            } elseif($row['type'] == 'MissedLog') {
                                                echo "<strong>Log:</strong> " . htmlspecialchars($row['log_missed_type']) . "<br>";
                                                
                                                // Pagpapakita ng log time kung sakaling sa field na ito naka-store
                                                if(!empty($row['estimated_time']) &&$row['estimated_time'] != '00:00:00') {
                                                    echo "<strong>Log Time:</strong> " . date('h:i A', strtotime($row['estimated_time'])) . "<br>";
                                                }
                                                
                                                echo "<em>" . htmlspecialchars($row['details']) . "</em>";
                                                
                                                if (!empty($row['attachment'])) {
                                                    echo "<div style='margin-top: 8px;'>";
                                                    echo "<a href='missed_logs_attachment/" . $row['attachment'] . "' target='_blank' class='view-link'>";
                                                    echo "<i class='fa-solid fa-image'></i> View Attached Proof</a>";
                                                    echo "</div>";
                                                }
                                            } elseif($row['type'] == 'OT') {
                                                echo "<strong>Type:</strong> " . htmlspecialchars($row['ot_type']) . "<br>";
                                                
                                                // Isinama ang Time From at Time To para sa Overtime block
                                                if(!empty($row['time_from']) &&$row['time_from'] != '00:00:00') {
                                                    echo "<strong>OT Period:</strong> " . date('h:i A', strtotime($row['time_from'])) . " - " . date('h:i A', strtotime($row['time_to'])) . "<br>";
                                                }
                                                
                                                echo "<em>" . htmlspecialchars($row['details']) . "</em>";
                                            } else {
                                                // Para sa Leaves o iba pang Type, ipapakita ang Time From/To kung meron
                                                if(!empty($row['time_from']) &&$row['time_from'] != '00:00:00') {
                                                    echo "<strong>Duration:</strong> " . date('h:i A', strtotime($row['time_from'])) . " - " . date('h:i A', strtotime($row['time_to'])) . "<br>";
                                                }
                                                echo "<strong>Details:</strong> " . htmlspecialchars($row['details']);
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <button type="button" class="btn-action btn-approve" onclick="approveWithConfirm(<?php echo $row['id']; ?>)">Approve</button>
                                        
                                        <button type="button" class="btn-action btn-reject" onclick="rejectWithReason(<?php echo $row['id']; ?>)">Reject</button>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo "<tr><td colspan='5' class='empty-state'>
                                    No pending applications for your review.
                                  </td></tr>";
                        }
                        $stmt_list->close();
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function approveWithConfirm(appId) {
        Swal.fire({
            title: 'Approve Application?',
            text: "Employee will be notified via email.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#05cd99',
            cancelButtonColor: '#a3aed0',
            confirmButtonText: 'Yes, Approve it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `process_approval.php?id=${appId}&action=Approved`;
            }
        })
    }

    function rejectWithReason(appId) {
        Swal.fire({
            title: 'Reject Application',
            text: 'Please enter the rejection reason:',
            input: 'textarea',
            inputPlaceholder: 'Type your reason here...',
            inputAttributes: { 'aria-label': 'Type your reason here' },
            showCancelButton: true,
            confirmButtonText: 'Submit Rejection',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#ee5d50',
            preConfirm: (reason) => {
                if (!reason) {
                    Swal.showValidationMessage('You need to provide a reason so the employee knows')
                }
                return reason;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `process_approval.php?id=${appId}&action=Rejected&reason=${encodeURIComponent(result.value)}`;
            }
        })
    }
    </script>
</body>
</html>