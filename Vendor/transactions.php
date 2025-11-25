<?php
/**
 * CAASP Spare Part Vendor Orders & History (transactions.php)
 * Displays a detailed list of all outgoing part orders/shipments for the vendor,
 * and handles viewing details and updating status using 'action' parameters.
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

$conn = connect_db();
$db_error_message = '';
$action_message = '';
$transaction = null; // Used for details and update views
$status_options = ['Pending', 'Processing', 'Shipped', 'Completed', 'Cancelled'];

// Get action and ID from URL or POST
$action = $_GET['action'] ?? null;
$transaction_id = $_GET['transaction_id'] ?? $_POST['transaction_id'] ?? null;

if (!$conn) {
    $db_error_message = '<div class="alert alert-danger">Error: Could not connect to database. Transaction data is unavailable.</div>';
} else {
    // 1. Fetch Vendor Name
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

    // --- NEW: FETCH VENDOR BALANCE ---
    $vendor_balance = 0.00;
    $sql_balance = "SELECT revenue_balance FROM Vendors WHERE vendor_id = ?";
    if ($stmt = $conn->prepare($sql_balance)) {
        $stmt->bind_param("i", $current_vendor_id);
        $stmt->execute();
        $stmt->bind_result($balance);
        if ($stmt->fetch()) {
            $vendor_balance = $balance;
        }
        $stmt->close();
    }
    // --- END NEW BALANCE FETCH ---


    // --- 2. ACTION HANDLER LOGIC ---

    // Handle Status Update POST request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $new_status = $_POST['new_status'];

        if ($transaction_id && in_array($new_status, $status_options)) {

            // 1. FETCH DETAILS NEEDED FOR REFUND/BALANCE CHECK (CRITICAL: FETCH OLD STATUS)
            $check_sql = "SELECT transaction_amount, initiator_user_id, status AS old_status FROM Transactions WHERE transaction_id = ? AND target_vendor_id = ?";
            $data = null;
            if ($stmt = $conn->prepare($check_sql)) {
                $stmt->bind_param("ii", $transaction_id, $current_vendor_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $data = $result->fetch_assoc();
                $stmt->close();
            }
            $old_status = $data['old_status'] ?? '';
            $amount = $data['transaction_amount'] ?? 0;
            $user_to_refund = $data['initiator_user_id'] ?? null;

            $balance_update_sql = null;
            $balance_change_amount = 0;
            $message_text = "Status updated to **". htmlspecialchars($new_status) . "**.";

            // --- LOGIC A: REVENUE DEDUCTION (Refund/Cancellation) ---
            if ($new_status === 'Cancelled' && $old_status !== 'Cancelled') {

                // 1. CRITICAL: Check if the Vendor was previously credited
                if ($old_status === 'Completed') {
                    $balance_update_sql = "UPDATE Vendors SET revenue_balance = revenue_balance - ? WHERE vendor_id = ?";
                    $balance_change_amount = $amount; // Amount to DEDUCT
                    $message_text .= " **Ksh " . number_format($amount, 2) . " deducted from revenue.**";
                }

                // 2. Customer Refund Logic (Always refund if cancelling, regardless of old business status)
                if ($user_to_refund) {
                    $refund_sql = "UPDATE Users SET account_balance = account_balance + ? WHERE user_id = ?";
                    if ($stmt_refund = $conn->prepare($refund_sql)) {
                        $stmt_refund->bind_param("di", $amount, $user_to_refund);
                        $stmt_refund->execute();
                        $stmt_refund->close();
                        $message_text .= " Customer refunded Ksh " . number_format($amount, 2) . ".";
                    }
                }
            }

            // --- LOGIC B: REVENUE CREDIT (Completion) ---
            elseif ($new_status === 'Completed' && $old_status !== 'Completed') {
                // Credit the Vendor balance
                $balance_update_sql = "UPDATE Vendors SET revenue_balance = revenue_balance + ? WHERE vendor_id = ?";
                $balance_change_amount = $amount; // Amount to CREDIT
                $message_text .= " **Ksh " . number_format($amount, 2) . " credited to revenue.**";
            }

            // 2. UPDATE TRANSACTION STATUS
            $update_sql = "UPDATE Transactions SET status = ? WHERE transaction_id = ? AND target_vendor_id = ?";

            if ($stmt = $conn->prepare($update_sql)) {
                $stmt->bind_param("sii", $new_status, $transaction_id, $current_vendor_id);

                if ($stmt->execute()) {

                    // --- 3. EXECUTE BALANCE CHANGE (if any) ---
                    if ($balance_update_sql && $balance_change_amount > 0) {
                        if ($stmt_balance = $conn->prepare($balance_update_sql)) {
                            $stmt_balance->bind_param("di", $balance_change_amount, $current_vendor_id);
                            $stmt_balance->execute();
                            $stmt_balance->close();

                            // Update the displayed balance for the refresh
                            if ($new_status === 'Completed') {
                                $vendor_balance += $balance_change_amount;
                            } elseif ($new_status === 'Cancelled' && $old_status === 'Completed') {
                                $vendor_balance -= $balance_change_amount;
                            }
                        }
                    }

                    $action_message = '<div class="alert alert-success bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">' . $message_text . '</div>';

                } elseif ($stmt->affected_rows === 0) {
                    $action_message = '<div class="alert alert-info bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative" role="alert">No changes made, status was already **' . htmlspecialchars($new_status) . '**.</div>';
                } else {
                    $action_message = '<div class="alert alert-danger">Update failed: ' . $stmt->error . '</div>';
                }
                $stmt->close();
            }
            $action = 'details'; // Show details after update
        } else {
            $action_message = '<div class="alert alert-danger">Invalid status or ID provided for update.</div>';
        }
    }

    // --- 3. FETCH TRANSACTION DETAILS (For 'details' or 'update_form' views) ---
    if (($action === 'details' || $action === 'update_form') && $transaction_id) {
        $sql = "
            SELECT 
                T.*, U.email AS customer_email, U.contact AS customer_contact,
                P.part_name AS item_name,
                P.description AS item_description 
            FROM Transactions T
            JOIN Users U ON T.initiator_user_id = U.user_id 
            LEFT JOIN Parts P ON T.part_id = P.part_id 
            WHERE T.transaction_id = ? AND T.target_vendor_id = ? 
        ";

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("ii", $transaction_id, $current_vendor_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $transaction = $result->fetch_assoc();

                if (!$transaction) {
                    $action_message = '<div class="alert alert-warning">Order not found or unauthorized.</div>';
                    $action = null; // Fall back to main table view
                }
            }
            $stmt->close();
        }
    }

    // --- 4. FETCH ALL TRANSACTIONS (Only for the main table view) ---
    $transactions = [];
    if (!$action) {
        $filter_status = $_GET['status'] ?? 'all';

        // Base query: Filter by target_vendor_id (supplier)
        $sql = "
            SELECT 
                T.transaction_id, T.created_at, T.transaction_amount, T.status,
                T.part_id, U.email AS customer_email,
                P.part_name AS item_name
            FROM Transactions T
            JOIN Users U ON T.initiator_user_id = U.user_id 
            LEFT JOIN Parts P ON T.part_id = P.part_id 
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
}
// --- End of PHP Logic ---

// --- TEMPLATE INCLUDE START ---
require 'vendor_template.php';
// --- TEMPLATE INCLUDE END ---
?>

    <div class="p-6 bg-white rounded-xl shadow-md">

        <header class="mb-8 flex justify-between items-center border-b pb-4 border-gray-100">
            <h2 class="text-3xl font-bold text-gray-900">
                📦 Orders & Shipment History
            </h2>
            <div class="text-right">
                <span class="text-sm font-medium text-gray-500 block">Current Revenue Balance</span>
                <span class="text-2xl font-bold text-green-600 block">Ksh <?= number_format($vendor_balance, 2) ?></span>
            </div>
        </header>

        <?php echo $db_error_message; // Display database connection errors ?>
        <?php echo $action_message; // Display action status messages ?>


        <?php if ($action === 'details' && $transaction):
            // --- VIEW 1: ORDER DETAILS ---
            $status_class = 'status-' . str_replace(' ', '', htmlspecialchars($transaction['status']));
            $status_style_map = [
                    'Completed' => 'bg-green-100 text-green-700',
                    'Pending' => 'bg-red-100 text-red-700',
                    'Processing' => 'bg-red-100 text-red-700',
                    'Shipped' => 'bg-yellow-100 text-yellow-700',
                    'Cancelled' => 'bg-gray-100 text-gray-700',
            ];
            $status_class = $status_style_map[$transaction['status']] ?? 'bg-gray-100 text-gray-700';

            ?>
            <header class="mb-8 border-b pb-4 border-gray-100">
                <h2 class="text-3xl font-bold text-gray-900">
                    🔍 Order Details (ID: <?= htmlspecialchars($transaction_id) ?>)
                </h2>
            </header>

            <section class="grid grid-cols-1 md:grid-cols-3 gap-6">

                <div class="md:col-span-1 p-6 border border-gray-200 rounded-lg bg-gray-50">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Order Status</h3>
                    <p class="text-lg font-semibold mb-4">
                        Status:
                        <span class="px-3 py-1 text-sm font-bold rounded-full <?= $status_class ?>">
                            <?= htmlspecialchars($transaction['status']) ?>
                        </span>
                    </p>

                    <div class="mt-6 space-y-3">
                        <a href="transactions.php?action=update_form&transaction_id=<?= htmlspecialchars($transaction['transaction_id']) ?>"
                           class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i> Update Status
                        </a>
                    </div>
                </div>

                <div class="md:col-span-2 p-6 border border-gray-200 rounded-lg bg-white">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Order Information</h3>
                    <dl class="divide-y divide-gray-100">
                        <?php
                        $details = [
                                "Transaction ID" => $transaction['transaction_id'],
                                "Date Initiated" => date('F j, Y, g:i a', strtotime($transaction['created_at'])),
                                "Total Amount" => 'Ksh ' . number_format($transaction['transaction_amount'] ?? 0, 2),
                                "Part ID" => htmlspecialchars($transaction['part_id']),
                                "Part Name" => htmlspecialchars($transaction['item_name'] ?? 'N/A'),
                                "Customer Email" => htmlspecialchars($transaction['customer_email']),
                                "Customer Contact" => htmlspecialchars($transaction['customer_contact'] ?? 'N/A'),
                        ];
                        foreach ($details as $label => $value): ?>
                            <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
                                <dt class="text-sm font-medium text-gray-500"><?= $label ?></dt>
                                <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0"><?= $value ?></dd>
                            </div>
                        <?php endforeach; ?>

                        <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
                            <dt class="text-sm font-medium text-gray-500">Part Description</dt>
                            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
                                <?= nl2br(htmlspecialchars($transaction['item_description'] ?? 'No part description available.')) ?>
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

        <?php elseif ($action === 'update_form' && $transaction):
            // --- VIEW 2: ORDER UPDATE FORM ---
            $status_class = 'status-' . str_replace(' ', '', htmlspecialchars($transaction['status']));
            ?>

            <header class="mb-8 border-b pb-4 border-gray-100">
                <h2 class="text-3xl font-bold text-gray-900">
                    🔄 Update Order Status (ID: <?= htmlspecialchars($transaction_id) ?>)
                </h2>
            </header>

            <section class="max-w-xl mx-auto">
                <div class="p-6 border border-gray-200 rounded-lg bg-gray-50 mb-8">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Order Summary</h3>
                    <dl class="text-sm">
                        <div class="flex justify-between py-1">
                            <dt class="font-medium text-gray-600">Part:</dt>
                            <dd class="text-gray-900"><?= htmlspecialchars($transaction['item_name']) ?></dd>
                        </div>
                        <div class="flex justify-between py-1">
                            <dt class="font-medium text-gray-600">Customer Email:</dt>
                            <dd class="text-blue-600"><?= htmlspecialchars($transaction['customer_email']) ?></dd>
                        </div>
                        <div class="flex justify-between py-1">
                            <dt class="font-medium text-gray-600">Current Status:</dt>
                            <dd>
                                <span class="px-3 py-1 text-xs font-bold rounded-full <?= $status_class ?>">
                                    <?= htmlspecialchars($transaction['status']) ?>
                                </span>
                            </dd>
                        </div>
                    </dl>
                    <div class="mt-4 text-center">
                        <a href="transactions.php?action=details&transaction_id=<?= htmlspecialchars($transaction_id) ?>"
                           class="text-sm text-blue-600 hover:text-blue-800">View Details</a>
                    </div>
                </div>

                <form method="POST" action="transactions.php" class="bg-white p-6 rounded-lg shadow-md border border-orange-200">
                    <input type="hidden" name="transaction_id" value="<?= htmlspecialchars($transaction['transaction_id']) ?>">
                    <input type="hidden" name="action" value="update_status">

                    <div class="mb-4">
                        <label for="new_status" class="block text-lg font-medium text-gray-700 mb-2">Select New Status</label>
                        <select id="new_status" name="new_status" required
                                class="mt-1 block w-full pl-3 pr-10 py-3 text-base border-gray-300 focus:outline-none focus:ring-orange-500 focus:border-orange-500 sm:text-lg rounded-md shadow-sm">
                            <?php foreach ($status_options as $status): ?>
                                <option value="<?= $status ?>"
                                        <?= $transaction['status'] === $status ? 'selected' : '' ?>>
                                    <?= $status ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mt-6">
                        <button type="submit"
                                class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 transition duration-150 ease-in-out">
                            Confirm Status Update
                        </button>
                    </div>
                </form>
            </section>

        <?php else:
            // --- VIEW 3: MAIN TRANSACTION TABLE (DEFAULT) ---
            ?>

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
                                        <a href="transactions.php?action=details&transaction_id=<?= htmlspecialchars($tx['transaction_id']) ?>"
                                           class="bg-blue-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-blue-600 inline-block">Details</a>
                                        <a href="transactions.php?action=update_form&transaction_id=<?= htmlspecialchars($tx['transaction_id']) ?>"
                                           class="bg-yellow-500 text-white px-3 py-1 rounded-lg text-xs hover:bg-yellow-600 inline-block">Update Status</a>
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
        <?php endif; ?>
    </div>

<?php
// Close DB connection first
if ($conn && $conn->ping()) {
    $conn->close();
}
require 'vendor_footer.php';
?>