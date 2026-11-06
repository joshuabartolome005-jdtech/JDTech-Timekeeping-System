<?php

session_start();

include 'config.php';

if (isset($_SESSION['user']['employee_id'])) {
    audit_log(
        $conn,
        'LOGOUT',
        'AUTH',
        $_SESSION['user']['employee_id'],
        'User logged out',
        $_SESSION['user']['employee_id'],
        $_SESSION['user']['role'] ?? null
    );
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header("Location: login.php");
exit();
?>