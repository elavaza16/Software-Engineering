<?php
/**
 * CAASP Admin Dashboard
 * * Manages business approvals, user accounts, and content moderation.
 */
session_start();
// Includes database configuration
require_once '../api_db_config.php'; // Corrected path to api_db_config.php

// --- AUTHENTICATION CHECK ---
$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

// Ensure user is logged in AND is an Admin
if (!$user_id || $role !== 'Admin') {
    session_unset();
    session_destroy();
    // Use an absolute path for redirection if needed
    header("Location: ../index.html?status=error&message=" . urlencode("Access denied. Admin login required.")); // Corrected path to index.html
    exit;
}
// --- END AUTHENTICATION CHECK ---


// Determine the current view
$current_view = $_GET['view'] ?? 'pending'; // Default to 'pending' approvals
$status_message = $_GET['message'] ?? '';
$status_type = $_GET['status'] ?? '';


// --- DATA FETCHING FUNCTIONS ---
// (Functions remain unchanged, using procedural mysqli style)

/**
 * Fetches businesses awaiting admin approval (is_approved = 0).
 */
function fetch_pending_businesses($db) {
    $pending_list = [];
    $sql = "
        SELECT 
            U.user_id, U.email, U.contact, U.created_at, R.role_name AS role, 
            COALESCE(G.garage_name, V.vendor_name) AS business_name 
        FROM Users U
        JOIN UserRole UR ON U.user_id = UR.user_id
        JOIN Roles R ON UR.role_id = R.role_id
        LEFT JOIN Garages G ON U.user_id = G.user_id
        LEFT JOIN Vendors V ON U.user_id = V.user_id
        WHERE R.role_name IN ('Garage', 'Vendor') AND U.is_approved = 0 
        ORDER BY U.created_at ASC
    ";

    if ($stmt = mysqli_prepare($db, $sql)) {
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $pending_list[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $pending_list;
}

// *** NEW FUNCTION: Fetches all users for management ***
/**
 * Fetches all users (including Admin, Customer, Garage, Vendor).
 */
function fetch_all_users($db) {
    $user_list = [];
    $sql = "
        SELECT 
            U.user_id, U.email, U.contact, U.created_at, U.is_approved, 
            R.role_name AS role, 
            COALESCE(G.garage_name, V.vendor_name) AS business_name
        FROM Users U
        JOIN UserRole UR ON U.user_id = UR.user_id
        JOIN Roles R ON UR.role_id = R.role_id
        LEFT JOIN Garages G ON U.user_id = G.user_id
        LEFT JOIN Vendors V ON U.user_id = V.user_id
        ORDER BY U.user_id ASC
    ";

    if ($stmt = mysqli_prepare($db, $sql)) {
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $user_list[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $user_list;
}
// ******************************************************

/**
 * Fetches all active/approved listings for moderation/review.
 */
function fetch_all_listings($db) {
    // This fetches the last 10 services and parts across all businesses
    $listings = [];

    // Services Query
    $sql_services = "
        SELECT 
            S.service_id AS item_id, 
            S.service_name AS item_name, 
            S.service_price AS price, 
            'Service' AS type, 
            G.garage_name AS business_name
        FROM Services S 
        JOIN Garages G ON S.garage_id = G.garage_id
        ORDER BY S.service_id DESC LIMIT 10
    ";

    // Parts Query
    $sql_parts = "
        SELECT 
            P.part_id AS item_id, 
            P.part_name AS item_name, 
            P.part_price AS price, 
            'Part' AS type, 
            V.vendor_name AS business_name
        FROM Parts P 
        JOIN Vendors V ON P.vendor_id = V.vendor_id
        ORDER BY P.part_id DESC LIMIT 10
    ";

    // Combine results
    if ($result = mysqli_query($db, $sql_services)) {
        while ($row = mysqli_fetch_assoc($result)) $listings[] = $row;
    }
    if ($result = mysqli_query($db, $sql_parts)) {
        while ($row = mysqli_fetch_assoc($result)) $listings[] = $row;
    }
    return $listings;
}


$db = connect_db();
$pending_businesses = [];
$all_listings = [];
// *** NEW VARIABLE ***
$all_users = [];
// ********************

// Data fetching proceeds
if ($db) {
    if ($current_view === 'pending') {
        $pending_businesses = fetch_pending_businesses($db);
    } elseif ($current_view === 'listings') {
        $all_listings = fetch_all_listings($db);
        // *** NEW LOGIC BLOCK ***
    } elseif ($current_view === 'users') {
        $all_users = fetch_all_users($db);
        // ***********************
    }
} else {
    // Database connection failed
    $status_message = 'Database connection failed. Data is unavailable.';
    $status_type = 'error';
}


// --- TEMPLATE INCLUDE START ---
require 'admin_template.php';
// --- TEMPLATE INCLUDE END ---
?>
    <div class="p-6 bg-white rounded-xl shadow-md">
        <header class="mb-8 flex justify-between items-center border-b pb-4 border-gray-100">
            <h2 class="text-3xl font-bold text-gray-900">
                👑 <?= ucwords(str_replace('_', ' ', $current_view)) ?> Management
            </h2>
            <a href="admin_dashboard.php" class="text-gray-500 hover:text-indigo-600">
                <i data-lucide="circle-user" class="w-8 h-8"></i>
            </a>
        </header>

        <?php if (!empty($status_message)): ?>
            <div class="p-4 rounded-lg mb-6 <?= $status_type === 'success' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-red-100 text-red-800 border-red-300' ?> border" role="alert">
                <p class="font-semibold"><?= htmlspecialchars($status_message) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($current_view === 'pending'): ?>
            <section class="mb-10">
                <h3 class="text-2xl font-bold mb-5 text-gray-800 border-b pb-2">Pending Business Registration Requests (Awaiting Approval)</h3>

                <div class="overflow-x-auto shadow-md rounded-lg border border-gray-200">
                    <table class="min-w-full bg-white border-collapse">
                        <thead class="bg-gray-100">
                        <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                            <th class="p-4 border-b">User ID</th>
                            <th class="p-4 border-b">Business Name</th>
                            <th class="p-4 border-b">Role</th>
                            <th class="p-4 border-b">Contact Email</th>
                            <th class="p-4 border-b">Registered</th>
                            <th class="p-4 border-b">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                        <?php if (!empty($pending_businesses)): ?>
                            <?php foreach ($pending_businesses as $business): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($business['user_id']) ?></td>
                                    <td class="p-4 text-sm font-medium"><?= htmlspecialchars($business['business_name'] ?? 'N/A') ?></td>
                                    <td class="p-4 text-sm text-indigo-600 font-bold"><?= htmlspecialchars($business['role']) ?></td>
                                    <td class="p-4 text-sm"><?= htmlspecialchars($business['email']) ?></td>
                                    <td class="p-4 text-sm"><?= date('Y-m-d', strtotime($business['created_at'])) ?></td>
                                    <td class="p-4 space-x-2">
                                        <form action="admin_handler.php" method="POST" class="inline-block">
                                            <input type="hidden" name="action" value="approve_business">
                                            <input type="hidden" name="user_id" value="<?= $business['user_id'] ?>">
                                            <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded-lg text-sm hover:bg-green-700 transition duration-150">✅ Approve</button>
                                        </form>
                                        <form action="admin_handler.php" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to reject this account? This will permanently suspend access.');">
                                            <input type="hidden" name="action" value="reject_business">
                                            <input type="hidden" name="user_id" value="<?= $business['user_id'] ?>">
                                            <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded-lg text-sm hover:bg-red-700 transition duration-150">❌ Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="p-4 text-center text-gray-500">
                                    <i data-lucide="check-circle" class="w-6 h-6 text-green-500 inline-block mr-2"></i>
                                    No pending business accounts requiring approval.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($current_view === 'listings'): ?>
            <section class="mb-10">
                <h3 class="text-2xl font-bold mb-5 text-gray-800 border-b pb-2">All Active Listings (Services & Parts)</h3>
                <p class="text-gray-600 mb-4">Review and moderate the latest 20 listings from Garages and Vendors.</p>

                <div class="overflow-x-auto shadow-md rounded-lg border border-gray-200">
                    <table class="min-w-full bg-white border-collapse">
                        <thead class="bg-gray-100">
                        <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                            <th class="p-4 border-b">ID</th>
                            <th class="p-4 border-b">Item Name</th>
                            <th class="p-4 border-b">Type</th>
                            <th class="p-4 border-b">Price</th>
                            <th class="p-4 border-b">Business</th>
                            <th class="p-4 border-b">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                        <?php if (!empty($all_listings)): ?>
                            <?php foreach ($all_listings as $listing): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($listing['item_id']) ?></td>
                                    <td class="p-4 text-sm font-medium"><?= htmlspecialchars($listing['item_name']) ?></td>
                                    <td class="p-4 text-sm font-bold <?= $listing['type'] === 'Service' ? 'text-orange-600' : 'text-blue-600' ?>">
                                        <?= htmlspecialchars($listing['type']) ?>
                                    </td>
                                    <td class="p-4 text-sm">KES <?= number_format($listing['price'], 2) ?></td>
                                    <td class="p-4 text-sm"><?= htmlspecialchars($listing['business_name']) ?></td>
                                    <td class="p-4">
                                        <form action="admin_handler.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this listing?');">
                                            <input type="hidden" name="action" value="delete_listing">
                                            <input type="hidden" name="item_type" value="<?= $listing['type'] ?>">
                                            <input type="hidden" name="item_id" value="<?= $listing['item_id'] ?>">
                                            <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded-lg text-xs hover:bg-red-700 transition duration-150">🗑️ Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="p-4 text-center text-gray-500">
                                    <i data-lucide="package" class="w-6 h-6 text-indigo-500 inline-block mr-2"></i>
                                    No active listings found in the system.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($current_view === 'users'): ?>
            <section class="mb-10">
                <h3 class="text-2xl font-bold mb-5 text-gray-800 border-b pb-2">All System Users (Management)</h3>

                <div class="overflow-x-auto shadow-md rounded-lg border border-gray-200">
                    <table class="min-w-full bg-white border-collapse">
                        <thead class="bg-gray-100">
                        <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                            <th class="p-4 border-b">User ID</th>
                            <th class="p-4 border-b">Email</th>
                            <th class="p-4 border-b">Account Name</th>
                            <th class="p-4 border-b">Role</th>
                            <th class="p-4 border-b">Status</th>
                            <th class="p-4 border-b">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                        <?php if (!empty($all_users)): ?>
                            <?php foreach ($all_users as $user): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($user['user_id']) ?></td>
                                    <td class="p-4 text-sm font-medium"><?= htmlspecialchars($user['email']) ?></td>
                                    <td class="p-4 text-sm">
                                        <?= htmlspecialchars($user['business_name'] ?? 'N/A') ?>
                                    </td>
                                    <td class="p-4 text-sm">
                                        <form action="admin_handler.php" method="POST" class="inline-block" onchange="this.submit()">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                            <select name="new_role" class="p-1 border border-gray-300 rounded-lg text-xs bg-white focus:ring-indigo-500 focus:border-indigo-500" <?= $user['role'] === 'Admin' ? 'disabled' : '' ?>>
                                                <?php foreach (['Customer', 'Garage', 'Vendor', 'Admin'] as $role_option): ?>
                                                    <option value="<?= $role_option ?>" <?= $user['role'] === $role_option ? 'selected' : '' ?>>
                                                        <?= $role_option ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="p-4 text-sm font-bold">
                                        <?php
                                        // 0=Pending, 1=Active/Approved, 2=Rejected/Suspended
                                        if ($user['is_approved'] == 1) {
                                            echo '<span class="text-green-600">ACTIVE</span>';
                                        } elseif ($user['is_approved'] == 2) {
                                            echo '<span class="text-red-600">SUSPENDED</span>';
                                        } else {
                                            echo '<span class="text-yellow-600">PENDING</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="p-4 space-x-2">
                                        <?php
                                        if ($user['role'] !== 'Admin' && $user['user_id'] != $user_id): // Cannot suspend self or another Admin
                                            if ($user['is_approved'] == 1): // Is Active, show Suspend button
                                                ?>
                                                <form action="admin_handler.php" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to SUSPEND this user (ID: <?= $user['user_id'] ?>)?');">
                                                    <input type="hidden" name="action" value="suspend_user">
                                                    <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                                    <button type="submit" class="bg-yellow-600 text-white px-3 py-1 rounded-lg text-xs hover:bg-yellow-700 transition duration-150">🚫 Suspend</button>
                                                </form>
                                            <?php
                                            elseif ($user['is_approved'] == 2): // Is Suspended, show Activate button
                                                ?>
                                                <form action="admin_handler.php" method="POST" class="inline-block">
                                                    <input type="hidden" name="action" value="activate_user">
                                                    <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                                    <button type="submit" class="bg-indigo-600 text-white px-3 py-1 rounded-lg text-xs hover:bg-indigo-700 transition duration-150">✅ Activate</button>
                                                </form>
                                            <?php
                                            endif;
                                        endif;
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="p-4 text-center text-gray-500">
                                    <i data-lucide="users" class="w-6 h-6 text-indigo-500 inline-block mr-2"></i>
                                    No users found in the system.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php endif; ?>

    </div>

<?php
// Close DB connection if it was opened and is still active
if ($db && mysqli_ping($db)) {
    mysqli_close($db);
}
// --- FOOTER INCLUDE START ---
require 'admin_footer.php';
// --- FOOTER INCLUDE END ---
?>