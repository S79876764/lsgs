<?php
// ============================================================
//  LSGS  |  db.php — Database Connection
//  Reads Railway environment variables when deployed.
//  Falls back to XAMPP localhost for local development.
// ============================================================
$db_host = getenv('MYSQLHOST')     ?: 'localhost';
$db_user = getenv('MYSQLUSER')     ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: '';
$db_name = getenv('MYSQLDATABASE') ?: 'lsgs_db';
$db_port = (int)(getenv('MYSQLPORT') ?: 3306);

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
if ($conn->connect_error) {
    die('<p style="font-family:sans-serif;color:red;padding:40px">DB Error: ' . htmlspecialchars($conn->connect_error) . '</p>');
}
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");