<?php
include 'config.php';

// I-verify kung HR o CBD ang naka-login
if(!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['HR', 'CBD', 'President'])) {
    header("Location: dashboard.php");
    exit();
}

$user_role = $_SESSION['user']['role'];
$today = date('Y-m-d');

// Function para sa Tenure Calculation
function getTenure($hiredDate) {
    if (!$hiredDate || $hiredDate == '0000-00-00') return "N/A";
    $start = new DateTime($hiredDate);
    $end = new DateTime();
    $diff = $start->diff($end);
    return "{$diff->y}y {$diff->m}m {$diff->d}d";
}

// --- SQL QUERIES ---

// Query para sa Leave Balances
$leave_sql = "SELECT employee_id, fullname, department, annual_leave, mandatory_leave 
              FROM users WHERE role != 'CBD' ORDER BY fullname ASC";

// NEW: Query para sa HR Masterlist Report
$hr_masterlist_sql = "SELECT employee_id, fullname, role, status, department, email, date_hired, position, address, birthday FROM users ORDER BY fullname ASC";

// Logic para sa Detail View ng Employee (Modal)
$view_emp_id = $_GET['view_id'] ?? null;
$emp_details = null;
if ($view_emp_id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE employee_id = ?");
    $stmt->bind_param("s", $view_emp_id);
    $stmt->execute();
    $emp_details = $stmt->get_result()->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>JDTech - reports</title>
    <style>
        :root { --primary: #2c3e50; --blue: #3498db; --red: #e74c3c; --green: #27ae60; --light: #f4f7f6; }
        body { font-family: 'Segoe UI', sans-serif; display: flex; margin: 0; background: var(--light); }
        .content { flex: 1; margin-left: 260px; padding: 30px; box-sizing: border-box; }
        
        .report-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        
        .btn { padding: 10px 18px; border-radius: 6px; border: none; cursor: pointer; color: white; font-weight: bold; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; }
        .btn-excel { background: #217346; } 
        .btn-pdf { background: var(--red); } 
        .btn-hr { background: #8e44ad; } /* Purple for HR Specific */
        .btn-all { background: var(--primary); }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8f9fa; padding: 12px; text-align: left; font-size: 11px; border-bottom: 2px solid #eee; color: #7f8c8d; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; color: #333; }
        tr:hover { background: #fafafa; }

        .search-box { width: 100%; padding: 14px; border: 2px solid #e0e0e0; border-radius: 10px; margin-bottom: 20px; box-sizing: border-box; font-size: 15px; outline: none; }

        /* Modal Design */
        .modal { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.7); display: flex; justify-content: center; align-items: center; z-index: 999; backdrop-filter: blur(4px); }
        .modal-body { background: white; width: 85%; max-height: 90vh; overflow-y: auto; padding: 40px; border-radius: 15px; position: relative; }
        .close { position: absolute; right: 25px; top: 20px; font-size: 30px; text-decoration: none; color: #999; }

        h4 { margin-top: 30px; padding-bottom: 10px; border-bottom: 2px solid var(--light); color: var(--primary); }

        /* Hide the HR-only export table from the actual page view */
        #hrMasterTable { display: none; }

        @media print { 
            .sidebar, .btn, .search-box, .close, .no-print { display: none !important; } 
            .content { margin: 0; padding: 0; width: 100%; }
            .modal { position: relative; background: white; }
            .modal-body { box-shadow: none; width: 100%; padding: 0; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="header-flex">
            <h1 style="color: var(--primary); margin:0;">Monitoring</h1>
            <div style="display: flex; gap: 10px;">
                <?php if($user_role == 'HR'): ?>
                    <button onclick="exportToExcel('hrMasterTable', 'HR_Employee_Masterlist')" class="btn btn-hr">Download HR Masterlist (Excel)</button>
                <?php endif; ?>
                
                <button onclick="exportToExcel('leaveTable', 'All_Employees_Leave_Balances')" class="btn btn-all">Download Leave Balances (Excel)</button>
            </div>
        </div>

        <?php if($user_role == 'HR'): ?>
        <table id="hrMasterTable">
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Department</th>
                    <th>Email</th>
                    <th>Date Hired</th>
                    <th>Tenure</th>
                    <th>Position</th>
                    <th>Address</th>
                    <th>Birthday</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $hr_res = $conn->query($hr_masterlist_sql);
                while($hr_row = $hr_res->fetch_assoc()): ?>
                <tr>
                    <td>'<?php echo $hr_row['employee_id']; ?></td> <td><?php echo $hr_row['fullname']; ?></td>
                    <td><?php echo $hr_row['role']; ?></td>
                    <td><?php echo $hr_row['status']; ?></td>
                    <td><?php echo $hr_row['department']; ?></td>
                    <td><?php echo $hr_row['email']; ?></td>
                    <td><?php echo $hr_row['date_hired']; ?></td>
                    <td><?php echo calculateTenure($hr_row['date_hired']); ?></td>
                    <td><?php echo $hr_row['position']; ?></td>
                    <td><?php echo $hr_row['address']; ?></td>
                    <td><?php echo $hr_row['birthday']; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="report-card">
            <h3 style="margin-top:0;">Employee Leave Balances</h3>
            <input type="text" id="empSearch" class="search-box" placeholder="Search Name or Department..." onkeyup="filterTable()">
            
            <table id="leaveTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Department</th>
                        <th style="text-align:center;">Annual</th>
                        <th style="text-align:center;">Mandatory</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $res = $conn->query($leave_sql); 
                    while($row = $res->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $row['fullname']; ?></strong></td>
                        <td><?php echo $row['department']; ?></td>
                        <td align="center"><?php echo number_format($row['annual_leave'], 1); ?></td>
                        <td align="center"><?php echo number_format($row['mandatory_leave'], 1); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <?php if ($view_emp_id && $emp_details): ?>
        <div class="modal">
            <div class="modal-body">
                <a href="reports.php" class="close">&times;</a>
                <div class="header-flex">
                    <div>
                        <h2 style="margin:0; color:var(--primary);"><?php echo $emp_details['fullname']; ?></h2>
                        <p style="color:#666; font-size: 14px; margin: 5px 0;">
                            <strong><?php echo $emp_details['department']; ?></strong> | ID: <?php echo $emp_details['employee_id']; ?>
                        </p>
                    </div>
                    <div class="no-print">
                        <button onclick="exportToExcel('modalData', '<?php echo $emp_details['fullname']; ?>_History')" class="btn btn-excel">Export to Excel</button>
                        <button onclick="window.print()" class="btn btn-pdf">Save as PDF</button>
                    </div>
                </div>

                <div id="modalData">
                    <h4>Leave History</h4>
                    <table>
                        <thead><tr><th>Type</th><th>Start Date</th><th>End Date</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php 
                            $l = $conn->query("SELECT * FROM applications WHERE employee_id='$view_emp_id' AND type LIKE '%Leave%' ORDER BY target_date DESC");
                            if($l->num_rows > 0) {
                                while($r = $l->fetch_assoc()) {
                                    echo "<tr><td>{$r['type']}</td><td>{$r['target_date']}</td><td>{$r['end_date']}</td><td>{$r['status']}</td></tr>";
                                }
                            } else { echo "<tr><td colspan='4' align='center'>No leave history.</td></tr>"; }
                            ?>
                        </tbody>
                    </table>
                    </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function filterTable() {
        let input = document.getElementById("empSearch").value.toUpperCase();
        let table = document.getElementById("leaveTable");
        let tr = table.getElementsByTagName("tr");
        for (let i = 1; i < tr.length; i++) {
            let rowText = tr[i].textContent || tr[i].innerText;
            tr[i].style.display = rowText.toUpperCase().includes(input) ? "" : "none";
        }
    }

    function exportToExcel(elementId, fileName) {
        let table = document.getElementById(elementId);
        let html = table.outerHTML;
        let blob = new Blob([html], { type: "application/vnd.ms-excel" });
        let url = URL.createObjectURL(blob);
        let link = document.createElement("a");
        link.href = url;
        link.download = fileName + "_" + new Date().toISOString().slice(0,10) + ".xls";
        link.click();
    }
    </script>
</body>
</html>

<?php
// Helper function para sa hidden masterlist table tenure
function calculateTenure($hiredDate) {
    if (!$hiredDate || $hiredDate == '0000-00-00') return "N/A";
    $start = new DateTime($hiredDate);
    $end = new DateTime();
    $diff = $start->diff($end);
    return $diff->y . "y " . $diff->m . "m " . $diff->d . "d";
}
?>