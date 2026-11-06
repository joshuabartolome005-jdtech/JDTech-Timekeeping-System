<?php
if (!isset($_SESSION)) { session_start(); }
include_once 'config.php';

// 1. SECURITY CHECK: Ensure only CBD role can access this page
$user_session = $_SESSION['user'] ?? [];
$user_role = $user_session['role'] ?? '';
if ($user_role !== 'CBD') {
    header("Location: dashboard.php");
    exit();
}

$message_success = "";
$message_error = "";

// Display session flash messages after POST redirect
if (isset($_SESSION['flash_success'])) {
    $message_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $message_error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// HELPER FUNCTION: Bilangin ang eksaktong working days (Excluding Saturdays and Sundays)
function getWorkingDays($startDate, $endDate) {
    if (empty($startDate)) return 0;
    if (empty($endDate)) $endDate = $startDate;

    $begin = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day'); // Isama ang huling araw sa loop range

    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($begin, $interval, $end);

    $workingDays = 0;
    foreach ($period as $dt) {
        // 6 = Saturday, 7 = Sunday
        if ($dt->format('N') < 6) {
            $workingDays++;
        }
    }
    return $workingDays;
}

// 2. BACKEND PROCESS: When the "Cancel Application" button is clicked
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_revert'])) {
    $app_id = (int)($_POST['application_id'] ?? 0);
    $target_status = 'Cancelled'; 

    if ($app_id > 0) {
        // Simulan ang Database Transaction para ligtas ang pag-update sa dalawang magkaibang tables
        $conn->begin_transaction();

        try {
            // A. Kunin muna ang impormasyon ng application bago tuluyang i-cancel
            // AUDIT: dinagdag ang "status" sa SELECT para malaman kung ano ang dating status ng na-cancel
            $fetch_sql = "SELECT employee_id, type, target_date, end_date, status FROM applications WHERE id = ?";
            $stmt_f = $conn->prepare($fetch_sql);
            $stmt_f->bind_param("i", $app_id);
            $stmt_f->execute();
            $app_data = $stmt_f->get_result()->fetch_assoc();
            $stmt_f->close();

            if ($app_data) {
                $emp_id = $app_data['employee_id'];
                $leave_type = $app_data['type'];
                $start_date = $app_data['target_date'];
                $end_date = !empty($app_data['end_date']) ? $app_data['end_date'] : $start_date;

                // AUDIT: ihahanda ang mga detalye para sa log (ilalagay lang pagkatapos ng commit)
                $prev_status = $app_data['status'];
                $days_returned = 0;
                $balance_column = null;

                // B. LEAVE REVERSION LOGIC: Para lamang sa Annual Leave at Mandatory Leave
                if ($leave_type === 'Annual Leave' || $leave_type === 'Mandatory Leave') {
                    
                    // Kalkulahin kung ilang working days ang ibabalik (hindi kasama ang Sabado at Linggo)
                    $days_to_return = (float)getWorkingDays($start_date, $end_date);

                    if ($days_to_return > 0) {
                        // Tukuyin kung anong column ang ia-update base sa structure ng iyong users table
                        $balance_column = ($leave_type === 'Annual Leave') ? 'annual_leave' : 'mandatory_leave';

                        // I-update at ibalik ang kaukulang credit balance sa record ng empleyado
                        $update_bal_sql = "UPDATE users SET {$balance_column} = {$balance_column} + ? WHERE employee_id = ?";
                        $stmt_b = $conn->prepare($update_bal_sql);
                        $stmt_b->bind_param("ds", $days_to_return, $emp_id);
                        $stmt_b->execute();
                        $stmt_b->close();

                        $days_returned = $days_to_return;
                    }
                }

                // C. I-update ang status ng application upang maging 'Cancelled' at burahin ang approver information
                $update_sql = "UPDATE applications SET status = ?, approver_id = NULL, approved_at = NULL WHERE id = ?";
                $stmt_u = $conn->prepare($update_sql);
                $stmt_u->bind_param("si", $target_status, $app_id);
                $stmt_u->execute();
                $stmt_u->close();

                // Kung walang naging error sa buong proseso, i-commit/i-save ang transaksyon sa database
                $conn->commit();

                // ==========================================================
                // AUDIT LOG: nasa PAGKATAPOS ng commit, para hindi mawala ang log kung sakaling mag-rollback
                // ==========================================================
                $warn = ($prev_status !== 'Approved') ? ' | WARNING: previous status was not Approved' : '';
                audit_log($conn, 'CANCEL_APPROVED', 'APPLICATION', $app_id,
                    "Override by CBD | Type: $leave_type | Employee: $emp_id | Dates: $start_date to $end_date | Previous status: $prev_status -> $target_status | Approver info cleared" . $warn);

                if ($days_returned > 0 && $balance_column !== null) {
                    audit_log($conn, 'LEAVE_CREDIT_RESTORED', 'LEAVE_BALANCE', $emp_id,
                        "Application #$app_id cancelled by CBD | $balance_column: +$days_returned day(s)");
                }
                // ==========================================================

                $_SESSION['flash_success'] = "Success! The application has been cancelled and the leave balance was successfully returned to the employee.";
            } else {
                $conn->rollback();

                // AUDIT: walang nahanap na application
                audit_log($conn, 'CANCEL_APPROVED_FAILED', 'APPLICATION', $app_id, 'Application record not found');

                $_SESSION['flash_error'] = "Error: Application record not found.";
            }

        } catch (Exception $e) {
            // Kung magkaroon ng kahit anong error sa process block, i-rollback o i-undo lahat para iwas-corruption
            $conn->rollback();

            // AUDIT: pumalya ang cancellation (na-rollback na, kaya ligtas nang mag-log)
            audit_log($conn, 'CANCEL_APPROVED_FAILED', 'APPLICATION', $app_id, 'Rolled back | Error: ' . $e->getMessage());

            $_SESSION['flash_error'] = "Error: Failed to process application cancellation. " . $e->getMessage();
        }
    } else {
        // AUDIT: maling application ID
        audit_log($conn, 'CANCEL_APPROVED_FAILED', 'APPLICATION', null, 'Invalid application ID: ' . ($_POST['application_id'] ?? ''));

        $_SESSION['flash_error'] = "Error: Invalid application ID provided.";
    }

    // Redirect pabalik sa eksaktong URL para ma-preserve ang active filters/tab at ma-clear ang POST data
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

// 3. FILTERS LOGIC
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$search = $_GET['search'] ?? '';
$active_tab = $_GET['tab'] ?? 'leave'; // default tab

// Base condition: Fetch only 'Approved' applications
$status_condition = "WHERE a.status = 'Approved'"; 
$params = [];
$types = "";

// Filter based on application type mapping
if ($active_tab == 'leave') {
    $status_condition .= " AND a.type IN ('Annual Leave', 'Mandatory Leave', 'Maternity Leave', 'Paternity Leave', 'Undertime')";
} elseif ($active_tab == 'ot') {
    $status_condition .= " AND a.type = 'OT'";
} elseif ($active_tab == 'ob') {
    $status_condition .= " AND a.type = 'OB'";
} elseif ($active_tab == 'missed_log') {
    $status_condition .= " AND a.type = 'MissedLog'";
}

// Search Filter (Full Name or Employee ID)
if (!empty($search)) {
    $status_condition .= " AND (u.fullname LIKE ? OR a.employee_id LIKE ?)";
    $search_val = "%$search%";
    $params[] = $search_val;
    $params[] = $search_val;
    $types .= "ss";
}

// Date Range Filter using target_date or date_filed
if ($active_tab == 'leave' || $active_tab == 'ob') {
    $status_condition .= " AND (a.target_date BETWEEN ? AND ?)";
} else {
    $status_condition .= " AND (DATE(a.date_filed) BETWEEN ? AND ?)";
}

$params[] = $start_date;
$params[] = $end_date;
$types .= "ss";

// Fetch approved applications records joined with user info
$sql = "SELECT a.*, u.fullname, u.department 
        FROM applications a 
        JOIN users u ON a.employee_id = u.employee_id 
        $status_condition 
        ORDER BY a.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - cancel application</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --body-bg: #f4f7fe;
            --card-bg: #ffffff;
            --text-dark: #1b254b;
            --text-muted: #a3aed0;
            --primary: #4318ff;
            --danger: #e74c3c;
            --border-color: #f1f1f1;
        }
        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--body-bg);
            color: var(--text-dark);
            margin: 0;
            padding: 40px 40px 40px 300px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .page-header { margin-bottom: 30px; }
        .page-header h2 { font-size: 24px; font-weight: 700; margin: 0 0 5px 0; }
        .page-header p { color: var(--text-muted); margin: 0; font-size: 14px; }
        .card { background: var(--card-bg); border-radius: 20px; padding: 25px; box-shadow: 0px 4px 20px rgba(0, 0, 0, 0.02); border: 1px solid var(--border-color); }
        .filter-row { display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-dark); text-transform: uppercase; }
        .form-control { padding: 10px 15px; border-radius: 10px; border: 1px solid #edf2f7; font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text-dark); }
        .btn-filter { background: var(--primary); color: #fff; border: none; padding: 11px 20px; border-radius: 10px; cursor: pointer; font-weight: 500; font-family: 'DM Sans', sans-serif; }
        .tabs-container { display: flex; gap: 10px; border-bottom: 2px solid var(--border-color); margin-bottom: 20px; }
        .tab-btn { padding: 12px 20px; border: none; background: none; font-family: 'DM Sans', sans-serif; font-weight: 700; color: var(--text-muted); cursor: pointer; font-size: 14px; text-transform: uppercase; position: relative; }
        .tab-btn.active { color: var(--primary); }
        .tab-btn.active::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 100%; height: 2px; background: var(--primary); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 15px; border-bottom: 2px solid var(--border-color); color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 700; }
        td { padding: 15px; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        .emp-name { font-weight: 700; }
        .emp-dept { font-size: 12px; color: var(--text-muted); }
        .btn-revert { background: #fff5f5; color: var(--danger); border: 1px solid #fed7d7; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .btn-revert:hover { background: var(--danger); color: #fff; }
        .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 500; }
        .alert-success { background: #e6fffa; color: #00875a; border: 1px solid #b2f5ea; }
        .alert-danger { background: #fff5f5; color: #e53e3e; border: 1px solid #fed7d7; }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

<?php include_once 'sidebar.php'; ?>

<div class="container">
    <div class="page-header">
        <h2>Cancel Approved Application (CBD Override)</h2>
        <p>Override system records by explicitly forcing approved entries into a "Cancelled" state.</p>
    </div>

    <?php if (!empty($message_success)): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $message_success; ?></div>
    <?php endif; ?>
    <?php if (!empty($message_error)): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo $message_error; ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="GET" action="revert_application.php">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
            <div class="filter-row">
                <div class="form-group">
                    <label>Employee Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search Name or ID..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div>
                    <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
                </div>
            </div>
        </form>

        <div class="tabs-container">
            <button class="tab-btn <?php echo $active_tab == 'leave' ? 'active' : ''; ?>" onclick="switchTab('leave')">Leaves</button>
            <button class="tab-btn <?php echo $active_tab == 'ot' ? 'active' : ''; ?>" onclick="switchTab('ot')">Overtime</button>
            <button class="tab-btn <?php echo $active_tab == 'ob' ? 'active' : ''; ?>" onclick="switchTab('ob')">Official Business</button>
            <button class="tab-btn <?php echo $active_tab == 'missed_log' ? 'active' : ''; ?>" onclick="switchTab('missed_log')">Missed Logs</button>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Employee Info</th>
                        <th>Application Type</th>
                        <th>Details / Inclusive Dates</th>
                        <th>Date Filed</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="emp-name"><?php echo htmlspecialchars($row['fullname']); ?></div>
                                    <div class="emp-dept"><?php echo htmlspecialchars($row['department']); ?> (ID: <?php echo htmlspecialchars($row['employee_id']); ?>)</div>
                                </td>
                                <td>
                                    <span class="role-badge" style="background:#edf2f7; color:var(--text-dark); padding:4px 8px; border-radius:6px; font-size:12px; font-weight:700;">
                                        <?php echo htmlspecialchars($row['type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    if ($row['type'] == 'MissedLog') {
                                        echo "<strong>Log Type:</strong> " . htmlspecialchars($row['log_missed_type'] ?? 'N/A') . "<br>";
                                    }
                                    if (!empty($row['end_date']) && $row['target_date'] !== $row['end_date']) {
                                        echo "<strong>Date:</strong> " . htmlspecialchars($row['target_date']) . " to " . htmlspecialchars($row['end_date']);
                                    } else {
                                        echo "<strong>Date:</strong> " . htmlspecialchars($row['target_date']);
                                    }
                                    if (!empty($row['details'])) {
                                        echo "<br><small style='color:var(--text-muted);'><strong>Reason:</strong> " . htmlspecialchars($row['details']) . "</small>";
                                    }
                                    ?>
                                </td>
                                <td><?php echo date('M d, Y h:i A', strtotime($row['date_filed'])); ?></td>
                                <td style="text-align: center;">
                                    <form action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" method="POST" onsubmit="return confirmAction();">
                                        <input type="hidden" name="application_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="action_revert" class="btn-revert">
                                            <i class="fa-solid fa-ban"></i> Cancel Application
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                <i class="fa-solid fa-folder-open" style="font-size: 28px; margin-bottom: 10px; display: block;"></i>
                                No approved applications found matching the selected filter criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('tab', tabName);
    window.location.search = urlParams.toString();
}

function confirmAction() {
    return confirm("Are you sure you want to change this application's status to 'Cancelled'?");
}
</script>

</body>
</html>
<?php 
if (isset($stmt) && $stmt) { $stmt->close(); } 
$conn->close(); 
?>