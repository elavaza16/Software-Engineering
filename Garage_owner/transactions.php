<?php
/**
 * CAASP Garage Owner Orders & History (transactions.php)
 * Displays a detailed list of all incoming service/part orders for the garage.
 */
session_start();
require_once '../api_db_config.php';

// --- SECURITY & REDIRECTION CHECK (Keep this) ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Garage' || !isset($_SESSION['garage_id'])) {
    header("Location: index.html");
    exit();
}

$current_garage_id = $_SESSION['garage_id'];
$current_view = 'transactions'; // Set for active sidebar link
$garage_name = "Your Garage Dashboard"; // Fallback name

// Get filter status from URL, default to 'all'
$filter_status = $_GET['status'] ?? 'all';

$conn = connect_db();
$transactions = [];
$db_error_message = '';

if (!$conn) {
    $db_error_message = '<div class="alert alert-danger">Error: Could not connect to database. Transaction data is unavailable.</div>';
} else {
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

    // --- FETCH ALL TRANSACTIONS WITH FILTERS (Keep this core logic) ---

    // Base query using corrected target_garage_id
    $sql = "
        SELECT 
            T.transaction_id, T.created_at, T.transaction_amount, T.status,
            T.service_id, T.part_id, U.email AS customer_email,
            COALESCE(S.service_name, 'Part Order') AS item_name
        FROM Transactions T
        JOIN Users U ON T.initiator_user_id = U.user_id 
        LEFT JOIN Services S ON T.service_id = S.service_id 
        WHERE T.target_garage_id = ? 
    ";

    $params = [$current_garage_id];
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
                // Determine item type for display
                $item_type = $row['service_id'] !== NULL ? 'Service' : ($row['part_id'] !== NULL ? 'Part Order' : 'N/A');
                $row['item_name'] = $item_type === 'Service' ? $row['item_name'] : $item_type;

                $transactions[] = $row;
            }
        } else {
            $db_error_message = '<div class="alert alert-danger">SQL execution error: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    } else {
        $db_error_message = '<div class="alert alert-danger">SQL preparation error.</div>';
    }

    // DB connection will be closed in the footer section below
}
// --- End of PHP Logic ---

// --- TEMPLATE INCLUDE START (Replaces <head> and sidebar HTML) ---
require 'garage_template.php';
// --- TEMPLATE INCLUDE END ---
?>

    <div class="p-6 bg-white rounded-xl shadow-md">

        <header class="mb-8 flex justify-between items-center border-b pb-4 border-gray-100">
            <h2 class="text-3xl font-bold text-gray-900">
                💸 Orders & Transaction History
            </h2>
            <a href="garage_profile.php" class="text-gray-500 hover:text-orange-600">
                <i data-lucide="circle-user" class="w-8 h-8"></i>
            </a>
        </header>

        <?php echo $db_error_message; // Display database errors here ?>

        <section>
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-800">All Orders Received (<?= count($transactions) ?> Total)</h3>

                <div class="flex items-center space-x-2">
                    <label for="status-filter" class="text-sm font-medium text-gray-600">Filter by Status:</label>
                    <select id="status-filter" onchange="window.location.href='transactions.php?status=' + this.value"
                            class="p-2 border border-gray-300 rounded-lg shadow-sm focus:ring-orange-500 focus:border-orange-500 text-sm">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="Pending" <?= $filter_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="In Progress" <?= $filter_status === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
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
                        <th class="p-4 border-b">Item/Service</th>
                        <th class="p-4 border-b">Customer</th>
                        <th class="p-4 border-b">Amount</th>
                        <th class="p-4 border-b">Status</th>
                        <th class="p-4 border-b">Actions</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $tx):
                            // Map status to Tailwind/Custom CSS class names defined in garage_template.php
                            $status_class = 'status-' . str_replace(' ', '', htmlspecialchars($tx['status']));
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($tx['transaction_id']) ?></td>
                                <td class="p-4 text-sm"><?= date('Y-m-d', strtotime($tx['created_at'])) ?></td>
                                <td class="p-4 text-sm font-medium text-gray-800">
                                    <?= htmlspecialchars($tx['item_name']) ?>
                                    <span class="text-xs font-mono text-gray-400 block">
                                            ID: <?= $tx['service_id'] ?? $tx['part_id'] ?>
                                        </span>
                                </td>
                                <td class="p-4 text-sm text-blue-600"><?= htmlspecialchars($tx['customer_email']) ?></td>
                                <td class="p-4 text-sm font-semibold text-gray-900">Ksh <?= number_format($tx['transaction_amount'], 2) ?></td>
                                <td class="p-4">
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $status_class ?>">
                                            <?= htmlspecialchars($tx['status']) ?>
                                        </span>
                                </td>
                                <td class="p-4 space-x-2">
                                    <button class="bg-blue-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-blue-600">Details</button>
                                    <button class="bg-yellow-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-yellow-600">Update</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-4 text-center text-gray-500">No transactions found matching the filter '<?= htmlspecialchars($filter_status) ?>'.</td>
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
// --- FOOTER INCLUDE START (Replaces closing HTML and JS) ---
require 'garage_footer.php';
// --- FOOTER INCLUDE END ---
?>