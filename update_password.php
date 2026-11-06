<?php
session_start();
include 'config.php';

// Siguraduhin na naka-login ang user bago makapag-update
if(!isset($_SESSION['user']) || !isset($_SESSION['user']['employee_id'])) { 
    header("Location: login.php"); 
    exit();
}

$eid = $_SESSION['user']['employee_id'];
$error_msg = "";
$success = false;

if(isset($_POST['update_pass'])){
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];
    
    // 1. Validation: Check length (at least 8 chars)
    if(strlen($new_pass) < 8) {
        $error_msg = "Password must be at least 8 characters long.";
        // AUDIT: bigong pagpalit ng password (walang nilalagay na password sa log)
        audit_log($conn, 'PASSWORD_CHANGE_FAILED', 'AUTH', $eid, 'Rejected: password shorter than 8 characters');
    } 
    // 2. Validation: Check if passwords match
    elseif($new_pass !== $confirm_pass) {
        $error_msg = "Passwords do not match. Please try again.";
        // AUDIT
        audit_log($conn, 'PASSWORD_CHANGE_FAILED', 'AUTH', $eid, 'Rejected: password confirmation did not match');
    } 
    else {
        // SECURE: I-hash ang bagong password
        $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
        
        // SECURE: Gamitin ang Prepared Statement para sa UPDATE
        // I-set ang is_first_login sa 0 para hindi na bumalik dito
        $stmt = $conn->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE employee_id = ?");
        $stmt->bind_param("ss", $hashed_pass, $eid);
        
        if($stmt->execute()){
            $success = true;

            // AUDIT: dapat i-log BAGO ang session_unset(), kailangan pa ang user info sa session
            audit_log($conn, 'PASSWORD_CHANGED', 'AUTH', $eid, 'User updated own password (first-login change)');

            // Linisin ang session sa server side para force login mamaya
            session_unset();
            session_destroy();
        } else {
            $error_msg = "Something went wrong. Please try again later.";
            // AUDIT: DB error sa pag-update
            audit_log($conn, 'PASSWORD_CHANGE_FAILED', 'AUTH', $eid, 'DB error: ' . $conn->error);
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
    <title>JDTech - Update Password</title>
    <link rel="icon" href="jdlogo.png">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f8faff; }
        .update-container { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 400px; text-align: center; }
        .logo { width: 80px; margin-bottom: 20px; }
        h2 { color: #2c3e50; font-size: 22px; margin-bottom: 10px; }
        p { color: #7f8c8d; font-size: 14px; margin-bottom: 25px; line-height: 1.5; }
        input { width: 100%; padding: 15px; margin-bottom: 15px; border: 1px solid #dcdde1; background-color: #f0f2f5; border-radius: 8px; font-size: 14px; outline: none; transition: 0.3s; }
        input:focus { border-color: #638cfd; background-color: #fff; box-shadow: 0 0 8px rgba(99, 140, 253, 0.2); }
        button { width: 100%; padding: 15px; background-color: #638cfd; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.3s; margin-top: 10px; }
        button:hover { background-color: #4a74e0; }
        .security-note { margin-top: 25px; font-size: 11px; color: #bdc3c7; text-transform: uppercase; letter-spacing: 1px; }

        /* --- RESPONSIVE FIX para sa maliliit na phone --- */
        @media (max-width: 480px) {
            /* min-height imbes na height, para hindi maputol ang form kapag lumabas ang keyboard */
            body { height: auto; min-height: 100vh; padding: 15px; }
            .update-container { padding: 25px 20px; }
            .logo { width: 65px; margin-bottom: 15px; }
            h2 { font-size: 20px; }
            p { font-size: 13px; margin-bottom: 20px; }
        }
    </style>
</head>
<body>

    <div class="update-container">
        <img src="jdlogo.png" alt="JD Logo" class="logo">
        <h2>Secure Your Account</h2>
        <p>This is your first time logging in. For your security, please update your temporary password.</p>
        
        <form method="POST" id="passForm">
            <input type="password" name="new_password" placeholder="New Password" required minlength="8">
            <input type="password" name="confirm_password" placeholder="Confirm New Password" required minlength="8">
            <button type="submit" name="update_pass">Update & Sign in</button>
        </form>

        <div class="security-note">
            JDTech Solutions Security Protocol
        </div>
    </div>

    <script>
        // Check kung may error message mula sa PHP
        <?php if($error_msg != ""): ?>
            Swal.fire({
                icon: 'error',
                title: 'Oops...',
                text: '<?php echo $error_msg; ?>',
                confirmButtonColor: '#638cfd'
            });
        <?php endif; ?>

        // Check kung successful ang update
        <?php if($success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Password Updated!',
                text: 'Please login again with your new password.',
                confirmButtonColor: '#638cfd',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'login.php';
                }
            });
        <?php endif; ?>
    </script>

</body>
</html>