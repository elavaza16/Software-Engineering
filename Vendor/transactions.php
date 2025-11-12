<?php
/**
 * CAASP Spare Part Vendor Orders & History (transactions.php)
 * Displays a detailed list of all outgoing part orders/shipments for the vendor.
 */
session_start();
require_once '../api_db_config.php';

// --- SECURITY & REDIRECTION CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Vendor' || !isset($_SESSION['vendor_id'])) {
    header("Location: index.html");
    exit();
}

$current_vendor_id = $_SESSION['vendor_id'];
$current_view = 'transactions'; // Set for active sidebar link
$vendor_name = "Your Vendor Dashboard"; // Fallback name

// Get filter status from URL, default to 'all'
$filter_status = $_GET['status'] ?? 'all';

$conn = connect_db();
$transactions = [];
$db_error_message = '';

if (!$conn) {
    $db_error_message = '<div class="alert alert-danger">Error: Could not connect to database. Transaction data is unavailable.</div>';
} else {
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

    // --- FETCH ALL TRANSACTIONS WITH FILTERS ---

    // Base query: Filter by target_vendor_id (supplier)
    $sql = "
        SELECT 
            T.transaction_id, T.created_at, T.transaction_amount, T.status,
            T.part_id, U.email AS customer_email,
            P.part_name AS item_name
        FROM Transactions T
        -- Join to Users table to get the email of the person who placed the order
        JOIN Users U ON T.initiator_user_id = U.user_id 
        -- Join to Parts table to get the name of the part ordered
        LEFT JOIN Parts P ON T.part_id = P.part_id 
        -- Filter transactions where the current vendor is the supplier
        WHERE T.target_vendor_id = ? AND T.part_id IS NOT NULL 
    ";

    $params = [$current_vendor_id];
    $types = "i";

    // Add status filtering
    if ($filter_status !== 'all') {
        $sql .= " AND T.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }

    $sql .= " ORDER BY T.created_at DESC";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                // Determine item name, defaulting if part name is missing
                $row['item_name'] = htmlspecialchars($row['item_name'] ?? ('Part ID: ' . $row['part_id']));

                $transactions[] = $row;
            }
        } else {
            $db_error_message = '<div class="alert alert-danger">SQL execution error: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    } else {
        $db_error_message = '<div class="alert alert-danger">SQL preparation error.</div>';
    }
}
?>

<?php require 'vendor_template.php'; ?>

    <div class="p-6 bg-white rounded-xl shadow-md">

        <header class="mb-8 flex justify-between items-center border-b pb-4 border-gray-100">
            <h2 class="text-3xl font-bold text-gray-900">
                📦 Orders & Shipment History
            </h2>
            <a href="vendor_profile.php" class="text-gray-500 hover:text-orange-600">
                <i data-lucide="circle-user" class="w-8 h-8"></i>
            </a>
        </header>

        <?php echo $db_error_message; // Display database errors here ?>

        <section>
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-800">All Part Orders Received (<?= count($transactions) ?> Total)</h3>

                <div class="flex items-center space-x-2">
                    <label for="status-filter" class="text-sm font-medium text-gray-600">Filter by Status:</label>
                    <select id="status-filter" onchange="window.location.href='transactions.php?status=' + this.value"
                            class="p-2 border border-gray-300 rounded-lg shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="Pending" <?= $filter_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Processing" <?= $filter_status === 'Processing' ? 'selected' : '' ?>>Processing</option>
                        <option value="Shipped" <?= $filter_status === 'Shipped' ? 'selected' : '' ?>>Shipped</option>
                        <option value="Completed" <?= $filter_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= $filter_status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto shadow-md rounded-lg border border-gray-200">
                <table class="min-w-full bg-white border-collapse">
                    <thead class="bg-gray-100">
                    <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                        <th class="p-4 border-b">ID</th>
                        <th class="p-4 border-b">Date</th>
                        <th class="p-4 border-b">Part Ordered</th>
                        <th class="p-4 border-b">Customer Email</th>
                        <th class="p-4 border-b">Amount</th>
                        <th class="p-4 border-b">Status</th>
                        <th class="p-4 border-b">Actions</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $tx):
                            // Set status classes based on garage_dashboard.php logic
                            $status_class = 'bg-gray-100 text-gray-700';
                            if ($tx['status'] === 'Completed') $status_class = 'bg-green-100 text-green-700';
                            elseif (in_array($tx['status'], ['Pending', 'Processing'])) $status_class = 'bg-red-100 text-red-700';
                            elseif ($tx['status'] === 'Shipped') $status_class = 'bg-yellow-100 text-yellow-700';
                            elseif ($tx['status'] === 'Cancelled') $status_class = 'bg-gray-100 text-gray-700';

                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($tx['transaction_id']) ?></td>
                                <td class="p-4 text-sm"><?= date('Y-m-d', strtotime($tx['created_at'])) ?></td>
                                <td class="p-4 text-sm font-medium text-gray-800">
                                    <?= htmlspecialchars($tx['item_name']) ?>
                                </td>
                                <td class="p-4 text-sm text-blue-600"><?= htmlspecialchars($tx['customer_email']) ?></td>
                                <td class="p-4 text-sm font-semibold text-gray-900">Ksh <?= number_format($tx['transaction_amount'] ?? 0, 2) ?></td>
                                <td class="p-4">
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $status_class ?>">
                                            <?= htmlspecialchars($tx['status']) ?>
                                        </span>
                                </td>
                                <td class="p-4 space-x-2">
                                    <button class="bg-blue-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-blue-600">Details</button>
                                    <button class="bg-yellow-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-yellow-600">Update Status</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-4 text-center text-gray-500">No part orders found matching the filter '<?= htmlspecialchars($filter_status) ?>'.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

<?php
// Close DB connection first
if ($conn && $conn->ping()) {
    $conn->close();
}
require 'vendor_footer.php';
?>