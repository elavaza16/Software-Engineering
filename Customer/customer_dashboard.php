<?php
/**
 * CAASP Customer Dashboard (Desktop/Web Layout)
 * * Reads user identity from session, enforces authentication, and displays content
 * for service/part searching and transaction history using a multi-column, desktop-first UI.
 */

require_once '../api_db_config.php';

// --- AUTHENTICATION CHECK ---
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$role = isset($_SESSION['role']) ? $_SESSION['role'] : null;

// Ensure user is logged in AND is a Customer
if (!$user_id || $role !== 'Customer') {
    // Clear session and redirect to login if unauthorized
    session_unset();
    session_destroy();
    header("Location: ../index.php?status=error&message=" . urlencode("Access denied. Please log in as a Customer."));
    exit;
}

// Determine the current view (Home, Search, History, etc.)
$current_view = isset($_GET['view']) ? $_GET['view'] : 'home'; // Default to 'home'
$status_message = isset($_GET['message']) ? $_GET['message'] : '';
$status_type = isset($_GET['status']) ? $_GET['status'] : '';


// --- SEARCH & FILTER INPUTS ---
$search_query = trim(isset($_GET['query']) ? $_GET['query'] : '');
$search_category = trim(isset($_GET['category']) ? $_GET['category'] : ''); // From Quick Access links
$search_target_type = trim(isset($_GET['target']) ? $_GET['target'] : ''); // NEW: Target type (Garage or Vendor)
$filter_show = isset($_GET['show']) ? $_GET['show'] : 'All'; // Filter for all, Garage, or Vendor

// If a search query or category is present, force the 'search' view
if (!empty($search_query) || !empty($search_category) || $filter_show !== 'All') {
    $current_view = 'search';
}


// --- DATA FETCHING FUNCTIONS ---

function fetch_customer_data($db, $user_id) {
    // Fetch all user and location data required for the profile
    $data = array('email' => 'N/A', 'contact' => 'N/A', 'city' => 'N/A', 'district' => 'N/A', 'balance' => 0.00);
    $sql = "SELECT U.email, U.contact, U.account_balance, L.city, L.district 
            FROM Users U
            LEFT JOIN Locations L ON U.location_id = L.location_id
            WHERE U.user_id = ?";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $email, $contact, $balance, $city, $district);
            if (mysqli_stmt_fetch($stmt)) {
                $data['email'] = $email;
                $data['contact'] = $contact;
                $data['balance'] = $balance;
                $data['city'] = $city;
                $data['district'] = $district;
            }
        }
        mysqli_stmt_close($stmt);
    }

    // Fetch all available cities (Kept here in case needed later, though unused in Read-Only view)
    $data['all_cities'] = array();
    $sql_cities = "SELECT DISTINCT city FROM Locations ORDER BY city ASC";
    if ($result = mysqli_query($db, $sql_cities)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data['all_cities'][] = $row['city'];
        }
        mysqli_free_result($result);
    }

    return $data;
}


/**
 * Executes a full search query based on user input (query, category, and optional target type).
 */
function execute_full_search($db, $query, $category, $target_type) {

    $results = array();
    $is_home_view = empty($query) && empty($category) && empty($target_type);

    $bind_params_master = array();
    $bind_types_master = "";

    // 1. Keyword Search (Item and Business Name)
    if (!empty($query)) {
        $bind_params_master[] = "%{$query}%"; // Item Name placeholder
        $bind_params_master[] = "%{$query}%"; // Business Name placeholder
        $bind_types_master .= "ss";
    }

    // 2. Category Filter
    if (!empty($category)) {
        $bind_params_master[] = "%{$category}%"; // Item Name placeholder
        $bind_params_master[] = "%{$category}%"; // Business Name placeholder
        $bind_types_master .= "ss";
    }

    // Determine which searches to run
    $run_garage_search = (empty($target_type) || $target_type === 'Garage');
    $run_vendor_search = (empty($target_type) || $target_type === 'Vendor');

    // --- SET ORDER BY / LIMIT ---
    if ($is_home_view) {
        $order_by_limit = "ORDER BY RAND() LIMIT 6";
    } else {
        $order_by_limit = "LIMIT 20";
    }


    // --- Helper function to build the SQL WHERE clause components ---
    function get_where_components($is_garage, $query, $category) {
        $where_parts = array();
        $params_count = 0;

        // 1. Keyword Clause
        if (!empty($query)) {
            $item_col = $is_garage ? "S.service_name" : "P.part_name";
            $biz_col = $is_garage ? "G.garage_name" : "V.vendor_name";
            // Uses two placeholders: one for item, one for business name
            $where_parts[] = "({$item_col} LIKE ? OR {$biz_col} LIKE ?)";
            $params_count += 2;
        }

        // 2. Category Clause
        if (!empty($category)) {
            $item_col = $is_garage ? "S.service_name" : "P.part_name";
            // Uses one placeholder
            $where_parts[] = "{$item_col} LIKE ?";
            $params_count += 1;
        }

        $where_clause = "WHERE " . (empty($where_parts) ? "1=1" : implode(' AND ', $where_parts));

        // Return the WHERE clause and the number of parameters it requires
        return array('clause' => $where_clause, 'count' => $params_count);
    }

    $garage_components = get_where_components(true, $query, $category);
    $vendor_components = get_where_components(false, $query, $category);

    // Determine the subset of master parameters needed for the Garage query
    $garage_bind_params = array_slice($bind_params_master, 0, $garage_components['count']);
    $garage_bind_types = substr($bind_types_master, 0, $garage_components['count']);

    // Determine the subset of master parameters needed for the Vendor query
    $vendor_bind_params = array_slice($bind_params_master, 0, $vendor_components['count']);
    $vendor_bind_types = substr($bind_types_master, 0, $vendor_components['count']);


    // --- Execute Garage Search ---
    if ($run_garage_search) {
        $sql_garage_search = "
            SELECT 
                G.garage_name AS business_name,
                S.service_name AS item_name,
                S.service_price AS price,
                L.city,
                'Garage' AS type,
                G.garage_id AS entity_id,
                U.user_id AS business_user_id,
                CASE 
                    WHEN G.profile_image_path IS NULL OR TRIM(G.profile_image_path) = '' 
                    THEN '../uploads/garage.jpg' 
                    ELSE G.profile_image_path 
                END AS image_path
            FROM Garages G
            INNER JOIN Services S ON G.garage_id = S.garage_id
            INNER JOIN Users U ON G.user_id = U.user_id
            INNER JOIN Locations L ON U.location_id = L.location_id
            {$garage_components['clause']}
            {$order_by_limit}
        ";

        if ($stmt = mysqli_prepare($db, $sql_garage_search)) {

            if (!empty($garage_bind_types) && count($garage_bind_params) > 0) {
                $refs = array();
                $refs[] = &$garage_bind_types; // Use the subset of types
                foreach ($garage_bind_params as $key => $value) {
                    $refs[] = &$garage_bind_params[$key]; // Use the subset of parameters
                }

                if (!call_user_func_array('mysqli_stmt_bind_param', array_merge(array($stmt), $refs))) {
                    error_log("Garage Bind Param Failed: " . mysqli_error($db));
                }
            }
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['rating'] = 4.5;
                    $row['reviews'] = 130;
                    $row['image'] = $row['image_path'];
                    $results[] = $row;
                }
            } else {
                error_log("Garage Search Execution Failed: " . mysqli_error($db));
            }
            mysqli_stmt_close($stmt);
        }
    }


    // --- Execute Vendor Search ---
    if ($run_vendor_search) {
        $sql_vendor_search = "
            SELECT 
                V.vendor_name AS business_name,
                P.part_name AS item_name,
                P.part_price AS price,
                L.city,
                'Vendor' AS type,
                V.vendor_id AS entity_id,
                U.user_id AS business_user_id,
                CASE 
                    WHEN V.profile_image_path IS NULL OR TRIM(V.profile_image_path) = '' 
                    THEN '../uploads/sparepart.jpg' 
                    ELSE V.profile_image_path 
                END AS image_path
            FROM Vendors V
            INNER JOIN Parts P ON V.vendor_id = P.vendor_id
            INNER JOIN Users U ON V.user_id = U.user_id
            INNER JOIN Locations L ON U.location_id = L.location_id
            {$vendor_components['clause']}
            {$order_by_limit}
        ";

        if ($stmt = mysqli_prepare($db, $sql_vendor_search)) {

            // NOTE: We reuse the SAME binding logic as the Garage search since the parameters are identical.
            if (!empty($vendor_bind_types) && count($vendor_bind_params) > 0) {
                $refs = array();
                $refs[] = &$vendor_bind_types;
                foreach ($vendor_bind_params as $key => $value) {
                    $refs[] = &$vendor_bind_params[$key];
                }

                if (!call_user_func_array('mysqli_stmt_bind_param', array_merge(array($stmt), $refs))) {
                    error_log("Vendor Bind Param Failed: " . mysqli_error($db));
                }
            }

            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                while ($row = mysqli_fetch_assoc($result)) {
                    $row['rating'] = 4.2;
                    $row['reviews'] = 85;
                    $row['image'] = $row['image_path'];
                    $results[] = $row;
                }
            } else {
                error_log("Vendor Search Execution Failed: " . mysqli_error($db));
            }
            mysqli_stmt_close($stmt);
        }
    }

    return $results;
}

/**
 * Fetches the transaction history for the logged-in user.
 */
function fetch_transaction_history($db, $user_id) {
    $transactions = array();

    $sql = "
        SELECT
            T.transaction_id AS id,
            T.transaction_amount AS amount,
            T.status,
            T.created_at AS date,
            T.target_garage_id,
            T.target_vendor_id,
            T.service_id,
            T.part_id,
            
            IFNULL(G.garage_name, V.vendor_name) AS business,
            IFNULL(S.service_name, P.part_name) AS description,
            CASE
                WHEN T.service_id IS NOT NULL THEN 'Service'
                WHEN T.part_id IS NOT NULL THEN 'Part Order'
                ELSE 'Unknown'
            END AS type,
            IFNULL(GU.user_id, VU.user_id) AS target_user_id,
            R.rating_id IS NOT NULL AS has_rated

        FROM Transactions T
        LEFT JOIN Services S ON T.service_id = S.service_id
        LEFT JOIN Parts P ON T.part_id = P.part_id
        
        LEFT JOIN Garages G ON T.target_garage_id = G.garage_id
        LEFT JOIN Vendors V ON T.target_vendor_id = V.vendor_id
        
        LEFT JOIN Users GU ON G.user_id = GU.user_id
        LEFT JOIN Users VU ON V.user_id = VU.user_id

        LEFT JOIN Ratings R ON T.transaction_id = R.transaction_id
        
        WHERE T.initiator_user_id = ?
        ORDER BY T.created_at DESC
    ";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $row['can_rate'] = ($row['status'] === 'Completed' && !$row['has_rated']);
                $row['date'] = date('Y-m-d', strtotime($row['date']));
                $transactions[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }

    return $transactions;
}


$db = connect_db();
$customer_data = $db ? fetch_customer_data($db, $user_id) : array('email' => 'Database Offline', 'balance' => 0.00, 'contact' => 'N/A', 'city' => 'N/A', 'district' => 'N/A', 'all_cities' => array());
$customer_email = $customer_data['email'];
$customer_contact = $customer_data['contact'];
$customer_city = $customer_data['city'];
$customer_district = $customer_data['district'];
$all_cities = $customer_data['all_cities'];
$customer_balance = $customer_data['balance'];

$search_results = array();
$transaction_history = array();

if ($db) {
    if (in_array($current_view, array('home', 'search'))) {
        $search_results = execute_full_search($db, $search_query, $search_category, $search_target_type);
    } elseif ($current_view === 'history') {
        $transaction_history = fetch_transaction_history($db, $user_id);
    }
    mysqli_close($db);
}

if (empty($search_results) && ($current_view === 'home' || $current_view === 'search')) {
    $no_results_message = "We couldn't find any listings matching your criteria. Try broadening your search or check back later!";
}


// --- START HTML TEMPLATE INCLUDES ---
include 'customer_template_header.php';
?>

<?php if ($current_view === 'profile'): ?>
    <section class="max-w-3xl mx-auto p-8 bg-white rounded-xl shadow-lg border-t-4 border-blue-500">
        <h3 class="text-3xl font-bold mb-6 text-gray-900">
            <i data-lucide="circle-user" class="w-7 h-7 mr-2 inline-block"></i> Profile & Security Settings
        </h3>

        <p class="text-gray-600 mb-6 border-b pb-4">
            Manage your account details and security settings.
            Your current status is: <span class="font-semibold text-blue-600"><?= htmlspecialchars($customer_email) ?> (Customer)</span>.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

            <div>
                <h4 class="text-xl font-semibold mb-3 text-gray-700 border-b pb-2">Personal Details</h4>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-500 mb-1">Email Address</label>
                        <p class="p-3 bg-gray-100 rounded-lg font-mono text-sm text-gray-700 border border-gray-300"><?= htmlspecialchars($customer_email) ?></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contact (Phone)</label>
                        <p class="p-3 bg-gray-100 rounded-lg font-mono text-sm text-gray-700 border border-gray-300">
                            <?= htmlspecialchars($customer_contact) ?>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                        <p class="p-3 bg-gray-100 rounded-lg font-mono text-sm text-gray-700 border border-gray-300">
                            <?= htmlspecialchars($customer_city) ?>
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">District/Area</label>
                        <p class="p-3 bg-gray-100 rounded-lg font-mono text-sm text-gray-700 border border-gray-300">
                            <?= htmlspecialchars($customer_district) ?>
                        </p>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="text-xl font-semibold mb-3 text-gray-700 border-b pb-2">Security & Wallet</h4>

                <div class="mb-6 p-4 rounded-lg bg-green-50 border border-green-300">
                    <p class="text-sm font-medium text-green-700">Current Wallet Balance</p>
                    <span class="text-3xl font-extrabold text-green-600">KES <?= number_format($customer_balance, 2) ?></span>
                </div>

            </div>
        </div>
    </section>

<?php endif; ?>

<?php if ($current_view === 'home' || $current_view === 'search'): ?>

    <section class="mb-10 p-6 bg-white rounded-xl shadow-lg border-t-4 border-emerald-500">
        <h3 class="text-xl font-bold mb-4 text-gray-800">Find Your Service or Part</h3>
        <form method="GET" action="customer_dashboard.php">
            <input type="hidden" name="view" value="search">
            <div class="flex space-x-3">
                <div class="relative flex-grow">
                    <i data-lucide="search" class="w-5 h-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="query" placeholder="Search for garage services or spare parts by keyword"
                           value="<?= htmlspecialchars($search_query) ?>"
                           class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                </div>

                <button type="submit" class="bg-emerald-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-emerald-700 transition duration-150">Search</button>
            </div>
        </form>
    </section>

    <section class="mb-10">

        <div class="flex space-x-4 mb-6">
            <a href="?view=search&show=All" class="px-5 py-2 rounded-full font-semibold transition <?= $filter_show === 'All' && !$search_query ? 'bg-emerald-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50' ?>">
                All Listings
            </a>
            <a href="?view=search&show=Garage" class="px-5 py-2 rounded-full font-semibold transition <?= $filter_show === 'Garage' ? 'bg-emerald-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50' ?>">
                Garages Only
            </a>
            <a href="?view=search&show=Vendor" class="px-5 py-2 rounded-full font-semibold transition <?= $filter_show === 'Vendor' ? 'bg-emerald-600 text-white shadow-md' : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50' ?>">
                Spare Parts Only
            </a>
        </div>

        <h3 class="2xl font-bold mb-5 text-gray-800 border-b pb-2">
            <?php
            if ($current_view === 'search' && empty($search_query) && $filter_show === 'All') {
                echo 'Featured Businesses';
            } elseif (!empty($search_query) || $search_category) {
                echo 'Search Results';
            } else {
                echo $filter_show . ' Listings';
            }
            ?> (<?= count($search_results) ?> Found)
        </h3>

        <?php if (!empty($search_results)): ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <?php foreach ($search_results as $biz): // Looping over search results ?>
                    <?php
                    // Apply the show filter to the rendered loop
                    if ($filter_show !== 'All' && $biz['type'] !== $filter_show) continue;
                    ?>
                    <div class="business-card bg-white rounded-xl overflow-hidden flex shadow-lg">
                        <div class="w-1/3 h-40 bg-gray-200" style="background-image: url('<?= $biz['image'] ?>'); background-size: cover; background-position: center;">
                        </div>
                        <div class="w-2/3 p-4 flex flex-col justify-between">
                            <div>
                                <div class="flex justify-between items-start">
                                    <h4 class="text-xl font-bold text-gray-900"><?= htmlspecialchars($biz['business_name']) ?></h4>
                                    <span class="text-xs font-medium px-3 py-1 rounded-full <?php echo $biz['type'] === 'Garage' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'; ?>">
                                            <?= $biz['type'] ?>
                                        </span>
                                </div>
                                <p class="text-sm font-medium text-gray-700 mt-1"><?= htmlspecialchars($biz['item_name']) ?></p>
                                <p class="text-sm text-gray-600 flex items-center">
                                    <i data-lucide="map-pin" class="w-4 h-4 mr-1 text-gray-400"></i> <?= $biz['city'] ?>
                                </p>
                            </div>
                            <div class="flex justify-between items-end">
                                <p class="text-lg font-bold <?php echo $biz['type'] === 'Garage' ? 'text-emerald-600' : 'text-red-600'; ?>">
                                    KES <?= number_format($biz['price'], 2) ?>
                                </p>
                                <a href="business_profile.php?type=<?= $biz['type'] ?>&id=<?= $biz['entity_id'] ?>"
                                   class="bg-blue-600 text-white px-5 py-2 rounded-lg font-semibold hover:bg-blue-700 transition duration-150 text-sm">
                                    View Profile
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: // No search results found ?>
            <div class="p-6 bg-yellow-100 border border-yellow-300 text-yellow-800 rounded-lg shadow-md">
                <p class="font-semibold text-lg mb-2">No Listings Found</p>
                <p><?= isset($no_results_message) ? $no_results_message : "We couldn't find any listings currently available in the system." ?></p>
            </div>
        <?php endif; ?>

    </section>

    <?php if ($current_view === 'home' || $current_view === 'search'): ?>
        <section class="mt-8">
            <h3 class="2xl font-bold mb-5 text-gray-800 border-b pb-2">Quick Access Categories</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php
                $quick_links = [
                        ['title' => 'Tires & Alignment', 'icon' => 'gauge', 'color' => 'blue', 'keyword' => 'tire', 'target' => 'Garage'],
                        ['title' => 'Oil Change Services', 'icon' => 'droplet', 'color' => 'red', 'keyword' => 'oil', 'target' => 'Garage'],
                        ['title' => 'Brake Systems', 'icon' => 'disc-3', 'color' => 'purple', 'keyword' => 'brake', 'target' => 'Vendor'],
                        ['title' => 'Engine Parts', 'icon' => 'settings', 'color' => 'yellow', 'keyword' => 'engine', 'target' => 'Vendor'],
                ];
                foreach ($quick_links as $link):
                    ?>
                    <a href="?view=search&category=<?= $link['keyword'] ?>&target=<?= $link['target'] ?>" class="icon-box p-4 rounded-xl flex flex-col items-center bg-white shadow-md hover:shadow-lg transition duration-200 text-center">
                        <div class="w-12 h-12 bg-<?= $link['color'] ?>-100 rounded-full flex items-center justify-center mb-3">
                            <i data-lucide="<?= $link['icon'] ?>" class="w-6 h-6 text-<?= $link['color'] ?>-600"></i>
                        </div>
                        <p class="font-semibold text-sm text-gray-800"><?= $link['title'] ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>


<?php elseif ($current_view === 'history'): ?>
    <section class="p-6 bg-white rounded-xl shadow-lg">
        <h3 class="2xl font-bold mb-5 text-gray-800 border-b pb-2">Transaction History</h3>
        <div class="mb-6 p-4 bg-gray-100 rounded-lg flex justify-between items-center">
            <div class="text-xl font-semibold text-gray-800 flex items-center">
                <i data-lucide="wallet" class="w-6 h-6 mr-3 text-blue-600"></i>
                Current Balance: <span class="ml-2 text-blue-600">KES <?= number_format($customer_balance, 2) ?></span>
            </div>
            <form action="transaction_handler.php" method="POST" class="flex space-x-2">
                <input type="hidden" name="action" value="recharge">
                <input type="number" name="recharge_amount" placeholder="Amount (KES)" required min="100" step="100"
                       class="p-2 border border-gray-300 rounded-lg w-36 text-sm focus:ring-blue-500 focus:border-blue-500">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg font-semibold hover:bg-blue-700 transition text-sm">Recharge Account</button>
            </form>
        </div>
        <div class="space-y-4">
            <p class="text-gray-600">Below is a list of your past service and part transactions.</p>

            <div class="overflow-x-auto shadow-md rounded-lg">
                <table class="min-w-full bg-white border-collapse">
                    <thead class="bg-gray-100">
                    <tr class="text-left text-sm font-semibold text-gray-600 uppercase tracking-wider">
                        <th class="p-4 border-b">T-ID</th>
                        <th class="p-4 border-b">Item</th>
                        <th class="p-4 border-b">Business</th>
                        <th class="p-4 border-b">Amount</th>
                        <th class="p-4 border-b">Status</th>
                        <th class="p-4 border-b">Date</th>
                        <th class="p-4 border-b">Action</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                    <?php if (!empty($transaction_history)): ?>
                        <?php foreach ($transaction_history as $transaction): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-4 font-mono text-xs text-gray-700"><?= htmlspecialchars($transaction['id']) ?></td>
                                <td class="p-4 text-sm font-medium <?php echo $transaction['type'] === 'Service' ? 'text-blue-600' : 'text-purple-600'; ?>"><?= htmlspecialchars($transaction['description']) ?> </td>
                                <td class="p-4 text-sm"><?= htmlspecialchars($transaction['business']) ?> (<?= $transaction['type'] ?>)</td>
                                <td class="p-4 text-sm font-bold text-gray-700">KES <?= number_format($transaction['amount'], 2) ?></td>
                                <td class="p-4">
                                    <?php
                                    $status_class = 'bg-gray-100 text-gray-800';
                                    if (strpos($transaction['status'], 'Completed') !== false) {
                                        $status_class = 'bg-green-100 text-green-800';
                                    } elseif (strpos($transaction['status'], 'Pending') !== false) {
                                        $status_class = 'bg-blue-100 text-blue-800';
                                    } elseif (strpos($transaction['status'], 'Cancelled') !== false) {
                                        $status_class = 'bg-red-100 text-red-800';
                                    }
                                    ?>
                                    <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $status_class ?>"><?= htmlspecialchars($transaction['status']) ?></span>
                                </td>
                                <td class="p-4 text-sm"><?= htmlspecialchars($transaction['date']) ?></td>
                                <td class="p-4 space-x-2">
                                    <?php if ($transaction['status'] === 'Completed' && !$transaction['has_rated']): ?>
                                        <form action="transaction_handler.php" method="POST" class="inline-block">
                                            <input type="hidden" name="action" value="rate_init">
                                            <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                                            <input type="hidden" name="target_user_id" value="<?= $transaction['target_user_id'] ?>">
                                            <button type="submit" class="bg-yellow-500 text-white px-3 py-1 rounded-lg text-sm hover:bg-yellow-600">⭐ Rate</button>
                                        </form>

                                    <?php elseif ($transaction['status'] === 'Completed' && $transaction['has_rated']): ?>
                                        <button class="bg-gray-400 text-white px-3 py-1 rounded-lg text-sm cursor-not-allowed" disabled>Rated</button>

                                    <?php elseif ($transaction['status'] === 'Pending' && $transaction['target_user_id']): ?>
                                        <form action="transaction_handler.php" method="POST" class="inline-block">
                                            <input type="hidden" name="action" value="finalize">
                                            <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                                            <?php if ($customer_balance >= $transaction['amount']): ?>
                                                <button type="submit" class="bg-green-600 text-white px-3 py-1 rounded-lg text-sm hover:bg-green-700">✅ Pay KES <?= number_format($transaction['amount'], 2) ?></button>
                                            <?php else: ?>
                                                <button type="button" class="bg-red-500 text-white px-3 py-1 rounded-lg text-sm cursor-not-allowed" disabled>❌ Insufficient Funds</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="p-4 text-center text-gray-500">You have no transaction history yet.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>


<?php elseif ($current_view === 'rate'): ?>
    <section class="max-w-xl mx-auto p-8 bg-white rounded-xl shadow-lg border-t-4 border-yellow-500">
        <h3 class="text-3xl font-bold mb-6 text-gray-900 flex items-center">
            <i data-lucide="star" class="w-6 h-6 mr-2 text-yellow-500"></i> Submit Review
        </h3>

        <?php
        $t_id = (int)(isset($_GET['t_id']) ? $_GET['t_id'] : 0);
        $biz_id = (int)(isset($_GET['biz_id']) ? $_GET['biz_id'] : 0);
        $biz_name = htmlspecialchars(isset($_GET['biz_name']) ? $_GET['biz_name'] : 'Business');

        if ($t_id === 0 || $biz_id === 0): ?>
            <div class="p-4 bg-red-100 text-red-800 rounded-lg">Invalid rating request. Please rate from the History tab.</div>
        <?php else: ?>

            <p class="text-gray-700 mb-4">You are rating *<?= $biz_name ?>* for Transaction ID: *T-<?= $t_id ?>*.</p>
            <p class="text-sm text-gray-500 mb-6">Your feedback is important!</p>

            <form action="transaction_handler.php" method="POST">
                <input type="hidden" name="action" value="rate_submit">
                <input type="hidden" name="transaction_id" value="<?= $t_id ?>">
                <input type="hidden" name="target_user_id" value="<?= $biz_id ?>">

                <div class="mb-6">
                    <label class="block text-lg font-semibold text-gray-800 mb-2">Rating</label>
                    <select name="rating_value" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-yellow-500 focus:border-yellow-500 bg-white text-gray-700">
                        <option value="" disabled selected>Select a rating...</option>
                        <option value="5">★★★★★ (5 Stars) - Excellent</option>
                        <option value="4">★★★★☆ (4 Stars) - Good</option>
                        <option value="3">★★★☆☆ (3 Stars) - Average</option>
                        <option value="2">★★☆☆☆ (2 Stars) - Below Average</option>
                        <option value="1">★☆☆☆☆ (1 Star) - Poor</option>
                    </select>
                </div>

                <div class="mb-6">
                    <label for="review_text" class="block text-lg font-semibold text-gray-800 mb-2">Detailed Review</label>
                    <textarea id="review_text" name="review_text" rows="4" maxlength="500" placeholder="What did you think of the service/part?"
                              class="w-full p-3 border border-gray-300 rounded-lg focus:ring-yellow-500 focus:border-yellow-500"></textarea>
                </div>

                <button type="submit" class="w-full bg-yellow-500 text-white py-3 rounded-lg font-semibold hover:bg-yellow-600 transition duration-150 shadow-md">
                    Submit Rating
                </button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php
include 'customer_template_footer.php';
// --- END HTML TEMPLATE INCLUDES ---
?>
<script>
    // Function to toggle the sidebar state
    function toggleSidebar() {
        const body = document.body;
        const isCollapsed = body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarState', isCollapsed ? 'collapsed' : 'open');
    }

    // Load saved state on page load
    window.onload = function() {
        lucide.createIcons();
        const savedState = localStorage.getItem('sidebarState');
        if (savedState === 'collapsed') {
            document.body.classList.add('sidebar-collapsed');
        }
    };
</script>