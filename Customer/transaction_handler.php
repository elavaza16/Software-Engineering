<?php
/**
 * CAASP Transaction Handler
 * * Processes POST requests for service/part requests, payment finalization, account recharge, and rating submissions.
 */

require_once '../api_db_config.php';

// --- AUTHENTICATION CHECK ---
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
if (!$user_id) {
    header("Location: index.php?status=error&message=" . urlencode("Session expired. Please log in to complete your transaction."));
    exit;
}

// Helper function to safely redirect back to the dashboard with a status
function redirect_to_dashboard($status, $message, $view = 'history') {
    header("Location: customer_dashboard.php?view={$view}&status=" . urlencode($status) . "&message=" . urlencode($message));
    exit;
}

// Helper function to fetch current balance
function get_current_balance($db, $user_id) {
    $balance = 0.00;
    $sql = "SELECT account_balance FROM Users WHERE user_id = ?";
    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $balance);
            mysqli_stmt_fetch($stmt);
        }
        mysqli_stmt_close($stmt);
    }
    return $balance;
}


// Ensure it's a POST request (except for the initial redirect action)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (isset($_POST['action']) ? $_POST['action'] : '') !== 'rate_init') {
    redirect_to_dashboard('error', 'Invalid transaction request method.');
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$db = connect_db();
if (!$db) {
    redirect_to_dashboard('error', 'Database connection failed. Cannot process transaction.');
}


// ====================================================================
// --- D. PROCESS RATING INITIALIZATION (Redirect to Form) ---
// ====================================================================
if ($action === 'rate_init') {
    $transaction_id = (int)(isset($_POST['transaction_id']) ? $_POST['transaction_id'] : 0);
    $target_user_id = (int)(isset($_POST['target_user_id']) ? $_POST['target_user_id'] : 0);

    if ($transaction_id === 0 || $target_user_id === 0) {
        redirect_to_dashboard('error', 'Invalid data for rating. Please retry from history.');
    }

    // Fetch item/business name to display on the rating form
    $sql_fetch_name = "
        SELECT 
            IFNULL(G.garage_name, V.vendor_name) AS business_name
        FROM Transactions T
        LEFT JOIN Garages G ON T.target_garage_id = G.garage_id
        LEFT JOIN Vendors V ON T.target_vendor_id = V.vendor_id
        WHERE T.transaction_id = ?
    ";

    $biz_name = 'Business';
    if ($stmt = mysqli_prepare($db, $sql_fetch_name)) {
        mysqli_stmt_bind_param($stmt, "i", $transaction_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $biz_name = $row['business_name'];
        }
        mysqli_stmt_close($stmt);
    }
    mysqli_close($db);

    // Redirect to the dashboard in 'rate' view with required IDs
    header("Location: customer_dashboard.php?view=rate&t_id={$transaction_id}&biz_id={$target_user_id}&biz_name=" . urlencode($biz_name));
    exit;
}
// ====================================================================
// --- E. PROCESS RATING SUBMISSION ---
// ====================================================================
elseif ($action === 'rate_submit') {
    $transaction_id = (int)(isset($_POST['transaction_id']) ? $_POST['transaction_id'] : 0);
    $target_user_id = (int)(isset($_POST['target_user_id']) ? $_POST['target_user_id'] : 0);
    $rating_value = (int)(isset($_POST['rating_value']) ? $_POST['rating_value'] : 0);
    $review_text = trim(isset($_POST['review_text']) ? $_POST['review_text'] : '');

    if ($transaction_id === 0 || $target_user_id === 0 || $rating_value < 1 || $rating_value > 5) {
        redirect_to_dashboard('error', 'Invalid rating data submitted.', 'rate');
    }

    // 1. Determine if the rating is for a Garage or Vendor
    $biz_id = null;
    $biz_id_col = null;
    $type_sql = "SELECT target_garage_id, target_vendor_id FROM Transactions WHERE transaction_id = ?";

    if ($stmt = mysqli_prepare($db, $type_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $transaction_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $garage_id, $vendor_id);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        if ($garage_id !== null) {
            $biz_id = $garage_id;
            $biz_id_col = 'garage_id';
        } elseif ($vendor_id !== null) {
            $biz_id = $vendor_id;
            $biz_id_col = 'vendor_id';
        }
    }

    if ($biz_id === null) {
        redirect_to_dashboard('error', 'Could not determine business type for rating.', 'rate');
    }

    // 2. Insert Rating into the Ratings table
    $review_text = mysqli_real_escape_string($db, $review_text);

    // Build SQL dynamically for either garage_id or vendor_id column
    $sql_insert = "INSERT INTO Ratings (rating_value, review, created_at, user_id, {$biz_id_col}, transaction_id) 
                   VALUES (?, ?, NOW(), ?, ?, ?)";

    if ($stmt = mysqli_prepare($db, $sql_insert)) {
        // Bind parameters: (i=rating_value, s=review, i=user_id, i=biz_id, i=transaction_id)
        mysqli_stmt_bind_param($stmt, "isiii", $rating_value, $review_text, $user_id, $biz_id, $transaction_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_dashboard('success', "Thank you! Your {$rating_value}-star rating has been successfully submitted.");
        } else {
            $error = mysqli_error($db);
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            error_log("Rating insertion failed: " . $error);
            redirect_to_dashboard('error', 'Rating failed: Database error during submission.');
        }
    } else {
        $error = mysqli_error($db);
        mysqli_close($db);
        error_log("Rating prepare failed: " . $error);
        redirect_to_dashboard('error', 'Internal server error during rating setup.');
    }

}
// ====================================================================

// --- A. PROCESS SERVICE/PART REQUEST ---
elseif ($action === 'request') {
    // Input collection and validation logic remains the same...
    $item_type = isset($_POST['item_type']) ? $_POST['item_type'] : '';
    $item_id = (int)(isset($_POST['item_id']) ? $_POST['item_id'] : 0);
    $business_type = isset($_POST['business_type']) ? $_POST['business_type'] : '';
    $business_id = (int)(isset($_POST['business_id']) ? $_POST['business_id'] : 0);
    $amount = (float)(isset($_POST['amount']) ? $_POST['amount'] : 0.0);

    if ($item_id === 0 || $business_id === 0 || $amount <= 0 || !in_array($item_type, ['Service', 'Part']) || !in_array($business_type, ['Garage', 'Vendor'])) {
        redirect_to_dashboard('error', 'Missing or invalid transaction details. Please select an item.');
    }

    // Determine SQL fields
    $item_id_field = $item_type === 'Service' ? 'service_id' : 'part_id';
    $business_id_field = $business_type === 'Garage' ? 'target_garage_id' : 'target_vendor_id';

    // TRANSACTION INSERTION (Status set to 'Pending')
    $sql = "INSERT INTO Transactions (initiator_user_id, {$item_id_field}, transaction_amount, {$business_id_field}, status) 
            VALUES (?, ?, ?, ?, 'Pending')";

    if ($stmt = mysqli_prepare($db, $sql)) {
        mysqli_stmt_bind_param($stmt, "iidi", $user_id, $item_id, $amount, $business_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            mysqli_close($db);

            $success_message = $item_type === 'Service' ?
                "Service Request Placed Successfully! The {$business_type} will be in contact." :
                "Part Order Placed Successfully! Awaiting confirmation from the {$business_type}.";

            redirect_to_dashboard('success', $success_message);

        } else {
            error_log("Transaction failed: " . mysqli_error($db));
            mysqli_stmt_close($stmt);
            mysqli_close($db);
            redirect_to_dashboard('error', 'Transaction processing failed: Database insertion error.');
        }
    } else {
        error_log("Transaction prepare failed: " . mysqli_error($db));
        mysqli_close($db);
        redirect_to_dashboard('error', 'Internal server error during transaction setup.');
    }
}
// --- B. PROCESS ACCOUNT RECHARGE ---
elseif ($action === 'recharge') {
    $recharge_amount = (float)(isset($_POST['recharge_amount']) ? $_POST['recharge_amount'] : 0.0);

    if ($recharge_amount <= 0) {
        redirect_to_dashboard('error', 'Please enter a valid amount to recharge.');
    }

    // Begin transaction for safe balance update
    mysqli_begin_transaction($db);

    try {
        // 1. Update the user's balance
        $sql_update = "UPDATE Users SET account_balance = account_balance + ? WHERE user_id = ?";
        if ($stmt = mysqli_prepare($db, $sql_update)) {
            mysqli_stmt_bind_param($stmt, "di", $recharge_amount, $user_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to update account balance.");
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Internal error during recharge preparation.");
        }

        mysqli_commit($db);
        mysqli_close($db);
        redirect_to_dashboard('success', "Account successfully recharged with KES " . number_format($recharge_amount, 2));

    } catch (Exception $e) {
        mysqli_rollback($db);
        mysqli_close($db);
        redirect_to_dashboard('error', 'Recharge failed: ' . $e->getMessage());
    }

}
// --- C. PROCESS PAYMENT FINALIZATION (Deduct from Customer & Deposit to Business) ---
elseif ($action === 'finalize') {
    $transaction_id = (int)(isset($_POST['transaction_id']) ? $_POST['transaction_id'] : 0);

    if ($transaction_id === 0) {
        redirect_to_dashboard('error', 'Invalid transaction ID provided for finalization.');
    }

    // Begin transaction for safe balance update/check
    mysqli_begin_transaction($db);

    try {
        // 1. Fetch transaction details, status, amount, and business ID/Type
        $sql_fetch = "SELECT T.transaction_amount, T.status, T.target_garage_id, T.target_vendor_id, U.account_balance 
                      FROM Transactions T 
                      JOIN Users U ON T.initiator_user_id = U.user_id
                      WHERE T.transaction_id = ? AND T.initiator_user_id = ? FOR UPDATE"; // Lock user row

        $transaction_amount = 0.0;
        $status = '';
        $target_garage_id = null;
        $target_vendor_id = null;
        $account_balance = 0.0;

        if ($stmt = mysqli_prepare($db, $sql_fetch)) {
            mysqli_stmt_bind_param($stmt, "ii", $transaction_id, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_bind_result($stmt, $transaction_amount, $status, $target_garage_id, $target_vendor_id, $account_balance);
                if (!mysqli_stmt_fetch($stmt)) {
                    throw new Exception('Transaction not found or unauthorized.');
                }
            } else {
                throw new Exception('Database error during transaction check.');
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception('Internal error during transaction preparation.');
        }

        if ($status !== 'Pending') {
            throw new Exception('Transaction is not pending. Status: ' . $status);
        }

        // 2. CHECK BALANCE
        if ($account_balance < $transaction_amount) {
            throw new Exception('Insufficient funds (KES ' . number_format($account_balance, 2) . ' available). Please recharge.');
        }

        // 3. DEDUCT FUNDS from Customer's Account
        $sql_deduct = "UPDATE Users SET account_balance = account_balance - ? WHERE user_id = ?";
        if ($stmt = mysqli_prepare($db, $sql_deduct)) {
            mysqli_stmt_bind_param($stmt, "di", $transaction_amount, $user_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to deduct funds from customer's account.");
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Internal error during deduction preparation.");
        }

        // 4. DEPOSIT FUNDS into Business Revenue Account
        $biz_id = $target_garage_id ?: $target_vendor_id;
        $biz_table = $target_garage_id ? 'Garages' : 'Vendors';
        $biz_col = $target_garage_id ? 'garage_id' : 'vendor_id';

        $sql_deposit = "UPDATE {$biz_table} SET revenue_balance = revenue_balance + ? WHERE {$biz_col} = ?";

        if ($stmt = mysqli_prepare($db, $sql_deposit)) {
            mysqli_stmt_bind_param($stmt, "di", $transaction_amount, $biz_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to deposit funds into business account.");
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Internal error during deposit preparation.");
        }

        // 5. Update Transaction Status
        $sql_update = "UPDATE Transactions SET status = 'Completed' WHERE transaction_id = ?";
        if ($stmt = mysqli_prepare($db, $sql_update)) {
            mysqli_stmt_bind_param($stmt, "i", $transaction_id);
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Failed to mark transaction as completed.");
            }
            mysqli_stmt_close($stmt);
        } else {
            throw new Exception("Internal error during status update preparation.");
        }

        // 6. Commit the transaction
        mysqli_commit($db);
        mysqli_close($db);

        redirect_to_dashboard('success', 'Payment successful! KES ' . number_format($transaction_amount, 2) . ' deducted. Transaction marked as Completed. You can now leave a rating.');

    } catch (Exception $e) {
        mysqli_rollback($db);
        mysqli_close($db);
        redirect_to_dashboard('error', 'Payment failed: ' . $e->getMessage());
    }
}

else {
    mysqli_close($db);
    redirect_to_dashboard('error', 'Unknown action requested.');
}

?>