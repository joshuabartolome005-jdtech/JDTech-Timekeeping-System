<?php
session_start();
include 'config.php';
include 'mailer_config.php';

if(!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'CBD' && $_SESSION['user']['role'] !== 'HR')) { 
    header("Location: dashboard.php"); 
    exit(); 
}

$date_from = $_GET['f_from'] ?? date('Y-m-d');
$date_to   = $_GET['f_to'] ?? date('Y-m-d');

$sql_base = "SELECT u.fullname, u.department, DATE(a.log_time) as log_date, 
                    TIME(a.log_time) as log_clock, a.log_type, a.status as log_status
             FROM users u
             JOIN attendance_logs a ON u.employee_id = a.employee_id
             WHERE DATE(a.log_time) BETWEEN ? AND ?
             AND (a.status IS NULL 
                  OR (a.status NOT LIKE '%Cancelled%' 
                      AND a.status NOT LIKE '%Rejected%' 
                      AND a.status NOT LIKE '%Reverted%'))
             ORDER BY DATE(a.log_time) DESC, u.fullname ASC, a.log_time ASC";
function getLogStatus($status_field) {
    if (empty($status_field)) {
        return "Normal Log";
    }
    if (strpos($status_field, 'OB Approved') !== false) {
        return "OB File";
    }
    if (strpos($status_field, 'Missed Log Approved') !== false) {
        return "Missed Log File";
    }
    if (strpos($status_field, 'OT Approved') !== false) {
        return "OT File";
    }
    return htmlspecialchars($status_field);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Attendance_Report_'.$date_from.'_to_'.$date_to.'.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, array('Employee Name', 'Department', 'Log Date', 'Log Time', 'Type', 'Status/Origin'));

    $stmt_csv = $conn->prepare($sql_base);
    $stmt_csv->bind_param("ss", $date_from, $date_to);
    $stmt_csv->execute();
    $res_csv = $stmt_csv->get_result();

    while ($row_csv = $res_csv->fetch_assoc()) {
        fputcsv($output, [
            $row_csv['fullname'],
            $row_csv['department'],
            $row_csv['log_date'],
            date('h:i A', strtotime($row_csv['log_clock'])),
            $row_csv['log_type'],
            getLogStatus($row_csv['log_status'])
        ]);
    }
    fclose($output);
    exit();
}

if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Attendance_Report_".$date_from."_to_".$date_to.".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<tr><th style="background-color:#2c3e50; color:#fff;">Employee Name</th><th style="background-color:#2c3e50; color:#fff;">Department</th><th style="background-color:#2c3e50; color:#fff;">Log Date</th><th style="background-color:#2c3e50; color:#fff;">Log Time</th><th style="background-color:#2c3e50; color:#fff;">Type</th><th style="background-color:#2c3e50; color:#fff;">Status/Origin</th></tr>';

    $stmt_xls = $conn->prepare($sql_base);
    $stmt_xls->bind_param("ss", $date_from, $date_to);
    $stmt_xls->execute();
    $res_xls = $stmt_xls->get_result();

    while ($row_xls = $res_xls->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row_xls['fullname']) . '</td>';
        echo '<td>' . htmlspecialchars($row_xls['department']) . '</td>';
        echo '<td>' . $row_xls['log_date'] . '</td>';
        echo '<td>' . date('h:i A', strtotime($row_xls['log_clock'])) . '</td>';
        echo '<td>' . $row_xls['log_type'] . '</td>';
        echo '<td>' . getLogStatus($row_xls['log_status']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit();
}

$stmt = $conn->prepare($sql_base);
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$logs = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - attendance summary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-dark: #2c3e50; --bg-gray: #f4f7f6; }
        body { background-color: var(--bg-gray); font-family: 'Segoe UI', sans-serif; display: flex; margin: 0; }
        .main-content { flex: 1; margin-left: 260px; padding: 30px; min-height: 100vh; }
        .page-header { background: white; padding: 20px 25px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); overflow: hidden; }
        .table thead { background-color: var(--primary-dark); color: white; }
        .table th { font-weight: 500; text-transform: uppercase; font-size: 0.85rem; padding: 15px; }
        .table td { vertical-align: middle; padding: 12px 15px; }
        .filter-section { background: white; padding: 10px 15px; border-radius: 8px; display: inline-flex; align-items: center; gap: 10px; }
        .text-time { font-weight: 600; color: #2c3e50; }
        .date-label { font-size: 12px; font-weight: bold; color: #7f8c8d; text-transform: uppercase; margin-bottom: 0; }
        @media (max-width: 768px) { .main-content { margin-left: 0; padding: 15px; } .page-header { flex-direction: column; align-items: stretch; } }
    </style>
     <link rel="icon" href="jdlogo.png">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h4 class="mb-0 fw-bold">Attendance Summary</h4>
                <p class="text-muted small mb-0">Showing logs from <?php echo date('M d, Y', strtotime($date_from)); ?> to <?php echo date('M d, Y', strtotime($date_to)); ?></p>
            </div>
            
            <div class="d-flex flex-wrap gap-2">
                <form method="GET" class="filter-section shadow-sm border">
                    <div class="d-flex align-items-center gap-2">
                        <label class="date-label">From:</label>
                        <input type="date" name="f_from" class="form-control form-control-sm" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="date-label">To:</label>
                        <input type="date" name="f_to" class="form-control form-control-sm" value="<?php echo $date_to; ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm px-3">Filter</button>
                </form>
                
                <a href="?f_from=<?php echo $date_from; ?>&f_to=<?php echo $date_to; ?>&export=csv" class="btn btn-success btn-sm d-flex align-items-center">
                    <i class="fa-solid fa-file-csv me-2"></i> Export .CSV
                </a>

                <a href="?f_from=<?php echo $date_from; ?>&f_to=<?php echo $date_to; ?>&export=xls" class="btn btn-info btn-sm text-white d-flex align-items-center">
                    <i class="fa-solid fa-file-excel me-2"></i> Export .XLS
                </a>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="text-center">
                        <tr>
                            <th class="text-start">Employee Name</th>
                            <th>Department</th>
                            <th>Date</th>
                            <th>Log Time</th>
                            <th>Type</th>
                            <th>Status / Origin</th>
                        </tr>
                    </thead>
                    <tbody class="text-center">
                        <?php if($logs->num_rows > 0): ?>
                            <?php while($row = $logs->fetch_assoc()): ?>
                            <tr>
                                <td class="text-start fw-bold text-dark"><?php echo htmlspecialchars($row['fullname']); ?></td>
                                <td><span class="badge bg-light text-secondary border"><?php echo htmlspecialchars($row['department']); ?></span></td>
                                <td class="small text-muted"><?php echo date('M d, Y', strtotime($row['log_date'])); ?></td>
                                <td class="text-time"><?php echo date('h:i A', strtotime($row['log_clock'])); ?></td>
                                <td>
                                    <?php if($row['log_type'] === 'IN'): ?>
                                        <span class="badge bg-success px-3">IN</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger px-3">OUT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $current_status = getLogStatus($row['log_status']);
                                        if($current_status === 'Normal Log') {
                                            echo '<span class="text-secondary small fw-medium">Normal Log</span>';
                                        } elseif($current_status === 'OB File') {
                                            echo '<span class="text-primary small fw-bold"><i class="fa-solid fa-briefcase me-1"></i> OB File</span>';
                                        } elseif($current_status === 'OT File') {
                                            echo '<span class="text-indigo small fw-bold" style="color: #6f42c1;"><i class="fa-solid fa-business-time me-1"></i> OT File</span>';
                                        } elseif($current_status === 'Missed Log File') {
                                            echo '<span class="text-warning small fw-bold"><i class="fa-solid fa-clock-rotate-left me-1"></i> Missed Log</span>';
                                        } else {
                                            echo '<span class="text-muted small fw-normal">' . $current_status . '</span>';
                                        }
                                    ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="py-5 text-muted">No attendance found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>