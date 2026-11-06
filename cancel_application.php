<?php
session_start();
include 'config.php';

if(!isset($_SESSION['user']) || !isset($_GET['id'])) {
    exit("Access Denied.");
}

$app_id = intval($_GET['id']);
$user_id = $_SESSION['user']['employee_id'];
$user_role = $_SESSION['user']['role'] ?? null;

$check = $conn->prepare("SELECT status, type, target_date, end_date FROM applications WHERE id = ? AND employee_id = ?");
$check->bind_param("is", $app_id, $user_id);
$check->execute();
$res = $check->get_result()->fetch_assoc();

if ($res && strpos($res['status'], 'Pending') !== false) {
    $update = $conn->prepare("UPDATE applications SET status = 'Cancelled' WHERE id = ?");
    $update->bind_param("i", $app_id);
    
    if ($update->execute()) {
  
        $dates_info = $res['target_date'] ?? '';
        if (!empty($res['end_date']) && $res['end_date'] != $res['target_date']) {
            $dates_info .= " to " . $res['end_date'];
        }
        audit_log($conn, 'CANCEL', 'APPLICATION', $app_id,
            "Cancelled by owner | Type: " . ($res['type'] ?? '-') . " | Dates: $dates_info | Previous status: " . $res['status'],
            $user_id, $user_role);

        echo "<script>alert('Application Cancelled.'); window.history.back();</script>";
    } else {

        audit_log($conn, 'CANCEL_FAILED', 'APPLICATION', $app_id, 'DB error during user cancellation: ' . $conn->error, $user_id, $user_role);

        echo "Error cancelling: " . $conn->error;
    }
} else {

    if (!$res) {
        $why = 'Application not found or not owned by this user';
    } else {
        $why = 'Application is no longer Pending (current status: ' . $res['status'] . ')';
    }
    audit_log($conn, 'CANCEL_REJECTED', 'APPLICATION', $app_id, $why, $user_id, $user_role);

    echo "<script>alert('Error: Hindi na pwedeng i-cancel ang application na ito.'); window.history.back();</script>";
}
?>