<?php
function audit_log($conn, $action, $module, $target_id = null, $details = null, $actor_id = null, $actor_role = null) {
    if ($actor_id === null)   $actor_id   = $_SESSION['user']['employee_id'] ?? null;
    if ($actor_role === null) $actor_role = $_SESSION['user']['role'] ?? null;

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
    $target_id = ($target_id !== null) ? (string)$target_id : null;

    $stmt = $conn->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, module, target_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("ssssssss", $actor_id, $actor_role, $action, $module, $target_id, $details, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }

}