<?php
include 'config.php';

if(!isset($_SESSION['user']) || !isset($_SESSION['user']['employee_id'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user']['employee_id'];

$stmt = $conn->prepare("SELECT fullname, role, annual_leave, mandatory_leave, department, is_head FROM users WHERE employee_id = ?");
$stmt->bind_param("s", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

if (!$user_data) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$user_role = $user_data['role'];
$is_management = ($user_role == 'HR' || $user_role == 'CBD');

$total_active = 0;
if($is_management) {
    $count_res = $conn->query("SELECT COUNT(*) as total FROM users WHERE status = 'Active'");
    if ($count_res) {
        $count_data = $count_res->fetch_assoc();
        $total_active = $count_data['total'];
    }
}

$display_in = '--:--';
$display_out = '--:--';

if (!$is_management) {
    $today = date('Y-m-d');
    $log_stmt = $conn->prepare("SELECT MIN(log_time) as first_punch, MAX(log_time) as last_punch, COUNT(id) as total_punches FROM attendance_logs WHERE employee_id = ? AND DATE(log_time) = ?");
    $log_stmt->bind_param("ss", $user_id, $today);
    $log_stmt->execute();
    $today_logs = $log_stmt->get_result()->fetch_assoc();

    if ($today_logs && $today_logs['first_punch']) {
        $display_in = date('h:i a', strtotime($today_logs['first_punch']));
    }
    if ($today_logs && $today_logs['total_punches'] > 1) {
        $display_out = date('h:i a', strtotime($today_logs['last_punch']));
    }
    $log_stmt->close();
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - dashboard</title>
    <link rel="icon" href="jdlogo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-dark: #2c3e50;
            --secondary-dark: #34495e;
            --accent-orange: #f39c12;
            --text-light: #bdc3c7;
            --bg-gray: #f4f7f6;
            --success-green: #27ae60;
            --danger-red: #e74c3c;
            --hr-blue: #3498db;
            --cbd-gold: #d4af37;
            --purple-mandatory: #9b59b6;
        }

        body { font-family: 'Segoe UI', sans-serif; display: flex; margin: 0; background-color: var(--bg-gray); }
        .content { flex: 1; margin-left: 260px; padding: 40px; box-sizing: border-box; min-height: 100vh; }

        .management-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; }
        .active-count-box { 
            background: white; padding: 30px; border-radius: 15px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-left: 6px solid var(--success-green);
            display: flex; flex-direction: column; justify-content: center;
        }

        .hr-date-card { background: white; padding: 40px; border-radius: 15px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border-top: 6px solid <?php echo ($user_role == 'CBD') ? 'var(--cbd-gold)' : 'var(--hr-blue)'; ?>; }
        .today-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border-top: 5px solid var(--primary-dark); }
        .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-box { padding: 25px; border-radius: 12px; color: white; box-shadow: 0 6px 15px rgba(0,0,0,0.1); }
        .stat-box p { margin: 5px 0; font-size: 36px; font-weight: bold; }

        .sync-card { background: white; padding: 25px; border-radius: 12px; border-left: 5px solid var(--accent-orange); margin-top: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .btn-sync { background: var(--accent-orange); color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: bold; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-sync:hover { background: #d68910; }
        .btn-sync:disabled { background: #ccc; cursor: not-allowed; }

        input[type="date"] { padding: 10px; border: 1px solid #ddd; border-radius: 5px; outline: none; }
        #digitalClock { font-size: 24px; font-weight: bold; color: var(--primary-dark); margin-top: 5px; }
        
        .loading-spinner { display: none; width: 15px; height: 15px; border: 2px solid #ffffff; border-top: 2px solid transparent; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @media (max-width: 768px) { .management-grid { grid-template-columns: 1fr; } .content { padding: 20px; margin-left: 0; } }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="content">
        
        <?php if($is_management): ?>
            <div class="welcome-msg" style="margin-bottom: 20px;">
                <h2 style="margin:0; color: var(--primary-dark);">
                    <?php echo ($user_role == 'CBD') ? 'CBD Executive Dashboard' : 'HR Management Dashboard'; ?>
                </h2>
                <p style="margin:0; color: #7f8c8d;">Welcome back, <?php echo htmlspecialchars($user_data['fullname'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="management-grid">
                <div class="hr-date-card">
                    <div id="digitalClock">--:--:-- --</div>
                    <h1 style="font-size: 48px; margin: 10px 0;"><?php echo date('F d, Y'); ?></h1>
                    <div style="font-size: 22px; color: var(--accent-orange); font-weight: bold;"><?php echo date('l'); ?></div>
                </div>

                <div class="active-count-box">
                    <span style="color: #7f8c8d; font-weight: bold; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">Total Active Workforce</span>
                    <h2 style="font-size: 56px; color: var(--success-green); margin: 10px 0;"><?php echo (int)$total_active; ?></h2>
                </div>
            </div>

            <?php if($user_role == 'CBD'): ?>
                <div class="sync-card">
                    <h3 style="margin-top:0;"><i class="fa-solid fa-rotate me-2 text-warning"></i>Biometric Synchronization</h3>
                    <div style="display:flex; flex-wrap: wrap; gap:15px; align-items: flex-end;">
                        <div>
                            <label style="font-size:11px; font-weight:bold; color: #7f8c8d;">START DATE</label><br>
                            <input type="date" id="startDate" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label style="font-size:11px; font-weight:bold; color: #7f8c8d;">END DATE</label><br>
                            <input type="date" id="endDate" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button id="syncBtn" class="btn-sync">
                            <div class="loading-spinner" id="spinner"></div>
                            <span id="btnText">Sync Biometrics</span>
                        </button>
                    </div>
                    <div id="syncStatusBox" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 8px; display: none;">
                        <p id="syncStatus" style="margin: 0; font-size: 13px; color: #2c3e50;"></p>
                        <ul id="deviceDetails" style="margin: 5px 0 0 0; padding-left: 20px; font-size: 12px; color: #7f8c8d;"></ul>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="welcome-msg" style="margin-bottom: 20px;">
                <h2 style="margin:0; color: var(--primary-dark);">Employee Dashboard</h2>
                <p style="margin:0; color: #7f8c8d;">Hello, <?php echo htmlspecialchars($user_data['fullname'], ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="today-card">
                <div>
                    <div id="digitalClock" style="font-size: 20px;">--:--:-- --</div>
                    <h2 style="margin:5px 0 0 0; font-size: 16px; color: var(--secondary-dark);"><?php echo date('F d, Y - l'); ?></h2>
                </div>
                <div style="display:flex; gap:30px;">
                    <div style="text-align:center;">
                        <label style="display:block; font-size:11px; color:#95a5a6; font-weight:bold; text-transform: uppercase;">Time In</label>
                        <span style="font-size:20px; font-weight:bold; color:var(--success-green);"><?php echo htmlspecialchars($display_in); ?></span>
                    </div>
                    <div style="text-align:center;">
                        <label style="display:block; font-size:11px; color:#95a5a6; font-weight:bold; text-transform: uppercase;">Time Out</label>
                        <span style="font-size:20px; font-weight:bold; color:var(--danger-red);"><?php echo htmlspecialchars($display_out); ?></span>
                    </div>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat-box" style="background: var(--hr-blue);">
                    <h4 style="margin:0; opacity:0.8;">Annual Leave Balance</h4>
                    <p><?php echo number_format($user_data['annual_leave'], 1); ?></p>
                </div>
                <div class="stat-box" style="background: var(--purple-mandatory);">
                    <h4 style="margin:0; opacity:0.8;">Mandatory Leave Balance</h4>
                    <p><?php echo number_format($user_data['mandatory_leave'], 1); ?></p>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const clockElements = document.querySelectorAll('#digitalClock');
            clockElements.forEach(el => el.innerText = timeString);
        }
        setInterval(updateClock, 1000);
        updateClock();

        const syncBtn = document.getElementById('syncBtn');
        const syncStatus = document.getElementById('syncStatus');
        const syncStatusBox = document.getElementById('syncStatusBox');
        const deviceDetails = document.getElementById('deviceDetails');
        const spinner = document.getElementById('spinner');
        const btnText = document.getElementById('btnText');

        if(syncBtn) {
            syncBtn.addEventListener('click', function() {
                const start = document.getElementById('startDate').value;
                const end = document.getElementById('endDate').value;
                
                if(!start || !end) {
                    alert("Please select both start and end dates.");
                    return;
                }

               const apiUrl = `http://192.168.0.30:5035/sync-biometrics?start=${start}&end=${end}`;
                syncBtn.disabled = true;
                spinner.style.display = "block";
                btnText.innerText = "Syncing Devices...";
                syncStatusBox.style.display = "block";
                syncStatus.innerHTML = "<strong>Status:</strong> Connecting to Biometric Devices...";
                deviceDetails.innerHTML = "";

                fetch(apiUrl, { method: 'POST' })
                .then(response => {
                    if (!response.ok) throw new Error('API Response Error');
                    return response.json();
                })
                .then(data => {
                    syncStatus.innerHTML = `<strong>Success!</strong> Total records added: ${data.total_records || 0}`;
                    
                    if(data.details && data.details.length > 0) {
                        data.details.forEach(info => {
                            const li = document.createElement('li');
                            li.textContent = info;
                            deviceDetails.appendChild(li);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    syncStatus.innerHTML = "<strong style='color:red;'>Sync Failed:</strong> Connection refused.";
                    const li = document.createElement('li');
                    li.style.color = "red";
                    li.textContent = "Make sure your C# app is running and your network allows connection to port 5035.";
                    deviceDetails.appendChild(li);
                })
                .finally(() => {
                    syncBtn.disabled = false;
                    spinner.style.display = "none";
                    btnText.innerText = "Sync Biometrics";
                });
            });
        }
    </script>
</body>
</html>