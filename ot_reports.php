<?php
session_start(); // Mahalaga para sa session validation
include 'config.php';

// Proteksyon: CBD at HR lang ang may access
if(!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'CBD' && $_SESSION['user']['role'] !== 'HR')) {
    header("Location: login.php"); exit();
}

$user_role = $_SESSION['user']['role'];

// LOGIC PARA SA FILTER
// UPDATE: Isinama na ang iba't ibang Pending statuses bukod sa Approved
$where_clause = "WHERE a.type = 'OT' AND a.status IN ('Pending Unit Head', 'Pending Dept. Head', 'Pending Group Head', 'Pending President', 'Approved')";

if (isset($_GET['from_date']) && isset($_GET['to_date']) && !empty($_GET['from_date']) && !empty($_GET['to_date'])) {
    $from = mysqli_real_escape_string($conn, $_GET['from_date']);
    $to = mysqli_real_escape_string($conn, $_GET['to_date']);
    $where_clause .= " AND a.target_date BETWEEN '$from' AND '$to'";
}

// UPDATE: Idinagdag ang TIMEDIFF para makuha ang kabuuang oras ng Overtime
$sql = "SELECT a.*, 
               u.fullname as emp_name, 
               u.department, 
               u.employee_id as emp_id,
               u2.fullname as approver_name,
               TIMEDIFF(a.time_to, a.time_from) as total_hours
        FROM applications a 
        JOIN users u ON a.employee_id = u.employee_id 
        LEFT JOIN users u2 ON a.approver_id = u2.employee_id 
        $where_clause 
        ORDER BY a.target_date DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>JDTech - OT reports</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-dark: #2c3e50; --ot-green: #2ecc71; --bg-gray: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg-gray); margin: 0; display: flex; }
        .content { flex: 1; margin-left: 260px; padding: 30px; box-sizing: border-box; }
        .report-card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); overflow-x: auto; }
        
        .filter-section { background: white; padding: 20px; border-radius: 15px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #7f8c8d; }
        .filter-group input { padding: 8px; border: 1px solid #ddd; border-radius: 5px; }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th { background: #f8f9fa; padding: 12px; text-align: left; border-bottom: 2px solid #dee2e6; color: #34495e; }
        td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
        
        .btn-action { padding: 8px 15px; border-radius: 5px; border: none; cursor: pointer; font-weight: bold; font-size: 12px; transition: 0.3s; display: flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-filter { background: var(--primary-dark); color: white; }
        .btn-csv { background: #3498db; color: white; }
        .btn-xls { background: #27ae60; color: white; }
        
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; display: inline-block; }
        .status-approved { background: #e8f8f5; color: #27ae60; }
        .status-pending { background: #fef9e7; color: #f39c12; }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="content">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2><i class="fa-solid fa-file-contract text-success"></i> Overtime Reports</h2>
        <div style="display:flex; gap:10px;">
            <button onclick="exportTableToCSV('OT_Report_<?php echo date('Y-m-d'); ?>.csv')" class="btn-action btn-csv">
                <i class="fa-solid fa-file-csv"></i> CSV
            </button>
            <button onclick="exportTableToExcel('reportTable', 'OT_Report')" class="btn-action btn-xls">
                <i class="fa-solid fa-file-excel"></i> Excel
            </button>
        </div>
    </div>

    <form class="filter-section" method="GET">
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" name="from_date" value="<?php echo $_GET['from_date'] ?? ''; ?>">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" name="to_date" value="<?php echo $_GET['to_date'] ?? ''; ?>">
        </div>
        <button type="submit" class="btn-action btn-filter">Filter Report</button>
        <a href="ot_reports.php" class="btn-action" style="background:#95a5a6; color:white;">Reset</a>
    </form>

    <div class="report-card">
        <table id="reportTable">
            <thead>
                <tr>
                    <th>Emp ID</th>
                    <th>Full Name</th>
                    <th>Department</th>
                    <th>OT Type</th>
                    <th>OT Date</th>
                    <th>Time From</th>
                    <th>Time To</th>
                    <th>Total Hours</th>
                    <th>Reason</th>
                    <th>Approved By</th>
                    <th>Status</th>
                    <th>Approved At</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result && $result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $row['emp_id']; ?></strong></td>
                        <td><?php echo $row['emp_name']; ?></td>
                        <td><?php echo $row['department']; ?></td>
                        <td><span style="color:#e67e22; font-weight:500;"><?php echo $row['ot_type']; ?></span></td>
                        <td><?php echo date('M d, Y', strtotime($row['target_date'])); ?></td>
                        <td><?php echo date('h:i A', strtotime($row['time_from'])); ?></td>
                        <td><?php echo date('h:i A', strtotime($row['time_to'])); ?></td>
                        <td>
                            <strong>
                                <?php echo !empty($row['total_hours']) ? date('H:i', strtotime($row['total_hours'])) . ' hrs' : '00:00'; ?>
                            </strong>
                        </td>
                        <td style="max-width:200px; color:#666;"><?php echo $row['details']; ?></td>
                        
                        <td style="font-weight: bold; color: #2c3e50;">
                            <?php echo $row['approver_name'] ?? 'N/A'; ?>
                        </td>

                        <td>
                            <?php 
                                $status_class = ($row['status'] === 'Approved') ? 'status-approved' : 'status-pending';
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>"><?php echo $row['status']; ?></span>
                        </td>
                        <td style="font-size:11px;"><?php echo $row['approved_at'] ? date('M d, Y H:i', strtotime($row['approved_at'])) : '---'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="12" style="text-align:center; padding:30px;">No OT records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function exportTableToCSV(filename) {
    var csv = [];
    var rows = document.querySelectorAll("table tr");
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        for (var j = 0; j < cols.length; j++) row.push('"' + cols[j].innerText.trim() + '"');
        csv.push(row.join(","));
    }
    var csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

function exportTableToExcel(tableID, filename = ''){
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var tableSelect = document.getElementById(tableID);
    var tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
    filename = filename?filename+'.xls':'excel_data.xls';
    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    if(navigator.msSaveOrOpenBlob){
        var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob( blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = filename;
        downloadLink.click();
    }
}
</script>

</body>
</html>