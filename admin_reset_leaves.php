<?php
include 'config.php';

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'HR') {
    audit_log($conn, 'ACCESS_DENIED', 'LEAVE_BALANCE', null, 'Unauthorized access attempt to admin_reset_leaves.php');
    die("Unauthorized access.");
}

if(isset($_POST['confirm_reset'])) {

    // AUDIT: kunin ang kalagayan BAGO ang reset (hindi na ito maibabalik pagkatapos ng UPDATE)
    $before = $conn->query("SELECT COUNT(*) AS total_users, COALESCE(SUM(annual_leave),0) AS sum_al, COALESCE(SUM(mandatory_leave),0) AS sum_ml FROM users")->fetch_assoc();

    $sql = "UPDATE users SET annual_leave = 25.0, mandatory_leave = 5.0";
    
    if($conn->query($sql)) {
        $changed = $conn->affected_rows; 
        $n = (int)$before['total_users'];
        audit_log($conn, 'RESET_ALL_LEAVE_CREDITS', 'LEAVE_BALANCE', 'ALL',
            "Reset to AL 25.0 / ML 5.0 | Users in table: $n | Rows actually changed: $changed"
            . " | Total AL before: " . $before['sum_al'] . " -> after: " . (25 * $n)
            . " | Total ML before: " . $before['sum_ml'] . " -> after: " . (5 * $n));

        echo "<script>alert('All leave credits have been reset to 25 and 5!'); window.location='admin_dashboard.php';</script>";
    } else {
        audit_log($conn, 'RESET_ALL_LEAVE_CREDITS_FAILED', 'LEAVE_BALANCE', 'ALL', 'DB error: ' . $conn->error);

        echo "Error updating record: " . $conn->error;
    }
}
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    @media (max-width: 768px) {
        .container-card { padding: 15px; }
        .container-card button { width: 100%; }
    }
</style>

<div class="container-card">
    <h3>Yearly Leave Reset</h3>
    <p>Babala: Ang action na ito ay ibabalik ang lahat ng leave credits sa default (25 AL, 5 ML).</p>
    <form method="POST">
        <button type="submit" name="confirm_reset" onclick="return confirm('Sigurado ka ba? Hindi ito pwedeng ma-undo.')" 
                style="background: #e74c3c; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
            Reset All Credits Now
        </button>
    </form>
</div>