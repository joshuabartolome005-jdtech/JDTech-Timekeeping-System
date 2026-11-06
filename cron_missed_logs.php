<?php
include 'config.php';
include 'mailer_config.php';

$sql = "SELECT u.fullname, u.email 
        FROM attendance_logs al
        JOIN users u ON al.employee_id = u.employee_id
        WHERE DATE(al.log_time) = CURDATE()
        GROUP BY al.employee_id
        HAVING COUNT(CASE WHEN al.log_type = 'IN' THEN 1 END) > 0 
        AND COUNT(CASE WHEN al.log_type = 'OUT' THEN 1 END) = 0";

$res = $conn->query($sql);
while($row = $res->fetch_assoc()) {
    $subject = "Attendance Alert: Missed Time Out";
    $body = "Hi " . $row['fullname'] . ", <br><br> Napansin ng system na wala ka pang <b>Time Out</b> log para sa araw na ito. Pakiaayos ang iyong logs sa portal.";
    sendBOFEmail($row['email'], $subject, $body);
}
?>