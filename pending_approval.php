<?php
/**
 * CAASP Pending Approval Page
 * Displays a message to Garage/Vendor users waiting for Admin approval.
 */
session_start();
require_once 'api_db_config.php';

// --- SECURITY CHECK ---
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

// Only allow Garage and Vendor roles to view this page
if (!$user_id || !in_array($role, ['Garage', 'Vendor'])) {
    header("Location: index.html?status=error&message=" . urlencode("Access denied."));
    exit;
}

// 1. Fetch approval status
$is_approved = 0; // Default to pending if not found
$db = connect_db();
if ($db) {
    $sql = "SELECT is_approved FROM Users WHERE user_id = ?";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $is_approved);
            mysqli_stmt_fetch($stmt);
        }
        mysqli_stmt_close($stmt);
    }
    mysqli_close($db);
}

// 2. REDIRECT CHECK: If the user IS approved (1) or suspended (2), redirect them away.
if ($is_approved == 1) {
    // If approved, send to the correct dashboard
    $dashboard_files = [
        'Garage' => 'Garage_Owner/garage_dashboard.php',
        'Vendor' => 'Vendor/vendor_dashboard.php'
    ];
    $dashboard_file = $dashboard_files[$role] ?? 'index.html';
    header("Location: {$dashboard_file}");
    exit;
} elseif ($is_approved == 2) {
    // If suspended/rejected, send to login with error
    header("Location: index.html?status=error&message=" . urlencode("Your account has been suspended or rejected."));
    exit;
}

// User remains on this page only if is_approved == 0 (Pending)

$business_type = ($role === 'Garage') ? 'Garage Owner' : 'Spare Part Vendor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Pending</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

<div class="bg-white p-10 rounded-xl w-full max-w-lg shadow-2xl text-center">
    <div class="text-yellow-600 mb-6">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16 mx-auto" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <h2 class="text-3xl font-bold text-gray-800 mb-4">Approval Pending</h2>
    <p class="text-lg text-gray-600 mb-6">
        Thank you for registering as a **<?= $business_type ?>**!
    </p>
    <p class="text-gray-700 mb-8 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
        Your business account is currently under review by the system administrators. You will be notified via email once your account has been approved and activated.
    </p>
    <a href="index.html" class="inline-block px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition duration-200">
        Return to Login Page
    </a>
</div>

</body>
</html>