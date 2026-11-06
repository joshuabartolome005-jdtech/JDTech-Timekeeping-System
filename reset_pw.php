<?php
include 'config.php'; // Siguraduhin na tama ang path ng config mo

// Ang bagong password na gusto mong i-set
$new_password = "BagongPassword123"; 

// I-hash ang password gamit ang BCRYPT
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

// I-update ang ID 1 hanggang 7
$sql = "UPDATE users SET password = ? WHERE employee_id BETWEEN 1 AND 7";
// O kung 'id' ang column name: "UPDATE users SET password = ? WHERE id BETWEEN 1 AND 7"

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $hashed_password);

// AUDIT: alamin kung sino ang nagpatakbo. Kung walang naka-login, ito ay anonymous na request (IP at browser ay awtomatikong nakukuha ng audit_log)
$who = isset($_SESSION['user']['employee_id'])
    ? $_SESSION['user']['employee_id'] . ' (' . ($_SESSION['user']['role'] ?? '-') . ')'
    : 'NOT LOGGED IN (anonymous request)';

if($stmt->execute()) {
    // AUDIT: walang password (luma, bago, o hash) sa log, bilang lang ng apektadong rows
    audit_log($conn, 'PASSWORD_RESET_BULK', 'EMPLOYEE', '1-7',
        "reset_pw.php ran | Rows affected: " . $stmt->affected_rows . " | Condition: employee_id BETWEEN 1 AND 7 | Run by: $who");

    echo "Successfully updated IDs 1-7 with the new hashed password.";
} else {
    // AUDIT: pumalya ang UPDATE
    audit_log($conn, 'PASSWORD_RESET_BULK_FAILED', 'EMPLOYEE', '1-7',
        "reset_pw.php ran | DB error: " . $conn->error . " | Run by: $who");

    echo "Error: " . $conn->error;
}
?>