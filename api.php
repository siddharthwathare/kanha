<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$action = $_GET['action'] ?? '';

if ($action == "products") {

    $conn = new mysqli("sql205.infinityfree.com", "if0_41704812", "OX6joYEsffEpm", "if0_41704812_kanha");

    $result = $conn->query("SELECT * FROM products");

    $data = [];

    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    echo json_encode($data);
    exit; // 🔴 VERY IMPORTANT
}

echo json_encode(["error" => "Invalid action"]);
exit;
?>