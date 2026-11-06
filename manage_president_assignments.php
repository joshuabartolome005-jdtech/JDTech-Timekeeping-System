<?php
include 'config.php';

// Check if Admin or HR
if(!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['Admin', 'HR'])) {
    header("Location: dashboard.php");
    exit();
}

// --- HANDLE SAVING ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_assignments'])) {
    $president_id = $_POST['president_id'];
    $assigned_heads = isset($_POST['assigned_heads']) ? $_POST['assigned_heads'] : [];

    // Clean old assignments for this specific President
    $delete = $conn->prepare("DELETE FROM president_assignments WHERE president_id = ?");
    $delete->bind_param("s", $president_id);
    $delete->execute();

    // Insert new assignments (Handles both Group Heads and Dept Heads)
    if (!empty($assigned_heads)) {
        $insert = $conn->prepare("INSERT INTO president_assignments (president_id, group_head_id) VALUES (?, ?)");
        foreach ($assigned_heads as $head_id) {
            $insert->bind_param("ss", $president_id, $head_id);
            $insert->execute();
        }
    }
    echo "<script>alert('Assignments Updated Successfully!'); window.location='manage_president_assignments.php';</script>";
    exit();
}

// --- FETCH DATA ---
// 1. Kunin lahat ng Presidents
$presidents = $conn->query("SELECT employee_id, fullname FROM users WHERE role = 'President'");

// 2. Kunin lahat ng Group Heads AT Department Heads
// Ginamit ang IN para isama ang dalawang roles
$heads_res = $conn->query("SELECT employee_id, fullname, department, group_department, role FROM users WHERE role IN ('GroupHead', 'DeptHead') ORDER BY role DESC, fullname ASC");
$all_heads = [];
while($head = $heads_res->fetch_assoc()) {
    $all_heads[] = $head;
}

// 3. Kunin ang current mapping
$mappings = [];
$map_res = $conn->query("SELECT * FROM president_assignments");
while($row = $map_res->fetch_assoc()) {
    $mappings[$row['president_id']][] = $row['group_head_id'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>President Assignments - JDTech</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #f4f7fe; padding: 40px; margin: 0; }
        .card { background: white; padding: 30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); max-width: 900px; margin: auto; }
        h2 { color: #1b254b; margin-bottom: 5px; font-weight: 700; }
        .subtitle { color: #a3aed0; margin-bottom: 30px; font-size: 15px; }
        .pres-section { background: #ffffff; border: 1px solid #eef2f7; border-radius: 15px; padding: 25px; margin-bottom: 25px; transition: 0.3s; }
        .pres-section:hover { border-color: #4318ff; box-shadow: 0 5px 15px rgba(67, 24, 255, 0.05); }
        .pres-name { font-weight: 700; color: #4318ff; font-size: 18px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .gh-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; margin-top: 15px; }
        .gh-item { 
            background: #f8faff; padding: 12px 15px; border-radius: 12px; font-size: 14px; 
            display: flex; align-items: flex-start; gap: 10px; cursor: pointer; border: 1px solid transparent; transition: 0.2s;
        }
        .gh-item:hover { background: #f1f4ff; border-color: #d1d9ff; }
        .gh-item input { margin-top: 4px; }
        .btn-save { 
            background: #4318ff; color: white; border: none; padding: 12px 25px; 
            border-radius: 12px; cursor: pointer; margin-top: 20px; font-weight: 700; 
            box-shadow: 0 4px 14px rgba(67, 24, 255, 0.3); transition: 0.3s;
        }
        .btn-save:hover { background: #3311cc; transform: translateY(-2px); }
        .back-link { text-decoration: none; color: #707eae; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        .back-link:hover { color: #4318ff; }
        .badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; margin-left: 5px; }
        .badge-gh { background: #e0e7ff; color: #4318ff; }
        .badge-dh { background: #fef3c7; color: #d97706; }
    </style>
</head>
<body>

<div class="card">
    <a href="dashboard.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
    
    <h2><i class="fa-solid fa-user-gear"></i> President -> Heads Mapping</h2>
    <p class="subtitle">Piliin kung sinong Group Heads at Dept Heads ang dapat i-approve ng bawat President.</p>

    <?php if($presidents->num_rows > 0): ?>
        <?php while($pres = $presidents->fetch_assoc()): ?>
            <form method="POST" class="pres-section">
                <input type="hidden" name="president_id" value="<?php echo $pres['employee_id']; ?>">
                <div class="pres-name">
                    <i class="fa-solid fa-user-tie"></i> 
                    President: <?php echo htmlspecialchars($pres['fullname']); ?>
                </div>
                
                <div class="gh-list">
                    <?php if(!empty($all_heads)): ?>
                        <?php foreach($all_heads as $head): 
                            $is_checked = (isset($mappings[$pres['employee_id']]) && in_array($head['employee_id'], $mappings[$pres['employee_id']])) ? 'checked' : '';
                            $badge_class = ($head['role'] == 'GroupHead') ? 'badge-gh' : 'badge-dh';
                            $role_label = ($head['role'] == 'GroupHead') ? 'GH' : 'DH';
                            
                            // Display Dept based on role
                            $dept_info = ($head['role'] == 'GroupHead') ? $head['group_department'] : $head['department'];
                        ?>
                            <label class="gh-item">
                                <input type="checkbox" name="assigned_heads[]" value="<?php echo $head['employee_id']; ?>" <?php echo $is_checked; ?>>
                                <div>
                                    <div style="font-weight: 600; color: #1b254b;">
                                        <?php echo htmlspecialchars($head['fullname']); ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $role_label; ?></span>
                                    </div>
                                    <div style="color: #a3aed0; font-size: 12px;"><?php echo htmlspecialchars($dept_info); ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #e74c3c; font-size: 14px;">No Group Heads or Dept Heads found in the database.</p>
                    <?php endif; ?>
                </div>

                <button type="submit" name="save_assignments" class="btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> Update Assignments
                </button>
            </form>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #a3aed0;">
            <i class="fa-solid fa-user-slash" style="font-size: 40px; margin-bottom: 10px;"></i>
            <p>No users with 'President' role found.</p>
        </div>
    <?php endif; ?>
</div>

</body>
</html>