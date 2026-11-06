<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_image'])) {
    $emp_id = $_POST['employee_id'];
    $target_dir = "C:\xampp\htdocs\jdtech_time_keeping\uploads";
    
    // Kunin ang extension ng file (jpg, png, etc)
    $imageFileType = strtolower(pathinfo($_FILES["profile_image"]["name"], PATHINFO_EXTENSION));
    
    // Pangalanan ang file base sa employee_id para hindi magulo (e.g., 60231.jpg)
    $new_filename = $emp_id . "." . $imageFileType;
    $target_file = $target_dir . $new_filename;

    // I-upload ang file
    if (move_uploaded_file($_FILES["profile_image"]["tmp_name"], $target_file)) {
        // Update database
        $stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE employee_id = ?");
        $stmt->bind_param("ss", $new_filename, $emp_id);
        $stmt->execute();
    }
    
    header("Location: hr_management.php?success=uploaded");
    exit();
}