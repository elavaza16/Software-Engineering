<?php
session_start();
require_once '../api_db_config.php';

// --- SECURITY CHECK ---
// Change role check to 'Vendor' and use 'vendor_id'
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Vendor' || !isset($_SESSION['vendor_id'])) {
    header("Location: index.html");
    exit();
}

$current_vendor_id = $_SESSION['vendor_id']; // Changed ID variable
$current_view = 'profile'; // Set for active sidebar link

// --- SET MODE: Check URL parameter. Default is 'view'. ---
$mode = $_GET['mode'] ?? 'view';

$conn = connect_db();
$message = '';
// Change default data structure for vendor
$vendor_data = ['name' => 'Your Vendor Company', 'description' => '', 'profile_image_path' => 'placeholder.jpg'];


if (!$conn) {
    $message = '<div class="alert alert-danger">Error: Could not connect to database.</div>';
} else {
    // --- 1. FETCH CURRENT VENDOR DATA (Read) ---
    // Change table and column names
    $sql_fetch = "SELECT vendor_name, description, profile_image_path FROM Vendors WHERE vendor_id = ?";
    if ($stmt = $conn->prepare($sql_fetch)) {
        $stmt->bind_param("i", $current_vendor_id); // Use vendor ID
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $data = $result->fetch_assoc();
            $vendor_data['name'] = htmlspecialchars($data['vendor_name']);
            $vendor_data['description'] = htmlspecialchars($data['description'] ?? '');
            // Change default placeholder path
            $vendor_data['profile_image_path'] = htmlspecialchars($data['profile_image_path'] ?? 'uploads/vendor_profiles/placeholder.jpg');
        }
        $stmt->close();
    }

    // Set the name variable for the template include
    $vendor_name = $vendor_data['name'];


    // --- 2. HANDLE PROFILE UPDATE (Only runs in POST/Edit mode) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update_profile') {

        $new_description = $_POST['description'] ?? '';
        $image_path_update = $vendor_data['profile_image_path'];

        // --- Image Upload Logic (Updated directory and placeholder) ---
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/vendor_profiles/'; // Changed directory
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
            $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $file_name = $current_vendor_id . '_' . time() . '.' . $file_extension; // Use vendor ID
            $target_file = $upload_dir . $file_name;
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array(strtolower($file_extension), $allowed_types) && move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                $image_path_update = $target_file;
                $old_path = $vendor_data['profile_image_path'];
                // Update placeholder check
                if ($old_path && !str_ends_with($old_path, 'placeholder.jpg') && file_exists($old_path)) {
                    unlink($old_path);
                }
            } else {
                $message .= '<div class="alert alert-danger">Error uploading image. Check file type or permissions.</div>';
            }
        }

        // --- Database Update ---
        // Change table name
        $sql_update = "UPDATE Vendors SET description = ?, profile_image_path = ? WHERE vendor_id = ?";

        if ($stmt = $conn->prepare($sql_update)) {
            $decoded_description = htmlspecialchars_decode($new_description);
            $stmt->bind_param("ssi", $decoded_description, $image_path_update, $current_vendor_id); // Use vendor ID

            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">Profile updated successfully!</div>';
                // Re-sync local data and switch to view mode after success
                $vendor_data['description'] = htmlspecialchars($new_description);
                $vendor_data['profile_image_path'] = htmlspecialchars($image_path_update);
                $mode = 'view';
            } else {
                $message .= '<div class="alert alert-danger">Database error updating profile: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        } else {
            $message .= '<div class="alert alert-danger">Error preparing update statement.</div>';
        }
    }
    $conn->close();
}
// --- End of PHP Logic ---

// --- TEMPLATE INCLUDE START ---
// Use the vendor-specific template (vendor_template.php)
require 'vendor_template.php';
// --- TEMPLATE INCLUDE END ---
?>

    <header class="mb-8 flex justify-between items-center border-b pb-4 border-gray-100">
        <h2 class="text-3xl font-bold text-gray-900">
            📦 Profile Settings for <?= $vendor_data['name']; ?>
        </h2>
        <a href="vendor_profile.php" class="text-gray-500 hover:text-orange-600">
            <i data-lucide="circle-user" class="w-8 h-8"></i>
        </a>
    </header>

    <div class="p-6 bg-white rounded-xl shadow-md">

        <?php echo $message; // Display status message ?>

        <?php if ($mode === 'view'): ?>

            <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-2 flex justify-between items-center">
                Public Profile Overview
                <a href="vendor_profile.php?mode=edit" class="inline-flex items-center py-2 px-4 text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 transition duration-150">
                    <i data-lucide="pencil" class="w-4 h-4 mr-2"></i>
                    Edit Profile
                </a>
            </h3>

            <div class="flex items-center space-x-6 mb-6">
                <div class="w-32 h-32 overflow-hidden rounded-full border-4 border-orange-500 flex-shrink-0 bg-gray-200 shadow-lg">
                    <img src="<?php echo $vendor_data['profile_image_path']; ?>"
                         alt="Profile Image"
                         onerror="this.onerror=null; this.src='uploads/vendor_profiles/placeholder.jpg';"
                         class="w-full h-full object-cover">
                </div>
                <div>
                    <h4 class="text-2xl font-extrabold text-gray-900"><?= $vendor_data['name'] ?></h4>
                    <p class="text-gray-500">Spare Part Vendor Portal</p>
                </div>
            </div>

            <div class="mt-8 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <h4 class="text-lg font-semibold text-gray-800 mb-2 border-b pb-2">Company Description:</h4>
                <p class="text-gray-700 whitespace-pre-wrap"><?php echo $vendor_data['description']; ?></p>
                <?php if (empty($vendor_data['description'])): ?>
                    <p class="text-gray-500 italic">No description set. Click 'Edit Profile' to add one.</p>
                <?php endif; ?>
            </div>

            <div class="mt-6 text-right">
                <a href="vendor_profile.php?mode=edit" class="inline-flex items-center py-2 px-4 text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 transition duration-150">
                    <i data-lucide="pencil" class="w-4 h-4 mr-2"></i>
                    Edit Profile
                </a>
            </div>


        <?php else: // $mode === 'edit' ?>

            <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-2">✏️ Edit Public Profile Information</h3>

            <form action="vendor_profile.php?mode=edit" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="update_profile">

                <div class="flex items-center space-x-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="w-24 h-24 overflow-hidden rounded-full border-4 border-orange-500 flex-shrink-0 bg-gray-200">
                        <img src="<?php echo $vendor_data['profile_image_path']; ?>"
                             alt="Profile Image Preview"
                             onerror="this.onerror=null; this.src='uploads/vendor_profiles/placeholder.jpg';"
                             class="w-full h-full object-cover">
                    </div>
                    <div class="flex-grow">
                        <label for="profile_image" class="block text-sm font-medium text-gray-700 mb-1">Upload Company Logo/Image:</label>
                        <input type="file" id="profile_image" name="profile_image" accept=".jpg, .jpeg, .png, .gif"
                               class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-white focus:outline-none p-1">
                        <p class="text-xs text-gray-500 mt-1">Max file size 5MB. JPG, PNG, or GIF allowed. Current name: <?= basename($vendor_data['profile_image_path']) ?></p>
                    </div>
                </div>

                <div class="space-y-1">
                    <label for="description" class="block text-sm font-medium text-gray-700">Tell Garages About Your Company:</label>
                    <textarea id="description" name="description" rows="5" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500 sm:text-sm p-3 border"><?php echo htmlspecialchars_decode($vendor_data['description']); ?></textarea>
                    <p class="text-xs text-gray-500">This description will be visible to potential garage customers.</p>
                </div>

                <div class="flex space-x-3 justify-end">
                    <a href="vendor_profile.php?mode=view" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-gray-500 hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400">
                        Cancel / View Profile
                    </a>
                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        <i data-lucide="save" class="w-5 h-5 mr-2"></i>
                        Save Profile Updates
                    </button>
                </div>
            </form>

        <?php endif; ?>
    </div>

<?php
// --- FOOTER INCLUDE START ---
// Use the vendor-specific footer (vendor_footer.php)
require 'vendor_footer.php';
// --- FOOTER INCLUDE END ---
?>