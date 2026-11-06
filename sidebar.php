<?php
$current_page = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION)) {session_start();}
include_once 'config.php';

$user_session = $_SESSION['user'] ?? [];
$user_role = $user_session['role'] ?? '';
$my_employee_id = $user_session['employee_id'] ?? '';
$display_name = $user_data['fullname'] ?? ($user_session['fullname'] ?? 'User');
$display_dept = $user_data['department'] ?? ($user_session['department'] ?? 'General');
$is_head = $user_data['is_head'] ?? ($user_session['is_head'] ?? 0);
$head_roles = ['UnitHead', 'DeptHead', 'GroupHead', 'President'];

$badge_count = 0;
if (in_array($user_role, ['HR', 'Admin', 'CBD'])) {
    $sql_c = "SELECT COUNT(*) as total FROM applications WHERE status LIKE 'Pending%' AND employee_id != ?";
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param("s", $my_employee_id);
} elseif ($user_role == 'President') {
    $sql_c = "SELECT COUNT(*) as total FROM applications a INNER JOIN president_assignments pa ON a.employee_id = pa.group_head_id WHERE pa.president_id = ? AND a.status = 'Pending President' AND a.employee_id != ?";
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param("ss", $my_employee_id, $my_employee_id);
} elseif ($user_role == 'GroupHead') {
    $sql_c = "SELECT COUNT(*) as total FROM applications a JOIN users u ON a.employee_id = u.employee_id INNER JOIN group_head_assignments gha ON u.group_department = gha.department_name WHERE gha.group_head_id = ? AND a.status = 'Pending Group Head' AND a.employee_id != ?";
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param("ss", $my_employee_id, $my_employee_id);
} elseif ($user_role == 'DeptHead') {
    $sql_c = "SELECT COUNT(*) as total FROM applications a JOIN users u ON a.employee_id = u.employee_id INNER JOIN dept_head_assignments dha ON u.department = dha.unit_name WHERE dha.dept_head_id = ? AND a.status = 'Pending Dept. Head' AND a.employee_id != ?";
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param("ss", $my_employee_id, $my_employee_id);
} elseif ($user_role == 'UnitHead' || $is_head == 1) {
    $sql_c = "SELECT COUNT(*) as total FROM applications a JOIN users u ON a.employee_id = u.employee_id WHERE u.department = ? AND a.status = 'Pending Unit Head' AND a.employee_id != ?";
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param("ss", $display_dept, $my_employee_id);
}

if (isset($stmt_c)) {
    $stmt_c->execute();
    $res_c = $stmt_c->get_result()->fetch_assoc();
    $badge_count = (int)($res_c['total'] ?? 0);
    $stmt_c->close();
}
?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {--sidebar-width: 260px;--sidebar-bg: #ffffff;--text-dark: #1b254b;
        --text-muted: #a3aed0;--active-bg: #f4f7fe;--active-text: #4318ff;
        --danger-red: #e74c3c;--border-color: #f1f1f1;--transition-speed: 0.25s;}
    .sidebar {width: var(--sidebar-width); background: var(--sidebar-bg); color: var(--text-dark); 
        height: 100vh; padding: 0; box-sizing: border-box; position: fixed; overflow-y: auto; font-family: 'DM Sans', sans-serif;
        left: 0; top: 0; z-index: 1000;border-right: 1px solid var(--border-color);scrollbar-width: thin;scrollbar-color: #e0e5f2 transparent;
        transition: left var(--transition-speed);}
    .sidebar-header {padding: 30px 20px;text-align: center;border-bottom: 1px solid #f8faff;}
    .sidebar-header img {width: 70px;margin-bottom: 12px;filter: drop-shadow(0px 4px 4px rgba(0,0,0,0.05));}
    .sidebar-header h4 {font-size: 16px;margin: 0;font-weight: 800;color: var(--text-dark);letter-spacing: -0.5px;text-transform: uppercase;}
    .user-info-box {margin-top: 15px;padding: 12px;background: #f8faff;border-radius: 16px;border: 1px solid #edf2f7;}
    .user-name-display {display: block;font-size: 14px;font-weight: 700;color: var(--text-dark);
        white-space: nowrap;overflow: hidden;text-overflow: ellipsis;}
    .user-dept-display {display: block;font-size: 11px;color: var(--text-muted);margin-bottom: 8px;}
    .role-badge {font-size: 9px;padding: 4px 12px;border-radius: 10px;background: var(--active-text);color: #fff;text-transform: uppercase;font-weight: 700;
        display: inline-block;box-shadow: 0px 4px 10px rgba(67, 24, 255, 0.2);}
    .menu-section { padding: 15px 0 30px; }
    .menu-label {font-size: 11px; text-transform: uppercase; color: var(--text-muted); padding: 20px 25px 10px;font-weight: 700;letter-spacing: 1.2px;}
    .sidebar a { color: #707eae; display: flex;align-items: center;padding: 14px 25px;text-decoration: none; transition: all var(--transition-speed); font-size: 15px;
        font-weight: 500;gap: 15px;position: relative;}
    .sidebar a i {width: 22px;text-align: center;font-size: 18px;}
    .sidebar a:hover {background: var(--active-bg); color: var(--active-text);padding-left: 30px;}
    .sidebar a.active { background: var(--active-bg); color: var(--active-text); font-weight: 700;}
    .sidebar a.active::after {content: '';position: absolute;right: 0;top: 15%;height: 70%;width: 4px;background: var(--active-text);border-radius: 4px 0 0 4px;}
    .sidebar a.active i { color: var(--active-text); }
    .logout-btn {margin-top: 15px;border-radius: 12px;margin-left: 15px;margin-right: 15px;background: #fff5f5;color: var(--danger-red) !important;justify-content: center;}
    .logout-btn:hover {background: #fff0f0 !important;color: #c0392b !important;padding-left: 25px !important; }
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-thumb { background: #e0e5f2; border-radius: 10px; }
    .badge-notif {
        background: var(--danger-red);
        color: white;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 50px;
        position: absolute;
        right: 20px;
        box-shadow: 0px 2px 5px rgba(231, 76, 60, 0.3);
    }

    .sidebar-toggle-btn {
        display: none;
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1100;
        background: var(--active-text);
        color: #fff;
        border: none;
        width: 46px;
        height: 46px;
        border-radius: 12px;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        cursor: pointer;
        box-shadow: 0px 4px 12px rgba(67, 24, 255, 0.3);
    }
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(27, 37, 75, 0.4);
        z-index: 999;
    }
    .sidebar-overlay.active { display: block; }
    
    @media (max-width: 768px) {
        .sidebar { left: -100%; }
        .sidebar.mobile-open { left: 0; }
        body { margin-left: 0 !important; }
        .sidebar-toggle-btn { display: flex; }
    }
</style>
<button class="sidebar-toggle-btn" id="sidebarToggleBtn" onclick="toggleMobileSidebar()">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="jdlogo.png" alt="JD Logo">
        <h4>JDTech Solutions</h4> 
        <div class="user-info-box">
            <span class="user-name-display"><?php echo htmlspecialchars($display_name); ?></span>
            <span class="user-dept-display"><?php echo htmlspecialchars($display_dept); ?></span>
            <span class="role-badge">
                <?php 
                    if($user_role == 'HR') echo 'ADMIN';
                    elseif($user_role == 'CBD') echo 'COMPENSATION';
                    elseif($user_role == 'GroupHead') echo 'GROUP HEAD';
                    elseif($user_role == 'DeptHead') echo 'DEPT. HEAD';
                    elseif($user_role == 'UnitHead') echo 'UNIT HEAD';
                    elseif($user_role == 'President') echo 'PRESIDENT';
                    else echo 'STAFF';
                ?>
            </span>
        </div>
    </div> 
    <div class="menu-section">
        <?php if($user_role == 'HR'): ?>
            <div class="menu-label">Main</div>
            <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>
            <div class="menu-label">Administration</div>
            <a href="hr_management.php" class="<?php echo ($current_page == 'hr_management.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-sliders"></i> System Control
            </a>
            <div class="menu-label">Personnel</div>
            <a href="employees.php" class="<?php echo ($current_page == 'employees.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-users"></i> Employee List
            </a>
            <a href="reports.php" class="<?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i> General Reports
            </a>

        <?php elseif($user_role == 'CBD'): ?>
            <div class="menu-label">Main</div>
            <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>
            <div class="menu-label">Master Data</div>
            <a href="employees.php" class="<?php echo ($current_page == 'employees.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-user-group"></i> Employee List
            </a>
            <a href="all_attendance_logs.php" class="<?php echo ($current_page == 'all_attendance_logs.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> Attendance Logs
            </a>
            <div class="menu-label">Compliance</div>
            <a href="leave_reports.php" class="<?php echo ($current_page == 'leave_reports.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-check"></i> Leave Reports
            </a>
            <a href="ot_reports.php" class="<?php echo ($current_page == 'ot_reports.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-stopwatch"></i> Overtime Reports
            </a>
            <a href="ob_reports.php" class="<?php echo ($current_page == 'ob_reports.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-briefcase"></i> OB Reports
            </a>
            <a href="missed_log_reports.php" class="<?php echo ($current_page == 'missed_log_reports.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-xmark"></i> Missed Logs Reports
            </a>
              <a href="reports.php" class="<?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i> General Reports
            </a>
            <div class="menu-label" style="color: var(--danger-red);">Override Management</div>
            <a href="revert_application.php" class="<?php echo ($current_page == 'revert_application.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-rotate-left" style="color: var(--danger-red);"></i> Revert Application
            </a>

        <?php elseif($user_role == 'President'): ?>
            <div class="menu-label">Main</div>
            <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>
            <a href="my_profile.php?id=<?php echo $my_employee_id; ?>" class="<?php echo ($current_page == 'my_profile.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-circle-user"></i> My Profile
            </a>
            <div class="menu-label">Applications</div>
            <a href="apply_leave.php" class="<?php echo ($current_page == 'apply_leave.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-plus"></i> Apply Leave
            </a>
            <div class="menu-label">My History</div>
            <a href="leave_history.php" class="<?php echo ($current_page == 'leave_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-history"></i> Leave History
            </a>
            <div class="menu-label">Master Data</div>
            <a href="employees.php" class="<?php echo ($current_page == 'employees.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-user-group"></i> Employee List
            </a>
            <a href="team_leave_balances.php" class="<?php echo ($current_page == 'team_leave_balances.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-sack-dollar"></i> Team Leave Balances
            </a>
            <div class="menu-label">Reports</div>
            <a href="reports.php" class="<?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i> General Reports
            </a>
            <div class="menu-label" style="color: var(--active-text);">Approvals</div>
            <a href="pending_approvals.php" class="<?php echo ($current_page == 'pending_approvals.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-bell"></i> Pending for Approval
                <?php if($badge_count > 0): ?><span class="badge-notif"><?php echo $badge_count; ?></span><?php endif; ?>
            </a>
            <a href="approval_history.php" class="<?php echo ($current_page == 'approval_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-clipboard-check"></i> Approval History
            </a>

        <?php elseif($user_role == 'GroupHead'): ?>
            <div class="menu-label">Main</div>
            <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>
            <a href="my_profile.php?id=<?php echo $my_employee_id; ?>" class="<?php echo ($current_page == 'my_profile.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-circle-user"></i> My Profile
            </a>
            <div class="menu-label">Applications</div>
            <a href="apply_leave.php" class="<?php echo ($current_page == 'apply_leave.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-plus"></i> Apply Leave
            </a>
            <a href="apply_ob.php" class="<?php echo ($current_page == 'apply_ob.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-map-location-dot"></i> Apply OB
            </a>
            <div class="menu-label">My History</div>
            <a href="leave_history.php" class="<?php echo ($current_page == 'leave_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-history"></i> Leave History
            </a>
            <a href="ob_history.php" class="<?php echo ($current_page == 'ob_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> OB History
            </a>
            <div class="menu-label">Team</div>
            <a href="team_leave_balances.php" class="<?php echo ($current_page == 'team_leave_balances.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-sack-dollar"></i> Team Leave Balances
            </a>
            <div class="menu-label" style="color: var(--active-text);">Approvals</div>
            <a href="pending_approvals.php" class="<?php echo ($current_page == 'pending_approvals.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-bell"></i> Pending for Approval
                <?php if($badge_count > 0): ?><span class="badge-notif"><?php echo $badge_count; ?></span><?php endif; ?>
            </a>
            <a href="approval_history.php" class="<?php echo ($current_page == 'approval_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-clipboard-check"></i> Approval History
            </a>

        <?php else: ?>
            <div class="menu-label">Main</div>
            <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>
            <a href="my_profile.php?id=<?php echo $my_employee_id; ?>" class="<?php echo ($current_page == 'my_profile.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-circle-user"></i> My Profile
            </a>
            <a href="my_logs.php" class="<?php echo ($current_page == 'my_logs.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-list-ul"></i> My Attendance
            </a>

            <div class="menu-label">Applications</div>
            <a href="apply_leave.php" class="<?php echo ($current_page == 'apply_leave.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-plus"></i> Apply Leave
            </a>  
            <a href="apply_ot.php" class="<?php echo ($current_page == 'apply_ot.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-business-time"></i> Apply Overtime
            </a>
            <a href="apply_ob.php" class="<?php echo ($current_page == 'apply_ob.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-map-location-dot"></i> Apply OB
            </a>  
            <a href="apply_missed_log.php" class="<?php echo ($current_page == 'apply_missed_log.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-xmark"></i> Apply Missed Log
            </a>

            <div class="menu-label">My History</div>
            <a href="leave_history.php" class="<?php echo ($current_page == 'leave_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-history"></i> Leave History
            </a>      
            <a href="ot_history.php" class="<?php echo ($current_page == 'ot_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-hourglass-end"></i> OT History
            </a>
            <a href="ob_history.php" class="<?php echo ($current_page == 'ob_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> OB History
            </a>      
            <a href="missed_log_history.php" class="<?php echo ($current_page == 'missed_log_history.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-rectangle-list"></i> Missed Log History
            </a>

            <?php if($is_head == 1 || in_array($user_role, $head_roles)): ?>
                <?php if($user_role == 'DeptHead'): ?>
                    <div class="menu-label">Team</div>
                    <a href="team_leave_balances.php" class="<?php echo ($current_page == 'team_leave_balances.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-sack-dollar"></i> Team Leave Balances
                    </a>
                <?php endif; ?>
                <div class="menu-label" style="color: var(--active-text);">Approvals</div>
                <a href="pending_approvals.php" class="<?php echo ($current_page == 'pending_approvals.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-bell"></i> Pending for Approval
                    <?php if($badge_count > 0): ?><span class="badge-notif"><?php echo $badge_count; ?></span><?php endif; ?>
                </a>
                <a href="approval_history.php" class="<?php echo ($current_page == 'approval_history.php') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-clipboard-check"></i> Approval History
                </a>
            <?php endif; ?>
        <?php endif; ?>   

        <div style="padding: 20px 0;">
            <a href="logout.php" class="logout-btn">
                <i class="fa-solid fa-power-off"></i> Logout
            </a>
        </div>
    </div>
</div>

<script>
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    window.addEventListener('load', () => {
        const scrollPos = sessionStorage.getItem('sidebarScrollPos');
        if (scrollPos) {
            sidebar.scrollTop = scrollPos;
        }
    });
    sidebar.addEventListener('scroll', () => {
        sessionStorage.setItem('sidebarScrollPos', sidebar.scrollTop);
    });
    document.addEventListener('contextmenu', e => e.preventDefault());
    document.addEventListener('keydown', e => {
        if (e.key === "F12") { e.preventDefault(); }
        if (e.ctrlKey && e.shiftKey && (e.key === "I" || e.key === "J" || e.key === "C")) { e.preventDefault(); }
        if (e.ctrlKey && e.key === "u") { e.preventDefault(); }
    });
    function toggleMobileSidebar() {
        sidebar.classList.toggle('mobile-open');
        sidebarOverlay.classList.toggle('active');
    }
    function closeMobileSidebar() {
        sidebar.classList.remove('mobile-open');
        sidebarOverlay.classList.remove('active');
    }
    document.querySelectorAll('.sidebar a').forEach(function(link) {
        link.addEventListener('click', function() {
            closeMobileSidebar();
        });
    });
</script>