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
// ❌ INVALID ACTION
// =====================
echo json_encode(["error" => "Invalid action"]);
exit;
?>
