<?php
/**
 * JD Tech Solutions - Database & Session Configuration
 * Features: Session Timeout, Single Active Session, Multi-tab Sync
 * Integrated: Microsoft Graph API Email Configuration & SweetAlert2 Alerts
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "p@ssw0rd"; 
$dbname = "attendance";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) { 
    die("Error: Hindi makakonekta sa database."); 
}

mysqli_set_charset($conn, "utf8mb4");

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/audit_helper.php';

// 4. Global Constants
define('BASE_URL', 'https://timekeeping.bof.com.ph:5034/jdtech');

// Microsoft Graph API Configuration para sa Email Notification
define('MS_TENANT_ID', 'adbf0b1b-60e3-4679-8062-4908ff538ad1'); 
define('MS_CLIENT_ID', '7b86760d-d268-4c1a-92d9-0a3246fdf438'); 
define('MS_CLIENT_SECRET', 'Sdk8Q~fKizDxTUI.sfC02TqJ1-9fE2lPXO8E0ahb'); 
define('MS_SENDER_EMAIL', 'boftk@bof.com.ph');

// ==========================================================
// 5. SECURITY CHECK: TIMEOUT & SINGLE SESSION LOGIC
// ==========================================================

if (isset($_SESSION['user'])) {
    
    $user_id = $_SESSION['user']['employee_id'];
    $current_session_id = session_id();
    $stmt_session = $conn->prepare("SELECT last_session_id FROM users WHERE employee_id = ?");
    $stmt_session->bind_param("s", $user_id);
    $stmt_session->execute();
    $res_session = $stmt_session->get_result();
    $db_data = $res_session->fetch_assoc();

    if ($db_data && $db_data['last_session_id'] !== $current_session_id) {
        session_unset();
        session_destroy();
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
        echo "<script>
            window.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: 'warning',
                    title: 'Session Terminated',
                    text: 'Your account has been logged in from another device or browser.',
                    confirmButtonColor: '#2c3e50',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then(() => {
                    window.location.href = 'login.php';
                });
            });
        </script>";
        exit();
    }
    $stmt_session->close();
    $timeout_limit = 300; 
    $current_time = time();

    if (isset($_SESSION['last_activity'])) {
        $idle_time = $current_time - $_SESSION['last_activity'];
        
        if ($idle_time >= $timeout_limit) {
            session_unset();
            session_destroy();
            echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>";
            echo "<script>
                window.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'info',
                        title: 'Session Expired',
                        text: 'Your session has expired due to inactivity.',
                        confirmButtonColor: '#2c3e50',
                        allowOutsideClick: false,
                        allowEscapeKey: false
                    }).then(() => {
                        window.location.href = 'login.php';
                    });
                });
            </script>";
            exit();
        }
    }
    $_SESSION['last_activity'] = $current_time;

}

function check_login() {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit();
    }
}
?>