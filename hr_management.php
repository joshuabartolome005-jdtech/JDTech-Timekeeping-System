<?php
include 'config.php';
if(!isset($_SESSION['user']) || ($_SESSION['user']['role'] != 'HR' && $_SESSION['user']['role'] != 'CBD')) { 
    header("Location: dashboard.php"); 
    exit(); }
$user_id = $_SESSION['user']['employee_id'];
$current_role = $_SESSION['user']['role'];
$is_edit_mode = false;
$is_view_only = false; 
$emp_data = [];

$alert_message = '';
$alert_type = ''; 
$redirect_on_confirm = ''; 

if(isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $stmt_edit = $conn->prepare("SELECT * FROM users WHERE employee_id = ?");
    $stmt_edit->bind_param("s", $edit_id);
    $stmt_edit->execute();
    $result = $stmt_edit->get_result();
    if($result->num_rows > 0) {
        $emp_data = $result->fetch_assoc();
        $is_edit_mode = true;
    }
    if($current_role === 'CBD' || (isset($_GET['mode']) && $_GET['mode'] === 'view')) {
        $is_view_only = true;
    }}

if($is_view_only && $is_edit_mode && $_SERVER['REQUEST_METHOD'] === 'GET') {
    audit_log($conn, 'VIEW_USER', 'EMPLOYEE', $emp_data['employee_id'], 'Viewed employee profile (read-only)');
}

$stmt_user = $conn->prepare("SELECT fullname, role, department, is_head FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();
if(isset($_POST['save_account']) && !$is_view_only){
    $eid = $_POST['eid'];
    $ln = $_POST['ln'];
    $fn = $_POST['fn'];
    $mn = $_POST['mn'];
    $nickname = $_POST['nickname'];
    $fullname = "$ln, $fn $mn"; 
    $em = $_POST['em'];
    $personal_em = $_POST['personal_em'];
    $contact = $_POST['contact'];
    $ro = $_POST['ro'];
    $has_unit_head = isset($_POST['has_unit_head']) ? 1 : 0; 
    $raw_pw = $_POST['pw'];
    $force_update = $emp_data['is_first_login'] ?? 0; 
    if($is_edit_mode) {
        if($raw_pw !== $emp_data['password']) {
            $pw = password_hash($raw_pw, PASSWORD_DEFAULT);
            $force_update = 1; 
        } else {
            $pw = $raw_pw; 
        }
    } else {
        $pw = password_hash($raw_pw, PASSWORD_DEFAULT);
        $force_update = 1;
    }
    $civil_status = $_POST['civil_status'];
    $gender = $_POST['gender'];
    $bday = $_POST['birthday'];
    $is_parent = $_POST['is_parent'];
    $addr = $_POST['address'];
    $dept = $_POST['department'];
    $group_dept = $_POST['group_department'];
    $pos = $_POST['position']; 
    $job_grade = $_POST['job_grade'];
    $job_code = $_POST['job_code'];
    $emp_type = $_POST['emp_type'];
    $dh = $_POST['date_hired'];
    $dr = $_POST['date_regularized']; 
    $status = $_POST['status']; 
    $effectivity_resigned = !empty($_POST['date_resigned']) ? $_POST['date_resigned'] : NULL;
    $emergency_name = $_POST['emergency_name'];
    $emergency_contact = $_POST['emergency_contact'];
    $is_head = ($ro != 'Staff' && $ro != 'HR' && $ro != 'CBD') ? 1 : 0;
    $profile_pic = $emp_data['profile_pic'] ?? 'default.png';

    $reset_failed_attempts = ($status === 'Active') ? 1 : 0;

    if(isset($_FILES['profile_img']) && $_FILES['profile_img']['error'] == 0 && $current_role == 'HR') {
        $target_dir = "uploads/profiles/";
        if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); } 
        $ext = strtolower(pathinfo($_FILES["profile_img"]["name"], PATHINFO_EXTENSION));
        $new_filename = $eid . "_" . time() . "." . $ext;
        $target_file = $target_dir . $new_filename;
        if (move_uploaded_file($_FILES["profile_img"]["tmp_name"], $target_file)) {
            $profile_pic = $new_filename;
        }
    }
    if($is_edit_mode) {
        $sql = "UPDATE users SET 
                last_name=?, first_name=?, middle_name=?, nickname=?, fullname=?, 
                email=?, personal_email=?, contact_number=?, role=?, password=?, 
                civil_status=?, gender=?, birthday=?, is_parent=?, address=?, 
                department=?, group_department=?, position=?, job_grade=?, job_code=?, 
                employment_type=?, date_hired=?, date_regularized=?, status=?, 
                date_resigned=?,
                emergency_contact_name=?, emergency_contact_number=?, is_head=?, profile_pic=?,
                has_unit_head=?, is_first_login=?,
                failed_attempts=IF(? = 1, 0, failed_attempts)
                WHERE employee_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssssssssssssssssssssssisisii", 
            $ln, $fn, $mn, $nickname, $fullname, 
            $em, $personal_em, $contact, $ro, $pw, 
            $civil_status, $gender, $bday, $is_parent, $addr, 
            $dept, $group_dept, $pos, $job_grade, $job_code, 
            $emp_type, $dh, $dr, $status, 
            $effectivity_resigned,
            $emergency_name, $emergency_contact, $is_head, $profile_pic, $has_unit_head, $force_update, 
            $reset_failed_attempts, $eid
        );
        $msg = "Account updated successfully!";
    } else {
        $default_annual = 25.0;
        $default_mandatory = 5.0;
        $sql = "INSERT INTO users (
                employee_id, last_name, first_name, middle_name, nickname, fullname, 
                email, personal_email, contact_number, role, password, 
                civil_status, gender, birthday, is_parent, address, 
                department, group_department, position, job_grade, job_code, 
                employment_type, date_hired, date_regularized, status, 
                date_resigned,
                emergency_contact_name, emergency_contact_number, 
                is_head, is_first_login, annual_leave, mandatory_leave, profile_pic, has_unit_head, failed_attempts
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssssssssssssssssssssssssiiddsi", 
            $eid, $ln, $fn, $mn, $nickname, $fullname, 
            $em, $personal_em, $contact, $ro, $pw, 
            $civil_status, $gender, $bday, $is_parent, $addr, 
            $dept, $group_dept, $pos, $job_grade, $job_code, 
            $emp_type, $dh, $dr, $status, 
            $effectivity_resigned,
            $emergency_name, $emergency_contact, 
            $is_head, $force_update, $default_annual, $default_mandatory, $profile_pic, $has_unit_head
        );
        $msg = "Account created successfully!";
    }
    if($stmt->execute()){

        if($is_edit_mode) {
            $watch = [
                'last_name' => $ln, 'first_name' => $fn, 'middle_name' => $mn, 'nickname' => $nickname,
                'email' => $em, 'personal_email' => $personal_em, 'contact_number' => $contact,
                'role' => $ro, 'civil_status' => $civil_status, 'gender' => $gender, 'birthday' => $bday,
                'is_parent' => $is_parent, 'address' => $addr, 'department' => $dept,
                'group_department' => $group_dept, 'position' => $pos, 'job_grade' => $job_grade,
                'job_code' => $job_code, 'employment_type' => $emp_type, 'date_hired' => $dh,
                'date_regularized' => $dr, 'status' => $status, 'date_resigned' => $effectivity_resigned,
                'emergency_contact_name' => $emergency_name, 'emergency_contact_number' => $emergency_contact,
                'has_unit_head' => $has_unit_head, 'profile_pic' => $profile_pic
            ];
            $sensitive_fields = ['address', 'birthday', 'personal_email', 'contact_number', 'emergency_contact_name', 'emergency_contact_number'];

            $changes = [];
            foreach($watch as $col => $new_val) {
                $old_val = $emp_data[$col] ?? '';
                if((string)$old_val !== (string)$new_val) {
                    $changes[] = in_array($col, $sensitive_fields) ? "$col: changed" : "$col: '$old_val' -> '$new_val'";
                }
            }

            $password_changed = ($raw_pw !== $emp_data['password']);
            if($password_changed) { $changes[] = 'password: changed'; }

            audit_log($conn, 'UPDATE_USER', 'EMPLOYEE', $eid, $changes ? implode(' | ', $changes) : 'Saved with no field changes');

            if($password_changed) {
                audit_log($conn, 'PASSWORD_RESET', 'EMPLOYEE', $eid, 'Password set by admin; first-login password change forced');
            }
            if(($emp_data['status'] ?? '') === 'Blocked' && $status === 'Active') {
                audit_log($conn, 'UNBLOCK_USER', 'EMPLOYEE', $eid, 'Status changed from Blocked to Active; failed attempts reset');
            }
        } else {
            audit_log($conn, 'CREATE_USER', 'EMPLOYEE', $eid, "Role: $ro | Dept: $dept | Group: $group_dept | Position: $pos | Type: $emp_type | Status: $status");
        }

        $old_groups = []; $old_units = [];
        $q_old_g = $conn->prepare("SELECT department_name FROM group_head_assignments WHERE group_head_id = ?");
        $q_old_g->bind_param("s", $eid);
        $q_old_g->execute();
        $r_old_g = $q_old_g->get_result();
        while($x = $r_old_g->fetch_assoc()) { $old_groups[] = $x['department_name']; }
        $q_old_g->close();

        $q_old_u = $conn->prepare("SELECT unit_name FROM dept_head_assignments WHERE dept_head_id = ?");
        $q_old_u->bind_param("s", $eid);
        $q_old_u->execute();
        $r_old_u = $q_old_u->get_result();
        while($x = $r_old_u->fetch_assoc()) { $old_units[] = $x['unit_name']; }
        $q_old_u->close();
        // ==========================================================

        $conn->query("DELETE FROM group_head_assignments WHERE group_head_id = '$eid'");
        $conn->query("DELETE FROM dept_head_assignments WHERE dept_head_id = '$eid'");

        if($ro == 'GroupHead' && isset($_POST['assigned_depts'])){
            $stmt_dept = $conn->prepare("INSERT INTO group_head_assignments (group_head_id, department_name) VALUES (?, ?)");
            foreach($_POST['assigned_depts'] as $d_name){
                $stmt_dept->bind_param("ss", $eid, $d_name);
                $stmt_dept->execute();
            }}
        if($ro == 'DeptHead' && isset($_POST['assigned_units'])){
            $stmt_unit = $conn->prepare("INSERT INTO dept_head_assignments (dept_head_id, unit_name) VALUES (?, ?)");
            foreach($_POST['assigned_units'] as $u_name){
                $stmt_unit->bind_param("ss", $eid, $u_name);
                $stmt_unit->execute();
            }}

        $new_groups = ($ro == 'GroupHead' && isset($_POST['assigned_depts'])) ? $_POST['assigned_depts'] : [];
        $new_units  = ($ro == 'DeptHead' && isset($_POST['assigned_units'])) ? $_POST['assigned_units'] : [];

        $g_added = array_diff($new_groups, $old_groups); $g_removed = array_diff($old_groups, $new_groups);
        if($g_added || $g_removed) {
            audit_log($conn, 'UPDATE_ASSIGNMENT', 'GROUP_HEAD_ASSIGNMENT', $eid,
                'Added: ' . ($g_added ? implode(', ', $g_added) : '-') . ' | Removed: ' . ($g_removed ? implode(', ', $g_removed) : '-'));
        }
        $u_added = array_diff($new_units, $old_units); $u_removed = array_diff($old_units, $new_units);
        if($u_added || $u_removed) {
            audit_log($conn, 'UPDATE_ASSIGNMENT', 'DEPT_HEAD_ASSIGNMENT', $eid,
                'Added: ' . ($u_added ? implode(', ', $u_added) : '-') . ' | Removed: ' . ($u_removed ? implode(', ', $u_removed) : '-'));
        }

        $alert_message = $msg;
        $alert_type = 'success';
        $redirect_on_confirm = 'employees.php';
    } else {
        audit_log($conn, $is_edit_mode ? 'UPDATE_USER_FAILED' : 'CREATE_USER_FAILED', 'EMPLOYEE', $eid, 'DB error: ' . $conn->error);
        $alert_message = 'Error: ' . $conn->error;
        $alert_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - <?php echo $is_view_only ? 'View Employee' : ($is_edit_mode ? 'Edit Employee' : 'User Management'); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary-dark: #2c3e50; --bg-gray: #f4f7f6; --admin-blue: #3498db; --success-green: #27ae60; --cbd-gold: #f39c12; }
        body { font-family: 'Segoe UI', sans-serif; display: flex; margin: 0; background-color: var(--bg-gray); }
        .content { flex: 1; margin-left: 20px; padding: 20px 40px; box-sizing: border-box; min-height: 100vh; display: flex; justify-content: center; }
        .profile-layout { display: flex; gap: 30px; align-items: flex-start; width: 100%; max-width: 1000px; }
        .side-info { flex: 0 0 280px; background: white; padding: 25px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); text-align: center; border-top: 5px solid var(--cbd-gold); }
        .form-card { flex: 1; background: white; padding: 35px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-top: 5px solid <?php echo $is_view_only ? 'var(--cbd-gold)' : ($is_edit_mode ? '#f39c12' : 'var(--admin-blue)'); ?>; margin-bottom: 50px;}
        
        .side-name { font-size: 18px; font-weight: bold; color: var(--primary-dark); margin: 15px 0 5px; }
        .side-role { font-size: 14px; color: var(--admin-blue); font-weight: 600; margin-bottom: 20px; display: block; }
        .side-detail { text-align: left; background: #f9f9f9; padding: 10px; border-radius: 8px; margin-bottom: 10px; }
        .side-detail label { margin-bottom: 2px; }
        .side-detail span { font-size: 13px; font-weight: bold; color: #333; }

        h2 { margin-top: 0; color: var(--primary-dark); border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .two-cols { grid-column: span 2; }
        .section-label { font-size: 11px; font-weight: bold; color: var(--admin-blue); text-transform: uppercase; margin: 25px 0 10px; display: block; border-left: 3px solid var(--admin-blue); padding-left: 10px; }
        label { font-size: 12px; color: #7f8c8d; margin-bottom: 5px; display: block; font-weight: 600; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #dcdde1; border-radius: 8px; box-sizing: border-box; font-size: 13px; background: #fafafa; }
        input:focus, select:focus, textarea:focus { border-color: var(--admin-blue); outline: none; background: #fff; }
        input[disabled], select[disabled], textarea[disabled] { background: #eeeeee !important; cursor: not-allowed; color: #555; border: 1px solid #ccc; }
        .btn-save { background: var(--success-green); color: white; border: none; padding: 15px; width: 100%; border-radius: 8px; cursor: pointer; margin-top: 30px; font-weight: bold; font-size: 16px; transition: 0.3s; }
        .btn-cancel { background: #95a5a6; color: white; text-decoration: none; display:block; text-align: center; padding: 12px; width: 100%; border-radius: 8px; margin-top: 10px; font-weight: bold; font-size: 14px; }
        .btn-back { background: var(--primary-dark); color: white; text-decoration: none; display: block; text-align: center; padding: 15px; width: 100%; border-radius: 8px; margin-top: 30px; font-weight: bold; font-size: 16px; }
        .hr-note { background: #ebf5fb; color: #2e86c1; padding: 12px; border-radius: 8px; font-size: 13px; border-left: 4px solid var(--admin-blue); margin-bottom: 20px; }
        .profile-container { display: flex; justify-content: center; margin-bottom: 25px; position: relative; }
        .profile-wrapper { position: relative; width: 120px; height: 120px; }
        .profile-preview { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .upload-btn-wrapper {position: absolute; bottom: 0; right: 0; background: var(--admin-blue);width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; cursor: pointer; border: 2px solid white; transition: 0.3s;}
        .upload-btn-wrapper:hover { background: #2980b9; transform: scale(1.1); }
        .special-option-box { background: #fdf2e9; padding: 10px; border-radius: 8px; border: 1px dashed #e67e22; margin-top: 5px; display: none; }
        .multi-select-box {height: 100px; overflow-y: auto; background: #fff; border: 1px solid #ddd; padding: 5px; border-radius: 5px;}

        @media (max-width: 768px) {
            .content { margin-left: 0 !important; padding: 70px 15px 20px !important; }
            .profile-layout { flex-direction: column; }
            .side-info { flex: 1 1 auto; width: 100%; box-sizing: border-box; }
            .form-card { padding: 20px; }
            .form-grid { grid-template-columns: 1fr; }
            .two-cols { grid-column: span 1; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="content">
        <div class="profile-layout">
            
            <?php if($is_view_only): ?>
            <div class="side-info">
                <?php 
                    $pic = (!empty($emp_data['profile_pic'])) ? $emp_data['profile_pic'] : 'default.png';
                    $pic_path = "uploads/profiles/" . $pic;
                    if(!file_exists($pic_path)) { $pic_path = "uploads/profiles/default.png"; }
                ?>
                <img src="<?php echo $pic_path; ?>" class="profile-preview" style="width:150px; height:150px;">
                <div class="side-name"><?php echo $emp_data['fullname'] ?? 'User'; ?></div>
                <span class="side-role"><?php echo $emp_data['position'] ?? 'Position'; ?></span>
                
                <div class="side-detail">
                    <label>Employee ID</label>
                    <span><?php echo $emp_data['employee_id'] ?? '-'; ?></span>
                </div>
                <div class="side-detail">
                    <label>Company Email</label>
                    <span><?php echo $emp_data['email'] ?? '-'; ?></span>
                </div>
                <div class="side-detail">
                    <label>Contact Number</label>
                    <span><?php echo $emp_data['contact_number'] ?? '-'; ?></span>
                </div>
                <div class="side-detail">
                    <label>Status</label>
                    <span style="color:var(--success-green)"><?php echo $emp_data['status'] ?? '-'; ?></span>
                </div>
            </div>
            <?php endif; ?>

            <div class="form-card">
                <h2>
                    <?php 
                        if($is_view_only) echo "Employee Profile View";
                        else echo $is_edit_mode ? 'Edit Employee Account' : 'Employee Account Creation'; 
                    ?>
                </h2>  
                <?php if(!$is_edit_mode && !$is_view_only): ?>
                <div class="hr-note">
                    <strong>Auto-Provision:</strong> 25 Annual and 5 Mandatory leave credits will be granted.
                </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data">   
                    <?php if(!$is_view_only): ?>
                    <div class="profile-container">
                        <div class="profile-wrapper">
                            <?php 
                                $pic = (!empty($emp_data['profile_pic'])) ? $emp_data['profile_pic'] : 'default.png';
                                $pic_path = "uploads/profiles/" . $pic;
                                if(!file_exists($pic_path)) { $pic_path = "uploads/profiles/default.png"; }
                            ?>
                            <img src="<?php echo $pic_path; ?>" id="imgPreview" class="profile-preview">
                            <?php if($current_role == 'HR'): ?>
                                <label for="profile_img" class="upload-btn-wrapper" title="Upload Photo">
                                    <i class="fa-solid fa-camera"></i>
                                </label>
                                <input type="file" name="profile_img" id="profile_img" style="display: none;" accept="image/*" onchange="previewImage(this)">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <span class="section-label">Identity & Personal Details</span>
                    <div class="form-grid">
                        <div>
                            <label>Employee ID</label>
                            <input type="text" name="eid" value="<?php echo $emp_data['employee_id'] ?? ''; ?>" <?php echo ($is_edit_mode || $is_view_only) ? 'disabled' : 'required'; ?> placeholder="Ex: 2026-001">
                            <?php if($is_edit_mode): ?><input type="hidden" name="eid" value="<?php echo $emp_data['employee_id']; ?>"><?php endif; ?>
                        </div>
                        <div>
                            <label>Nickname</label>
                            <input type="text" name="nickname" value="<?php echo $emp_data['nickname'] ?? ''; ?>" <?php echo $is_view_only ? 'disabled' : ''; ?> placeholder="Optional">
                        </div>
                        <div>
                            <label>Role / Access Level</label>
                            <select name="ro" id="roleSelector" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <option value="Staff" <?php echo (isset($emp_data['role']) && $emp_data['role'] == 'Staff') ? 'selected' : ''; ?>>Staff</option>
                                <option value="UnitHead" <?php echo (isset($emp_data['role']) && $emp_data['role'] == 'UnitHead') ? 'selected' : ''; ?>>Unit Head</option>
                                <option value="DeptHead" <?php echo (isset($emp_data['role']) && $emp_data['role'] == 'DeptHead') ? 'selected' : ''; ?>>Department Head</option>
                                <option value="GroupHead" <?php echo (isset($emp_data['role']) && $emp_data['role'] == 'GroupHead') ? 'selected' : ''; ?>>Group Head</option>
                                <option value="President" <?php echo (isset($emp_data['role']) && $emp_data['role'] == 'President') ? 'selected' : ''; ?>>President</option>
                                <option value="HR" <?php echo (isset($emp_data['role']) && $emp_data['role'] == 'HR') ? 'selected' : ''; ?>>HR Admin</option>
                                <option value="CBD" <?php echo (isset($emp_data['role']) && $emp_data['role'] == 'CBD') ? 'selected' : ''; ?>>CBD Admin</option>
                            </select>
                            
                            <div id="unit_head_toggle" class="special-option-box">
                                <label style="color: #e67e22; cursor:pointer;">
                                    <input type="checkbox" name="has_unit_head" value="1" <?php echo (isset($emp_data['has_unit_head']) && $emp_data['has_unit_head'] == 1) ? 'checked' : ''; ?>> 
                                    With Unit Head Approval
                                </label>
                            </div>
                            <div id="group_dept_selector" class="special-option-box" style="background:#e8f4fd; border-color:#3498db;">
                                <label style="color: #2980b9;">Assign Departments:</label>
                                <div class="multi-select-box">
                                    <?php 
                                    $depts_list = ['Executive Management','Consultant', 'Accounting and Finance Group (AFG)', 'Admin Support Group (ASG)', 'Information Technology Department', 
                                                    'Human Resources Department', 'Lending Department','Internal Audit Department','Risk and Compliance Department',
                                                    'Marketing and Communications Group (MCG)', 'Legal Department','Credit Management Group (CMG)','Branch Sales Group (BSG)', 'Branch Operations Group (BOG)' ];
                                    $assigned_g = [];
                                    if($is_edit_mode){
                                        $res_g = $conn->query("SELECT department_name FROM group_head_assignments WHERE group_head_id = '".$emp_data['employee_id']."'");
                                        while($row_g = $res_g->fetch_assoc()) $assigned_g[] = $row_g['department_name'];
                                    }
                                    foreach($depts_list as $dl): ?>
                                        <label style="font-weight:normal;"><input type="checkbox" name="assigned_depts[]" value="<?php echo $dl; ?>" <?php echo in_array($dl, $assigned_g) ? 'checked' : ''; ?>> <?php echo $dl; ?></label><br>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div id="dept_unit_selector" class="special-option-box" style="background:#e8f8f5; border-color:#27ae60;">
                                <label style="color: #27ae60;">Assign Units:</label>
                                <div class="multi-select-box">
                                    <?php 
                                    $units_list = ['Accounting Department','Acquired Assets Department','Angeles City Branch','Arayat Branch',
    'Bacolor Branch','Banking Solutions Department',
    'Branch Manager ABO','Branch Manager FPB','Branch Manager GJB','Branch Manager LSTB',
    'Branch Manager MCBO','Branch Manager MGBO','Branch Manager PLBO','Branch Manager SABO','Branch Manager SRBO','Branch Manager STBO',
    'Branch Operations Group (BOG)','Branch Sales Group (BSG)',
    'Candaba Branch','Capas Branch','Compensation and Benefits Department','Corporate Planning and Communications Department','Credit Services Department',
    'Documentation Management Unit - Lending Department','Floridablanca Poblacion Branch','Floridablanca San Jose Branch',
    'General Services Department','Guagua JASA Branch','Guagua Plaza Branch Lite',
    'Human Resources Department','Internal Audit Department',
    'Legal Department','Lending Department','Loans Administration Department','Lubao Sta. Cruz Branch','Lubao Sto. Tomas Branch',
    'Mabalacat Branch','Macabebe Branch','Magalang Branch','Marketing Department','Minalin Branch','Network Support Unit',
    'Porac Branch','President Driver','Property Management Department','Pulilan Branch',
    'Remedial Management Department','Risk and Compliance Department',
    'Sales Unit - Lending Department','San Fernando Dolores Branch',
    'San Fernando JASA Branch','Secretariat','Security Officer','Solutions Support Unit','Sta. Ana Branch','Sta. Rita Branch','Sto. Tomas Branch',
    'Technical Support Unit','Treasury Department'
];
                                    $assigned_u = [];
                                    if($is_edit_mode){
                                        $res_u = $conn->query("SELECT unit_name FROM dept_head_assignments WHERE dept_head_id = '".$emp_data['employee_id']."'");
                                        while($row_u = $res_u->fetch_assoc()) $assigned_u[] = $row_u['unit_name'];
                                    }
                                    foreach($units_list as $ul): ?>
                                        <label style="font-weight:normal;"><input type="checkbox" name="assigned_units[]" value="<?php echo $ul; ?>" <?php echo in_array($ul, $assigned_u) ? 'checked' : ''; ?>> <?php echo $ul; ?></label><br>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div><label>Last Name</label><input type="text" name="ln" value="<?php echo $emp_data['last_name'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>First Name</label><input type="text" name="fn" value="<?php echo $emp_data['first_name'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>Middle Name</label><input type="text" name="mn" value="<?php echo $emp_data['middle_name'] ?? ''; ?>" <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>Birth Date</label><input type="date" name="birthday" value="<?php echo $emp_data['birthday'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>Gender</label><select name="gender" required <?php echo $is_view_only ? 'disabled' : ''; ?>><option value="" disabled selected>Select</option><option value="Male" <?php echo (isset($emp_data['gender']) && $emp_data['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option><option value="Female" <?php echo (isset($emp_data['gender']) && $emp_data['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option></select></div>
                        <div><label>Civil Status</label><select name="civil_status" required <?php echo $is_view_only ? 'disabled' : ''; ?>><option value="" disabled selected>Select</option><?php $civ_stats = ['Single', 'Married', 'Legally Separated', 'Separated', 'Widowed'];foreach($civ_stats as $cs) {$selected = (isset($emp_data['civil_status']) && $emp_data['civil_status'] == $cs) ? 'selected' : '';echo "<option value='$cs' $selected>$cs</option>"; }?></select></div>
                        <div><label>Mother/Father? (Parent)</label><select name="is_parent" required <?php echo $is_view_only ? 'disabled' : ''; ?>><option value="No" <?php echo (isset($emp_data['is_parent']) && $emp_data['is_parent'] == 'No') ? 'selected' : ''; ?>>No</option><option value="Yes" <?php echo (isset($emp_data['is_parent']) && $emp_data['is_parent'] == 'Yes') ? 'selected' : ''; ?>>Yes</option></select></div>
                        <div class="two-cols"><label>Home Address</label><textarea name="address" rows="1" required <?php echo $is_view_only ? 'disabled' : ''; ?>><?php echo $emp_data['address'] ?? ''; ?></textarea></div>
                    </div>
                    <span class="section-label">Contact Information</span>
                    <div class="form-grid">
                        <div><label>Company Email</label><input type="email" name="em" value="<?php echo $emp_data['email'] ?? ''; ?>" placeholder="email@bof.com.ph" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>Personal Email</label><input type="email" name="personal_em" value="<?php echo $emp_data['personal_email'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>Contact Number</label><input type="text" name="contact" value="<?php echo $emp_data['contact_number'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>Emergency Contact Name</label><input type="text" name="emergency_name" value="<?php echo $emp_data['emergency_contact_name'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                        <div><label>Emergency Contact Number</label><input type="text" name="emergency_contact" value="<?php echo $emp_data['emergency_contact_number'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>></div>
                    </div>
                    <span class="section-label">Employment & Job Assignment</span>
                    <div class="form-grid">
                        <div class="two-cols">
                            <label>Job Title / Position</label>
                            <select name="position" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <option value="" disabled selected>Select Position</option>
                                <?php 
                                      $job_titles = ["President and CEO","Senior Executive Vice President","Executive Vice President","Account Officer", "Accounting and Finance Group Head", 
                            "Accounting Assistant", "Accounting Head", " Accounting Specialist", "Acquired Assets Department Assistant","Acquired Assets Department Associate",
                            "Acquired Assets Department Head", "AMLA Associate", "AMLA Assistant", "Area Operations Officer/ Branch Operations Officer - Area 1 Head",
                            "Area Operations Officer/ Branch Operations Officer - Area 2 Head", "Area Operations Officer/ Branch Operations Officer - Area 3 Head",
                            "Area Operations Officer/ Branch Operations Officer - Area 4 Head", "Area Sales Manager/Branch Manager", "Audit Assistant", "Audit Associate", 
                            "Audit Specialist", "AVP - Admin Support Group", "AVP - Branch Sales Group", "AVP - Human Resources Department", "AVP - Information Technology Department",
                            "AVP - Property Management Department", "Banking Channels Associate", "Banking Channels Specialist", "Banking Solutions Department Head", "Branch Manager", 
                            "Branch Operations Group Head", "Branch Operations Officer", "Branch Operations Officer (Reliever)", "Branch Operations Officer Trainee", 
                            "Branch Operations Officer Trainee Reliever", "Cashier", "Central Clearing Assistant", "Central Clearing Specialist", "Chief Compliance Officer", 
                            "Chief Financial Officer", "Chief Internal Auditor", "Chief Operating Officer", "Compensation and Benefits Associate", "Compensation and Benefits Head",
                            "Compliance Manager", "Consultant", "Corporate Planning and Communications Manager", "Corporate Secretary", "Credit Appraiser", "Credit Investigator",
                            "Credit Services Head", "Credit Specialist", "Documentation Management Assistant", "Documentation Management Associate","Driver/Messenger", 
                            "Executive Assistant", "Executive Secretary", "General Services Associate", "General Services Specialist", "General Services Supervisor", "Graphic Artist", 
                            "Human Resources Department Assistant", "Human Resources Department Associate", "Human Resources Department Specialist","Legal Counsel", "Loans Administration Assistant", "Loans Administration Associate", 
                            "Loans Administration Head", "Loans Administration Specialist", "Maintenance Personnel", "Maintenance Service Provider", "Marketing Assistant", "Marketing Head", 
                            "Messenger/Janitor", "Messenger/Maintenance", "Network Support Specialist", "Technical Support Specialist", "Technical Support Head", "Solutions Support Specialist", 
                            "Solutions Support Head", "OIC - Remedial Management Department", "Posting Assistant", "Posting Assistant/Accounting Assistant", "Posting Associate", "Posting Specialist", 
                            "Posting/Accounting Assistant", "Posting/Accounting Assistant Reliever", "Posting/Accounting Associate", "President and CEO", "Property Management Specialist", 
                            "Relationship Manager", "Remedial Management Assistant", "Risk and Information Security Officer", "Secretariat Head", "Security Officer", "Senior Relationship Manager", "Teller", "Teller/Cashier", 
                            "Teller/Cashier Reliever", "Treasury Assistant", "Treasury Associate", "Treasury Head", "VP - Credit Management Group", "VP - Legal Management Department", 
                            "VP - Lending Group", "VP - Marketing and Communications Group"];
                            foreach($job_titles as $jt) {
                                        $selected = (isset($emp_data['position']) && $emp_data['position'] == $jt) ? 'selected' : '';
                                        echo "<option value='$jt' $selected>$jt</option>"; }
                                ?>
                            </select>
                        </div>
                        <div>
                            <label>Employment Type</label>
                            <select name="emp_type" id="empTypeSelector" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <option value="Probationary" <?php echo (isset($emp_data['employment_type']) && $emp_data['employment_type'] == 'Probationary') ? 'selected' : ''; ?>>Probationary</option>
                                <option value="Regular (Permanent)" <?php echo (isset($emp_data['employment_type']) && $emp_data['employment_type'] == 'Regular (Permanent)') ? 'selected' : ''; ?>>Regular (Permanent)</option>
                                <option value="Contractual" <?php echo (isset($emp_data['employment_type']) && $emp_data['employment_type'] == 'Contractual') ? 'selected' : ''; ?>>Contractual</option>
                                <option value="Consultant" <?php echo (isset($emp_data['employment_type']) && $emp_data['employment_type'] == 'Consultant') ? 'selected' : ''; ?>>Consultant</option>
                            </select>
                        </div>
                        <div>
                            <label>Job Code</label>
                            <select name="job_code" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <option value="" disabled selected>Select Job Code</option>
                                <?php 
                                    $job_codes = ["1" => "1 - Rank and File", "2" => "2 - Supervisor/Specialist", "3" => "3 - Middle Management","4" => "4 - Senior Management", "5" => "5 - Executive Management"];
                                    foreach($job_codes as $val => $label) {
                                        $selected = (isset($emp_data['job_code']) && $emp_data['job_code'] == $val) ? 'selected' : '';
                                        echo "<option value='$val' $selected>$label</option>";
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="two-cols">
                            <label>Job Grade</label>
                            <select name="job_grade" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <option value="" disabled selected>Select Job Grade</option>
                                <?php 
                                    $job_grades = ["101" => "101 - Utility Staff", "102" => "102 - Assistant", "103" => "103 - Associate","201" => "201 - Supervisor I/Specialist I", "202" => "202 - Supervisor II/Specialist II", "203" => "203 - Supervisor III/Specialist III","301" => "301 - Assistant Manager", "302" => "302 - Manager", "303" => "303 - Senior Manager","401" => "401 - Assistant Vice President", "402" => "402 - Vice President", "403" => "403 - Senior Vice President","501" => "501 - Executive Vice President", "502" => "502 - Senior Executive Vice President", "503" => "503 - President"];
                                    foreach($job_grades as $val => $label) {
                                        $selected = (isset($emp_data['job_grade']) && $emp_data['job_grade'] == $val) ? 'selected' : '';
                                        echo "<option value='$val' $selected>$label</option>"; }
                                ?>
                            </select>
                        </div><div>
                            <label>Group Department</label>
                            <select name="group_department" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <?php 
                                  $groups = ['Executive Management','Consultant','Accounting and Finance Group (AFG)','Admin Support Group (ASG)','Information Technology Department', 
                        'Human Resources Department', 'Lending Department','Internal Audit Department','Risk and Compliance Department','Legal Department',
                        'Marketing and Communications Group (MCG)','Credit Management Group (CMG)','Branch Sales Group (BSG)','Branch Operations Group (BOG)','BOG Area Head 1', 
                        'BOG Area Head 2','BOG Area Head 3','BOG Area Head 4','BSG Area Head 1','BSG Area Head 2','BSG Area Head 3','BSG Area Head 4',];
                                foreach($groups as $g) {
                                    $selected = (isset($emp_data['group_department']) && $emp_data['group_department'] == $g) ? 'selected' : '';
                                    echo "<option value='$g' $selected>$g</option>";}
                                ?> 
                            </select></div>
                        <div>
                            <label>Department Section</label>
                            <select name="department" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <?php 
 $depts = ['Accounting Department','Acquired Assets Department','Angeles City Branch','Arayat Branch',
 'Bacolor Branch','Banking Solutions Department','Branch Manager ABO','Branch Manager FPB','Branch Manager GJB','Branch Manager LSTB',
 'Branch Manager MCBO','Branch Manager MGBO','Branch Manager PBO','Branch Manager PLBO','Branch Manager SABO','Branch Manager SRBO','Branch Manager STBO',
 'Branch Operations Group (BOG)','Branch Sales Group (BSG)',
 'Candaba Branch','Capas Branch','CBO_BOG','CBO_BSG','Compensation and Benefits Department','Corporate Planning and Communications Department','Credit Services Department',
 'Documentation Management Unit - Lending Department','EM',
 'Floridablanca Poblacion Branch','Floridablanca San Jose Branch','FPB',
 'General Services Department','Guagua JASA Branch','Guagua Plaza Branch Lite',
 'Human Resources Department','Internal Audit Department','ITD',
 'Legal Department','Lending Department','Loans Administration Department','LSCB','Lubao Sta. Cruz Branch','Lubao Sto. Tomas Branch',
 'Mabalacat Branch','Macabebe Branch','Magalang Branch','Marketing Department','Minalin Branch','Network Support Unit',
 'Porac Branch','President Driver','Property Management Department','Pulilan Branch',
 'Remedial Management Department','Risk and Compliance Department',
 'Sales Unit - Lending Department','San Fernando Dolores Branch','San Fernando JASA Branch','Secretariat','Security Officer',
 'SFJB','Solutions Support Unit','Sta. Ana Branch','Sta. Rita Branch','Sto. Tomas Branch','SRBO',
 'Technical Support Unit','Treasury Department'
];
foreach($depts as $d) {
                                    $selected = (isset($emp_data['department']) && $emp_data['department'] == $d) ? 'selected' : '';
                                    echo "<option value='$d' $selected>$d</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <label>Date Hired</label>
                            <input type="date" name="date_hired" value="<?php echo $emp_data['date_hired'] ?? ''; ?>" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                        </div>
                        <div>
                            <label>Date Regularized</label>
                            <input type="date" name="date_regularized" id="dateRegularized" value="<?php echo $emp_data['date_regularized'] ?? ''; ?>" <?php echo $is_view_only ? 'disabled' : ''; ?>>
                        </div>
                        <div>
                            <label>Employment Status</label>
                            <select name="status" required <?php echo $is_view_only ? 'disabled' : ''; ?>>
                                <option value="Active" <?php echo (isset($emp_data['status']) && $emp_data['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Blocked" <?php echo (isset($emp_data['status']) && $emp_data['status'] == 'Blocked') ? 'selected' : ''; ?>>Blocked</option>
                                <option value="Resigned" <?php echo (isset($emp_data['status']) && $emp_data['status'] == 'Resigned') ? 'selected' : ''; ?>>Resigned</option>
                            </select></div>
                        <div>
                            <label>Resignation Effectivity Date</label>
                            <input type="date" name="date_resigned" value="<?php echo $emp_data['date_resigned'] ?? ''; ?>" <?php echo $is_view_only ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <?php if(!$is_view_only): ?>
                        <span class="section-label"><?php echo $is_edit_mode ? 'Security (Password)' : 'Temporary Password'; ?></span>
                        <div class="form-grid">
                            <div class="two-cols">
                                <input type="password" name="pw" value="<?php echo $emp_data['password'] ?? ''; ?>" required placeholder="Enter password">
                                <small style="color: #888;">Note: Always use hashed passwords for security.</small>
                            </div></div>
                        <button type="submit" name="save_account" class="btn-save">Save Employee Data</button>
                        <a href="employees.php" class="btn-cancel">Cancel</a>
                    <?php else: ?>
                        <a href="employees.php" class="btn-back">Back to Directory</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <script>
        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('imgPreview').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }}
        const roleSelector = document.getElementById('roleSelector');
        const unitHeadToggle = document.getElementById('unit_head_toggle');
        const groupDeptSelector = document.getElementById('group_dept_selector');
        const deptUnitSelector = document.getElementById('dept_unit_selector');

        function checkRoleOptions() {
            const val = roleSelector.value;
            unitHeadToggle.style.display = (val === 'Staff') ? 'block' : 'none';
            groupDeptSelector.style.display = (val === 'GroupHead') ? 'block' : 'none';
            deptUnitSelector.style.display = (val === 'DeptHead') ? 'block' : 'none'; }
        roleSelector.addEventListener('change', checkRoleOptions);
        window.addEventListener('load', checkRoleOptions);
        const dateRegularizedInput = document.getElementById('dateRegularized');
        const empTypeSelector = document.getElementById('empTypeSelector');

        if (dateRegularizedInput && empTypeSelector) {
            dateRegularizedInput.addEventListener('change', function() {
                if (this.value) {
                    empTypeSelector.value = 'Regular (Permanent)';
                }
            });
        }

        <?php if(!empty($alert_message)): ?>
        Swal.fire({
            icon: <?php echo json_encode($alert_type === 'success' ? 'success' : 'error'); ?>,
            title: <?php echo json_encode($alert_type === 'success' ? 'Success!' : 'Oops!'); ?>,
            text: <?php echo json_encode($alert_message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            confirmButtonColor: '#27ae60',
            confirmButtonText: 'OK',
            allowOutsideClick: <?php echo !empty($redirect_on_confirm) ? 'false' : 'true'; ?>
        }).then((result) => {
            <?php if(!empty($redirect_on_confirm)): ?>
                window.location.href = <?php echo json_encode($redirect_on_confirm); ?>;
            <?php endif; ?>
        });
        <?php endif; ?>
    </script>
</body>
</html>