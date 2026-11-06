<?php
session_start(); 
include 'config.php';
include 'mailer_config.php'; 

if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];

$stmt_user = $conn->prepare("SELECT fullname, role, department, group_department, is_head, has_unit_head FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

$user_role = $user_data['role'];
$user_dept = $user_data['department'];
$group_dept = $user_data['group_department'];
$has_unit_head_val = $user_data['has_unit_head'];
$pre_date = isset($_GET['date']) ? $_GET['date'] : '';
$pre_type = isset($_GET['type']) ? $_GET['type'] : '';

$mapped_type = "";
if (strpos($pre_type, 'IN') !== false) {
    $mapped_type = "TIME IN";
} elseif (strpos($pre_type, 'OUT') !== false) {
    $mapped_type = "TIME OUT";
}
$alert_message = '';
$alert_type = ''; 
$redirect_on_confirm = '';

// --- Limit ng server (para makita sa audit log kung bakit tinanggihan ang upload) ---
$server_limits = "upload_max_filesize=" . ini_get('upload_max_filesize') . ", post_max_size=" . ini_get('post_max_size');

// --- Kapag lumampas ang laki ng buong request sa post_max_size, nawawala ang LAHAT ng $_POST at $_FILES ---
$post_overflow = ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($_POST) && empty($_FILES)
                  && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0);
if ($post_overflow) {
    $alert_message = 'The photo is too large for the server. Please choose a smaller photo or retake it at a lower resolution.';
    $alert_type = 'error';
    audit_log($conn, 'SUBMIT_REJECTED', 'MISSED_LOG', null, "Request exceeded post_max_size | Request size: " . (int)$_SERVER['CONTENT_LENGTH'] . " bytes | Server limits: $server_limits");
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && !$post_overflow){
    $date = $_POST['target_date'];
    $time = $_POST['estimated_time'];
    $raw_type = $_POST['log_type']; 
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);

    $log_missed_type = (strpos($raw_type, 'IN') !== false) ? 'IN' : 'OUT';
    $details = "Request to add $raw_type. Reason: $reason";

    $target_dir = "missed_logs_attachment/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $file_name = NULL;
    $upload_ok = true;
    $upload_tech = ''; // teknikal na detalye para sa audit log lang
    $upload_err_code = isset($_FILES["attachment"]) ? $_FILES["attachment"]["error"] : UPLOAD_ERR_NO_FILE;

    if (!empty($_FILES["attachment"]["name"]) && $upload_err_code === UPLOAD_ERR_OK) {
        $file_extension = strtolower(pathinfo($_FILES["attachment"]["name"], PATHINFO_EXTENSION));
        $file_name = "ML_" . $user_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $file_name;
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        $file_size = $_FILES["attachment"]["size"];

        if (!in_array($file_extension, $allowed_ext)) {
            $alert_message = 'Invalid file format: Only JPG, JPEG, and PNG images are allowed.';
            $alert_type = 'error';
            $upload_ok = false;
            $upload_tech = "Extension: $file_extension";
        }
        if ($upload_ok && $file_size > 5000000) {
            $alert_message = 'File size exceeds the limit: Maximum allowed size is 5MB.';
            $alert_type = 'error';
            $upload_ok = false;
            $upload_tech = "Size: $file_size bytes (limit 5000000)";
        }
        // Totoong image ba talaga (hindi lang pinalitan ang extension)
        if ($upload_ok && @getimagesize($_FILES["attachment"]["tmp_name"]) === false) {
            $alert_message = 'Invalid file: The uploaded file is not a valid image.';
            $alert_type = 'error';
            $upload_ok = false;
            $upload_tech = "getimagesize() failed | Extension: $file_extension | Size: $file_size bytes";
        }
        if ($upload_ok) {
            if (!move_uploaded_file($_FILES["attachment"]["tmp_name"], $target_file)) {
                $alert_message = 'The server could not save the attachment. Please try again, or contact IT if it keeps happening.';
                $alert_type = 'error';
                $upload_ok = false;
                $upload_tech = "move_uploaded_file() failed | Target folder writable: " . (is_writable($target_dir) ? 'yes' : 'NO') . " | upload_tmp_dir: " . (ini_get('upload_tmp_dir') ?: '(default)');
            }
        }
    } elseif (!empty($_FILES["attachment"]["name"])) {
        // May file na pinili pero pumalya ang pag-upload mismo (galing sa PHP)
        $upload_ok = false;
        $alert_type = 'error';
        switch ($upload_err_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $alert_message = 'The photo is too large for the server. Please choose a smaller photo or retake it at a lower resolution.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $alert_message = 'The upload was interrupted. Please check your connection and try again.';
                break;
            default: // NO_TMP_DIR, CANT_WRITE, EXTENSION
                $alert_message = 'The server could not receive the file. Please contact IT.';
        }
        $upload_tech = "PHP upload error code: $upload_err_code | File: " . $_FILES["attachment"]["name"];
    } else {
        $upload_ok = false;
        $alert_message = 'Proof attachment is required.';
        $alert_type = 'error';
        $upload_tech = "No file received";
    }
    if (!$upload_ok) {
        audit_log($conn, 'SUBMIT_REJECTED', 'MISSED_LOG', null, "Type: $raw_type | Date: $date | Reason: $alert_message | $upload_tech | Server limits: $server_limits");
    }

    if ($upload_ok) {
        if ($user_role == 'President') {
            $initial_status = 'Approved';
            $target_role = NULL;
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
        $sql = "INSERT INTO applications (employee_id, group_department, type, log_missed_type, details, target_date, estimated_time, status, attachment) 
                VALUES (?, ?, 'MissedLog', ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssss", $user_id, $group_dept, $log_missed_type, $details, $date, $time, $initial_status, $file_name);
        
        if($stmt->execute()) { 

            $new_app_id = $stmt->insert_id;
            audit_log($conn, 'SUBMIT', 'MISSED_LOG', $new_app_id, "Type: $raw_type | Date: $date | Time: $time | Attachment: $file_name | Initial status: $initial_status");

            if ($initial_status != 'Approved' && !empty($target_role)) {
                $head_email = "";
                $head_name = "";

                if ($target_role == 'President') {
                    $stmt_head = $conn->prepare("SELECT email, fullname FROM users WHERE role = 'President' LIMIT 1");
                } elseif ($target_role == 'GroupHead') {
                    $stmt_head = $conn->prepare("SELECT u.email, u.fullname FROM users u JOIN group_head_assignments gha ON u.employee_id = gha.group_head_id WHERE gha.department_name = ? AND u.role = 'GroupHead' LIMIT 1");
                    $stmt_head->bind_param("s", $group_dept);
                } else {
                    $stmt_head = $conn->prepare("SELECT email, fullname FROM users WHERE department = ? AND role = ? LIMIT 1");
                    $stmt_head->bind_param("ss", $user_dept, $target_role);
                }
                
                $stmt_head->execute();
                $head_result = $stmt_head->get_result();
                
                if($head_result->num_rows > 0) {
                    $head_data = $head_result->fetch_assoc();
                    $head_email = $head_data['email'];
                    $head_name = $head_data['fullname'];
                    
                    $subject = "Missed Log Application: " . $user_data['fullname'];
                    $message = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333; border: 1px solid #e0e5f2; border-radius: 10px;'>
                            <h3 style='color: #f39c12;'>Missed Log Correction</h3>
                            <p>Hi <b>{$head_name}</b>,</p>
                            <p>This is to notify you that a new missed log request requires your review and approval:</p>
                            <hr style='border: 0; border-top: 1px solid #eee;'>
                            <p><b>Employee:</b> {$user_data['fullname']}<br>
                            <b>Date:</b> $date<br>
                            <b>Type:</b> $raw_type<br>
                            <b>Reason:</b> $reason<br>
                            <b>Status:</b> <span style='color: #f39c12;'>$initial_status</span></p>
                            <p style='margin-top: 20px;'>Login to the portal to review this request.</p>
                            <p><a href='https://" . $_SERVER['HTTP_HOST'] . "/jdtech/login.php' style='display: inline-block; background-color: #4318ff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 10px;'>Go to JDTech URL</a></p>
                        </div>
                    ";
                    $email_sent = sendBOFEmail($head_email, $subject, $message);

                    // AUDIT: nakasulat kung naipadala o pumalya (pangalan at role lang, hindi ang email address)
                    if ($email_sent) {
                        audit_log($conn, 'NOTIFY_APPROVER', 'MISSED_LOG', $new_app_id, "Email sent to $target_role: $head_name");
                    } else {
                        audit_log($conn, 'NOTIFY_FAILED', 'MISSED_LOG', $new_app_id, "Email to $target_role ($head_name) was NOT sent. Check the PHP error log for the Microsoft Graph error");
                    }
                } else {
                    audit_log($conn, 'NOTIFY_NO_RECIPIENT', 'MISSED_LOG', $new_app_id, "No $target_role found | Employee dept: $user_dept | Group dept: $group_dept");
                }
            }
            $alert_message = 'Application submitted successfully!';
            $alert_type = 'success';
            $redirect_on_confirm = 'apply_missed_log.php';
        } else {
            audit_log($conn, 'SUBMIT_FAILED', 'MISSED_LOG', null, "Type: $raw_type | Date: $date | Attachment already uploaded: $file_name | DB error: " . $stmt->error);
            $alert_message = 'Error: ' . $stmt->error;
            $alert_type = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech Solutions - Missed Log</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-light: #f4f7fe;
            --primary-orange: #f39c12;
            --text-dark: #1b254b;
            --text-muted: #a3aed0;
            --white: #ffffff;
            --shadow: 14px 17px 40px 4px rgba(112, 144, 176, 0.08);
        }

        body { margin: 0; padding: 0; font-family: 'DM Sans', sans-serif; background-color: var(--bg-light); display: flex; }
        .content { flex: 1; padding: 30px; box-sizing: border-box; min-height: 100vh; display: flex; flex-direction: column; align-items: center; }
        .content { flex: 1; padding: 30px; box-sizing: border-box; min-height: 100vh; display: flex; flex-direction: column; align-items: center; }
        .header-nav { width: 100%; max-width: 550px; margin-bottom: 25px; }
        .header-nav h1 { color: var(--text-dark); font-size: 24px; margin: 0; font-weight: 700; }
        .form-card { background: var(--white); padding: 40px; border-radius: 20px; max-width: 550px; width: 100%; box-shadow: var(--shadow); border-top: 5px solid var(--primary-orange); }
        .form-card h2 { color: var(--text-dark); margin-top: 0; font-size: 20px; display: flex; align-items: center; gap: 12px; margin-bottom: 5px; }
        label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; display: block; letter-spacing: 0.5px; margin-top: 20px; }
        input, select, textarea { width: 100%; padding: 12px 15px; border: 1px solid #e0e5f2; border-radius: 12px; box-sizing: border-box; font-size: 15px; color: var(--text-dark); outline: none; transition: 0.3s; background: #fff; }
        input:focus, select:focus, textarea:focus { border-color: var(--primary-orange); box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.05); }
        .btn-submit { background: var(--primary-orange); color: white; border: none; padding: 16px; width: 100%; border-radius: 12px; cursor: pointer; margin-top: 30px; font-weight: 700; font-size: 16px; transition: 0.3s; box-shadow: 0px 10px 20px rgba(243, 156, 18, 0.2); display: flex; align-items: center; justify-content: center; gap: 10px; }
        .btn-submit:hover { background: #e67e22; transform: translateY(-2px); }
        .back-link { display: inline-block; margin-top: 20px; color: var(--text-muted); text-decoration: none; font-size: 14px; transition: 0.3s; }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="header-nav">
            <h1>Attendance Correction</h1>
        </div>

        <div class="form-card">
            <h2>
                <i class="fa-solid fa-clock-rotate-left" style="color: var(--primary-orange);"></i> 
                Missed Log Application
            </h2>
            <p style="color: var(--text-muted); font-size: 14px; margin: 0 0 20px 0;">Request to add a missing attendance punch.</p>
            
            <hr style="border: 0; border-top: 1px solid #f4f7fe; margin-bottom: 10px;">

            <form method="POST" enctype="multipart/form-data">
                <label>Date of Missed Log</label>
                <input type="date" name="target_date" value="<?php echo $pre_date; ?>" required>

                <label>Type of Log Missed</label>
                <select name="log_type" required>
                    <option value="" disabled <?php echo empty($mapped_type) ? 'selected' : ''; ?>>-- Select Type --</option>
                    <option value="TIME IN" <?php echo ($mapped_type == 'TIME IN') ? 'selected' : ''; ?>>Time In</option>
                    <option value="TIME OUT" <?php echo ($mapped_type == 'TIME OUT') ? 'selected' : ''; ?>>Time Out</option>
                </select>

                <label>Requested Time of Punch</label>
                <input type="time" name="estimated_time" required>

                <label>Detailed Reason</label>
                <textarea name="reason" rows="3" placeholder="Briefly explain why the log was missed..." required></textarea>

                <label>Proof Attachment (Image)</label>
                <input type="file" name="attachment" id="attachmentInput" accept="image/*" required>
                <p style="color: #a3aed0; font-size: 10px; margin-top: 5px;">Upload screenshot or photo (JPG/PNG, max 5MB). Large photos are automatically resized.</p>
                <p id="attachInfo" style="color: #27ae60; font-size: 11px; margin-top: 0;"></p>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i> Submit Application
                </button>
            </form>

            <center><a href="my_logs.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Logs</a></center>
        </div>
    </div>

    <script>
    // --- Awtomatikong pag-liliit ng malaking larawan bago i-submit (para hindi lumampas sa limit ng server) ---
    // Kapag hindi gumana (lumang browser, hindi mabasa ang format), mananatili ang orihinal na file at ang server na ang magbibigay ng mensahe.
    document.getElementById('attachmentInput').addEventListener('change', async function () {
        const info = document.getElementById('attachInfo');
        info.textContent = '';
        const file = this.files[0];
        if (!file || !file.type.startsWith('image/')) return;

        const MAX_SIDE = 2200;               // sapat para mabasa pa ang sulat-kamay at maliliit na detalye
        const COMPRESS_ABOVE = 1.5 * 1024 * 1024; // liliitan lang kapag mas malaki sa 1.5 MB
        if (file.size <= COMPRESS_ABOVE) return;

        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const ratio = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * ratio);
            canvas.height = Math.round(bitmap.height * ratio);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

            const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.85));
            if (!blob || blob.size >= file.size) return; // kung hindi gumaan, huwag palitan

            const newName = file.name.replace(/\.[^.]+$/, '') + '.jpg';
            const dt = new DataTransfer();
            dt.items.add(new File([blob], newName, { type: 'image/jpeg' }));
            this.files = dt.files;

            info.textContent = 'Photo optimized: ' + (file.size / 1048576).toFixed(1) + ' MB → ' + (blob.size / 1048576).toFixed(1) + ' MB';
        } catch (err) {
            // Hindi nabasa ng browser ang larawan; ipapadala ang orihinal
        }
    });

    <?php if(!empty($alert_message)): ?>
    Swal.fire({
        icon: '<?php echo ($alert_type === "success") ? "success" : "error"; ?>',
        title: '<?php echo ($alert_type === "success") ? "Success!" : "Oops!"; ?>',
        text: '<?php echo addslashes($alert_message); ?>',
        confirmButtonColor: '#f39c12',
        confirmButtonText: 'OK'
    }).then((result) => {
        <?php if(!empty($redirect_on_confirm)): ?>
            window.location.href = '<?php echo $redirect_on_confirm; ?>';
        <?php endif; ?>
    });
    <?php endif; ?>
    </script>
</body>
</html>