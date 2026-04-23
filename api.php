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
// 👤 FETCH USERS
// =====================
if ($action == "users") {

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
// 💰 ADD CREDIT
// =====================
if ($action == "addCredit") {

    $customer_id = $_POST['customer_id'] ?? null;
    $amount = $_POST['amount'] ?? null;

    if (!$customer_id || !$amount) {
        echo json_encode(["error" => "Missing parameters"]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE credits SET balance = balance + ? WHERE customer_id = ?");
    $stmt->bind_param("di", $amount, $customer_id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["error" => $stmt->error]);
    }

    exit;
}

// =====================
// ❌ INVALID ACTION
// =====================
echo json_encode(["error" => "Invalid action"]);
exit;
?>
