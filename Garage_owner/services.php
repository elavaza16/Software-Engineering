<?php
session_start();
// Include the correct database configuration file
require_once '../api_db_config.php';

// --- SECURITY CHECK (CRITICAL) ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Garage' || !isset($_SESSION['garage_id'])) {
    header("Location: index.html");
    exit();
}

// Dynamically set the current_garage_id from the session
$current_garage_id = $_SESSION['garage_id'];
$current_view = 'services'; // Set for active sidebar link
$garage_name = 'Service Management'; // Default name

// Get database connection by calling the function
$conn = connect_db();
if (!$conn) {
    $message = '<div class="alert alert-danger">Error: Could not connect to database.</div>';
    $result_read = null;
} else {
    // Fetch Garage Name (required for the sidebar/template)
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

    // --- 1. HANDLE CRUD ACTIONS (C/U/D) ---
    $message = '';
    $action_completed = false;

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $action = $_POST['action'] ?? '';

        if ($action == 'add') {
            // Prepared statement for safety
            $sql = "INSERT INTO Services (service_name, service_price, garage_id) VALUES (?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $service_name = $_POST['service_name'];
                $service_price = floatval($_POST['service_price']);
                $stmt->bind_param("sdi", $service_name, $service_price, $current_garage_id);
                if ($stmt->execute()) {
                    // FIX START (PRG pattern): Redirect after successful ADD
                    $stmt->close();
                    $conn->close();
                    header("Location: services.php?status=success&message=" . urlencode("Service added successfully!"));
                    exit();
                    // FIX END
                } else {
                    $message = '<div class="alert alert-danger">Error adding service: ' . $conn->error . '</div>';
                }
                $stmt->close();
            }
        }

        elseif ($action == 'update') {
            // Prepared statement for safety
            $sql = "UPDATE Services SET service_name=?, service_price=? WHERE service_id=? AND garage_id=?";
            if ($stmt = $conn->prepare($sql)) {
                $service_id = intval($_POST['service_id']);
                $service_name = $_POST['service_name'];
                $service_price = floatval($_POST['service_price']);
                $stmt->bind_param("sdis", $service_name, $service_price, $service_id, $current_garage_id);
                if ($stmt->execute()) {
                    // FIX START (PRG pattern): Redirect after successful UPDATE
                    $stmt->close();
                    $conn->close();
                    header("Location: services.php?status=success&message=" . urlencode("Service updated successfully!"));
                    exit();
                    // FIX END
                } else {
                    $message = '<div class="alert alert-danger">Error updating service: ' . $conn->error . '</div>';
                }
                $stmt->close();
            }
        }
    }

    if ($_SERVER["REQUEST_METHOD"] == "GET") {
        // Handle Delete
        if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
            $service_id = intval($_GET['id']);
            $deleted_service_name = 'Service ID ' . $service_id; // Default name

            // FIX START: 1. Fetch the name before deletion
            $sql_fetch_name = "SELECT service_name FROM Services WHERE service_id = ? AND garage_id = ?";
            if ($stmt_fetch = $conn->prepare($sql_fetch_name)) {
                $stmt_fetch->bind_param("ii", $service_id, $current_garage_id);
                $stmt_fetch->execute();
                $stmt_fetch->bind_result($fetched_name);
                if ($stmt_fetch->fetch()) {
                    $deleted_service_name = htmlspecialchars($fetched_name);
                }
                $stmt_fetch->close();
            }
            // FIX END

            // Prepared statement for safety: Delete the row
            $sql_delete = "DELETE FROM Services WHERE service_id=? AND garage_id=?";
            if ($stmt = $conn->prepare($sql_delete)) {
                $stmt->bind_param("ii", $service_id, $current_garage_id);

                if ($stmt->execute()) {
                    // FIX START: Redirect for successful DELETE, including the item name
                    $stmt->close();
                    $conn->close(); // Must close connection before redirect
                    $success_message = "Service '{$deleted_service_name}' deleted successfully!";
                    header("Location: services.php?status=warning&message=" . urlencode($success_message));
                    exit();
                    // FIX END
                } else {
                    $message = '<div class="alert alert-danger">Error deleting service: ' . $conn->error . '</div>';
                }
                $stmt->close();
            }
        }

        // Handle URL message parameter from successful actions
        if (isset($_GET['message']) && isset($_GET['status'])) {
            $status_class = ($_GET['status'] === 'warning') ? 'alert-warning' : 'alert-success';
            $message = '<div class="alert ' . $status_class . '">' . htmlspecialchars($_GET['message']) . '</div>';
        }
    }


    // --- 2. CHECK FOR EDIT MODE ---
    $edit_mode = false;
    $service_data = null;
    if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
        $service_id = intval($_GET['id']);

        $sql_edit = "SELECT service_name, service_price FROM Services WHERE service_id = ? AND garage_id = ?";
        if ($stmt = $conn->prepare($sql_edit)) {
            $stmt->bind_param("ii", $service_id, $current_garage_id);
            $stmt->execute();
            $result_edit = $stmt->get_result();

            if ($result_edit && $result_edit->num_rows == 1) {
                $service_data = $result_edit->fetch_assoc();
                $edit_mode = true;
            } else {
                $message = '<div class="alert alert-danger">Error: Service not found or unauthorized.</div>';
            }
            $stmt->close();
        }
    }


    // --- 3. FETCH DATA FOR DISPLAY (Read) ---
    $sql_read = "SELECT service_id, service_name, service_price FROM Services WHERE garage_id = $current_garage_id ORDER BY service_id DESC";
    $result_read = $conn->query($sql_read);
}


// --- TEMPLATE INCLUDE START ---
require 'garage_template.php';
// --- TEMPLATE INCLUDE END ---
?>

<header class="mb-8 flex justify-between items-center">
    <h2 class="text-3xl font-bold text-gray-900">
        🛠️ Service Management
    </h2>
    <a href="garage_profile.php" class="text-gray-500 hover:text-orange-600">
        <i data-lucide="circle-user" class="w-8 h-8"></i>
    </a>
</header>

<div class="p-6 bg-white rounded-xl shadow-md">
    <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-2">Service Configuration</h3>

    <?php echo $message; // Display status message ?>

    <?php if ($edit_mode): ?>
        <section class="p-4 bg-gray-50 rounded-lg mb-6 border border-gray-200">
            <h3 class="text-xl font-bold text-orange-600 mb-4">✏️ Edit Service: **<?php echo htmlspecialchars($service_data['service_name'] ?? 'N/A'); ?>**</h3>
            <form action="services.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="service_id" value="<?php echo htmlspecialchars($service_id); ?>">

                <div>
                    <label for="service_name" class="block text-sm font-medium text-gray-700">Service Name:</label>
                    <input type="text" id="service_name" name="service_name"
                           value="<?php echo htmlspecialchars($service_data['service_name']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 sm:text-sm p-2 border">
                </div>
                <div>
                    <label for="service_price" class="block text-sm font-medium text-gray-700">Price (e.g., 2500.00):</label>
                    <input type="number" step="0.01" id="service_price" name="service_price"
                           value="<?php echo htmlspecialchars($service_data['service_price']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 sm:text-sm p-2 border">
                </div>

                <div class="flex space-x-3">
                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">Update Service</button>
                    <a href="services.php" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700">Cancel Edit</a>
                </div>
            </form>
        </section>

    <?php else: ?>
        <section class="p-4 bg-gray-50 rounded-lg mb-8 border border-gray-200">
            <h3 class="text-xl font-bold text-blue-600 mb-4">➕ Add New Service</h3>
            <form action="services.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add">
                <div>
                    <label for="service_name" class="block text-sm font-medium text-gray-700">Service Name:</label>
                    <input type="text" id="service_name" name="service_name" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border">
                </div>
                <div>
                    <label for="service_price" class="block text-sm font-medium text-gray-700">Price (e.g., 2500.00):</label>
                    <input type="number" step="0.01" id="service_price" name="service_price" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border">
                </div>
                <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">Add Service</button>
            </form>
        </section>

        <h3 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-2">📋 Your Current Services List</h3>
        <div class="shadow-sm border border-gray-200 rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price (Ksh)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php
                if ($conn && $result_read && $result_read->num_rows > 0) {
                    while($row = $result_read->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>" . htmlspecialchars($row["service_id"]) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-600'>" . htmlspecialchars($row["service_name"]) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-600'>Ksh " . number_format($row["service_price"], 2) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium'>
                                    <a href='services.php?action=edit&id=" . htmlspecialchars($row["service_id"]) . "' class='text-orange-600 hover:text-orange-900 mr-4'>Edit</a>
                                    <a href='services.php?action=delete&id=" . htmlspecialchars($row["service_id"]) . "' 
                                        onclick='return confirmDelete();' 
                                        class='text-red-600 hover:text-red-900'>Delete</a>
                                </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='4' class='px-6 py-4 text-center text-sm text-gray-500'>**No services added yet.** Use the form above to get started!</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    <?php endif; // End of $edit_mode check ?>
</div>

<?php
// Close DB connection first
if ($conn) {
    $conn->close();
}
// Then include the footer template
require 'garage_footer.php';
?>

<script>
    // Only keep the confirmDelete function, the rest is in the footer
    function confirmDelete() {
        return confirm('Are you sure you want to delete this service? This action cannot be undone.');
    }
</script>