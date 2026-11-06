<?php
// Kasama na dapat ang session_start() sa loob ng config.php
include 'config.php';

// Check kung naka-login
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Kunin ang sariling ID mula sa session para security
$emp_id = $_SESSION['user']['employee_id'];

// SECURE: Query gamit ang Prepared Statement
$sql = "SELECT * FROM users WHERE employee_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $emp_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

if (!$employee) {
    die("Employee profile not found.");
}

// Path para sa profile pictures
$upload_path = "uploads/profiles/";
$profile_image = $employee['profile_pic']; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JDTech - my profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-body: #f8f9fa;
            --card-bg: #ffffff;
            --text-main: #2d3748;
            --text-muted: #718096;
            --primary: #3182ce;
            --border-color: #e2e8f0;
            --input-bg: #edf2f7;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--bg-body);
            margin: 0;
            color: var(--text-main);
        }

        .main-content {
            margin-left: 260px; 
            padding: 40px;
        }

        .profile-container {
            max-width: 1000px;
            margin: 0 auto;
            background: var(--card-bg);
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border-top: 5px solid #f6ad55;
        }

        .header-title {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .profile-pic-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .img-container {
            width: 150px;
            height: 150px;
            margin: 0 auto;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .img-container i {
            font-size: 100px;
            color: #cbd5e0;
        }

        .section-label {
            font-size: 14px;
            font-weight: 700;
            color: #3182ce;
            text-transform: uppercase;
            margin: 30px 0 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-label::before {
            content: '';
            width: 3px;
            height: 15px;
            background: #3182ce;
            display: inline-block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: span 3;
        }

        label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
            font-weight: 600;
        }

        input, textarea {
            padding: 10px 15px;
            background-color: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            color: #4a5568;
            cursor: not-allowed;
            width: 100%;
            box-sizing: border-box;
        }

        textarea { height: 80px; resize: none; }

        .notice-box {
            margin-top: 40px;
            padding: 15px;
            background-color: #fffaf0;
            border-left: 4px solid #ed8936;
            color: #c05621;
            font-size: 13px;
            border-radius: 4px;
        }

        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr 1fr; }
            .form-group.full-width { grid-column: span 2; }
        }
    </style>
    <link rel="icon" href="jdlogo.png">
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="profile-container">
            <div class="header-title">Employee Profile View</div>

            <div class="profile-pic-header">
                <div class="img-container">
                    <?php if (!empty($profile_image) && file_exists($upload_path . $profile_image)): ?>
                        <img src="<?php echo $upload_path . htmlspecialchars($profile_image); ?>" alt="Profile Picture">
                    <?php else: ?>
                        <i class="fa-solid fa-circle-user"></i>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section-label">Identity & Personal Details</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Employee ID</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['employee_id']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Nickname</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['nickname'] ?? 'N/A'); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Role / Access Level</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['role']); ?>" disabled>
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['last_name']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['first_name']); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['middle_name'] ?? ''); ?>" disabled>
                </div>

                <div class="form-group">
                    <label>Birth Date</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['birth_date'] ?? 'N/A'); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['gender'] ?? 'N/A'); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Civil Status</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['civil_status'] ?? 'N/A'); ?>" disabled>
                </div>

                <div class="form-group">
                    <label>Mother/Father? (Parent)</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['is_parent'] ?? 'No'); ?>" disabled>
                </div>
                <div class="form-group full-width">
                    <label>Home Address</label>
                    <textarea disabled><?php echo htmlspecialchars($employee['home_address'] ?? 'N/A'); ?></textarea>
                </div>
            </div>

            <div class="section-label">Contact Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Personal Email</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['email'] ?? 'N/A'); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['contact_no'] ?? 'N/A'); ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" value="<?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?>" disabled>
                </div>
            </div>

            <div class="notice-box">
                <i class="fa-solid fa-circle-info"></i> 
                <strong>Read-Only Mode:</strong> The above information is from the HR Department's records. If any changes are required, please contact HR Department.
            </div>
        </div>
    </div>

</body>
</html>