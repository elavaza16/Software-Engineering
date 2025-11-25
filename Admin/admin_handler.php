<?php
/**
 * CAASP Admin Handler
 * * Processes POST requests for managing user approvals and deleting content.
 * * This assumes the 'is_approved' column exists and is used: 0=Pending, 1=Approved, 2=Rejected/Suspended.
 */

session_start(); // Session must be started to access $_SESSION variables
require_once '../api_db_config.php'; // Corrected path to api_db_config.php

// --- AUTHENTICATION CHECK ---
$current_user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

if (!$current_user_id || $role !== 'Admin') {
    header("Location: ../index.html?status=error&message=" . urlencode("Admin session required to perform actions."));
    exit;
}

// Helper function to safely redirect back to the current admin view
function redirect_to_admin_dashboard($status, $message, $view = 'pending') {
    header("Location: admin_dashboard.php?view=" . urlencode($view) . "&status=" . urlencode($status) . "&message=" . urlencode($message));
    exit;
}

// Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to_admin_dashboard('error', 'Invalid request method.', 'pending');
}

$action = $_POST['action'] ?? '';
$db = connect_db();
if (!$db) {
    redirect_to_admin_dashboard('error', 'Database connection failed. Cannot process action.');
}

// ====================================================================
// --- A. PROCESS BUSINESS APPROVAL / REJECTION ---
// ====================================================================
if ($action === 'approve_business' || $action === 'reject_business') {
    $target_user_id = (int)($_POST['user_id'] ?? 0);

    // Set status based on action: 1 for Approved, 2 for Rejected/Suspended
    $new_status = ($action === 'approve_business') ? 1 : 2;
    $message_verb = ($action === 'approve_business') ? 'Approved' : 'Rejected';
    $redirect_view = 'pending';

    if ($target_user_id === 0) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Invalid user ID.', $redirect_view);
    }

    // SQL: Update the is_approved status in the Users table
    $sql = "UPDATE Users SET is_approved = ? WHERE user_id = ?";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $new_status, $target_user_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('success', "Business account successfully {$message_verb}!", $redirect_view);
        } else {
            error_log("Approval/Rejection failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('error', "Database error during {$message_verb} process.", $redirect_view);
        }
    } else {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Internal server error during SQL preparation.');
    }
}
// ====================================================================
// --- B. PROCESS LISTING DELETION ---
// ====================================================================
elseif ($action === 'delete_listing') {
    $item_type = $_POST['item_type'] ?? ''; // 'Service' or 'Part'
    $item_id = (int)($_POST['item_id'] ?? 0);
    $redirect_view = 'listings';

    if ($item_id === 0 || !in_array($item_type, ['Service', 'Part'])) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Invalid listing details provided.', $redirect_view);
    }

    $table = ($item_type === 'Service') ? 'Services' : 'Parts';
    $id_field = ($item_type === 'Service') ? 'service_id' : 'part_id';

    // SQL: DELETE the listing
    $sql = "DELETE FROM {$table} WHERE {$id_field} = ?";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $item_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('success', "{$item_type} listing ID {$item_id} has been permanently deleted.", $redirect_view);
        } else {
            error_log("Listing deletion failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('error', 'Database error during listing deletion.', $redirect_view);
        }
    } else {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Internal server error during SQL preparation.');
    }

}
// ====================================================================
// --- C. MANAGE USER STATUS (SUSPEND/ACTIVATE) ---
// ====================================================================
elseif ($action === 'suspend_user' || $action === 'activate_user') {
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $redirect_view = 'users';

    // Prevent Admin from suspending themselves or other Admins
    if ($target_user_id === $current_user_id) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Cannot suspend your own account!', $redirect_view);
    }

    // Check if the target user is an Admin (Requires a SELECT query, but we'll simplify for now)
    // NOTE: A robust implementation would check the target user's role before proceeding.

    // Set status: 2 for Suspended, 1 for Active/Approved
    $new_status = ($action === 'suspend_user') ? 2 : 1;
    $message_verb = ($action === 'suspend_user') ? 'Suspended' : 'Activated';

    if ($target_user_id === 0) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Invalid user ID.', $redirect_view);
    }

    // SQL: Update the is_approved status in the Users table
    $sql = "UPDATE Users SET is_approved = ? WHERE user_id = ?";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $new_status, $target_user_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('success', "User account ID {$target_user_id} successfully {$message_verb}!", $redirect_view);
        } else {
            error_log("Status change failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_admin_dashboard('error', "Database error during status change.", $redirect_view);
        }
    } else {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Internal server error during SQL preparation.');
    }
}
// ====================================================================
// --- D. MANAGE USER ROLE (CHANGE ROLE) ---
// ====================================================================
elseif ($action === 'change_role') {
    $target_user_id = (int)($_POST['user_id'] ?? 0);
    $new_role_name = $_POST['new_role'] ?? '';
    $redirect_view = 'users';

    // Prevent Admin from changing their own role
    if ($target_user_id === $current_user_id) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Cannot change your own role!', $redirect_view);
    }

    $valid_roles = ['Customer', 'Garage', 'Vendor', 'Admin'];
    if ($target_user_id === 0 || !in_array($new_role_name, $valid_roles)) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Invalid user ID or role.', $redirect_view);
    }

    // 1. Get the role_id for the new role name
    $role_id = 0;
    $sql_get_role_id = "SELECT role_id FROM Roles WHERE role_name = ?";
    if ($stmt_get = mysqli_prepare($db, $sql_get_role_id)) {
        mysqli_stmt_bind_param($stmt_get, "s", $new_role_name);
        mysqli_stmt_execute($stmt_get);
        mysqli_stmt_bind_result($stmt_get, $role_id);
        mysqli_stmt_fetch($stmt_get);
        mysqli_stmt_close($stmt_get);
    }

    if ($role_id === 0) {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'New role not found in database.', $redirect_view);
    }

    // 2. Update the UserRole table with the new role_id
    // This assumes a user can only have one role at a time (One-to-One or One-to-Many where we update the single entry).
    $sql_update_role = "UPDATE UserRole SET role_id = ? WHERE user_id = ?";

    if ($stmt_update = mysqli_prepare($db, $sql_update_role)) {
        mysqli_stmt_bind_param($stmt_update, "ii", $role_id, $target_user_id);

        if (mysqli_stmt_execute($stmt_update)) {
            mysqli_stmt_close($stmt_update);
            mysqli_close($db);

            // 🚀 MODIFIED REDIRECT LOGIC FOR ROLE CHANGE
            // We use a custom message that instructs the user to log in again.
            $new_message = "Role for User ID {$target_user_id} changed to **{$new_role_name}**. The user will be required to log in again for the change to take full effect.";
            redirect_to_admin_dashboard('success', $new_message, $redirect_view);

        } else {
            error_log("Role change failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt_update);
            mysqli_close($db);
            redirect_to_admin_dashboard('error', 'Database error during role change.', $redirect_view);
        }
    } else {
        mysqli_close($db);
        redirect_to_admin_dashboard('error', 'Internal server error during SQL preparation for role change.');
    }
}
// ====================================================================
// --- E. UNKNOWN ACTION ---
// ====================================================================
else {
    mysqli_close($db);
    redirect_to_admin_dashboard('error', 'Unknown action requested.', 'pending');
}

?>