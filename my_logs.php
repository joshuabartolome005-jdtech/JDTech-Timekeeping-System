<?php
session_start(); // Mahalaga para sa session validation
include 'config.php';

// Proteksyon: Dapat naka-login muna
if(!isset($_SESSION['user'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];

// 1. KUNIN ANG DATA NG USER
$stmt_user = $conn->prepare("SELECT fullname, role, department, is_head FROM users WHERE employee_id = ?");
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

// 2. DATE RANGE FILTER LOGIC
$start_date = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

// 3. SQL QUERY PARA SA LOGS (PROTEKTADO LABAN SA CANCELLED APPLICATIONS)
// ITINAMA: Sinala ang query gamit ang NOT LIKE para i-exclude ang logs na galing sa mga kinansela o tinanggihang aplikasyon
$query = "SELECT 
            DATE(log_time) as log_date,
            GROUP_CONCAT(CONCAT(DATE_FORMAT(log_time, '%h:%i %p'), ':', log_type, ':', IFNULL(status, 'Normal')) ORDER BY log_time SEPARATOR '|') as punches
          FROM attendance_logs 
          WHERE employee_id = ? 
          AND DATE(log_time) BETWEEN ? AND ?
          AND (status IS NULL 
               OR (status NOT LIKE '%Cancelled%' 
                   AND status NOT LIKE '%Rejected%' 
                   AND status NOT LIKE '%Reverted%'))
          GROUP BY DATE(log_time) 
          ORDER BY log_date DESC";

$stmt_logs = $conn->prepare($query);
$stmt_logs->bind_param("sss", $user_id, $start_date, $end_date);
$stmt_logs->execute();
$result = $stmt_logs->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - Attendance Logs</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-light: #f4f7fe;
            --primary-blue: #2896f0;
            --text-dark: #1b254b;
            --text-muted: #a3aed0;
            --white: #ffffff;
            --shadow: 14px 17px 40px 4px rgba(112, 144, 176, 0.08);
            --success: #01b574;
            --warning: #ffb547;
            --info: #422afb;
        }

        body { margin: 0; padding: 0; font-family: 'DM Sans', 'Segoe UI', sans-serif; background-color: var(--bg-light); display: flex; }
        .content { flex: 1; margin-left: 260px; padding: 30px; box-sizing: border-box; min-height: 100vh; }
        .header-nav { margin-bottom: 25px; }
        .header-nav h1 { color: var(--text-dark); font-size: 24px; margin: 0; font-weight: 700; }
        .header-nav p { color: var(--text-muted); margin: 5px 0 0; font-size: 14px; }
        .container-card { background: var(--white); padding: 25px; border-radius: 20px; box-shadow: var(--shadow); }
        .filter-section { margin-bottom: 30px; padding: 20px; background: #f8faff; border-radius: 15px; display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap; }
        .input-section { display: flex; gap: 20px; flex-wrap: wrap; }
        .input-group { display: flex; flex-direction: column; gap: 8px; }
        .input-group label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .input-group input { padding: 10px 15px; border: 1px solid #e0e5f2; border-radius: 12px; outline: none; color: var(--text-dark); font-size: 14px; }
        .btn-filter { background: var(--primary-blue); color: white; border: none; padding: 12px 25px; border-radius: 12px; cursor: pointer; font-weight: 700; transition: 0.3s; display: flex; align-items: center; gap: 10px; }
        .btn-filter:hover { background: #3311db; transform: translateY(-2px); }
        .dtr-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .dtr-table th { text-align: center; padding: 15px; color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #e9edf7; }
        .dtr-table td { padding: 18px 15px; text-align: center; color: var(--text-dark); font-size: 14px; border-bottom: 1px solid #e9edf7; }
        .date-cell { text-align: left !important; }
        .date-cell strong { display: block; color: var(--text-dark); font-size: 15px; }
        .date-cell span { color: var(--text-muted); font-size: 12px; }
        
        /* Indicators for Logs */
        .punch-wrapper { position: relative; display: inline-block; margin-bottom: 5px; }
        .punch-val { font-weight: 700; color: var(--primary-blue); background: #f4f7fe; padding: 6px 10px; border-radius: 8px; display: inline-block; min-width: 80px; }
        .applied-tag { font-size: 9px; color: var(--info); display: block; margin-top: 2px; font-weight: 600; text-transform: uppercase; }
        
        .status-badge { padding: 6px 12px; border-radius: 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .status-complete { background: #e6faf4; color: var(--success); }
        .status-incomplete { background: #fff8eb; color: var(--warning); }
        .no-data { padding: 50px !important; color: var(--text-muted); font-style: italic; }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>

<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="header-nav">
            <h1>Attendance Records</h1>
            <p>Review your daily time logs.</p>
        </div>

        <div class="container-card">
            <form method="GET" class="filter-section">
                <div class="input-section">
                    <div class="input-group">
                        <label>From Date</label>
                        <input type="date" name="from" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="input-group">
                        <label>To Date</label>
                        <input type="date" name="to" value="<?php echo $end_date; ?>">
                    </div>
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fa-solid fa-filter"></i> Filter Logs
                </button>
            </form>

            <table class="dtr-table">
                <thead>
                    <tr>
                        <th class="date-cell">Date</th>
                        <th>AM IN</th>
                        <th>AM OUT</th>
                        <th>PM IN</th>
                        <th>PM OUT</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): 
                            // Gagamit tayo ng multi-dimensional array para mag-suporta ng maraming logs sa isang cell
                            $logs = [
                                'am_in' => [],
                                'am_out' => [],
                                'pm_in' => [],
                                'pm_out' => []
                            ];

                            $punches_raw = explode('|', $row['punches']);
                            $total_punches_count = 0; // Gagamitin natin itong counter para sa status calculation
                            
                            foreach($punches_raw as $p_item) {
                                $parts = explode(':', $p_item);
                                if(count($parts) < 3) continue;

                                $time_val = $parts[0] . ':' . $parts[1]; 
                                $type_val = $parts[2]; 
                                $status_val = isset($parts[3]) ? $parts[3] : 'Normal';
                                
                                // Check kung galing sa Missed Log application
                                $is_applied = (strpos($status_val, 'Missed Log Approved') !== false);

                                $hour = (int)date('H', strtotime($time_val));
                                $total_punches_count++; // Dagdagan ang bilang kapag may wastong log item

                                // Sa halip na palitan ang laman, i-push natin sa array para maipon lahat ng logs
                                if($type_val == 'IN') {
                                    if($hour < 12) {
                                        $logs['am_in'][] = ['val' => $time_val, 'applied' => $is_applied];
                                    } else {
                                        $logs['pm_in'][] = ['val' => $time_val, 'applied' => $is_applied];
                                    }
                                } else if($type_val == 'OUT') {
                                    if($hour < 14) {
                                        $logs['am_out'][] = ['val' => $time_val, 'applied' => $is_applied];
                                    } else {
                                        $logs['pm_out'][] = ['val' => $time_val, 'applied' => $is_applied];
                                    }
                                }
                            }

                            // ITINAMA NA LOGIC: Basta mayroon siyang 2 o higit pang magkakahiwalay na punches sa araw na iyon, 
                            // ituturing itong 'Complete' kahit magkapareho ang log_type (e.g. magkasunod na IN dahil sa missed punch)
                            $is_complete = ($total_punches_count >= 2);
                        ?>
                            <tr>
                                <td class="date-cell">
                                    <strong><?php echo date('M d, Y', strtotime($row['log_date'])); ?></strong>
                                    <span><?php echo date('l', strtotime($row['log_date'])); ?></span>
                                </td>
                                <?php foreach(['am_in', 'am_out', 'pm_in', 'pm_out'] as $col): ?>
                                    <td>
                                        <?php if(!empty($logs[$col])): ?>
                                            <?php foreach($logs[$col] as $punch): ?>
                                                <div class="punch-wrapper">
                                                    <span class="punch-val"><?php echo $punch['val']; ?></span>
                                                    <?php if($punch['applied']): ?>
                                                        <span class="applied-tag"><i class="fa-solid fa-circle-check"></i> Applied</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="punch-wrapper">
                                                <span class="punch-val">--:--</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if($is_complete): ?>
                                        <span class="status-badge status-complete">Complete</span>
                                    <?php else: ?>
                                        <span class="status-badge status-incomplete">Incomplete</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="no-data">
                                <i class="fa-regular fa-folder-open" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                No attendance records found for the selected range.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>