<?php
// ============================================================
//  LSGS  |  session_check.php
//  - Guards all protected pages
//  - Updates last_active heartbeat on every page load
//  - Kicks old session if a new device logged in (token mismatch)
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user'])) {
    $is_admin_page = strpos(basename($_SERVER['PHP_SELF']), 'admin') !== false;
    header('Location: ' . ($is_admin_page ? 'admin_login.php' : 'index.php'));
    exit;
}

$_su  = &$_SESSION['user'];
$role = $_su['role']        ?? 'student';
$uid  = (int)($_su['id']   ?? 0);

if ($uid) {
    $_sc = new mysqli(getenv('MYSQLHOST')?:('localhost'), getenv('MYSQLUSER')?:('root'), getenv('MYSQLPASSWORD')?:(''), getenv('MYSQLDATABASE')?:('lsgs_db'), (int)(getenv('MYSQLPORT')?:(3306)));
    if (!$_sc->connect_error) {
        $_sc->set_charset('utf8mb4');

        if ($role === 'admin') {
            // Ensure column exists and refresh heartbeat
            $_sc->query("ALTER TABLE admin ADD COLUMN IF NOT EXISTS last_active DATETIME DEFAULT NULL");
            $_sc->query("UPDATE admin SET last_active=NOW() WHERE id=$uid");

        } else {
            // Ensure columns exist
            $_sc->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS last_active DATETIME DEFAULT NULL");

            // Check if our token still matches DB
            // If not — another device logged in and took over this account
            $chk = $_sc->prepare("SELECT session_token FROM students WHERE id=? LIMIT 1");
            $chk->bind_param('i', $uid);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();

            $our_token = $_su['session_token'] ?? '';
            $db_token  = $row['session_token']  ?? '';

            if (!empty($our_token) && !empty($db_token) && $our_token !== $db_token) {
                // Kicked — new login took over, boot this session out
                session_unset();
                session_destroy();
                header('Location: index.php?kicked=1');
                exit;
            }

            // Refresh heartbeat so login expiry countdown resets
            $_sc->query("UPDATE students SET last_active=NOW() WHERE id=$uid");
        }

        $_sc->close();
    }
}
