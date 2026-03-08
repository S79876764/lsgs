<?php
require_once __DIR__.'/db.php';

$new_password = 'admin1234';
$hash = password_hash($new_password, PASSWORD_BCRYPT);

$stmt = $conn->prepare("UPDATE admin SET password=? WHERE email='admin@ecot.ac.sz'");
$stmt->bind_param('s', $hash);
$stmt->execute();

echo "Password reset successfully!<br>";
echo "Email: admin@ecot.ac.sz<br>";
echo "Password: admin1234<br>";
echo "Hash: " . $hash . "<br>";
echo "<br><strong>DELETE this file immediately after use!</strong>";
?>
