<?php
session_start();
include 'config.php';
include 'mailer_config.php';

// --- HELPER FUNCTION: Modern SweetAlert Response ---
function renderSweetAlert($icon, $title, $text, $redirectUrl = 'pending_approvals.php') {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Processing Approval...</title>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            body {
                font-family: "Plus Jakarta Sans", sans-serif;
                background-color: #f4f7fe;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
            }
        </style>
    </head>
    <body>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                Swal.fire({
                    icon: "' . $icon . '",
                    title: "' . addslashes($title) . '",
                    text: "' . addslashes($text) . '",
                    confirmButtonColor: "#4318ff",
                    confirmButtonText: "OK",
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = "' . $redirectUrl . '";
                    }
                });
            });
        </script>
    </body>
    </html>';
    exit();
}

if(!isset($_SESSION['user'])) {
    // AUDIT: may nagbukas ng approval link na walang login (walang actor, pero nakukuha ang IP at browser)
    audit_log($conn, 'ACCESS_DENIED', 'APPLICATION', isset($_GET['id']) ? intval($_GET['id']) : null,
        'process_approval.php opened without login | Action: ' . substr($_GET['action'] ?? '', 0, 30));
    renderSweetAlert('error', 'Access Denied', 'You must be logged in to access this page.', 'login.php');
}

$app_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$reason = isset($_GET['reason']) ? $_GET['reason'] : ''; 
$approver_id = $_SESSION['user']['employee_id'];
$approver_role = $_SESSION['user']['role']; 
$approver_name = $_SESSION['user']['fullname']; 

// 1. Fetch application details
$query = "SELECT a.*, u.fullname as emp_name, u.role as applicant_role, u.department, u.group_department, u.email as emp_email
          FROM applications a 
          JOIN users u ON a.employee_id = u.employee_id 
          WHERE a.id = ?";
$stmt_app = $conn->prepare($query);
$stmt_app->bind_param("i", $app_id);
$stmt_app->execute();
$app = $stmt_app->get_result()->fetch_assoc();

if (!$app) {
    // AUDIT: may nag-try mag-process ng application na hindi umiiral
    audit_log($conn, 'APPROVAL_FAILED', 'APPLICATION', $app_id, 'Application not found | Action requested: ' . substr($action, 0, 30), $approver_id, $approver_role);
    renderSweetAlert('error', 'Not Found', 'Application record could not be found.');
}

$old_status = $app['status']; 
$emp_id = $app['employee_id'];
$emp_email = $app['emp_email']; 
$applicant_role = $app['applicant_role'];
$type = $app['type'];
$new_status = $old_status; 

// --- AUDIT CONTEXT: laman na idadagdag sa bawat audit entry ng request na ito ---
$is_override = in_array($approver_role, ['Admin', 'HR', 'CBD']);
$audit_ctx = "Type: $type | Employee: $emp_id | Previous status: $old_status" . ($is_override ? " | Override by $approver_role" : "");

// --- HELPER FUNCTION: I-record sa approval_logs ang bawat approve/reject action ---
// 1) approval_logs -> para sa Approval History page ng bawat approver
// 2) audit_logs    -> para sa pangkalahatang audit trail
function logApprovalAction($conn, $application_id, $approver_id, $approver_role, $action, $remarks = null, $audit_details = null) {
    try {
        $stmt_log = $conn->prepare("INSERT INTO approval_logs (application_id, approver_id, approver_role, action, remarks) VALUES (?, ?, ?, ?, ?)");
        $stmt_log->bind_param("issss", $application_id, $approver_id, $approver_role, $action, $remarks);
        $stmt_log->execute();
        $stmt_log->close();
    } catch (Throwable $e) {
        // Hindi dapat masira ang approval kapag pumalya ang logging (halimbawa, kapag wala ang approval_logs table)
        error_log('approval_logs insert failed: ' . $e->getMessage());
    }

    $audit_action = ($action == 'Rejected') ? 'REJECT' : 'APPROVE';
    $details = $audit_details;
    if (!empty($remarks)) { $details .= ' | Reason: ' . $remarks; }
    audit_log($conn, $audit_action, 'APPLICATION', $application_id, $details, $approver_id, $approver_role);
}

// --- FLOW LOGIC ---
if ($applicant_role == 'President') {
    $new_status = 'Approved';
} 
elseif ($action == 'Rejected') {
    $new_status = 'Rejected';
} 
elseif ($action == 'Approved') {
    
    // Admin / HR / CBD Override (Priority/Master Bypass)
    if (in_array($approver_role, ['Admin', 'HR', 'CBD'])) {
        $new_status = 'Approved';
    }
    else {
        // CHECK MAPPING: Check if the applicant is mapped directly to the President
        $stmt_check = $conn->prepare("SELECT president_id FROM president_assignments WHERE group_head_id = ? LIMIT 1");
        $stmt_check->bind_param("s", $emp_id);
        $stmt_check->execute();
        $mapping = $stmt_check->get_result()->fetch_assoc();

        if ($mapping || $applicant_role == 'GroupHead') {
            // CASE: Mapped to President OR Applicant is a Group Head -> President must approve
            if ($approver_role == 'President') {
                $new_status = 'Approved';
            } else {
                $new_status = 'Pending President';
                
                // --- I-RECORD SA APPROVAL LOGS: ito ang approval action ng kasalukuyang approver bago i-forward ---
                logApprovalAction($conn, $app_id, $approver_id, $approver_role, 'Approved', null, $audit_ctx . " | New status: Pending President (forwarded)");

                // Email notification to President
                $stmt_pres = $conn->prepare("SELECT email, fullname FROM users WHERE role = 'President' LIMIT 1");
                $stmt_pres->execute();
                $pres = $stmt_pres->get_result()->fetch_assoc();
                if($pres && !empty($pres['email'])) {
                    
                    // ITINAMA: Pwersahang ginawang HTTPS at tinanggal ang double slash para sa mail clients
                    $login_url = "https://" . str_replace(["http://", "https://"], "", rtrim(BASE_URL, '/')) . "/login.php";

                    $msg_pres = "Hi <b>" . htmlspecialchars($pres['fullname'], ENT_QUOTES, 'UTF-8') . "</b>,<br><br>
                                 This is to notify you that ". htmlspecialchars($app['emp_name'], ENT_QUOTES, 'UTF-8') . " has submitted an application for your review and approval:<br>
                                 <hr style='border:0; border-top:1px solid #eee; margin:15px 0;'>
                                 <b>Employee:</b> " . htmlspecialchars($app['emp_name'], ENT_QUOTES, 'UTF-8') . "<br>
                                 <b>Type:</b> " . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . "<br>
                                 <b>Duration:</b> " . htmlspecialchars($app['duration'], ENT_QUOTES, 'UTF-8') . "<br>
                                 <b>Dates:</b> " . htmlspecialchars($app['target_date'], ENT_QUOTES, 'UTF-8') . " to " . htmlspecialchars($app['end_date'], ENT_QUOTES, 'UTF-8') . "<br>
                                 <b>Status:</b> <span style='color: #4318ff; font-weight: bold;'>Pending President</span><br>
                                 <hr style='border:0; border-top:1px solid #eee; margin:15px 0;'>
                                 To review and approve, click the button below:<br><br>
                                 <a href='" . $login_url . "' style='background-color: #4318ff; color: #ffffff; padding: 12px 25px; text-decoration: none; font-weight: bold; border-radius: 5px; display: inline-block;'>Review & Approve Application</a><br><br>
                                 Best regards,<br>
                                 BOF Timekeeping Administration";
                    $sent_pres = sendBOFEmail($pres['email'], "Action Required: Timesheet/OT/OB Approval for " . $app['emp_name'], $msg_pres);

                    // AUDIT: naipadala o pumalya (pangalan lang ang nilalagay, hindi ang email address)
                    if ($sent_pres) {
                        audit_log($conn, 'NOTIFY_APPROVER', 'APPLICATION', $app_id, "Email sent to President: " . $pres['fullname'], $approver_id, $approver_role);
                    } else {
                        audit_log($conn, 'NOTIFY_FAILED', 'APPLICATION', $app_id, "Email to President (" . $pres['fullname'] . ") was NOT sent. Check the PHP error log for the Microsoft Graph error", $approver_id, $approver_role);
                    }
                } else {
                    // AUDIT: walang President na may email
                    audit_log($conn, 'NOTIFY_NO_RECIPIENT', 'APPLICATION', $app_id, "No President with an email address found", $approver_id, $approver_role);
                }

                // ITINAMA: I-update ang pansamantalang status sa DB at mag-exit agad para malinis ang request
                $current_time = date('Y-m-d H:i:s');
                $stmt_mid = $conn->prepare("UPDATE applications SET status = ?, approver_id = ?, approved_at = ? WHERE id = ?");
                $stmt_mid->bind_param("sssi", $new_status, $approver_id, $current_time, $app_id);
                $stmt_mid->execute();

                renderSweetAlert('info', 'Application Forwarded', 'Application has been forwarded to the President. Status: Pending President');
            }
        } 
        else {
            // CASE: STANDARD FLOW (Not mapped to the President)
            
            // 1. Kapag STAFF ang nag-file
            if ($applicant_role == 'Staff') {
                if ($old_status == 'Pending Unit Head') {
                    // Mula sa Unit Head approval, aakyat sa Department Head review
                    $new_status = 'Pending Dept. Head';

                    // --- I-RECORD SA APPROVAL LOGS: dito mismo nagre-record ang Unit Head's approval action ---
                    logApprovalAction($conn, $app_id, $approver_id, $approver_role, 'Approved', null, $audit_ctx . " | New status: Pending Dept. Head (forwarded)");
                    
                    // Notification to Department Head
                    $stmt_next = $conn->prepare("SELECT u.email, u.fullname FROM users u JOIN dept_head_assignments dha ON u.employee_id = dha.dept_head_id WHERE dha.unit_name = ? AND u.role = 'DeptHead' LIMIT 1");
                    $stmt_next->bind_param("s", $app['department']);
                    $stmt_next->execute();
                    $next = $stmt_next->get_result()->fetch_assoc();
                    if($next && !empty($next['email'])) {
                         
                         // ITINAMA: Pwersahang ginawang HTTPS at tinanggal ang double slash para sa mail clients
                         $login_url = "https://" . str_replace(["http://", "https://"], "", rtrim(BASE_URL, '/')) . "/login.php";

                         $msg_dept = "Hi <b>" . htmlspecialchars($next['fullname'], ENT_QUOTES, 'UTF-8') . "</b>,<br><br>
                                      This is to notify you that " . htmlspecialchars($app['emp_name'], ENT_QUOTES, 'UTF-8') . " has submitted an application for your review and approval.<br>
                                      <hr style='border:0; border-top:1px solid #eee; margin:15px 0;'>
                                      <b>Employee:</b> " . htmlspecialchars($app['emp_name'], ENT_QUOTES, 'UTF-8') . "<br>
                                      <b>Type:</b> " . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . "<br>
                                      <b>Duration:</b> " . htmlspecialchars($app['duration'], ENT_QUOTES, 'UTF-8') . "<br>
                                      <b>Dates:</b> " . htmlspecialchars($app['target_date'], ENT_QUOTES, 'UTF-8') . " to " . htmlspecialchars($app['end_date'], ENT_QUOTES, 'UTF-8') . "<br>
                                      <b>Status:</b> <span style='color: #4318ff; font-weight: bold;'>Pending Dept. Head</span><br>
                                      <hr style='border:0; border-top:1px solid #eee; margin:15px 0;'>
                                      To review and approve, click the button below:<br><br>
                                      <a href='" . $login_url . "' style='background-color: #4318ff; color: #ffffff; padding: 12px 25px; text-decoration: none; font-weight: bold; border-radius: 5px; display: inline-block;'>Review & Approve Application</a><br><br>
                                      Best regards,<br>
                                      BOF Timekeeping Administration";
                         $sent_dept = sendBOFEmail($next['email'], "Action Required: Timesheet/OT/OB Approval for " . $app['emp_name'], $msg_dept);

                         // AUDIT: naipadala o pumalya (pangalan lang ang nilalagay, hindi ang email address)
                         if ($sent_dept) {
                             audit_log($conn, 'NOTIFY_APPROVER', 'APPLICATION', $app_id, "Email sent to DeptHead: " . $next['fullname'], $approver_id, $approver_role);
                         } else {
                             audit_log($conn, 'NOTIFY_FAILED', 'APPLICATION', $app_id, "Email to DeptHead (" . $next['fullname'] . ") was NOT sent. Check the PHP error log for the Microsoft Graph error", $approver_id, $approver_role);
                         }
                    } else {
                         // AUDIT: walang nahanap na Dept Head para sa unit na ito, o walang email
                         audit_log($conn, 'NOTIFY_NO_RECIPIENT', 'APPLICATION', $app_id, "No DeptHead with an email found for unit: " . $app['department'], $approver_id, $approver_role);
                    }

                    // ITINAMA: I-update ang pansamantalang status sa DB at mag-exit agad para malinis ang request
                    $current_time = date('Y-m-d H:i:s');
                    $stmt_mid = $conn->prepare("UPDATE applications SET status = ?, approver_id = ?, approved_at = ? WHERE id = ?");
                    $stmt_mid->bind_param("sssi", $new_status, $approver_id, $current_time, $app_id);
                    $stmt_mid->execute();

                    renderSweetAlert('info', 'Application Forwarded', 'Application has been forwarded to Department Head. Status: Pending Dept. Head');
                } 
                elseif ($old_status == 'Pending Dept. Head') {
                    // Para sa Staff (with or without unit head approval), kapag in-approve ng Department Head -> APPROVED agad!
                    $new_status = 'Approved';
                }
            }
            
            // 2. Kapag UNIT HEAD ang nag-file
            elseif ($applicant_role == 'UnitHead') {
                if ($old_status == 'Pending Dept. Head') {
                    // Kapag in-approve ng Department Head -> APPROVED agad!
                    $new_status = 'Approved';
                }
            }
            
            // 3. Kapag DEPARTMENT HEAD ang nag-file
            elseif ($applicant_role == 'DeptHead') {
                if ($old_status == 'Pending Group Head') {
                    $new_status = 'Approved';
                }
            }
        }
    }
}

// --- ATTENDANCE & LEAVE LOGIC (ITINAMA PARA SA WORKING DAYS ONLY) ---
if ($new_status == 'Approved' && $old_status !== 'Approved') {
    if(strpos($type, 'Leave') !== false) {
        $startDate = new DateTime($app['target_date']);
        $endDate = new DateTime($app['end_date']);
        
        // Dagdagan ng 1 araw ang dulo para maging "inclusive" ang huling araw sa loop ng DatePeriod
        $endDate->modify('+1 day'); 
        
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($startDate, $interval, $endDate);
        
        $working_days = 0;
        
        foreach ($dateRange as $date) {
            // N = 1 (Monday) hanggang 7 (Sunday). Kung mas mababa sa 6, Lunes hanggang Biyernes ito.
            $dayOfWeek = $date->format('N');
            if ($dayOfWeek < 6) {
                $working_days++;
            }
        }
        
        $leave_col = ($type == 'Mandatory Leave') ? 'mandatory_leave' : 'annual_leave';
        
        // Kalkulahin ang bawas: Kung Half Day at 1 working day lang ang hiniling, magiging 0.5. Kung marami, working days ang batayan.
        $deduction = ($app['duration'] == 'Half Day' && $working_days == 1) ? 0.5 : (float)$working_days;
        
        // I-update lamang ang credit balance kung may tunay na pumasok na working day sa napiling range
        if ($working_days > 0) {
            $update_balance = $conn->prepare("UPDATE users SET $leave_col = $leave_col - ? WHERE employee_id = ?");
            $update_balance->bind_param("ds", $deduction, $emp_id);
            $update_balance->execute();

            // AUDIT: nabawasan ang leave credits ng empleyado. Kapag hindi Annual/Mandatory ang type pero nabawasan ang annual_leave, may WARNING sa log
            $leave_warn = (strpos($type, 'Annual Leave') === false && $type !== 'Mandatory Leave')
                ? ' | WARNING: type is not Annual/Mandatory but annual_leave was deducted' : '';
            audit_log($conn, 'LEAVE_CREDIT_DEDUCTED', 'LEAVE_BALANCE', $emp_id,
                "Application #$app_id | Type: $type | $leave_col: -$deduction day(s) | {$app['target_date']} to {$app['end_date']}" . $leave_warn,
                $approver_id, $approver_role);
        }
    }
    
    // ITINAMA NA LOGIC PARA SA OB FILING BUG
    if ($type == 'OB') {
        // Kukunin natin ang totoong departure at arrival time mula sa pinasa niyang dynamic row record
        $in = $app['target_date'] . " " . $app['departure_time']; 
        $out = $app['target_date'] . " " . $app['arrival_time'];
        $info = "OB Approved: " . $app['details'];
        
        $stmt_log = $conn->prepare("INSERT INTO attendance_logs (employee_id, log_time, log_type, status) VALUES (?, ?, 'IN', ?), (?, ?, 'OUT', ?)");
        $stmt_log->bind_param("ssssss", $emp_id, $in, $info, $emp_id, $out, $info);
        $stmt_log->execute();

        // AUDIT: nagdagdag ng attendance logs dahil sa approved OB
        audit_log($conn, 'ATTENDANCE_LOG_CREATED', 'ATTENDANCE', $emp_id, "From approved OB #$app_id | IN: $in | OUT: $out", $approver_id, $approver_role);
    } 
    elseif ($type == 'OT') {
        $ot_info = "OT Approved: " . $app['details'];
        $update_ot = $conn->prepare("UPDATE attendance_logs SET status = ? WHERE employee_id = ? AND DATE(log_time) = ? AND log_type = 'OUT'");
        $update_ot->bind_param("sss", $ot_info, $emp_id, $app['target_date']);
        $update_ot->execute();

        // AUDIT: na-update ang OUT log ng araw na iyon dahil sa approved OT (0 rows ang ibig sabihin ay walang OUT log sa araw na iyon)
        audit_log($conn, 'ATTENDANCE_LOG_UPDATED', 'ATTENDANCE', $emp_id, "From approved OT #$app_id | Date: {$app['target_date']} | Rows updated: " . $update_ot->affected_rows, $approver_id, $approver_role);
    }
    elseif ($type == 'MissedLog') {
        $full_log_time = $app['target_date'] . " " . $app['estimated_time'];
        $log_missed_type = $app['log_missed_type'];
        $ml_info = "Missed Log Approved: " . $app['details'];
        $stmt_ml = $conn->prepare("INSERT INTO attendance_logs (employee_id, log_time, log_type, status) VALUES (?, ?, ?, ?)");
        $stmt_ml->bind_param("ssss", $emp_id, $full_log_time, $log_missed_type, $ml_info);
        $stmt_ml->execute();

        // AUDIT: nagdagdag ng attendance log dahil sa approved Missed Log
        audit_log($conn, 'ATTENDANCE_LOG_CREATED', 'ATTENDANCE', $emp_id, "From approved Missed Log #$app_id | $log_missed_type at $full_log_time", $approver_id, $approver_role);
    }
}

// 3. Update Application Table
$current_time = date('Y-m-d H:i:s');
$final_approver = ($applicant_role == 'President') ? $emp_id : $approver_id;

$stmt_final = $conn->prepare("UPDATE applications SET status = ?, approver_id = ?, approved_at = ?, reject_reason = ? WHERE id = ?");
$stmt_final->bind_param("ssssi", $new_status, $final_approver, $current_time, $reason, $app_id);

if($stmt_final->execute()){

    // --- I-RECORD SA APPROVAL LOGS: ito ang huling Approve/Reject action na nagawa ngayon ---
    if ($new_status == 'Approved' || $new_status == 'Rejected') {
        $log_remarks = ($new_status == 'Rejected') ? $reason : null;
        // Gamit ang $approver_id (hindi $final_approver) dahil siya talaga ang gumawa ng aksyon ngayon;
        // maliban kung self-filed ng President (auto-approved), doon ang approver mismo ang applicant.
        $log_approver_id = ($applicant_role == 'President') ? $emp_id : $approver_id;
        $log_approver_role = ($applicant_role == 'President') ? 'President' : $approver_role;
        logApprovalAction($conn, $app_id, $log_approver_id, $log_approver_role, $new_status, $log_remarks, $audit_ctx . " | New status: $new_status");
    } else {
        // AUDIT: tumakbo ang request pero walang nagbago sa status (hindi tumugma sa kahit anong flow, o hindi Approved/Rejected ang action)
        audit_log($conn, 'ACTION_NO_TRANSITION', 'APPLICATION', $app_id, $audit_ctx . " | Action requested: " . substr($action, 0, 30) . " | Status unchanged: $new_status", $approver_id, $approver_role);
    }

    // Send email notification to employee only if the final state is Approved or Rejected
    if (($new_status == 'Approved' || $new_status == 'Rejected') && !empty($emp_email)) {
        $color = ($new_status == 'Approved') ? '#28a745' : '#dc3545';
        $subj_result = "JDTech Update: $new_status (" . $type . ")";
        $body_result = "Good day, " . htmlspecialchars($app['emp_name'], ENT_QUOTES, 'UTF-8') . "!<br><br>
                        Your application for <b>" . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . "</b> has been 
                        <span style='color: $color; font-weight: bold;'>$new_status</span>.<br>
                        <b>Date:</b> " . htmlspecialchars($app['target_date'], ENT_QUOTES, 'UTF-8') . "<br>";
        
        if ($new_status == 'Rejected') {
            $body_result .= "<b>Rejection Reason:</b> " . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "<br>";
        }

        $body_result .= "<br>Processed by: <b>" . htmlspecialchars($approver_name, ENT_QUOTES, 'UTF-8') . "</b>";
        
        $sent_emp = sendBOFEmail($emp_email, $subj_result, $body_result);

        // AUDIT: naipadala o pumalya ang resulta sa empleyado (pangalan lang, hindi ang email address)
        if ($sent_emp) {
            audit_log($conn, 'NOTIFY_EMPLOYEE', 'APPLICATION', $app_id, "Result email ($new_status) sent to employee: " . $app['emp_name'], $approver_id, $approver_role);
        } else {
            audit_log($conn, 'NOTIFY_FAILED', 'APPLICATION', $app_id, "Result email ($new_status) to employee (" . $app['emp_name'] . ") was NOT sent. Check the PHP error log for the Microsoft Graph error", $approver_id, $approver_role);
        }
    } elseif ($new_status == 'Approved' || $new_status == 'Rejected') {
        // AUDIT: walang email address ang empleyado
        audit_log($conn, 'NOTIFY_NO_RECIPIENT', 'APPLICATION', $app_id, "Employee " . $app['emp_name'] . " has no email address on file", $approver_id, $approver_role);
    }

    $swal_icon = ($new_status == 'Approved') ? 'success' : (($new_status == 'Rejected') ? 'error' : 'info');
    $swal_title = ($new_status == 'Approved') ? 'Application Approved' : (($new_status == 'Rejected') ? 'Application Rejected' : 'Action Processed');
    
    renderSweetAlert($swal_icon, $swal_title, "The application status has been updated to: $new_status");
} else {
    // AUDIT: pumalya ang pag-update ng application
    audit_log($conn, 'APPROVAL_FAILED', 'APPLICATION', $app_id, $audit_ctx . " | DB error: " . $conn->error, $approver_id, $approver_role);

    renderSweetAlert('error', 'Database Error', 'Error updating record: ' . $conn->error);
}
?>