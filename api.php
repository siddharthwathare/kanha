$conn = mysqli_init();

// 🔴 FORCE SSL
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
