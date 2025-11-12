<?php
/**
 * CAASP Business Profile Viewer
 * * Displays detailed profile information for a specific Garage or Vendor.
 * * Allows customers to initiate chat and view listings/reviews.
 */

require_once '../api_db_config.php';

// --- AUTHENTICATION CHECK ---
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$role = isset($_SESSION['role']) ? $_SESSION['role'] : null;

if (!$user_id || $role !== 'Customer') {
    session_unset();
    session_destroy();
    header("Location: index.php?status=error&message=" . urlencode("Access denied. Please log in."));
    exit;
}

// --- INPUT VALIDATION ---
$type = isset($_GET['type']) ? $_GET['type'] : '';
$entity_id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);

if (!in_array($type, ['Garage', 'Vendor']) || $entity_id === 0) {
    header("Location: customer_dashboard.php?status=error&message=" . urlencode("Invalid business profile requested."));
    exit;
}


// --- DATA FETCHING FUNCTIONS ---

/**
 * Executes a query to fetch full business details based on type and ID.
 * This query also fetches the critical U.user_id (the chat target).
 */
function fetch_business_details($db, $type, $entity_id) {
    $business = null;
    $listings = [];
    $reviews = [];

    // Base fields common to both Garages and Vendors
    $base_fields = "B.user_id AS owner_user_id, L.city, L.district, U.contact, U.email";

    // --- QUERY 1: Fetch Business Details and Owner ID ---
    if ($type === 'Garage') {
        $sql = "SELECT B.garage_name AS name, {$base_fields} FROM Garages B JOIN Users U ON B.user_id = U.user_id JOIN Locations L ON U.location_id = L.location_id WHERE B.garage_id = ?";
    } else { // Vendor
        $sql = "SELECT B.vendor_name AS name, {$base_fields} FROM Vendors B JOIN Users U ON B.user_id = U.user_id JOIN Locations L ON U.location_id = L.location_id WHERE B.vendor_id = ?";
    }

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $entity_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $business = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }

    if (!$business) {
        return ['error' => 'Business not found.'];
    }

    // --- QUERY 2: Fetch Listings (Services or Parts) ---
    // NOTE: We need the ID of the item (service_id/part_id) to place the order
    if ($type === 'Garage') {
        $sql_listings = "SELECT service_id AS item_id, service_name AS name, service_price AS price FROM Services WHERE garage_id = ?";
    } else { // Vendor
        $sql_listings = "SELECT part_id AS item_id, part_name AS name, part_price AS price FROM Parts WHERE vendor_id = ?";
    }

    if ($stmt = mysqli_prepare($db, $sql_listings)) {
        mysqli_stmt_bind_param($stmt, "i", $entity_id);
        mysqli_stmt_execute($stmt);
        $listings = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    }

    // --- QUERY 3: Fetch Reviews (Mock data for now, but ready for logic) ---
    // In a final app, this would join Ratings, Users, and the specific entity table.
    $reviews = [
            ['user' => 'Jane Doe', 'rating' => 5, 'comment' => 'Fantastic service! Quick oil change.', 'date' => '2025-10-15'],
            ['user' => 'John Smith', 'rating' => 4, 'comment' => ($type === 'Garage' ? 'A bit pricey but excellent work.' : 'Part arrived quickly and as described.'), 'date' => '2025-09-20'],
    ];


    return [
            'details' => $business,
            'listings' => $listings,
            'reviews' => $reviews
    ];
}


$db = connect_db();
$data = fetch_business_details($db, $type, $entity_id);
mysqli_close($db);

if (isset($data['error'])) {
    // If business not found, redirect gracefully
    header("Location: customer_dashboard.php?status=error&message=" . urlencode($data['error']));
    exit;
}

$business = $data['details'];
$listings = $data['listings'];
$reviews = $data['reviews'];
$chat_target_id = $business['owner_user_id'];
$listing_type = $type === 'Garage' ? 'Services' : 'Spare Parts';

// --- HTML OUTPUT ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($business['name']) ?> Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .tab-button.active { border-color: #10B981; color: #10B981; font-weight: 600; }
    </style>
</head>
<body class="p-8">

<div class="max-w-4xl mx-auto bg-white shadow-xl rounded-xl overflow-hidden">

    <!-- Header Section -->
    <div class="p-8 <?= $type === 'Garage' ? 'bg-emerald-600' : 'bg-red-600' ?> text-white">
        <a href="customer_dashboard.php" class="text-sm font-medium hover:text-gray-200 transition mb-4 inline-flex items-center">
            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Back to Search
        </a>
        <div class="flex justify-between items-start mt-2">
            <div>
                <h1 class="text-4xl font-extrabold mb-1"><?= htmlspecialchars($business['name']) ?></h1>
                <p class="text-lg font-medium opacity-90"><?= $type ?> | <?= $business['city'] ?></p>
            </div>

            <div class="text-right">
                    <span class="text-3xl font-bold flex items-center">
                        4.4 <i data-lucide="star" class="w-6 h-6 ml-1 fill-yellow-300 text-yellow-300"></i>
                    </span>
                <p class="text-sm opacity-90">(150 Reviews)</p>
            </div>
        </div>

        <!-- Main Action Button (Removed Chat, now just informative) -->
        <div class="mt-6 flex space-x-4">
            <button class="bg-white text-gray-900 px-6 py-3 rounded-full font-bold shadow-lg hover:bg-gray-100 transition duration-200 flex items-center cursor-default">
                <i data-lucide="info" class="w-5 h-5 mr-2"></i> View Listings Below
            </button>

            <!-- This button is now purely decorative/informative -->
            <button class="border border-white text-white px-6 py-3 rounded-full font-semibold opacity-90 transition duration-200 cursor-default">
                <?= $type === 'Garage' ? 'Service Menu' : 'Parts Catalog' ?>
            </button>
        </div>
    </div>

    <!-- Contact and Tabs Area -->
    <div class="p-8">
        <div class="flex justify-between items-center mb-6 pb-4 border-b">
            <div class="flex space-x-8 text-sm text-gray-600">
                <div class="flex items-center">
                    <i data-lucide="mail" class="w-4 h-4 mr-2 text-gray-500"></i>
                    <?= htmlspecialchars($business['email']) ?>
                </div>
                <div class="flex items-center">
                    <i data-lucide="phone" class="w-4 h-4 mr-2 text-gray-500"></i>
                    <?= htmlspecialchars($business['contact']) ?>
                </div>
                <div class="flex items-center">
                    <i data-lucide="map-pin" class="w-4 h-4 mr-2 text-gray-500"></i>
                    <?= htmlspecialchars($business['district']) ?>, <?= htmlspecialchars($business['city']) ?>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="flex space-x-4 mb-6 border-b">
            <button id="tab-listings" class="tab-button border-b-2 pb-2 px-3 border-transparent text-gray-600 active" onclick="showTab('listings')">
                <?= $listing_type ?>
            </button>
            <button id="tab-reviews" class="tab-button border-b-2 pb-2 px-3 border-transparent text-gray-600" onclick="showTab('reviews')">
                Reviews (<?= count($reviews) ?>)
            </button>
        </div>

        <!-- Tab Content -->
        <div id="listings-content" class="tab-content space-y-4">
            <h3 class="text-2xl font-bold mb-4 text-gray-800"><?= $listing_type ?> Offered</h3>
            <?php if (!empty($listings)): ?>
                <div class="space-y-4">
                    <?php foreach ($listings as $item): ?>
                        <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg border">
                            <div>
                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($item['name']) ?></p>
                                <span class="text-lg font-bold <?= $type === 'Garage' ? 'text-emerald-600' : 'text-red-600' ?>">
                                        KES <?= number_format($item['price'], 2) ?>
                                    </span>
                            </div>
                            <!-- NEW: Order/Request Button next to the specific item -->
                            <form action="transaction_handler.php" method="POST">
                                <input type="hidden" name="action" value="request">
                                <input type="hidden" name="item_type" value="<?= $type === 'Garage' ? 'Service' : 'Part' ?>">
                                <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                <input type="hidden" name="business_type" value="<?= $type ?>">
                                <input type="hidden" name="business_id" value="<?= $entity_id ?>">
                                <input type="hidden" name="amount" value="<?= $item['price'] ?>">

                                <button type="submit"
                                        class="bg-blue-500 text-white px-4 py-2 rounded-lg font-semibold hover:bg-blue-600 transition duration-150 text-sm flex items-center space-x-1">
                                    <i data-lucide="<?= $type === 'Garage' ? 'wrench' : 'shopping-cart' ?>" class="w-4 h-4"></i>
                                    <span><?= $type === 'Garage' ? 'Request Service' : 'Place Order' ?></span>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-gray-500">No <?= strtolower($listing_type) ?> currently listed by this business.</p>
            <?php endif; ?>
        </div>

        <div id="reviews-content" class="tab-content space-y-6 hidden">
            <h3 class="text-2xl font-bold mb-4 text-gray-800">Customer Reviews</h3>
            <?php foreach ($reviews as $review): ?>
                <div class="border-b pb-4">
                    <div class="flex justify-between items-center">
                        <p class="font-semibold text-gray-800 flex items-center">
                            <?= htmlspecialchars($review['user']) ?>
                        </p>
                        <span class="text-md font-bold text-yellow-500 flex items-center">
                                <?= $review['rating'] ?> <i data-lucide="star" class="w-4 h-4 ml-1 fill-yellow-500 text-yellow-500"></i>
                            </span>
                    </div>
                    <p class="text-gray-600 mt-2 italic">"<?= htmlspecialchars($review['comment']) ?>"</p>
                    <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($review['date']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- JavaScript for Tab Switching -->
<script>
    function showTab(tabId) {
        // Hide all tab content
        document.querySelectorAll('.tab-content').forEach(el => {
            el.classList.add('hidden');
        });
        // Deactivate all tab buttons
        document.querySelectorAll('.tab-button').forEach(el => {
            el.classList.remove('active');
        });

        // Show the selected tab content
        document.getElementById(tabId + '-content').classList.remove('hidden');
        // Activate the selected tab button
        document.getElementById('tab-' + tabId).classList.add('active');
    }

    window.onload = function() {
        lucide.createIcons();
        // Automatically switch to the Listings tab and make sure the class is set
        showTab('listings');
    };
</script>
</body>
</html>