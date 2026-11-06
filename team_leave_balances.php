<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_role =$_SESSION['user']['role'];
$my_employee_id =$_SESSION['user']['employee_id'];

// Proteksyon: DeptHead, GroupHead, at President lang
$allowed_roles = ['DeptHead', 'GroupHead', 'President'];
if(!in_array($user_role,$allowed_roles)) { 
    header("Location: dashboard.php"); 
    exit(); 
}

// --- KUNIN ANG MGA EMPLOYEE NA NASA ILALIM NG APPROVER, BASE SA ROLE ---
$search =$_GET['search'] ?? '';

if($user_role == 'DeptHead') {
    // DeptHead: Empleyado na naka-assign sa Unit na hawak ng Dept Head
    $query = "SELECT DISTINCT u.employee_id, u.fullname, u.department, u.annual_leave, u.mandatory_leave
              FROM users u
              INNER JOIN dept_head_assignments dha ON u.department = dha.unit_name
              WHERE dha.dept_head_id = ? AND u.employee_id != ?";
              
    $params = [$my_employee_id, $my_employee_id];$types = "ss";

} elseif($user_role == 'GroupHead') {
    // GroupHead: Empleyado sa mga Units ng mga DeptHead na naka-assign sa Group Head na ito, 
    // O kaya naman ang group_department/department ng user ay tumatama sa group_head_assignments.
    $query = "SELECT DISTINCT u.employee_id, u.fullname, u.department, u.annual_leave, u.mandatory_leave
              FROM users u
              LEFT JOIN dept_head_assignments dha ON u.department = dha.unit_name
              LEFT JOIN group_head_assignments gha_direct ON (u.group_department = gha_direct.department_name OR u.department = gha_direct.department_name)
              WHERE (
                  gha_direct.group_head_id = ? 
                  OR dha.dept_head_id IN (
                      SELECT u_sub.employee_id 
                      FROM users u_sub 
                      INNER JOIN group_head_assignments gha_sub ON (u_sub.group_department = gha_sub.department_name OR u_sub.department = gha_sub.department_name)
                      WHERE gha_sub.group_head_id = ?
                  )
              ) AND u.employee_id != ?";

    $params = [$my_employee_id,$my_employee_id, $my_employee_id];$types = "sss";

} else { // President
    // President: Empleyado na nasa ilalim ng Group Heads OR Dept Heads na assigned sa President sa `president_assignments`
    $query = "SELECT DISTINCT u.employee_id, u.fullname, u.department, u.annual_leave, u.mandatory_leave
              FROM users u
              LEFT JOIN dept_head_assignments dha ON u.department = dha.unit_name
              LEFT JOIN group_head_assignments gha ON (u.group_department = gha.department_name OR u.department = gha.department_name)
              INNER JOIN president_assignments pa ON (
                  pa.group_head_id = gha.group_head_id 
                  OR pa.group_head_id = dha.dept_head_id 
                  OR pa.group_head_id = u.employee_id
              )
              WHERE pa.president_id = ? AND u.employee_id != ?";

    $params = [$my_employee_id, $my_employee_id];$types = "ss";
}

// Subquery wrapper para malinis ang pag-filter gamit ang SEARCH bar
$query = "SELECT * FROM ($query) AS team WHERE 1=1";

if(!empty($search)) {$query .= " AND (fullname LIKE ? OR employee_id LIKE ?)";
    $search_val = "\%$search%";
    $params[] =$search_val;
    $params[] =$search_val;
    $types .= "ss";
}

$query .= " ORDER BY fullname ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);$stmt->execute();
$result =$stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Team Leave Balances</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-dark: #1b254b;
            --bg-gray: #f4f7fe;
            --primary-blue: #4318ff;
            --text-muted: #a3aed0;
        }
        body { font-family: 'DM Sans', 'Segoe UI', sans-serif; margin: 0; display: flex; background-color: var(--bg-gray); }
        .content { flex: 1; margin-left: 260px; padding: 40px; box-sizing: border-box; min-height: 100vh; }

        .page-header { margin-bottom: 25px; }
        .page-header h2 { margin: 0; color: var(--primary-dark); font-size: 26px; font-weight: 800; }
        .page-header p { margin: 5px 0 0 0; color: var(--text-muted); }

        .search-box { width: 100%; max-width: 400px; padding: 12px 16px; border: 2px solid #e0e5f2; border-radius: 12px; margin-bottom: 20px; box-sizing: border-box; font-size: 14px; outline: none; }

        .history-card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; min-width: 650px; }
        th { text-align: left; padding: 14px; color: var(--text-muted); text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border-bottom: 1px solid #f1f1f1; }
        td { padding: 16px 14px; border-bottom: 1px solid #f1f1f1; color: var(--primary-dark); font-size: 14px; }
        tr:hover { background-color: #fcfcfc; }

        .emp-id { font-family: 'Courier New', monospace; font-weight: 700; color: var(--primary-blue); }
        .leave-count { font-weight: 700; font-size: 15px; }
        .annual-tag { color: #3498db; }
        .mandatory-tag { color: #9b59b6; }

        /* --- RESPONSIVE FIX --- */
        @media (max-width: 768px) {
            .content { margin-left: 0 !important; padding: 20px !important; padding-top: 70px !important; }
            .search-box { max-width: 100%; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2>Team Leave Balances</h2>
        </div>

        <form method="GET">
            <input type="text" name="search" class="search-box" placeholder="Search Name or ID..." value="<?php echo htmlspecialchars($search); ?>">
        </form>

        <div class="history-card">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Department / Unit</th>
                        <th>Annual Leave</th>
                        <th>Mandatory Leave</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row =$result->fetch_assoc()): ?>
                            <tr>
                                <td class="emp-id"><?php echo htmlspecialchars($row['employee_id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['fullname']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['department']); ?></td>
                                <td><span class="leave-count annual-tag"><?php echo number_format($row['annual_leave'], 1); ?></span></td>
                                <td><span class="leave-count mandatory-tag"><?php echo number_format($row['mandatory_leave'], 1); ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 60px;">
                                <i class="fa-solid fa-user-slash" style="font-size: 30px; margin-bottom: 10px; display: block; opacity: 0.3;"></i>
                                No employee was found under you.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>