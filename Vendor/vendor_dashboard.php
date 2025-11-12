<?php
/**
 * CAASP Spare Part Vendor Dashboard (Home View)
 */
session_start();
require_once '../api_db_config.php';

// --- SECURITY & REDIRECTION CHECK ---
// Assuming the session role for a spare part vendor is 'Vendor'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Vendor' || !isset($_SESSION['vendor_id'])) {
    header("Location: index.html");
    exit();
}

// Renamed variables for clarity:
$current_vendor_id = $_SESSION['vendor_id'];
$current_view = 'dashboard'; // Set for active sidebar link
$vendor_name = "Your Vendor Dashboard"; // Default

$conn = connect_db();

if (!$conn) {
    // Graceful failure for DB connection in the UI
    $vendor_name = "Database Offline";
    $kpis = [];
    $recent_activity = [];
    $db_error_message = '<div class="alert alert-danger">Error: Could not connect to database. KPI data is unavailable.</div>';
} else {
    $db_error_message = '';

    // 1. Fetch Vendor Name (Required for the sidebar template)
    $sql_name = "SELECT vendor_name FROM Vendors WHERE vendor_id = ?";
    if ($stmt = $conn->prepare($sql_name)) {
        $stmt->bind_param("i", $current_vendor_id);
        $stmt->execute();
        $stmt->bind_result($name);
        if ($stmt->fetch()) {
            $vendor_name = htmlspecialchars($name);
        }
        $stmt->close();
    }

    // --- REAL DATA FETCHING FUNCTIONS ---

    function fetch_single_value($db, $sql, $param_type = null, $param_value = null) {
        $value = 0;
        if ($stmt = $db->prepare($sql)) {
            if ($param_type && $param_value !== null) {
                // Handle binding a single value (can be an array for multiple parameters if needed)
                if (is_array($param_value)) {
                    $stmt->bind_param($param_type, ...$param_value);
                } else {
                    $stmt->bind_param($param_type, $param_value);
                }
            }
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_row()) {
                    $value = $row[0];
                }
            }
            $stmt->close();
        }
        return $value;
    }

    function fetch_recent_transactions($db, $vendor_id) {
        $activity = [];
        $sql = "
            SELECT 
                T.transaction_id, T.created_at, T.status, T.part_id, P.part_name,
                'Part' as type
            FROM Transactions T
            LEFT JOIN Parts P ON T.part_id = P.part_id
            -- CORRECTED: Use target_vendor_id to link the transaction to the vendor who supplied the part
            WHERE T.target_vendor_id = ? AND T.part_id IS NOT NULL
            ORDER BY T.created_at DESC LIMIT 5
        ";

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param("i", $vendor_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $part_display_name = !empty($row['part_name']) ? htmlspecialchars($row['part_name']) : "Part ID: " . $row['part_id'];
                    $description = "Part Order: " . $part_display_name;

                    $activity[] = [
                        'date' => date('Y-m-d', strtotime($row['created_at'])),
                        'description' => $description,
                        'status' => htmlspecialchars($row['status']),
                        'type' => 'part',
                    ];
                }
            }
            $stmt->close();
        }
        return $activity;
    }

    // --- KPI Fetching Logic (Updated for Vendors) ---
    // Total Parts Listed
    $sql_parts = "SELECT COUNT(*) FROM Parts WHERE vendor_id = ?";
    $total_parts = fetch_single_value($conn, $sql_parts, "i", $current_vendor_id);

    // New Orders (Last 7 Days)
    $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
    // CORRECTED: Assuming transactions involving parts link back to the vendor via 'target_vendor_id'
    $sql_new_orders = "SELECT COUNT(*) FROM Transactions WHERE target_vendor_id = ? AND part_id IS NOT NULL AND created_at >= ?";
    $stmt = $conn->prepare($sql_new_orders);
    $new_orders = 0;
    if ($stmt) {
        $stmt->bind_param("is", $current_vendor_id, $seven_days_ago);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_row()) {
                $new_orders = $row[0];
            }
        }
        $stmt->close();
    }

    // Average Rating (Assuming Ratings table has a 'vendor_id' column for vendor ratings)
    $sql_avg_rating = "SELECT AVG(rating_value) FROM Ratings WHERE vendor_id = ?";
    $avg_rating = fetch_single_value($conn, $sql_avg_rating, "i", $current_vendor_id);
    $avg_rating_display = $avg_rating ? number_format($avg_rating, 1) . ' / 5.0' : 'N/A';

    // Pending Transactions (Pending shipments/orders for the vendor)
    // CORRECTED: Use target_vendor_id
    $sql_pending = "SELECT COUNT(*) FROM Transactions WHERE target_vendor_id = ? AND part_id IS NOT NULL AND status IN ('Pending', 'Processing')";
    $pending_transactions = fetch_single_value($conn, $sql_pending, "i", $current_vendor_id);

    $recent_activity = fetch_recent_transactions($conn, $current_vendor_id);

    // Consolidate KPIs (Updated icons and colors)
    $kpis = [
        ['title' => 'Total Parts Listed', 'value' => $total_parts, 'icon' => 'box', 'color' => 'blue', 'link' => 'parts.php'],
        ['title' => 'New Orders (Last 7 Days)', 'value' => $new_orders, 'icon' => 'package-plus', 'color' => 'orange', 'link' => 'transactions.php'],
        ['title' => 'Average Rating', 'value' => $avg_rating_display, 'icon' => 'star', 'color' => 'yellow', 'link' => '#'],
        ['title' => 'Pending Shipments', 'value' => $pending_transactions, 'icon' => 'truck', 'color' => 'red', 'link' => 'transactions.php?filter=pending'],
    ];

    $conn->close();
}
// --- End of PHP Logic ---

// --- TEMPLATE INCLUDE START ---
require 'vendor_template.php';
// --- TEMPLATE INCLUDE END ---
?>

    <div class="p-6 bg-white rounded-xl shadow-md">

        <?php echo $db_error_message; // Display database errors here ?>

        <header class="mb-8 flex justify-between items-center">
            <h2 class="text-3xl font-bold text-gray-900">
                👋 Welcome, <?= $vendor_name ?>!
            </h2>
            <a href="vendor_profile.php" class="text-gray-500 hover:text-orange-600">
                <i data-lucide="circle-user" class="w-8 h-8"></i>
            </a>
        </header>

        <section class="mb-10">
            <h3 class="text-xl font-bold mb-4 text-gray-800 border-b pb-2">Key Metrics Overview</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($kpis as $kpi): ?>
                    <div class="p-6 rounded-xl shadow-md border-t-4 border-<?= $kpi['color'] ?>-500 transition duration-200 hover:shadow-lg">
                        <div class="flex items-center justify-between">
                            <p class="text-lg font-semibold text-gray-500"><?= $kpi['title'] ?></p>
                            <i data-lucide="<?= $kpi['icon'] ?>" class="w-6 h-6 text-<?= $kpi['color'] ?>-500"></i>
                        </div>
                        <p class="text-4xl font-extrabold text-gray-900 mt-2"><?= $kpi['value'] ?></p>
                        <a href="<?= $kpi['link'] ?>" class="text-sm font-medium text-blue-500 hover:underline mt-2 inline-block">View Details</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section>
            <h3 class="text-xl font-bold mb-4 text-gray-800 border-b pb-2">Recent Activity</h3>
            <div class="space-y-4">
                <?php if (empty($recent_activity)): ?>
                    <div class="p-6 rounded-xl shadow-md text-center text-gray-500 border border-gray-100">
                        No recent activity found. Start listing parts to attract customers!
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activity as $activity):
                        // Set specific styling for Part transactions
                        $bg_class = 'bg-blue-50 border-blue-200';
                        $text_color = 'text-blue-700';

                        $status_class = 'bg-gray-100 text-gray-700';
                        if ($activity['status'] === 'Completed') $status_class = 'bg-green-100 text-green-700';
                        elseif (in_array($activity['status'], ['Pending', 'Processing'])) $status_class = 'bg-red-100 text-red-700';
                        elseif ($activity['status'] === 'Shipped') $status_class = 'bg-yellow-100 text-yellow-700';

                        ?>
                        <div class="flex items-center justify-between p-4 rounded-xl shadow-sm border <?= $bg_class ?>">
                            <div class="flex items-center space-x-4">
                                <i data-lucide="package" class="w-6 h-6 <?= $text_color ?>"></i>
                                <div>
                                    <p class="font-semibold text-gray-800"><?= $activity['description'] ?></p>
                                    <p class="text-sm text-gray-500">Date: <?= $activity['date'] ?></p>
                                </div>
                            </div>
                            <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $status_class ?>">
                                <?= $activity['status'] ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

<?php
// --- FOOTER INCLUDE START ---
require 'vendor_footer.php';
// --- FOOTER INCLUDE END ---
?>