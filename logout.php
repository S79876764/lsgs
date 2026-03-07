<?php
// ============================================================
//  LSGS  |  logout.php
//  Clears session token from DB so account can log in again
//  on any device, then destroys session.
// ============================================================
session_start();

if (isset($_SESSION['user'])) {
    $uid  = (int)($_SESSION['user']['id']   ?? 0);
    $role = $_SESSION['user']['role'] ?? '';

    if ($uid) {
        require_once __DIR__.'/db.php';
        if (!$conn->connect_error) {
            $conn->set_charset('utf8mb4');
            // Clear token — account is now free to log in from any device
            $table = ($role === 'admin') ? 'admin' : 'students';
            $st = $conn->prepare("UPDATE $table SET session_token=NULL WHERE id=?");
            if ($st) { $st->bind_param('i',$uid); $st->execute(); }
            $conn->close();
        }
    }
}

session_unset();
session_destroy();
header('Location: welcome.php');
exit;
