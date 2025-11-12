<?php
/**
 * CAASP Garage Owner Dashboard (Home View)
 */
session_start();
require_once '../api_db_config.php';

// --- SECURITY & REDIRECTION CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Garage' || !isset($_SESSION['garage_id'])) {
    header("Location: index.html");
    exit();
}

$current_garage_id = $_SESSION['garage_id'];
$current_view = 'dashboard'; // Set for active sidebar link
$garage_name = "Your Garage Dashboard"; // Default

$conn = connect_db();

if (!$conn) {
    // Graceful failure for DB connection in the UI
    $garage_name = "Database Offline";
    $kpis = [];
    $recent_activity = [];
    $db_error_message = '<div class="alert alert-danger">Error: Could not connect to database. KPI data is unavailable.</div>';
} else {
    $db_error_message = '';

    // 1. Fetch Garage Name (Required for the sidebar template)
    $sql_name = "SELECT garage_name FROM Garages WHERE garage_id = ?";
    if ($stmt = $conn->prepare($sql_name)) {
        $stmt->bind_param("i", $current_garage_id);
        $stmt->execute();
        $stmt->bind_result($name);
        if ($stmt->fetch()) {
            $garage_name = htmlspecialchars($name);
        }
        $stmt->close();
    }

    // --- REAL DATA FETCHING FUNCTIONS (Keep these in your file) ---

    function fetch_single_value($db, $sql, $param_type = null, $param_value = null) {
        $value = 0;
        if ($stmt = $db->prepare($sql)) {
            if ($param_type && $param_value !== null) {
                $stmt->bind_param($param_type, $param_value);
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

    function fetch_recent_transactions($db, $garage_id) {
        $activity = [];
        $sql = "
            SELECT 
                T.transaction_id, T.created_at, T.status, T.service_id, T.part_id, S.service_name,
                CASE WHEN T.service_id IS NOT NULL THEN 'Service' WHEN T.part_id IS NOT NULL THEN 'Part' ELSE 'Other' END as type
            FROM Transactions T
            LEFT JOIN Services S ON T.service_id = S.service_id
            WHERE T.target_garage_id = ? 
            ORDER BY T.created_at DESC LIMIT 5
        ";

        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param("i", $garage_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    if ($row['type'] === 'Service' && !empty($row['service_name'])) {
                        $description = "Service Request: " . htmlspecialchars($row['service_name']);
                    } elseif ($row['type'] === 'Part') {
                        $description = "Part Order (ID: " . $row['part_id'] . ")";
                    } else {
                        $description = "General Transaction (ID: " . $row['transaction_id'] . ")";
                    }
                    $activity[] = [
                        'date' => date('Y-m-d', strtotime($row['created_at'])),
                        'description' => $description,
                        'status' => htmlspecialchars($row['status']),
                        'type' => strtolower($row['type']),
                    ];
                }
            }
            $stmt->close();
        }
        return $activity;
    }

    // --- KPI Fetching Logic ---
    $sql_services = "SELECT COUNT(*) FROM Services WHERE garage_id = ?";
    $total_services = fetch_single_value($conn, $sql_services, "i", $current_garage_id);
    $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
    $sql_new_orders = "SELECT COUNT(*) FROM Transactions WHERE target_garage_id = ? AND created_at >= ?";
    $stmt = $conn->prepare($sql_new_orders);
    $new_orders = 0;
    if ($stmt) {
        $stmt->bind_param("is", $current_garage_id, $seven_days_ago);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_row()) {
                $new_orders = $row[0];
            }
        }
        $stmt->close();
    }
    $sql_avg_rating = "SELECT AVG(rating_value) FROM Ratings WHERE garage_id = ?";
    $avg_rating = fetch_single_value($conn, $sql_avg_rating, "i", $current_garage_id);
    $avg_rating_display = $avg_rating ? number_format($avg_rating, 1) . ' / 5.0' : 'N/A';
    $sql_pending = "SELECT COUNT(*) FROM Transactions WHERE target_garage_id = ? AND status = 'Pending'";
    $pending_transactions = fetch_single_value($conn, $sql_pending, "i", $current_garage_id);
    $recent_activity = fetch_recent_transactions($conn, $current_garage_id);

    // Consolidate KPIs
    $kpis = [
        ['title' => 'Total Services Listed', 'value' => $total_services, 'icon' => 'wrench', 'color' => 'blue', 'link' => 'services.php'],
        ['title' => 'New Orders (Last 7 Days)', 'value' => $new_orders, 'icon' => 'package-plus', 'color' => 'orange', 'link' => 'transactions.php'],
        ['title' => 'Average Rating', 'value' => $avg_rating_display, 'icon' => 'star', 'color' => 'yellow', 'link' => '#'],
        ['title' => 'Pending Transactions', 'value' => $pending_transactions, 'icon' => 'timer', 'color' => 'red', 'link' => 'transactions.php?filter=pending'],
    ];

    $conn->close();
}
// --- End of PHP Logic ---

// --- TEMPLATE INCLUDE START ---
require 'garage_template.php';
// --- TEMPLATE INCLUDE END ---
?>

    <div class="p-6 bg-white rounded-xl shadow-md">

        <?php echo $db_error_message; // Display database errors here ?>

        <header class="mb-8 flex justify-between items-center">
            <h2 class="text-3xl font-bold text-gray-900">
                👋 Welcome, <?= $garage_name ?>!
            </h2>
            <a href="garage_profile.php" class="text-gray-500 hover:text-orange-600">
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
                        No recent activity found. Start listing services to attract customers!
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activity as $activity):
                        $bg_class = $activity['type'] === 'service' ? 'bg-orange-50 border-orange-200' : 'bg-blue-50 border-blue-200';
                        $text_color = $activity['type'] === 'service' ? 'text-orange-700' : 'text-blue-700';

                        $status_class = 'bg-gray-100 text-gray-700';
                        if ($activity['status'] === 'Completed') $status_class = 'bg-green-100 text-green-700';
                        elseif ($activity['status'] === 'Pending') $status_class = 'bg-red-100 text-red-700';
                        elseif ($activity['status'] === 'In Progress') $status_class = 'bg-yellow-100 text-yellow-700';

                        ?>
                        <div class="flex items-center justify-between p-4 rounded-xl shadow-sm border <?= $bg_class ?>">
                            <div class="flex items-center space-x-4">
                                <i data-lucide="<?= $activity['type'] === 'service' ? 'calendar-check' : 'package' ?>" class="w-6 h-6 <?= $text_color ?>"></i>
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
require 'garage_footer.php';
// --- FOOTER INCLUDE END ---
?>