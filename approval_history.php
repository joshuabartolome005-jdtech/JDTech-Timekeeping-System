<?php
session_start();
include 'config.php';
if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_role = $_SESSION['user']['role'];
$my_employee_id = $_SESSION['user']['employee_id'];
$head_roles = ['UnitHead', 'DeptHead', 'GroupHead', 'President'];
$stmt_check = $conn->prepare("SELECT is_head, fullname FROM users WHERE employee_id = ?");
$stmt_check->bind_param("s", $my_employee_id);
$stmt_check->execute();
$check_data = $stmt_check->get_result()->fetch_assoc();
$is_head = $check_data['is_head'] ?? 0;

if(!in_array($user_role, $head_roles) && $is_head != 1) { 
    header("Location: dashboard.php"); 
    exit(); 
}
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

$leave_types = "'Annual Leave','Mandatory Leave','Maternity Leave','Paternity Leave','Undertime','Solo Parent Leave','Excused Tardiness','Authorized Leave without Pay'";
$query = "SELECT al.action as log_action, al.action_date as log_action_date, al.remarks as log_remarks,
                 a.*, u.fullname as employee_name, u.department as employee_department
          FROM approval_logs al
          JOIN applications a ON al.application_id = a.id
          JOIN users u ON a.employee_id = u.employee_id
          WHERE al.approver_id = ?";

$params = [$my_employee_id];
$types = "s";

if(!empty($search)) {
    $query .= " AND (u.fullname LIKE ? OR a.employee_id LIKE ?)";
    $search_val = "%$search%";
    $params[] = $search_val;
    $params[] = $search_val;
    $types .= "ss";
}

if(!empty($type_filter)) {
    if($type_filter == 'Leave') {
        $query .= " AND a.type IN ($leave_types)";
    } else {
        $query .= " AND a.type = ?";
        $params[] = $type_filter;
        $types .= "s";
    }
}

if(!empty($status_filter)) {
    $query .= " AND al.action = ?";
    $params[] = $status_filter;
    $types .= "s";
}
$query .= " ORDER BY al.action_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

function getTypeLabel($type) {
    switch($type) {
        case 'OT': return 'Overtime';
        case 'OB': return 'Official Business';
        case 'MissedLog': return 'Missed Log';
        default: return $type;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Approval History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-dark: #1b254b;
            --bg-gray: #f4f7fe;
            --primary-blue: #4318ff;
            --text-muted: #a3aed0;
            --success-green: #05cd99;
            --danger-red: #ee5d50;
        }

        body { font-family: 'DM Sans', 'Segoe UI', sans-serif; margin: 0; display: flex; background-color: var(--bg-gray); }
        .content { flex: 1; margin-left: 260px; padding: 40px; box-sizing: border-box; min-height: 100vh; }

        .page-header { margin-bottom: 25px; }
        .page-header h2 { margin: 0; color: var(--primary-dark); font-size: 26px; font-weight: 800; }
        .page-header p { margin: 5px 0 0 0; color: var(--text-muted); }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-input { padding: 10px 12px; border: 1px solid #e0e5f2; border-radius: 10px; font-size: 14px; min-width: 200px; background: #f8faff; }
        .btn-search { background: var(--primary-blue); color: white; border: none; padding: 0 22px; border-radius: 10px; cursor: pointer; font-weight: bold; height: 40px; }
        .btn-clear { background: #f4f7fe; color: var(--text-muted); text-decoration: none; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; }

        .history-card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { text-align: left; padding: 14px; color: var(--text-muted); text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border-bottom: 1px solid #f1f1f1; }
        td { padding: 16px 14px; border-bottom: 1px solid #f1f1f1; color: var(--primary-dark); font-size: 14px; }
        tr:hover { background-color: #fcfcfc; }

        .type-tag { font-size: 11px; padding: 4px 8px; border-radius: 4px; background: #f0f2f5; color: #475467; font-weight: 700; text-transform: uppercase; border-left: 3px solid var(--primary-blue); display: inline-block; }

        .status-badge { padding: 6px 12px; border-radius: 10px; font-size: 10px; font-weight: 800; text-transform: uppercase; display: inline-block; }
        .approved { background: #e6fff5; color: #00ab66; }
        .rejected { background: #fff5f5; color: #ee5d50; }

        .reason-box { margin-top: 6px; font-size: 11.5px; color: var(--danger-red); max-width: 200px; word-wrap: break-word; }
        .emp-name { font-weight: 700; display: block; }
        .emp-sub { font-size: 12px; color: var(--text-muted); }
        .detail-text { font-size: 13px; color: #475467; }
        .date-text { color: #7f8c8d; font-size: 13px; }
        @media (max-width: 768px) {
            .content { margin-left: 0 !important; padding: 20px !important; padding-top: 70px !important; }
            .filter-section { padding: 15px; }
            .filter-input { min-width: 100%; }
            .filter-group { width: 100%; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2>Approval History</h2>
        </div>

        <form method="GET" class="filter-section">
            <div class="filter-group">
                <label>Search Employee</label>
                <input type="text" name="search" class="filter-input" placeholder="Search ID or Name..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Application Type</label>
                <select name="type_filter" class="filter-input">
                    <option value="">All Types</option>
                    <option value="Leave" <?php echo ($type_filter == 'Leave') ? 'selected' : ''; ?>>Leave</option>
                    <option value="OT" <?php echo ($type_filter == 'OT') ? 'selected' : ''; ?>>Overtime</option>
                    <option value="OB" <?php echo ($type_filter == 'OB') ? 'selected' : ''; ?>>Official Business</option>
                    <option value="MissedLog" <?php echo ($type_filter == 'MissedLog') ? 'selected' : ''; ?>>Missed Log</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status_filter" class="filter-input">
                    <option value="">All (Approved & Rejected)</option>
                    <option value="Approved" <?php echo ($status_filter == 'Approved') ? 'selected' : ''; ?>>Approved</option>
                    <option value="Rejected" <?php echo ($status_filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <button type="submit" class="btn-search">Filter</button>
            <?php if(!empty($search) || !empty($type_filter) || !empty($status_filter)): ?>
                <a href="approval_history.php" class="btn-clear">Clear</a>
            <?php endif; ?>
        </form>

        <div class="history-card">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Application Type</th>
                        <th>Details</th>
                        <th>Date Filed</th>
                        <th>Your Action Date</th>
                        <th>Your Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <?php
                                $log_action = $row['log_action'];
                                $badge_class = ($log_action == 'Approved') ? 'approved' : 'rejected';
                                $type_label = getTypeLabel($row['type']);
                            ?>
                            <tr>
                                <td>
                                    <span class="emp-name"><?php echo htmlspecialchars($row['employee_name']); ?></span>
                                    <span class="emp-sub"><?php echo htmlspecialchars($row['employee_department']); ?> | ID: <?php echo htmlspecialchars($row['employee_id']); ?></span>
                                </td>
                                <td>
                                    <div class="type-tag"><?php echo htmlspecialchars($type_label); ?></div>
                                </td>
                                <td class="detail-text">
                                    <?php if($row['type'] == 'OT'): ?>
                                        <?php echo htmlspecialchars($row['ot_type'] ?? ''); ?><br>
                                        <small style="color:#95a5a6;"><?php echo ($row['target_date']) ? date('M d, Y', strtotime($row['target_date'])) : ''; ?></small>
                                    <?php elseif($row['type'] == 'OB'): ?>
                                        <?php echo htmlspecialchars($row['destination'] ?? ''); ?><br>
                                        <small style="color:#95a5a6;"><?php echo ($row['target_date']) ? date('M d, Y', strtotime($row['target_date'])) : ''; ?></small>
                                    <?php elseif($row['type'] == 'MissedLog'): ?>
                                        <?php echo htmlspecialchars($row['log_missed_type'] ?? ''); ?><br>
                                        <small style="color:#95a5a6;"><?php echo ($row['target_date']) ? date('M d, Y', strtotime($row['target_date'])) : ''; ?></small>
                                    <?php else: ?>
                                        <?php 
                                            echo ($row['target_date']) ? date('M d, Y', strtotime($row['target_date'])) : '';
                                            if(!empty($row['end_date']) && $row['end_date'] != $row['target_date']) {
                                                echo ' - ' . date('M d, Y', strtotime($row['end_date']));
                                            }
                                        ?>
                                        <br><small style="color:#95a5a6;"><?php echo htmlspecialchars($row['duration'] ?? ''); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="date-text">
                                    <?php echo ($row['date_filed']) ? date('M d, Y', strtotime($row['date_filed'])) : '-'; ?>
                                </td>
                                <td class="date-text">
                                    <?php echo ($row['log_action_date']) ? date('M d, Y | h:i A', strtotime($row['log_action_date'])) : '-'; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $badge_class; ?>">
                                        <?php echo htmlspecialchars($log_action); ?>
                                    </span>
                                    <?php if($log_action == 'Rejected' && !empty($row['log_remarks'])): ?>
                                        <div class="reason-box"><?php echo htmlspecialchars($row['log_remarks']); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 60px;">
                                <i class="fa-solid fa-folder-open" style="font-size: 30px; margin-bottom: 10px; display: block; opacity: 0.3;"></i>
                                You do not have any approved or rejected applications yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>