<?php
session_start(); 
include 'config.php';
include 'mailer_config.php'; 

// Proteksyon: Dapat naka-login muna
if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];

// 1. KUNIN ANG DATA NG USER
$stmt_user = $conn->prepare("SELECT fullname, role, department, group_department, is_head, has_unit_head FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

$user_role = $user_data['role'];
$user_dept = $user_data['department'];
$group_dept = $user_data['group_department'];
$has_unit_head_val = $user_data['has_unit_head'];

// --- Variable para sa SweetAlert popup ---
$alert_message = '';
$alert_type = ''; // 'success' o 'error'
$redirect_on_confirm = ''; // saan pupunta pag OK/confirm sa alert; blank kung wala

// 2. LOGIC PARA SA SUBMISSION
if($_SERVER['REQUEST_METHOD'] == 'POST'){
    $date = $_POST['target_date'];
    $departure = $_POST['departure_time'];
    $arrival = $_POST['arrival_time'];
    $destination = mysqli_real_escape_string($conn, $_POST['destination']);
    $purpose = mysqli_real_escape_string($conn, $_POST['purpose']);

    // --- SECURE ATTACHMENT UPLOAD LOGIC ---
    $target_dir = "ob_attachment/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true); // Siguraduhing umiiral ang folder na may tamang permissions
    }

    $file_name = NULL;
    $upload_ok = true;

    if (isset($_FILES["attachment"]) && $_FILES["attachment"]["error"] === UPLOAD_ERR_OK) {
        $file_tmp   = $_FILES["attachment"]["tmp_name"];
        $file_size  = $_FILES["attachment"]["size"];
        $file_orig  = $_FILES["attachment"]["name"];
        $file_ext   = strtolower(pathinfo($file_orig, PATHINFO_EXTENSION));

        // Validation 1: Allowed Extensions
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        if (!in_array($file_ext, $allowed_ext)) {
            $alert_message = 'Invalid file format: Only JPG, JPEG, and PNG images are allowed.';
            $alert_type = 'error';
            $upload_ok = false;
        }

        // Validation 2: File Size Limit (5MB)
        if ($upload_ok && $file_size > 5000000) {
            $alert_message = 'File size exceeds limit: Maximum allowed size is 5MB.';
            $alert_type = 'error';
            $upload_ok = false;
        }

        // Validation 3: Real Image (MIME-type Check)
        if ($upload_ok) {
            $check_image = getimagesize($file_tmp);
            if ($check_image === false) {
                $alert_message = 'Invalid file: The uploaded file is not a valid image.';
                $alert_type = 'error';
                $upload_ok = false;
            }
        }

        // File Moving & Renaming
        if ($upload_ok) {
            $file_name = "OB_" . $user_id . "_" . time() . "." . $file_ext;
            $target_file = $target_dir . $file_name;

            if (!move_uploaded_file($file_tmp, $target_file)) {
                $alert_message = 'Failed to upload attachment to the server.';
                $alert_type = 'error';
                $upload_ok = false;
            }
        }
    } else {
        $upload_ok = false;
        $alert_message = 'Proof attachment is required.';
        $alert_type = 'error';
    }

    // AUDIT: hindi pumasa ang attachment (maling file type, masyadong malaki, hindi totoong image, hindi na-upload, o walang attachment)
    if (!$upload_ok) {
        audit_log($conn, 'SUBMIT_REJECTED', 'OB', null, "Destination: $destination | Date: $date | Reason: $alert_message");
    }

    if ($upload_ok) {
        // --- CHECK IF THE USER IS ASSIGNED TO PRESIDENT ---
        $check_pres = $conn->prepare("SELECT president_id FROM president_assignments WHERE group_head_id = ? LIMIT 1");
        $check_pres->bind_param("s", $user_id);
        $check_pres->execute();
        $is_assigned_to_president = $check_pres->get_result()->num_rows > 0;
        $check_pres->close();

        // --- UPDATED HIERARCHY STATUS LOGIC (WITH PRESIDENT ASSIGNMENTS) ---
        if ($user_role == 'President') {
            $initial_status = 'Approved';
            $target_role = NULL;
        } elseif ($is_assigned_to_president) {
            // Kung naka-check sa manage_president_assignments.php, diretso kay President
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

        // INSERT query - Isinama na ang attachment column
        $sql = "INSERT INTO applications (employee_id, group_department, type, details, destination, target_date, departure_time, arrival_time, status, attachment) 
                VALUES (?, ?, 'OB', ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssss", $user_id, $group_dept, $purpose, $destination, $date, $departure, $arrival, $initial_status, $file_name);
        
        if($stmt->execute()) { 

            // AUDIT: matagumpay na na-file ang OB (kasama ang ID ng bagong application at ang pangalan ng attachment)
            $new_app_id = $stmt->insert_id;
            audit_log($conn, 'SUBMIT', 'OB', $new_app_id, "Destination: $destination | Date: $date | $departure to $arrival | Attachment: $file_name | Initial status: $initial_status");

            // --- EMAIL NOTIFICATION LOGIC ---
            if ($initial_status != 'Approved') {
                $head_email = "";
                $head_name = "";

                if ($target_role == 'President') {
                    $stmt_head = $conn->prepare("SELECT email, fullname FROM users WHERE role = 'President' LIMIT 1");
                } elseif ($target_role == 'GroupHead') {
                    // TAMA NA QUERY: Gumagamit ng group_head_assignments table (hindi na users.group_department)
                    $stmt_head = $conn->prepare("SELECT u.email, u.fullname FROM users u JOIN group_head_assignments gha ON u.employee_id = gha.group_head_id WHERE gha.department_name = ? AND u.role = 'GroupHead' LIMIT 1");
                    $stmt_head->bind_param("s", $group_dept);
                } else {
                    $stmt_head = $conn->prepare("SELECT email, fullname FROM users WHERE department = ? AND role = ? LIMIT 1");
                    $stmt_head->bind_param("ss", $user_dept, $target_role);
                }

                if ($stmt_head) {
                    $stmt_head->execute();
                    $head_result = $stmt_head->get_result();

                    if($head_result->num_rows > 0) {
                        $head_data = $head_result->fetch_assoc();
                        $head_email = $head_data['email'];
                        $head_name = $head_data['fullname'];
                        
                        $subject = "New OB Application: " . $user_data['fullname'];
                        $message = "
                            <div style='font-family: Arial, sans-serif; padding: 20px; color: #333; border: 1px solid #e0e5f2; border-radius: 10px;'>
                                <h3 style='color: #4318ff;'>Official Business Request</h3>
                                <p>Hi <b>{$head_name}</b>,</p>
                                <p>This is to notify you that a new OB application that needs your review and approval:</p>
                                <hr style='border: 0; border-top: 1px solid #eee;'>
                                <p><b>Employee:</b> {$user_data['fullname']}<br>
                                <b>Destination:</b> $destination<br>
                                <b>Date:</b> " . date('M d, Y', strtotime($date)) . "<br>
                                <b>Schedule:</b> " . date('h:i A', strtotime($departure)) . " to " . date('h:i A', strtotime($arrival)) . "<br>
                                <b>Purpose:</b> $purpose<br>
                                <b>Status:</b> <span style='color: #4318ff;'>$initial_status</span></p>
                                <p style='margin-top: 20px;'>Login to the portal to take action on this request.</p>
                                 <p><a href='https://" . $_SERVER['HTTP_HOST'] . "/jdtech/login.php' style='display: inline-block; background-color: #4318ff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 10px;'>Go to JDTech URL</a></p>
                            </div>
                        ";
                        $email_sent = sendBOFEmail($head_email, $subject, $message);

                        // AUDIT: nakasulat kung naipadala o pumalya (pangalan at role lang, hindi ang email address)
                        if ($email_sent) {
                            audit_log($conn, 'NOTIFY_APPROVER', 'OB', $new_app_id, "Email sent to $target_role: $head_name");
                        } else {
                            audit_log($conn, 'NOTIFY_FAILED', 'OB', $new_app_id, "Email to $target_role ($head_name) was NOT sent. Check the PHP error log for the Microsoft Graph error");
                        }
                    } else {
                        // AUDIT: walang nahanap na approver na padadalhan, dito makikita kung bakit walang email na dumating
                        audit_log($conn, 'NOTIFY_NO_RECIPIENT', 'OB', $new_app_id, "No $target_role found | Employee dept: $user_dept | Group dept: $group_dept");
                    }
                    $stmt_head->close();
                }
            }

            $alert_message = 'OB application submitted successfully!';
            $alert_type = 'success';
            $redirect_on_confirm = 'apply_ob.php';
        } else {
            // AUDIT: pumalya ang pag-save (nasa server na ang attachment, nakasulat ang pangalan para madaling hanapin)
            audit_log($conn, 'SUBMIT_FAILED', 'OB', null, "Destination: $destination | Date: $date | Attachment already uploaded: $file_name | DB error: " . $stmt->error);

            $alert_message = 'Error: ' . $stmt->error;
            $alert_type = 'error';
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech Solutions - Apply OB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-light: #f4f7fe;
            --primary-blue: #4318ff;
            --text-dark: #1b254b;
            --text-muted: #a3aed0;
            --white: #ffffff;
            --shadow: 14px 17px 40px 4px rgba(112, 144, 176, 0.08);
        }

        body { 
            margin: 0; padding: 0; 
            font-family: 'DM Sans', sans-serif; 
            background-color: var(--bg-light); 
            display: flex; 
        }

        .content { 
            flex: 1; padding: 30px; 
            box-sizing: border-box; min-height: 100vh; 
            display: flex; flex-direction: column; align-items: center; 
        }

        .header-nav { width: 100%; max-width: 650px; margin-bottom: 25px; }
        .header-nav h1 { color: var(--text-dark); font-size: 24px; margin: 0; font-weight: 700; }

        .form-card { 
            background: var(--white); padding: 40px; border-radius: 20px; 
            max-width: 650px; width: 100%; box-shadow: var(--shadow); 
        }

        .form-card h2 { 
            color: var(--text-dark); margin-top: 0; font-size: 20px; 
            display: flex; align-items: center; gap: 12px; margin-bottom: 5px;
        }

        label { 
            font-size: 12px; font-weight: 700; color: var(--text-muted); 
            text-transform: uppercase; margin-bottom: 8px; display: block; 
            letter-spacing: 0.5px; margin-top: 20px;
        }

        input, textarea { 
            width: 100%; padding: 12px 15px; border: 1px solid #e0e5f2; 
            border-radius: 12px; box-sizing: border-box; font-size: 15px; 
            color: var(--text-dark); outline: none; transition: 0.3s; background: #fff;
        }

        input:focus, textarea:focus { border-color: var(--primary-blue); box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.05); }

        .time-group { display: flex; gap: 20px; }
        .time-group > div { flex: 1; }
        
        .btn-submit { 
            background: var(--primary-blue); color: white; border: none; 
            padding: 16px; width: 100%; border-radius: 12px; cursor: pointer; 
            margin-top: 30px; font-weight: 700; font-size: 16px; transition: 0.3s; 
            box-shadow: 0px 10px 20px rgba(67, 24, 255, 0.2);
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn-submit:hover { background: #3311db; transform: translateY(-2px); }

        .back-link {
            display: inline-block; margin-top: 20px; color: var(--text-muted);
            text-decoration: none; font-size: 14px; transition: 0.3s;
        }
        .back-link:hover { color: var(--primary-blue); }

        @media (max-width: 768px) { 
            .content { padding: 20px; } 
            .time-group { flex-direction: column; gap: 0; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="header-nav">
            <h1>Official Business</h1>
        </div>

        <div class="form-card">
            <h2>
                <i class="fa-solid fa-briefcase" style="color: var(--primary-blue);"></i> 
                OB Application
            </h2>
            
            <hr style="border: 0; border-top: 1px solid #f4f7fe; margin-bottom: 10px;">

            <form method="POST" enctype="multipart/form-data">
                <label>Date of OB</label>
                <input type="date" name="target_date" value="<?php echo date('Y-m-d'); ?>" required>

                <div class="time-group">
                    <div>
                        <label><i class="fa-regular fa-clock"></i> Departure Time</label>
                        <input type="time" name="departure_time" required>
                    </div>
                    <div>
                        <label><i class="fa-regular fa-clock"></i> Arrival Time</label>
                        <input type="time" name="arrival_time" required>
                    </div>
                </div>

                <label>Destination / Venue</label>
                <input type="text" name="destination" placeholder="E.g., Client Office, Branch Visit" required>

                <label>Purpose / Details</label>
                <textarea name="purpose" rows="4" placeholder="Briefly explain the nature of your business trip..." required></textarea>

                <label>Proof Attachment (Image)</label>
                <input type="file" name="attachment" accept="image/png, image/jpeg" required>
                <p style="color: #a3aed0; font-size: 11px; margin-top: 5px;">Upload screenshot or photo (JPG/PNG, max 5MB).</p>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i> Submit Application
                </button>
            </form>

            <center><a href="dashboard.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a></center>
        </div>
    </div>

    <script>
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