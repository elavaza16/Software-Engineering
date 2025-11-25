<?php
session_start();
// Include the correct database configuration file
require_once '../api_db_config.php';

// --- SECURITY CHECK (CRITICAL) ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Vendor' || !isset($_SESSION['vendor_id'])) {
    header("Location: index.html");
    exit();
}

// Dynamically set the current_vendor_id from the session
$current_vendor_id = $_SESSION['vendor_id'];
$current_view = 'parts'; // Set for active sidebar link
$vendor_name = 'Parts Management'; // Default name

// Define the default image path
const DEFAULT_PART_IMAGE = 'uploads/part_images/placeholder.jpg';

// Get database connection by calling the function
$conn = connect_db();
if (!$conn) {
    $message = '<div class="alert alert-danger">Error: Could not connect to database.</div>';
    $result_read = null;
} else {
    // Fetch Vendor Name (required for the sidebar/template)
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

    // Helper function for file upload
    function handle_part_image_upload($part_id, $current_path = null) {
        $upload_dir = 'uploads/part_images/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }

        if (isset($_FILES['part_image']) && $_FILES['part_image']['error'] == UPLOAD_ERR_OK) {
            $file_extension = pathinfo($_FILES['part_image']['name'], PATHINFO_EXTENSION);
            $file_name = $part_id . '_' . time() . '.' . $file_extension;
            $target_file = $upload_dir . $file_name;
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array(strtolower($file_extension), $allowed_types) && move_uploaded_file($_FILES['part_image']['tmp_name'], $target_file)) {
                // Delete old image if it's not the placeholder
                if ($current_path && $current_path !== DEFAULT_PART_IMAGE && file_exists($current_path)) {
                    unlink($current_path);
                }
                return $target_file;
            } else {
                // Return an error message to be handled in the main logic
                return 'ERROR: Error uploading image. Check file type (JPG, PNG, GIF) or permissions.';
            }
        }
        return $current_path; // No new file uploaded, keep current path
    }


    // --- 1. HANDLE CRUD ACTIONS (C/U/D) ---
    $message = '';
    $action_completed = false;
    $action = $_POST['action'] ?? $_GET['action'] ?? ''; // Capture action for fail check

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $part_name = $_POST['part_name'] ?? '';
        $part_description = $_POST['description'] ?? '';

        // FIX APPLIED HERE: Get price as a string, not a float, for precision
        $part_price = $_POST['part_price'] ?? '0.00';

        $image_path_update = DEFAULT_PART_IMAGE;

        if ($action == 'add') {
            // Step 1: Insert Part without image path (or with default) to get the new part_id
            $sql = "INSERT INTO Parts (part_name, description, part_price, vendor_id, part_image_path) VALUES (?, ?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {

                // FIX APPLIED HERE: Change bind type for part_price from 'd' to 's'
                $stmt->bind_param("sssis", // s(name), s(desc), s(price), i(vendor_id), s(path)
                        $part_name,
                        $part_description,
                        $part_price, // Bound as string
                        $current_vendor_id,
                        $image_path_update
                );

                if ($stmt->execute()) {
                    $new_part_id = mysqli_insert_id($conn);
                    $stmt->close();

                    // Step 2: Handle Image Upload and Update the path
                    $image_result = handle_part_image_upload($new_part_id, $image_path_update);

                    if (strpos($image_result, 'ERROR') === 0) {
                        $message = '<div class="alert alert-danger">Part added, but ' . $image_result . '</div>';
                    } else {
                        // Update the database with the final image path
                        $sql_update_path = "UPDATE Parts SET part_image_path = ? WHERE part_id = ?";
                        if ($stmt_path = $conn->prepare($sql_update_path)) {
                            $stmt_path->bind_param("si", $image_result, $new_part_id);
                            $stmt_path->execute();
                            $stmt_path->close();
                        }
                        // FIX START (PRG pattern): Redirect after successful ADD
                        $conn->close();
                        header("Location: parts.php?status=success&message=" . urlencode("Part added successfully!"));
                        exit();
                        // FIX END
                    }
                    $action_completed = true;
                } else {
                    $message = '<div class="alert alert-danger">Error adding part: ' . $conn->error . '</div>';
                }
            }
        }

        elseif ($action == 'update') {
            $part_id = intval($_POST['part_id']);
            $current_image_path = $_POST['current_image_path'];

            // 1. Handle image upload first
            $image_result = handle_part_image_upload($part_id, $current_image_path);

            if (strpos($image_result, 'ERROR') === 0) {
                $message = '<div class="alert alert-danger">Part updated (excluding image): ' . $image_result . '</div>';
                $image_path_final = $current_image_path;
            } else {
                $image_path_final = $image_result;
            }

            // 2. Update all text fields and the image path
            $sql = "UPDATE Parts SET part_name=?, description=?, part_price=?, part_image_path=? WHERE part_id=? AND vendor_id=?";
            if ($stmt = $conn->prepare($sql)) {
                // FIX APPLIED HERE: Change bind type for part_price from 'd' to 's'
                $stmt->bind_param("ssssii", // s(name), s(desc), s(price), s(path), i(id), i(vendor_id)
                        $part_name,
                        $part_description,
                        $part_price, // Bound as string
                        $image_path_final, // Updated path
                        $part_id,
                        $current_vendor_id
                );

                if ($stmt->execute()) {
                    // FIX START (PRG pattern): Redirect after successful UPDATE
                    $stmt->close();
                    $conn->close();
                    $message_final = urlencode(strpos($message, 'ERROR') === false ? 'Part updated successfully!' : $message);
                    header("Location: parts.php?status=success&message=" . $message_final);
                    exit();
                    // FIX END
                } else {
                    $message .= '<div class="alert alert-danger">Database error updating part: ' . $conn->error . '</div>';
                }
                $stmt->close();
            }
        }
    }

    if ($_SERVER["REQUEST_METHOD"] == "GET") {
        // Handle Delete
        if ($action == 'delete' && isset($_GET['id'])) {
            $part_id = intval($_GET['id']);
            $deleted_part_name = 'Part ID ' . $part_id; // Default name

            // FIX START: 1. Fetch the name and path before deletion
            $sql_fetch_name = "SELECT part_name, part_image_path FROM Parts WHERE part_id = ? AND vendor_id = ?";
            if ($stmt_fetch = $conn->prepare($sql_fetch_name)) {
                $stmt_fetch->bind_param("ii", $part_id, $current_vendor_id);
                $stmt_fetch->execute();
                $stmt_fetch->bind_result($fetched_name, $path_to_delete);
                if ($stmt_fetch->fetch()) {
                    $deleted_part_name = htmlspecialchars($fetched_name);
                }
                $stmt_fetch->close();
            }
            // FIX END

            // Delete the file if it exists and is not the placeholder
            if ($path_to_delete && $path_to_delete !== DEFAULT_PART_IMAGE && file_exists($path_to_delete)) {
                unlink($path_to_delete);
            }

            // Prepared statement for safety: Delete the row
            $sql_delete = "DELETE FROM Parts WHERE part_id=? AND vendor_id=?";
            if ($stmt = $conn->prepare($sql_delete)) {
                $stmt->bind_param("ii", $part_id, $current_vendor_id);

                if ($stmt->execute()) {
                    // FIX START: Redirect for successful DELETE, including the item name
                    $stmt->close();
                    $conn->close(); // Must close connection before redirect
                    $success_message = "Part '{$deleted_part_name}' deleted successfully!";
                    header("Location: parts.php?status=warning&message=" . urlencode($success_message));
                    exit();
                    // FIX END
                } else {
                    $message = '<div class="alert alert-danger">Error deleting part: ' . $conn->error . '</div>';
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


    // --- 2. CHECK FOR EDIT MODE / ADD MODE DISPLAY ---
    $edit_mode = false;
    $part_data = null;
    $show_add_form = false; // NEW FLAG

    // Check if we just failed an ADD attempt (message is set but no successful action)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && $action == 'add' && !$action_completed) {
        $show_add_form = true;
    }


    if ($action == 'edit' && isset($_GET['id'])) {
        $part_id = intval($_GET['id']);

        // Fetch ALL fields for editing, including image path and description
        $sql_edit = "SELECT part_name, description, part_price, part_image_path FROM Parts WHERE part_id = ? AND vendor_id = ?";
        if ($stmt = $conn->prepare($sql_edit)) {
            $stmt->bind_param("ii", $part_id, $current_vendor_id);
            $stmt->execute();
            $result_edit = $stmt->get_result();

            if ($result_edit && $result_edit->num_rows == 1) {
                $part_data = $result_edit->fetch_assoc();
                $edit_mode = true;
            } else {
                $message = '<div class="alert alert-danger">Error: Part not found or unauthorized.</div>';
            }
            $stmt->close();
        }
    }


    // --- 3. FETCH DATA FOR DISPLAY (Read) ---
    // Fetch ALL fields for the table display
    $sql_read = "SELECT part_id, part_name, description, part_price, part_image_path FROM Parts WHERE vendor_id = $current_vendor_id ORDER BY part_id DESC";
    $result_read = $conn->query($sql_read);
}


// --- TEMPLATE INCLUDE START ---
require 'vendor_template.php';
// --- TEMPLATE INCLUDE END ---
?>

<header class="mb-8 flex justify-between items-center">
    <h2 class="text-3xl font-bold text-gray-900">
        📦 Spare Parts Inventory
    </h2>
    <a href="vendor_profile.php" class="text-gray-500 hover:text-orange-600">
        <i data-lucide="circle-user" class="w-8 h-8"></i>
    </a>
</header>

<div class="p-6 bg-white rounded-xl shadow-md">
    <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-2">Parts Configuration</h3>

    <?php echo $message; // Display status message ?>

    <?php if ($edit_mode): ?>
        <section class="p-4 bg-gray-50 rounded-lg mb-6 border border-gray-200">
            <h3 class="text-xl font-bold text-orange-600 mb-4">✏️ Edit Part: **<?php echo htmlspecialchars($part_data['part_name'] ?? 'N/A'); ?>**</h3>

            <form action="parts.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="part_id" value="<?php echo htmlspecialchars($part_id); ?>">
                <input type="hidden" name="current_image_path" value="<?php echo htmlspecialchars($part_data['part_image_path']); ?>">

                <div class="flex items-center space-x-6 p-4 bg-white rounded-lg border border-gray-200">
                    <div class="w-16 h-16 overflow-hidden flex-shrink-0 border bg-gray-200">
                        <img src="<?php echo htmlspecialchars($part_data['part_image_path']); ?>"
                             alt="Part Image Preview"
                             onerror="this.onerror=null; this.src='<?php echo DEFAULT_PART_IMAGE; ?>';"
                             class="w-full h-full object-cover">
                    </div>
                    <div class="flex-grow">
                        <label for="part_image" class="block text-sm font-medium text-gray-700 mb-1">Upload New Part Photo:</label>
                        <input type="file" id="part_image" name="part_image" accept=".jpg, .jpeg, .png, .gif"
                               class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-white focus:outline-none p-1">
                        <p class="text-xs text-gray-500 mt-1">Current file: <?= basename($part_data['part_image_path']) === 'placeholder.jpg' ? 'None' : basename($part_data['part_image_path']) ?></p>
                    </div>
                </div>

                <div>
                    <label for="part_name" class="block text-sm font-medium text-gray-700">Part Name / SKU:</label>
                    <input type="text" id="part_name" name="part_name"
                           value="<?php echo htmlspecialchars($part_data['part_name']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 sm:text-sm p-2 border">
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Short Description:</label>
                    <textarea id="description" name="description" rows="3" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 sm:text-sm p-2 border"><?php echo htmlspecialchars($part_data['description']); ?></textarea>
                </div>

                <div>
                    <label for="part_price" class="block text-sm font-medium text-gray-700">Price (Ksh, e.g., 5000.00):</label>
                    <input type="number" step="0.01" id="part_price" name="part_price"
                           value="<?php echo htmlspecialchars($part_data['part_price']); ?>" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 sm:text-sm p-2 border">
                </div>

                <div class="flex space-x-3">
                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">Update Part</button>
                    <a href="parts.php" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-gray-600 hover:bg-gray-700">Cancel Edit</a>
                </div>
            </form>
        </section>

    <?php else: // Not in edit mode: Show button and list ?>

        <div class="mb-8">
            <button id="toggle-add-form" type="button" class="inline-flex items-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <i data-lucide="plus" class="w-5 h-5 mr-2"></i>
                Add a New Spare Part
            </button>
        </div>

        <section id="add-part-form-container" class="p-4 bg-gray-50 rounded-lg mb-8 border border-gray-200 <?= $show_add_form ? '' : 'hidden' ?>">
            <h3 class="text-xl font-bold text-blue-600 mb-4">➕ Add New Spare Part</h3>

            <form action="parts.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="add">

                <div>
                    <label for="part_image" class="block text-sm font-medium text-gray-700 mb-1">Part Photo:</label>
                    <input type="file" id="part_image" name="part_image" accept=".jpg, .jpeg, .png, .gif"
                           class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-white focus:outline-none p-1">
                </div>

                <div>
                    <label for="part_name" class="block text-sm font-medium text-gray-700">Part Name / SKU:</label>
                    <input type="text" id="part_name" name="part_name" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border">
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Short Description:</label>
                    <textarea id="description" name="description" rows="3" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border"></textarea>
                </div>

                <div>
                    <label for="part_price" class="block text-sm font-medium text-gray-700">Price (Ksh, e.g., 5000.00):</label>
                    <input type="number" step="0.01" id="part_price" name="part_price" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2 border">
                </div>

                <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">Add Part</button>
            </form>
        </section>

        <h3 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-2">📋 Your Current Parts List</h3>
        <div class="shadow-sm border border-gray-200 rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Part Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price (Ksh)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php
                if ($conn && $result_read && $result_read->num_rows > 0) {
                    while($row = $result_read->fetch_assoc()) {
                        // Determine image path, falling back to default if necessary
                        $image_src = htmlspecialchars($row["part_image_path"] ?? DEFAULT_PART_IMAGE);
                        if (empty($row["part_image_path"])) {
                            $image_src = DEFAULT_PART_IMAGE;
                        }

                        echo "<tr>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>
                                    <img src='{$image_src}' alt='Part' class='w-10 h-10 object-cover rounded' onerror=\"this.onerror=null; this.src='" . DEFAULT_PART_IMAGE . "';\">
                                  </td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-600'>" . htmlspecialchars($row["part_name"]) . "</td>";
                        echo "<td class='px-6 py-4 text-sm text-gray-600 max-w-xs truncate'>" . htmlspecialchars($row["description"] ?? 'N/A') . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-600'>Ksh " . number_format($row["part_price"], 2) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium'>
                                    <a href='parts.php?action=edit&id=" . htmlspecialchars($row["part_id"]) . "' class='text-orange-600 hover:text-orange-900 mr-4'>Edit</a>
                                    <a href='parts.php?action=delete&id=" . htmlspecialchars($row["part_id"]) . "' 
                                        onclick='return confirmDelete();' 
                                        class='text-red-600 hover:text-red-900'>Delete</a>
                                </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='px-6 py-4 text-center text-sm text-gray-500'>**No parts added yet.** Use the button above to get started!</td></tr>";
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
require 'vendor_footer.php';
?>

<script>
    // Toggle logic for the Add Part Form
    document.addEventListener('DOMContentLoaded', function() {
        const toggleButton = document.getElementById('toggle-add-form');
        const formContainer = document.getElementById('add-part-form-container');

        if (toggleButton && formContainer) {
            // Function to update the button text/icon
            function updateToggleButton(isFormVisible) {
                if (isFormVisible) {
                    toggleButton.innerHTML = '<i data-lucide="x" class="w-5 h-5 mr-2"></i> Cancel Add';
                } else {
                    toggleButton.innerHTML = '<i data-lucide="plus" class="w-5 h-5 mr-2"></i> Add a New Spare Part';
                }
                // Important: Re-render lucide icons after changing innerHTML
                lucide.createIcons();
            }

            // Set initial button text based on PHP logic ($show_add_form)
            updateToggleButton(!formContainer.classList.contains('hidden'));

            toggleButton.addEventListener('click', function() {
                const isHidden = formContainer.classList.toggle('hidden');
                updateToggleButton(!isHidden);
            });
        }
    });

    // Only keep the confirmDelete function, the rest is in the footer
    function confirmDelete() {
        return confirm('Are you sure you want to delete this part? This action cannot be undone.');
    }
</script>