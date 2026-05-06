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

    if ($user_id) {
        $stmt = $conn->prepare("SELECT * FROM products WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode($data);
        exit;
    }

    $result = $conn->query("SELECT * FROM products");
    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}

// =====================
// 👤 FETCH USER BY USERNAME
// =====================
if ($action == "users") {

    $username = $_GET['username'] ?? null;

    if ($username) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            echo json_encode(["error" => "User not found"]);
            exit;
        }

        echo json_encode($result->fetch_assoc());
        exit;
    }

    $result = $conn->query("SELECT * FROM users");
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
        SELECT c.id, c.name, IFNULL(cs.total_due, 0) AS total_due
        FROM customers c
        LEFT JOIN credits_summary cs ON c.id = cs.customer_id
        WHERE c.user_id = ?
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}

// =====================
// ➕ ADD TRANSACTION + UPDATE CREDIT + STOCK
// =====================
if ($action == "addTransaction") {

    $input = json_decode(file_get_contents("php://input"), true);

    $customer_id = $_POST['customer_id'] ?? $input['customer_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? $input['user_id'] ?? null;
    $amount      = $_POST['amount'] ?? $input['amount'] ?? null;
    $bill_json   = $_POST['bill_json'] ?? $input['bill_json'] ?? null;
    $type        = $_POST['type'] ?? $input['type'] ?? 'cash';

    if (!$customer_id || !$user_id || !$amount) {
        echo json_encode(["success" => false, "error" => "Missing required fields"]);
        exit;
    }

    $allowed_types = ['cash', 'credit', 'qr'];
    if (!in_array($type, $allowed_types)) {
        $type = 'cash';
    }

    $conn->begin_transaction();

    try {

        // Insert transaction
        $stmt1 = $conn->prepare("
            INSERT INTO `transactions`
            (customer_id, user_id, amount, bill_json, type)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt1->bind_param("iidss", $customer_id, $user_id, $amount, $bill_json, $type);

        if (!$stmt1->execute()) {
            throw new Exception($stmt1->error);
        }

        // Update credits
        $stmt2 = $conn->prepare("
            INSERT INTO credits_summary (customer_id, user_id, total_due)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE total_due = total_due + VALUES(total_due)
        ");
        $stmt2->bind_param("iid", $customer_id, $user_id, $amount);

        if (!$stmt2->execute()) {
            throw new Exception($stmt2->error);
        }

        // 🔥 STOCK UPDATE
        if ($bill_json) {
            $items = json_decode($bill_json, true);

            if (is_array($items)) {
                foreach ($items as $item) {

                    if (!isset($item['id']) || !isset($item['qty'])) {
                        continue;
                    }

                    $product_id = (int)$item['id'];
                    $qty = (int)$item['qty'];

                    if ($product_id <= 0 || $qty <= 0) {
                        continue;
                    }

                    $stmtStock = $conn->prepare("
                        UPDATE products
                        SET stock = stock - ?
                        WHERE id = ?
                    ");

                    $stmtStock->bind_param("ii", $qty, $product_id);

                    if (!$stmtStock->execute()) {
                        throw new Exception($stmtStock->error);
                    }
                }
            }
        }

        $conn->commit();

        echo json_encode([
            "success" => true,
            "message" => "Transaction added successfully"
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }

    exit;
}

// =====================
// ➕ ADD SIMPLE TRANSACTION + STOCK
// =====================
if ($action == "addTransactionSimple") {

    $input = json_decode(file_get_contents("php://input"), true);

    $user_id   = $_POST['user_id'] ?? $input['user_id'] ?? null;
    $amount    = $_POST['amount'] ?? $input['amount'] ?? null;
    $bill_json = $_POST['bill_json'] ?? $input['bill_json'] ?? null;
    $type      = $_POST['type'] ?? $input['type'] ?? 'cash';

    if (!$user_id || !$amount) {
        echo json_encode(["success" => false, "error" => "Missing required fields"]);
        exit;
    }

    $allowed_types = ['cash', 'credit', 'qr'];
    if (!in_array($type, $allowed_types)) {
        $type = 'cash';
    }

    try {

        $stmt = $conn->prepare("
            INSERT INTO `transactions`
            (customer_id, user_id, amount, bill_json, type)
            VALUES (NULL, ?, ?, ?, ?)
        ");
        $stmt->bind_param("idss", $user_id, $amount, $bill_json, $type);

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        // 🔥 STOCK UPDATE
        if ($bill_json) {
            $items = json_decode($bill_json, true);

            if (is_array($items)) {
                foreach ($items as $item) {

                    if (!isset($item['id']) || !isset($item['qty'])) {
                        continue;
                    }

                    $product_id = (int)$item['id'];
                    $qty = (int)$item['qty'];

                    if ($product_id <= 0 || $qty <= 0) {
                        continue;
                    }

                    $stmtStock = $conn->prepare("
                        UPDATE products
                        SET stock = stock - ?
                        WHERE id = ?
                    ");

                    $stmtStock->bind_param("ii", $qty, $product_id);

                    if (!$stmtStock->execute()) {
                        throw new Exception($stmtStock->error);
                    }
                }
            }
        }

        echo json_encode([
            "success" => true,
            "message" => "Transaction added successfully"
        ]);

    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }

    exit;
}

// =====================
// ❌ INVALID ACTION
// =====================
echo json_encode(["error" => "Invalid action"]);
exit;
?>
