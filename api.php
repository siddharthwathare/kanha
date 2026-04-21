<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$action = $_GET['action'] ?? '';

// 🔴 DB CONNECTION (TiDB)
$conn = mysqli_init();
$conn->ssl_set(NULL, NULL, NULL, NULL, NULL);

$conn->real_connect(
    "gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com",
    "2RpDz4SXbM8nohL.root",
    "2HOhPpMJzR5SZrg1",
    "kanha",
    4000
);

// Check connection
if ($conn->connect_error) {
    echo json_encode(["error" => $conn->connect_error]);
    exit;
}

if ($action == "products") {

    $result = $conn->query("SELECT * FROM products");

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}

echo json_encode(["error" => "Invalid action"]);
exit;
?>
