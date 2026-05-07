<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$action = $_GET['action'] ?? '';

// 🔴 DB CONNECTION (TiDB with SSL)
$conn = mysqli_init();

mysqli_ssl_set($conn, NULL, NULL, NULL, NULL, NULL);

$conn->real_connect(
    "gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com",
    "2RpDz4SXbM8nohL.root",
    "2HOhPpMJzR5SZrg1",
    "kanha",
    4000,
    NULL,
    MYSQLI_CLIENT_SSL
);

// Check connection
if ($conn->connect_error) {
    echo json_encode(["error" => $conn->connect_error]);
    exit;
}

// =====================
// 📦 FETCH PRODUCTS
// =====================
if ($action == "products") {

    $user_id = $_GET['user_id'] ?? null;

    // If user_id is provided → filter products
    if ($user_id) {

        $stmt = $conn->prepare("SELECT * FROM products WHERE user_id = ?");
        $stmt->bind_param("i", $user_id); // "i" = integer

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo json_encode([]);
            exit;
        }

        $data = [];

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode($data);
        exit;
    }

    // Fallback → fetch all products
    $result = $conn->query("SELECT * FROM products");

    if (!$result) {
        echo json_encode(["error" => $conn->error]);
        exit;
    }

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}
// =====================
// =====================
// 👤 FETCH USER BY USERNAME
// =====================
if ($action == "users") {

    $username = $_GET['username'] ?? null;

    // If username is provided → fetch specific user
    if ($username) {

        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username); // "s" = string

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo json_encode(["error" => "User not found"]);
            exit;
        }

        $data = $result->fetch_assoc();
        echo json_encode($data);
        exit;
    }

    // Fallback → fetch all users
    $result = $conn->query("SELECT * FROM users");

    if (!$result) {
        echo json_encode(["error" => $conn->error]);
        exit;
    }

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}
// =====================
// 💳 FETCH CUSTOMER CREDITS SUMMARY
// =====================
if ($action == "credits") {

    $user_id = $_GET['user_id'] ?? null;

    if (!$user_id) {
        echo json_encode(["error" => "Missing user_id"]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT 
            c.id, 
            c.name, 
            IFNULL(cs.total_due, 0) AS total_due
        FROM customers c
        LEFT JOIN credits_summary cs
            ON c.id = cs.customer_id
        WHERE c.user_id = ?
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if (!$result) {
        echo json_encode(["error" => $conn->error]);
        exit;
    }

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}
// =====================
// ➕ ADD CUSTOMER + CREDIT
// =====================
if ($action == "addCustomerWithCredit") {

    // 🔹 Read JSON input (for App Inventor JSON)
    $input = json_decode(file_get_contents("php://input"), true);

    // 🔹 Support both JSON and form-data
    $name = $_POST['name'] ?? $input['name'] ?? null;
    $phone = $_POST['phone'] ?? $input['phone'] ?? null;
    $user_id = $_POST['user_id'] ?? $input['user_id'] ?? null;

    // 🔹 Validate required fields (phone NOT required)
    if (!$name || !$user_id) {
        echo json_encode([
            "success" => false,
            "error" => "Missing required fields"
        ]);
        exit;
    }

    // 🔹 Make phone optional (store NULL if empty)
    if ($phone === "" || $phone === null) {
        $phone = null;
    }

    // 🔹 Start transaction
    $conn->begin_transaction();

    try {

        // 1️⃣ Insert into customers
        $stmt1 = $conn->prepare("
            INSERT INTO customers (user_id, name, phone)
            VALUES (?, ?, ?)
        ");
        $stmt1->bind_param("iss", $user_id, $name, $phone);

        if (!$stmt1->execute()) {
            throw new Exception($stmt1->error);
        }

        // Get inserted customer ID
        $customer_id = $stmt1->insert_id;

        // 2️⃣ Insert into credits_summary
        $stmt2 = $conn->prepare("
            INSERT INTO credits_summary (customer_id, user_id, total_due)
            VALUES (?, ?, 0)
        ");
        $stmt2->bind_param("ii", $customer_id, $user_id);

        if (!$stmt2->execute()) {
            throw new Exception($stmt2->error);
        }

        // 🔹 Commit transaction
        $conn->commit();

        echo json_encode([
            "success" => true,
            "customer_id" => $customer_id,
            "message" => "Customer added successfully"
        ]);

    } catch (Exception $e) {

        // 🔹 Rollback on error
        $conn->rollback();

        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }

    exit;
}
// =====================
// ➕ ADD TRANSACTION + UPDATE CREDIT (WITH TYPE)
// =====================
if ($action == "addTransaction") {

    // 🔹 Read JSON input
    $input = json_decode(file_get_contents("php://input"), true);

    // 🔹 Support both JSON and form-data
    $customer_id = $_POST['customer_id'] ?? $input['customer_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? $input['user_id'] ?? null;
    $amount      = $_POST['amount'] ?? $input['amount'] ?? null;
    $bill_json   = $_POST['bill_json'] ?? $input['bill_json'] ?? null;
    $type        = $_POST['type'] ?? $input['type'] ?? 'cash'; // default

    // 🔹 Validate required fields
    if (!$customer_id || !$user_id || !$amount) {
        echo json_encode([
            "success" => false,
            "error" => "Missing required fields"
        ]);
        exit;
    }

    // 🔹 Validate type (ENUM safety)
    $allowed_types = ['cash', 'credit', 'qr'];
    if (!in_array($type, $allowed_types)) {
        $type = 'cash';
    }

    // 🔹 Start transaction
    $conn->begin_transaction();

    try {

        // 1️⃣ Insert into transactions (with type)
        $stmt1 = $conn->prepare("
            INSERT INTO `transactions`
            (customer_id, user_id, amount, bill_json, type)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt1->bind_param("iidss", $customer_id, $user_id, $amount, $bill_json, $type);

        if (!$stmt1->execute()) {
            throw new Exception($stmt1->error);
        }

        // 2️⃣ UPSERT into credits_summary
        $stmt2 = $conn->prepare("
            INSERT INTO credits_summary (customer_id, user_id, total_due)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE total_due = total_due + VALUES(total_due)
        ");

        $stmt2->bind_param("iid", $customer_id, $user_id, $amount);

        if (!$stmt2->execute()) {
            throw new Exception($stmt2->error);
        }

        // 🔹 Commit
        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Transaction added successfully",
            "type_used" => $type
        ]);

    } catch (Exception $e) {

        // 🔹 Rollback
        $conn->rollback();

        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }

    exit;
}
// =====================
// 👤 FETCH USER PROFILE
// =====================
if ($action == "userProfile") {

    $user_id = $_GET['user_id'] ?? null;

    if (!$user_id) {
        echo json_encode([
            "success" => false,
            "error" => "Missing user_id"
        ]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT upi_id, upi_name
        FROM user_profiles
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode([
            "success" => true,
            "upi_id" => null,
            "upi_name" => null
        ]);
        exit;
    }

    $data = $result->fetch_assoc();

    echo json_encode([
        "success" => true,
        "upi_id" => $data['upi_id'],
        "upi_name" => $data['upi_name']
    ]);

    exit;
}
// =====================
// ➕ ADD SIMPLE TRANSACTION (NO CUSTOMER)
// =====================
if ($action == "addTransactionSimple") {

    // 🔹 Read JSON input
    $input = json_decode(file_get_contents("php://input"), true);

    // 🔹 Support both JSON and form-data
    $user_id   = $_POST['user_id'] ?? $input['user_id'] ?? null;
    $amount    = $_POST['amount'] ?? $input['amount'] ?? null;
    $bill_json = $_POST['bill_json'] ?? $input['bill_json'] ?? null;
    $type      = $_POST['type'] ?? $input['type'] ?? 'cash';

    // 🔹 Validate required fields
    if (!$user_id || !$amount) {
        echo json_encode([
            "success" => false,
            "error" => "Missing required fields"
        ]);
        exit;
    }

    // 🔹 Validate type
    $allowed_types = ['cash', 'credit', 'qr'];
    if (!in_array($type, $allowed_types)) {
        $type = 'cash';
    }

    try {

        // 🔹 Insert without customer_id
        $stmt = $conn->prepare("
            INSERT INTO `transactions`
            (customer_id, user_id, amount, bill_json, type)
            VALUES (NULL, ?, ?, ?, ?)
        ");

        $stmt->bind_param("idss", $user_id, $amount, $bill_json, $type);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        echo json_encode([
            "success" => true,
            "message" => "Transaction added successfully"
        ]);

    } catch (Exception $e) {

        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }

    exit;
}
// =====================
// ➖ ADD PAYMENT + UPDATE CREDIT
// =====================
if ($action == "addPayment") {

    // 🔹 Read JSON input
    $input = json_decode(file_get_contents("php://input"), true);

    // 🔹 Get values
    $customer_id = $_POST['customer_id'] ?? $input['customer_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? $input['user_id'] ?? null;
    $amount      = $_POST['amount'] ?? $input['amount'] ?? null;

    // 🔹 Force type = payment
    $type = 'payment';

    // 🔹 Validate
if ($customer_id === null || $user_id === null || $amount === null) {
    echo json_encode([
        "success" => false,
        "error" => "Missing required fields",
        "debug" => [
            "customer_id" => $customer_id,
            "user_id" => $user_id,
            "amount" => $amount,
            "raw_input" => $input,
            "_POST" => $_POST
        ]
    ]);
    exit;
}

    // 🔹 Start transaction
    $conn->begin_transaction();

    try {

        // 1️⃣ Insert payment transaction
        $stmt1 = $conn->prepare("
            INSERT INTO `transactions`
            (customer_id, user_id, amount, bill_json, type)
            VALUES (?, ?, ?, NULL, ?)
        ");

        $stmt1->bind_param("iids", $customer_id, $user_id, $amount, $type);

        if (!$stmt1->execute()) {
            throw new Exception($stmt1->error);
        }

        // 2️⃣ Deduct from credits_summary
        $stmt2 = $conn->prepare("
            UPDATE credits_summary
            SET total_due = total_due - ?
            WHERE customer_id = ?
        ");

        $stmt2->bind_param("di", $amount, $customer_id);

        if (!$stmt2->execute()) {
            throw new Exception($stmt2->error);
        }

        // 🔹 Ensure customer exists
        if ($stmt2->affected_rows == 0) {
            throw new Exception("Customer not found in credits_summary");
        }

        // 🔹 Commit
        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Payment recorded successfully",
            "type" => "payment"
        ]);

    } catch (Exception $e) {

        // 🔹 Rollback
        $conn->rollback();

        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }

    exit;
}
// =====================
// ❌ INVALID ACTION
// =====================
echo json_encode(["error" => "Invalid action"]);
exit;
?>
