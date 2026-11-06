<?php
session_start();
include 'config.php';
include 'mailer_config.php';

if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];

// --- FUNCTION TO EXCLUDE SATURDAYS AND SUNDAYS ---
function calculateWorkingDays($from, $to) {
    $startDate = new DateTime($from);
    $endDate = new DateTime($to);
    $workingDays = 0;

    while ($startDate <= $endDate) {
        $dayOfWeek = $startDate->format('N'); // 1 (Mon) to 7 (Sun)
        if ($dayOfWeek < 6) { // Count as working day if Monday to Friday
            $workingDays++;
        }
        $startDate->modify('+1 day');
    }
    return $workingDays;
}

// 1. FETCH USER PROFILE DATA (Including Leave Balances)
$stmt_user = $conn->prepare("SELECT fullname, role, department, group_department, is_head, has_unit_head, annual_leave, mandatory_leave FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

$user_role = $user_data['role'];
$user_dept = $user_data['department'];
$group_dept = $user_data['group_department'];
$has_unit_head_val = $user_data['has_unit_head'];
$current_annual = $user_data['annual_leave'];
$current_mandatory = $user_data['mandatory_leave'];

// --- Variable para sa SweetAlert popup ---
$alert_message = '';
$alert_type = ''; // 'success' o 'error'
$redirect_on_confirm = ''; // saan pupunta pag OK/confirm sa alert; blank kung wala

// 2. LOGIC FOR SUBMISSION
if(isset($_POST['submit_leave'])){
    $leave_type = $_POST['leave_type'];
    $duration_type = $_POST['duration']; 
    $from = $_POST['date_from'];
    $to = $_POST['date_to'];
    $time_from = !empty($_POST['time_from']) ? $_POST['time_from'] : NULL;
    $time_to = !empty($_POST['time_to']) ? $_POST['time_to'] : NULL;
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);

    // --- CRITICAL VALIDATION FIRST ---
    $date1 = new DateTime($from);
    $date2 = new DateTime($to);
    if ($date2 < $date1) {
        $alert_message = 'Error: The Date To cannot be earlier than the Date From.';
        $alert_type = 'error';
    }

    // --- CALCULATE WORKING DAYS (SKIP WEEKENDS) ---
    if (empty($alert_message)) {
        $total_days = calculateWorkingDays($from, $to);

        // Adjust for Half Day (range must be exactly 1 day)
        if ($duration_type == 'Half Day' && $total_days == 1) {
            $total_days = 0.5;
        }

        // --- LEAVE POLICY VALIDATIONS ---
        if ($leave_type == 'Mandatory Leave') {
            if ($duration_type != 'Full Day') {
                $alert_message = 'Error: Mandatory Leave must be a Full Day application.';
                $alert_type = 'error';
            }
            elseif ($current_mandatory <= 0 || $total_days > $current_mandatory) {
                $alert_message = "Error: No remaining mandatory leave balance. (Current: $current_mandatory days, Requested Working Days: $total_days)";
                $alert_type = 'error';
            }
        }

        if (empty($alert_message) && $leave_type == 'Annual Leave') {
            if ($current_annual <= 0 || $total_days > $current_annual) {
                $alert_message = "Error: No remaining annual leave balance. (Current: $current_annual days, Requested Working Days: $total_days)";
                $alert_type = 'error';
            }
        }

        if (empty($alert_message) && ($leave_type == 'Paternity Leave' || $leave_type == 'Maternity Leave')) {
            $duration_type = 'Full Day';
        }
    }

    // AUDIT: hindi pumasa sa validation (petsa o leave balance), hindi na magpapatuloy ang submission
    if (!empty($alert_message)) {
        audit_log($conn, 'SUBMIT_REJECTED', 'LEAVE', null, "Type: $leave_type | $from to $to | Reason: $alert_message");
    }

    // --- KUNG WALANG VALIDATION ERROR, MAG-PROCEED SA SUBMISSION ---
    if (empty($alert_message)) {

        // --- CHECK IF THE USER IS ASSIGNED TO PRESIDENT ---
        $check_pres = $conn->prepare("SELECT president_id FROM president_assignments WHERE group_head_id = ? LIMIT 1");
        $check_pres->bind_param("s", $user_id);
        $check_pres->execute();
        $is_assigned_to_president = $check_pres->get_result()->num_rows > 0;

        // --- APPROVAL HIERARCHY STATUS LOGIC ---
        if ($user_role == 'President') {
            $initial_status = 'Approved'; 
            $target_role = NULL;
        } elseif ($is_assigned_to_president) {
            $initial_status = 'Pending President';
            $target_role = 'President';
        } elseif ($user_role == 'GroupHead') {
            $initial_status = 'Pending President';
            $target_role = 'President';
        } elseif ($user_role == 'DeptHead') {
            $initial_status = 'Pending Group Head';
            $target_role = 'GroupHead';
        } elseif ($user_role == 'UnitHead') {
            $initial_status = 'Pending Dept. Head';
            $target_role = 'DeptHead';
        } else {
            if ($has_unit_head_val == 1) {
                $initial_status = 'Pending Unit Head';
                $target_role = 'UnitHead';
            } else {
                $initial_status = 'Pending Dept. Head';
                $target_role = 'DeptHead';
            }
        }

        // INSERT query
        $sql = "INSERT INTO applications (employee_id, group_department, type, with_unit_head, duration, details, target_date, end_date, time_from, time_to, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssisssssss", $user_id, $group_dept, $leave_type, $has_unit_head_val, $duration_type, $reason, $from, $to, $time_from, $time_to, $initial_status);
        
        if($stmt->execute()){

            // AUDIT: matagumpay na na-file ang leave (kasama ang ID ng bagong application)
            $new_app_id = $stmt->insert_id;
            audit_log($conn, 'SUBMIT', 'LEAVE', $new_app_id, "Type: $leave_type | Duration: $duration_type | $from to $to | Working days: $total_days | Initial status: $initial_status");

            // --- DEDUCTION LOGIC ONLY FOR PRESIDENT (AUTO-APPROVED) ---
            // For other roles, deductions are handled asynchronously inside process_approval.php
            if ($user_role == 'President') {
                if ($leave_type == 'Annual Leave') {
                    $new_annual = $current_annual - $total_days;
                    $update_credits = $conn->prepare("UPDATE users SET annual_leave = ? WHERE employee_id = ?");
                    $update_credits->bind_param("ds", $new_annual, $user_id);
                    $update_credits->execute();

                    // AUDIT: auto-approved at nabawasan agad ang credits ng President
                    audit_log($conn, 'LEAVE_CREDIT_DEDUCTED', 'LEAVE_BALANCE', $user_id, "Application #$new_app_id (self-filed, auto-approved) | annual_leave: $current_annual -> $new_annual");
                } elseif ($leave_type == 'Mandatory Leave') {
                    $new_mandatory = $current_mandatory - $total_days;
                    $update_credits = $conn->prepare("UPDATE users SET mandatory_leave = ? WHERE employee_id = ?");
                    $update_credits->bind_param("ds", $new_mandatory, $user_id);
                    $update_credits->execute();

                    // AUDIT
                    audit_log($conn, 'LEAVE_CREDIT_DEDUCTED', 'LEAVE_BALANCE', $user_id, "Application #$new_app_id (self-filed, auto-approved) | mandatory_leave: $current_mandatory -> $new_mandatory");
                }
            }

            // --- EMAIL NOTIFICATION LOGIC ---
            if ($initial_status != 'Approved') {
                $head_email = ""; $head_name = "";
                if ($target_role == 'President') {
                    $stmt_head = $conn->prepare("SELECT email, fullname FROM users WHERE role = 'President' LIMIT 1");
                } elseif ($target_role == 'GroupHead') {
                    // TAMA NA QUERY: Gumagamit ng group_head_assignments table, itinutugma sa group_department ($group_dept)
                    $stmt_head = $conn->prepare("SELECT u.email, u.fullname FROM users u JOIN group_head_assignments gha ON u.employee_id = gha.group_head_id WHERE gha.department_name = ? AND u.role = 'GroupHead' LIMIT 1");
                    $stmt_head->bind_param("s", $group_dept);
                } elseif ($target_role == 'DeptHead') {
                    // Resolved matching assignment query for next structural levels
                    $stmt_head = $conn->prepare("SELECT u.email, u.fullname FROM users u JOIN dept_head_assignments dha ON u.employee_id = dha.dept_head_id WHERE dha.unit_name = ? AND u.role = 'DeptHead' LIMIT 1");
                    $stmt_head->bind_param("s", $user_dept);
                } else {
                    $stmt_head = $conn->prepare("SELECT email, fullname FROM users WHERE department = ? AND role = ? LIMIT 1");
                    $stmt_head->bind_param("ss", $user_dept, $target_role);
                }
                $stmt_head->execute();
                $head_result = $stmt_head->get_result();
                if($head_result->num_rows > 0) {
                    $head_data = $head_result->fetch_assoc();
                    $head_email = $head_data['email'];
                    $head_name = $head_data['fullname'];
                    $time_info = ($time_from) ? " ($time_from to $time_to)" : "";
                    $subject = "New Leave Application for Approval: " . $user_data['fullname'];
                    $message = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333; border: 1px solid #eee; border-radius: 10px;'>
                            <h3 style='color: #4318ff;'>Leave Application Notification</h3>
                            <p>Hi <b>{$head_name}</b>,</p>
                            <p>This is to notify you that a new application requires your review and approval:</p>
                            <hr style='border: 0; border-top: 1px solid #eee;'>
                            <p><b>Employee:</b> {$user_data['fullname']}<br>
                            <b>Type:</b> $leave_type<br>
                            <b>Duration:</b> $duration_type $time_info<br>
                            <b>Dates:</b> $from to $to (Working Days: $total_days)<br>
                            <b>Status:</b> <span style='color: #4318ff; font-weight: bold;'>$initial_status</span></p>
                            <p>Please login to the portal to take action.</p>
                            <p><a href='https://" . $_SERVER['HTTP_HOST'] . "/jdtech/login.php' style='display: inline-block; background-color: #4318ff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 10px;'>Go to JDTech URL</a></p>
                        </div>
                    ";
                    $email_sent = sendBOFEmail($head_email, $subject, $message);

                    // AUDIT: nakasulat kung naipadala o pumalya (pangalan at role lang ang nilalagay, hindi ang email address)
                    if ($email_sent) {
                        audit_log($conn, 'NOTIFY_APPROVER', 'LEAVE', $new_app_id, "Email sent to $target_role: $head_name");
                    } else {
                        audit_log($conn, 'NOTIFY_FAILED', 'LEAVE', $new_app_id, "Email to $target_role ($head_name) was NOT sent. Check the PHP error log for the Microsoft Graph error");
                    }
                } else {
                    // AUDIT: walang nahanap na approver na padadalhan, dito makikita kung bakit walang email na dumating
                    audit_log($conn, 'NOTIFY_NO_RECIPIENT', 'LEAVE', $new_app_id, "No $target_role found | Employee dept: $user_dept | Group dept: $group_dept");
                }
            }
            $alert_message = 'Application Submitted Successfully!';
            $alert_type = 'success';
            $redirect_on_confirm = 'apply_leave.php';
        } else {
            // AUDIT: pumalya ang pag-save ng application
            audit_log($conn, 'SUBMIT_FAILED', 'LEAVE', null, "Type: $leave_type | $from to $to | DB error: " . $stmt->error);
            $alert_message = 'Error: ' . $stmt->error;
            $alert_type = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Apply Leave</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --bg-light: #f4f7fe; --primary-blue: #4318ff; --text-dark: #1b254b; --text-muted: #a3aed0; --white: #ffffff; --shadow: 14px 17px 40px 4px rgba(112, 144, 176, 0.08); }
        body { margin: 0; padding: 0; font-family: 'DM Sans', 'Segoe UI', sans-serif; background-color: var(--bg-light); display: flex; }
        .content { flex: 1; padding: 30px; box-sizing: border-box; min-height: 100vh; display: flex; flex-direction: column; align-items: center; }
        .header-nav { width: 100%; max-width: 700px; margin-bottom: 25px; }
        .header-nav h1 { color: var(--text-dark); font-size: 24px; margin: 0; font-weight: 700; }
        .form-card { background: var(--white); padding: 40px; border-radius: 20px; max-width: 700px; width: 100%; box-shadow: var(--shadow); }
        label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 0.5px; }
        select, input, textarea { width: 100%; padding: 12px 15px; border: 1px solid #e0e5f2; border-radius: 12px; box-sizing: border-box; font-size: 15px; color: var(--text-dark); outline: none; margin-bottom: 20px; transition: 0.3s; }
        select:focus, input:focus, textarea:focus { border-color: var(--primary-blue); }
        .form-row { display: flex; gap: 20px; margin-bottom: 10px; }
        .form-group { flex: 1; }
        .time-fields { display: none; background: #f8faff; padding: 20px; border-radius: 15px; border: 1px dashed var(--primary-blue); margin-bottom: 20px; }
        .btn-submit { background: var(--primary-blue); color: white; border: none; padding: 16px; width: 100%; border-radius: 12px; cursor: pointer; font-weight: 700; font-size: 16px; transition: 0.3s; box-shadow: 0px 10px 20px rgba(67, 24, 255, 0.2); }
        .btn-submit:hover { background: #3311db; transform: translateY(-2px); }
        .back-link { display: inline-block; margin-top: 20px; color: var(--text-muted); text-decoration: none; font-size: 14px; }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div class="header-nav">
            <h1>Create Application</h1>
        </div>
        <div class="form-card">
            <h2 style="color: var(--text-dark); margin-top: 0; font-size: 20px;">
                <i class="fa-solid fa-paper-plane" style="color: var(--primary-blue); margin-right: 10px;"></i>
                Application Details
            </h2>
            <hr style="border: 0; border-top: 1px solid #f4f7fe; margin-bottom: 25px;">
            <form method="POST" id="leaveForm">
                <div class="form-row">
                    <div class="form-group">
                        <label>Leave Type</label>
                        <select name="leave_type" id="leave_type" onchange="validateForm()" required>
                            <option value="Annual Leave">Annual Leave (SL & VL)</option>
                            <option value="Mandatory Leave">Mandatory Leave</option>
                            <option value="Paternity Leave">Paternity Leave</option>
                            <option value="Maternity Leave">Maternity Leave</option>
                            <option value="Undertime">Undertime</option>
                            <option value="Excused Tardiness">Excused Tardiness</option>
                            <option value="Solo Parent Leave">Solo Parent Leave</option>
                            <option value="Authorized Leave without Pay">Authorized Leave without Pay</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Duration</label>
                        <select name="duration" id="duration" onchange="validateForm()" required>
                            <option value="Full Day">Full Day</option>
                            <option value="Half Day">Half Day</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" id="date_from" onchange="validateForm()" required>
                    </div>
                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" id="date_to" onchange="validateForm()" required>
                    </div>
                </div>

                <div id="timeFields" class="time-fields">
                    <div class="form-row" style="margin-bottom: 0;">
                        <div class="form-group">
                            <label><i class="fa-regular fa-clock"></i> Time From</label>
                            <input type="time" name="time_from" id="time_from">
                        </div>
                        <div class="form-group">
                            <label><i class="fa-regular fa-clock"></i> Time To</label>
                            <input type="time" name="time_to" id="time_to">
                        </div>
                    </div>
                </div>

                <label>Reason / Details</label>
                <textarea name="reason" rows="4" placeholder="Kindly state your reasons here..." required></textarea>

                <button type="submit" name="submit_leave" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i> Submit Application</button>
            </form>
            <center><a href="dashboard.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a></center>
        </div>
    </div>

    <script>
    function validateForm() {
        const leaveType = document.getElementById('leave_type').value;
        const durationBox = document.getElementById('duration');
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        const timeFields = document.getElementById('timeFields');

        durationBox.style.pointerEvents = 'auto';
        durationBox.style.backgroundColor = '#ffffff';

        if (leaveType === 'Undertime' || leaveType === 'Excused Tardiness') {
            durationBox.value = 'Half Day';
            durationBox.style.pointerEvents = 'none';
            durationBox.style.backgroundColor = '#f4f7fe';
            timeFields.style.display = 'block';
        } 
        else if (leaveType === 'Mandatory Leave' || leaveType === 'Paternity Leave' || leaveType === 'Maternity Leave') {
            durationBox.value = 'Full Day';
            durationBox.style.pointerEvents = 'none';
            durationBox.style.backgroundColor = '#f4f7fe';
            timeFields.style.display = 'none';
        }
        else {
            if (durationBox.value === 'Half Day') {
                timeFields.style.display = 'block';
            } else {
                timeFields.style.display = 'none';
            }
        }

        if (dateFrom && dateTo && dateFrom !== dateTo) {
            durationBox.value = 'Full Day';
            durationBox.style.pointerEvents = 'none';
            durationBox.style.backgroundColor = '#f4f7fe';
            timeFields.style.display = 'none';
        }
    }
    window.onload = validateForm;

    <?php if(!empty($alert_message)): ?>
    // --- SweetAlert2 popup para sa resulta ng submission ---
    Swal.fire({
        icon: '<?php echo ($alert_type === "success") ? "success" : "error"; ?>',
        title: '<?php echo ($alert_type === "success") ? "Success!" : "Oops!"; ?>',
        text: '<?php echo addslashes($alert_message); ?>',
        confirmButtonColor: '#4318ff',
        confirmButtonText: 'OK'
    }).then((result) => {
        <?php if(!empty($redirect_on_confirm)): ?>
            window.location.href = '<?php echo $redirect_on_confirm; ?>';
        <?php else: ?>
            window.history.back();
        <?php endif; ?>
    });
    <?php endif; ?>
    </script>
</body>
</html>