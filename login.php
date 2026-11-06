<?php

session_start();
include 'config.php';

if(isset($_POST['login'])){
   
    session_unset(); 
    session_regenerate_id(true);

    $eid = $_POST['employee_id'];
    $pass = $_POST['password'];


    $audit_eid = substr(trim($eid), 0, 50);
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE employee_id = ?");
    $stmt->bind_param("s", $eid);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    if($user){

        if($user['status'] === 'Blocked'){
            $_SESSION['login_message'] = 'Your account has been blocked due to multiple incorrect login attempts.';
            $_SESSION['message_type'] = 'error';

            audit_log($conn, 'LOGIN_BLOCKED', 'AUTH', $audit_eid, 'Login attempt on a blocked account', $audit_eid, $user['role']);
        }
        else if($user['status'] === 'Resigned'){
            $_SESSION['login_message'] = 'This account is no longer active';
            $_SESSION['message_type'] = 'error';

            audit_log($conn, 'LOGIN_DENIED', 'AUTH', $audit_eid, 'Login attempt on a resigned account', $audit_eid, $user['role']);
        }
        else if(password_verify($pass, $user['password'])){

            $reset_stmt = $conn->prepare("UPDATE users SET failed_attempts = 0 WHERE employee_id = ?");
            $reset_stmt->bind_param("s", $eid);
            $reset_stmt->execute();
            $reset_stmt->close();

            $new_session_id = session_id();

            $update_session = $conn->prepare("UPDATE users SET last_session_id = ? WHERE employee_id = ?");
            $update_session->bind_param("ss", $new_session_id, $eid);
            $update_session->execute();
            $update_session->close();

            $_SESSION['user'] = $user;
            $_SESSION['last_activity'] = time();

            $is_first = (isset($user['is_first_login']) && $user['is_first_login'] == 1);
            audit_log($conn, 'LOGIN_SUCCESS', 'AUTH', $audit_eid, $is_first ? 'First login: redirected to password update' : null, $audit_eid, $user['role']);

            if(isset($user['is_first_login']) && $user['is_first_login'] == 1) { 
                header("Location: update_password.php"); 
                exit();
            } else { 
                header("Location: dashboard.php"); 
                exit();
            }
        } 
        else {
            $new_attempts = $user['failed_attempts'] + 1;

            if($new_attempts >= 5){

                $block_stmt = $conn->prepare("UPDATE users SET failed_attempts = ?, status = 'Blocked' WHERE employee_id = ?");
                $block_stmt->bind_param("is", $new_attempts, $eid);
                $block_stmt->execute();
                $block_stmt->close();
                $_SESSION['login_message'] = 'Your account has been blocked due to 5 incorrect password attempts.';
                $_SESSION['message_type'] = 'error';

                audit_log($conn, 'LOGIN_FAILED', 'AUTH', $audit_eid, "Failed attempt #$new_attempts", $audit_eid, $user['role']);
                audit_log($conn, 'ACCOUNT_BLOCKED', 'AUTH', $audit_eid, 'Auto-blocked after 5 incorrect password attempts', $audit_eid, $user['role']);
            } else {
                $attempt_stmt = $conn->prepare("UPDATE users SET failed_attempts = ? WHERE employee_id = ?");
                $attempt_stmt->bind_param("is", $new_attempts, $eid);
                $attempt_stmt->execute();
                $attempt_stmt->close();

                $remaining = 5 - $new_attempts;
                $_SESSION['login_message'] = 'Incorrect password. You have ' . $remaining . ' attempt(s) left before your account gets blocked.';
                $_SESSION['message_type'] = 'error';

                audit_log($conn, 'LOGIN_FAILED', 'AUTH', $audit_eid, "Failed attempt #$new_attempts ($remaining left before block)", $audit_eid, $user['role']);
            }
        }

    } else { 
        $_SESSION['login_message'] = 'Incorrect Employee ID o Password';
        $_SESSION['message_type'] = 'error';

        audit_log($conn, 'LOGIN_FAILED', 'AUTH', $audit_eid, 'Unknown employee ID', $audit_eid, null);
    }
    $stmt->close();

    header("Location: login.php");
    exit();
}

$login_message = $_SESSION['login_message'] ?? '';
$message_type = $_SESSION['message_type'] ?? '';
unset($_SESSION['login_message']);
unset($_SESSION['message_type']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Timekeeping System</title>
    <link rel="icon" href="jdlogo.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            display: flex;
            height: 100vh;
            overflow: hidden;
            background-color: #fff;
            position: relative;
        }

        .login-container {
            width: 40%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            position: relative;
            z-index: 2;
        }

        .login-box {
            width: 100%;
            max-width: 320px;
            text-align: center;
        }

        .logo {
            width: 100px;
            margin-bottom: 40px;
        }

        input {
            width: 100%;
            padding: 15px;
            margin-bottom: 15px;
            border: none;
            background-color: #f0f2f5;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        button {
            width: 100%;
            padding: 15px;
            background-color: #638cfd;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }

        button:hover {
            background-color: #4a74e0;
        }

        .footer-text {
            position: absolute;
            bottom: 30px;
            font-size: 16px;
            color: #888;
        }

        .brand-container {
            width: 60%;
            background-color: #f8faff;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 60px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: "";
            background: url('bof_logo.png') no-repeat center center;
            background-size: 200px;
            opacity: .15; 
            position: absolute;
            top: 10%;
            left: 5%;
            transform: translate(-50%, -50%);
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }

        .brand-text {
            font-size: clamp(50px, 8vw, 150px);
            font-weight: 800;
            color: #f0f2f5; 
            text-transform: capitalize;
            line-height: 1.1;
            user-select: none;
            position: relative;
            z-index: 2;
        }

        .login-message {
            text-align: left;
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        .login-message.error {
            background-color: #fdecea;
            color: #c0392b;
            border-left: 4px solid #e74c3c;
        }
        .login-message.success {
            background-color: #eafaf1;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        @media (max-width: 768px) {
            .brand-container { display: none; }
            .login-container { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="login-box">
            <img src="jdlogo.png" alt="JD Logo" class="logo">
            
            <form method="POST">
                <input type="text" name="employee_id" placeholder="Employee ID" required>
                <input type="password" name="password" placeholder="Password" required>

                <?php if(!empty($login_message)): ?>
                    <div class="login-message <?php echo htmlspecialchars($message_type); ?>">
                        <?php echo htmlspecialchars($login_message); ?>
                    </div>
                <?php endif; ?>

                <button name="login">Sign in</button>
            </form>
        </div>
        
        <p class="footer-text">© 2025 JoshDeveryTech Solutions</p>
    </div>

    <div class="brand-container">
        <h1 class="brand-text">JDTech<br>Time Keeping<br>System</h1>
    </div>

</body>
</html>