<?php
include 'config.php';
$allowed_roles = ['HR', 'CBD', 'President'];
if(!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], $allowed_roles)) { 
    header("Location: dashboard.php"); 
    exit(); 
}

$current_user_role = $_SESSION['user']['role'];

function calculateTenure($hiredDate) {
    if (!$hiredDate || $hiredDate == '0000-00-00') return "N/A";
    
    $start = new DateTime($hiredDate);
    $end = new DateTime(); 
    $diff = $start->diff($end);
    
    $tenure = [];
    if ($diff->y > 0) $tenure[] = $diff->y . "y";
    if ($diff->m > 0) $tenure[] = $diff->m . "m";
    if ($diff->d > 0) $tenure[] = $diff->d . "d";
    
    return !empty($tenure) ? implode(" ", $tenure) : "0d";
}
$search = $_GET['search'] ?? '';
$dept_filter = $_GET['dept'] ?? '';
$status_filter = $_GET['status'] ?? '';

$query = "SELECT * FROM users WHERE 1=1";
$params = [];
$types = "";

if(!empty($search)) {
    $query .= " AND (employee_id LIKE ? OR fullname LIKE ?)";
    $search_val = "%$search%";
    $params[] = $search_val;
    $params[] = $search_val;
    $types .= "ss";
}

if(!empty($dept_filter)) {
    $query .= " AND department = ?";
    $params[] = $dept_filter;
    $types .= "s";
}

if(!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$query .= " ORDER BY fullname ASC";

$stmt = $conn->prepare($query);
if(!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$dept_list = $conn->query("SELECT DISTINCT department FROM users ORDER BY department ASC");
$has_any_filter_param = (isset($_GET['search']) || isset($_GET['dept']) || isset($_GET['status']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - employee list</title>
    <link rel="icon" href="jdlogo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-dark: #1b254b;
            --bg-gray: #f4f7fe;
            --hr-blue: #4318ff;
            --cbd-gold: #f39c12;
            --pres-purple: #6f42c1;
            --success-green: #05cd99;
            --danger-red: #ee5d50;
            --text-muted: #a3aed0;
        }

        body { 
            font-family: 'DM Sans', sans-serif; 
            margin: 0; 
            display: flex; 
            background-color: var(--bg-gray); 
        }

        .content { 
            flex: 1; 
            margin-left: 260px; 
            padding: 40px; 
            box-sizing: border-box; 
            min-height: 100vh; 
        }

        .directory-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .directory-header h2 { margin: 0; color: var(--primary-dark); font-size: 28px; font-weight: 800; }
        .directory-header p { margin: 5px 0 0 0; color: var(--text-muted); }

        .btn-add-new {
            background-color: var(--success-green);
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 14px;
            transition: 0.3s;
            box-shadow: 0px 4px 12px rgba(5, 205, 153, 0.2);
        }

        .btn-add-new:hover { opacity: 0.9; transform: translateY(-2px); }

        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02);
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group { display: flex; flex-direction: column; gap: 8px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }
        
        .filter-input {
            padding: 12px;
            border: 1px solid #e0e5f2;
            border-radius: 12px;
            font-size: 14px;
            min-width: 220px;
            background: #f8faff;
        }

        .btn-search {
            background: var(--hr-blue);
            color: white;
            border: none;
            padding: 0 25px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: bold;
            height: 42px;
            transition: 0.3s;
        }

        .btn-clear {
            background: #f4f7fe;
            color: var(--text-muted);
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
        }

        .history-card { 
            background: white; 
            padding: 25px; 
            border-radius: 20px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.03); 
            overflow-x: auto;
        }

        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th { 
            text-align: left; padding: 15px; background: transparent; 
            color: var(--text-muted); text-transform: uppercase; 
            font-size: 11px; font-weight: 700; letter-spacing: 1px; border-bottom: 1px solid #f1f1f1;
        }

        td { padding: 18px 15px; border-bottom: 1px solid #f1f1f1; color: var(--primary-dark); font-size: 14px; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background-color: #fcfcfc; }

        .tenure-badge {
            display: inline-block;
            font-size: 11px;
            color: var(--hr-blue);
            font-weight: 700;
            background: #f4f7fe;
            padding: 2px 8px;
            border-radius: 6px;
            margin-top: 5px;
        }

        .status-badge { padding: 6px 12px; border-radius: 10px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .approved { background: #e6fff5; color: #00ab66; }
        .rejected { background: #fff5f5; color: #ee5d50; }

        .action-btn {
            text-decoration: none; font-weight: 700; font-size: 11px;
            padding: 8px 16px; border-radius: 10px; transition: 0.3s;
            display: inline-block;
            text-transform: uppercase;
            cursor: pointer;
            border: 1px solid transparent;
        }   
        .edit-btn { color: var(--hr-blue); border: 1px solid var(--hr-blue); }
        .edit-btn:hover { background: var(--hr-blue); color: white; }   
        .view-btn { color: var(--cbd-gold); border: 1px solid var(--cbd-gold); }
        .view-btn:hover { background: var(--cbd-gold); color: white; }
        .view-pres-btn { color: var(--pres-purple); border: 1px solid var(--pres-purple); }
        .view-pres-btn:hover { background: var(--pres-purple); color: white; }
        .emp-id { font-family: 'Courier New', monospace; font-weight: 700; color: var(--hr-blue); }
        .modal {display: none;position: fixed;z-index: 1000;left: 0;top: 0; width: 100%;height: 100%;background-color: rgba(27, 37, 75, 0.4);backdrop-filter: blur(4px);}
        .modal-content {background-color: white; margin: 5% auto;padding: 0;width: 500px;border-radius: 24px;box-shadow: 0 20px 40px rgba(0,0,0,0.1);position: relative;animation: slideIn 0.3s ease-out;overflow: hidden;
        }
        @keyframes slideIn {
            from { transform: translateY(-30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }}
        .modal-header {background: var(--primary-dark);color: white;padding: 30px;text-align: center;}
        .modal-header img {width: 100px;height: 100px;border-radius: 50%;border: 4px solid rgba(255,255,255,0.2);margin-bottom: 15px;object-fit: cover;}
        .modal-body { padding: 30px; }
        .info-row {display: flex;justify-content: space-between;padding: 12px 0;border-bottom: 1px solid #f1f4f9;}
        .info-row:last-child { border-bottom: none; }
        .info-label { color: var(--text-muted); font-size: 13px; font-weight: 600; }
        .info-value { color: var(--primary-dark); font-size: 14px; font-weight: 700; }
        .close-modal {position: absolute; right: 20px;top: 20px;color: white;font-size: 24px;cursor: pointer;opacity: 0.7;}
        .close-modal:hover { opacity: 1; }
    </style></head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div class="directory-header">
            <div>
                <h2>Employees Directory</h2>
                <p>Track employee tenure and manage profile details.</p>
            </div>
            
            <?php if($current_user_role == 'HR'): ?>
                <a href="hr_management.php" class="btn-add-new">+ Add Employee</a>
            <?php endif; ?></div>
        <form method="GET" class="filter-section" id="filterForm">
            <div class="filter-group">
                <label>Quick Search</label>
                <input type="text" name="search" id="quickSearch" class="filter-input" placeholder="Search ID or Name..." value="<?php echo htmlspecialchars($search); ?>" oninput="liveSearchEmployees()">
            </div>
            <div class="filter-group">
                <label>Department</label>
                <select name="dept" class="filter-input">
                    <option value="">All Departments</option>
                    <?php while($d = $dept_list->fetch_assoc()): ?>
                        <option value="<?php echo $d['department']; ?>" <?php echo ($dept_filter == $d['department']) ? 'selected' : ''; ?>>
                            <?php echo $d['department']; ?>
                        </option>
                    <?php endwhile; ?>
                </select></div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status" class="filter-input">
                    <option value="">All Status</option>
                    <option value="Active" <?php echo ($status_filter == 'Active') ? 'selected' : ''; ?>>Active</option>
                    <option value="Blocked" <?php echo ($status_filter == 'Blocked') ? 'selected' : ''; ?>>Blocked</option>
                    <option value="Resigned" <?php echo ($status_filter == 'Resigned') ? 'selected' : ''; ?>>Resigned</option>
                </select></div>
            <button type="submit" class="btn-search">Filter</button>
            <?php if(!empty($search) || !empty($dept_filter) || !empty($status_filter)): ?>
                <a href="employees.php" class="btn-clear" id="clearFilterBtn">Clear</a>
            <?php endif; ?></form>
        <div class="history-card">
            <table><thead> <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Department & Position</th>
                        <th>Date Hired & Tenure</th>
                        <th>Status</th>
                        <th style="text-align: center;">Action</th>
                    </tr></thead>
                <tbody id="employeeTableBody">
                    <?php if($res->num_rows > 0): ?>
                        <?php while($row = $res->fetch_assoc()): ?>
                        <tr>
                            <td class="emp-id"><?php echo htmlspecialchars($row['employee_id']); ?></td>
                            <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($row['department']); ?></div>
                                <small style="color: var(--text-muted);"><?php echo htmlspecialchars($row['position']); ?></small>
                            </td>
                            <td>
                                <div><?php echo ($row['date_hired'] && $row['date_hired'] != '0000-00-00') ? date('M d, Y', strtotime($row['date_hired'])) : '---'; ?></div>
                                <span class="tenure-badge">
                                    <i class="fa-regular fa-clock"></i> <?php echo calculateTenure($row['date_hired']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?php echo (strtolower($row['status'] ?? '') == 'active') ? 'approved' : 'rejected'; ?>">
                                    <?php echo htmlspecialchars($row['status'] ?? 'Active'); ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <?php 
                                    $emp_data = json_encode($row); 
                                    $edit_url = "hr_management.php?edit_id=" . urlencode($row['employee_id']);
                                    
                                    if($current_user_role == 'HR'): 
                                ?>
                                    <a href="<?php echo $edit_url; ?>" class="action-btn edit-btn">EDIT</a>
                                <?php elseif($current_user_role == 'President'): ?>
                                    <button onclick='openPresModal(<?php echo htmlspecialchars($emp_data, ENT_QUOTES, 'UTF-8'); ?>)' class="action-btn view-pres-btn">VIEW</button>
                                <?php else: ?>
                                    <a href="<?php echo $edit_url; ?>&mode=view" class="action-btn view-btn">VIEW</a>
                                <?php endif; ?>
                            </td> </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 60px;">
                                <i class="fa-solid fa-user-slash" style="font-size: 30px; margin-bottom: 10px; display: block;"></i>
                                No employees found.
                        </td></tr>
                    <?php endif; ?>
                </tbody></table></div></div>
    <div id="presModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closePresModal()">&times;</span>
            <div class="modal-header">
                <img src="default-avatar.png" id="modal_img" alt="Profile">
                <h3 id="modal_name" style="margin: 0; font-size: 20px;">Employee Name</h3>
                <p id="modal_pos" style="margin: 5px 0 0 0; opacity: 0.8; font-size: 14px;">Position</p>
            </div>
            <div class="modal-body">
                <div class="info-row">
                    <span class="info-label">Employee ID</span>
                    <span class="info-value" id="modal_id">--</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Department</span>
                    <span class="info-value" id="modal_dept">--</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date Hired</span>
                    <span class="info-value" id="modal_hired">--</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Current Status</span>
                    <span class="info-value" id="modal_status">--</span>
                </div>
                <div style="margin-top: 20px; text-align: center;">
                    <button onclick="closePresModal()" style="background: #f4f7fe; border: none; padding: 10px 30px; border-radius: 10px; cursor: pointer; font-weight: bold; color: var(--primary-dark);">Close Profile</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openPresModal(data) {
            document.getElementById('modal_name').innerText = data.fullname;
            document.getElementById('modal_pos').innerText = data.position;
            document.getElementById('modal_id').innerText = data.employee_id;
            document.getElementById('modal_dept').innerText = data.department;
            document.getElementById('modal_hired').innerText = data.date_hired;
            document.getElementById('modal_status').innerText = data.status || 'Active';
            
            if(data.profile_img) {
                document.getElementById('modal_img').src = 'uploads/' + data.profile_img;
            } else {
                document.getElementById('modal_img').src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(data.fullname) + '&background=random';
            }
            document.getElementById('presModal').style.display = 'block';
        }
        function closePresModal() {
            document.getElementById('presModal').style.display = 'none'; }
        window.onclick = function(event) {
            let modal = document.getElementById('presModal');
            if (event.target == modal) {
                modal.style.display = "none";
            } }

        function liveSearchEmployees() {
            const input = document.getElementById('quickSearch').value.toUpperCase();
            const tbody = document.getElementById('employeeTableBody');
            const rows = tbody.getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                const rowText = rows[i].textContent || rows[i].innerText;
                rows[i].style.display = rowText.toUpperCase().includes(input) ? '' : 'none';
            }
        }

        const currentSearch = "<?php echo addslashes($search); ?>";
        const currentDept = "<?php echo addslashes($dept_filter); ?>";
        const currentStatus = "<?php echo addslashes($status_filter); ?>";
        const hasAnyFilterParam = <?php echo $has_any_filter_param ? 'true' : 'false'; ?>;

        if (hasAnyFilterParam) {
            sessionStorage.setItem('emp_filter_search', currentSearch);
            sessionStorage.setItem('emp_filter_dept', currentDept);
            sessionStorage.setItem('emp_filter_status', currentStatus);
        } else {

            const savedSearch = sessionStorage.getItem('emp_filter_search');
            const savedDept = sessionStorage.getItem('emp_filter_dept');
            const savedStatus = sessionStorage.getItem('emp_filter_status');
            if (savedSearch || savedDept || savedStatus) {
                const params = new URLSearchParams();
                if (savedSearch) params.set('search', savedSearch);
                if (savedDept) params.set('dept', savedDept);
                if (savedStatus) params.set('status', savedStatus);
                window.location.href = 'employees.php?' + params.toString();
            }
        }
        const clearBtn = document.getElementById('clearFilterBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                sessionStorage.removeItem('emp_filter_search');
                sessionStorage.removeItem('emp_filter_dept');
                sessionStorage.removeItem('emp_filter_status');
            });
        }
    </script>
</body>
</html>