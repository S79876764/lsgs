<?php
// ============================================================
//  LSGS  |  migrate.php — One-time Database Migration
//  Run this ONCE by visiting: yoursite.up.railway.app/migrate.php
//  DELETE this file after running it.
// ============================================================
require_once __DIR__.'/db.php';

$results = [];

function safe_alter($conn, $sql, $label) {
    global $results;
    try {
        $conn->query($sql);
        if ($conn->error) {
            $results[] = ['status'=>'skip', 'label'=>$label, 'msg'=>$conn->error];
        } else {
            $results[] = ['status'=>'ok', 'label'=>$label, 'msg'=>'Done'];
        }
    } catch (Exception $e) {
        $results[] = ['status'=>'skip', 'label'=>$label, 'msg'=>$e->getMessage()];
    }
}

// ── students table ────────────────────────────────────────────
safe_alter($conn, "ALTER TABLE students ADD COLUMN phone VARCHAR(30) DEFAULT NULL", "students.phone");
safe_alter($conn, "ALTER TABLE students ADD COLUMN last_seen DATETIME DEFAULT NULL", "students.last_seen");
safe_alter($conn, "ALTER TABLE students ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP", "students.created_at");

// ── study_groups table ────────────────────────────────────────
safe_alter($conn, "ALTER TABLE study_groups ADD COLUMN is_online TINYINT(1) DEFAULT 0", "study_groups.is_online");
safe_alter($conn, "ALTER TABLE study_groups ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP", "study_groups.created_at");

// ── group_members table ───────────────────────────────────────
safe_alter($conn, "ALTER TABLE group_members ADD COLUMN status ENUM('active','blocked') NOT NULL DEFAULT 'active'", "group_members.status");
safe_alter($conn, "ALTER TABLE group_members ADD COLUMN joined_at DATETIME DEFAULT CURRENT_TIMESTAMP", "group_members.joined_at");

// ── resources table ───────────────────────────────────────────
safe_alter($conn, "ALTER TABLE resources ADD COLUMN file_path VARCHAR(500) DEFAULT NULL", "resources.file_path");
safe_alter($conn, "ALTER TABLE resources ADD COLUMN file_type VARCHAR(20) DEFAULT NULL", "resources.file_type");
safe_alter($conn, "ALTER TABLE resources ADD COLUMN size_label VARCHAR(20) DEFAULT NULL", "resources.size_label");
safe_alter($conn, "ALTER TABLE resources ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP", "resources.created_at");

// ── admin table ───────────────────────────────────────────────
safe_alter($conn, "ALTER TABLE admin ADD COLUMN session_token VARCHAR(64) DEFAULT NULL", "admin.session_token");
safe_alter($conn, "ALTER TABLE admin ADD COLUMN last_active DATETIME DEFAULT NULL", "admin.last_active");
safe_alter($conn, "ALTER TABLE admin ADD COLUMN security_question VARCHAR(255) DEFAULT NULL", "admin.security_question");
safe_alter($conn, "ALTER TABLE admin ADD COLUMN security_answer VARCHAR(255) DEFAULT NULL", "admin.security_answer");

// ── chat_messages table (create if missing) ───────────────────
$conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    group_id   INT NOT NULL,
    student_id INT NOT NULL,
    message    TEXT NOT NULL,
    sent_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_group_time (group_id, sent_at)
)");
$results[] = ['status'=>'ok','label'=>'chat_messages table','msg'=>'Ensured'];

// ── group_attendance table (create if missing) ────────────────
$conn->query("CREATE TABLE IF NOT EXISTS group_attendance (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    group_id   INT NOT NULL,
    student_id INT NOT NULL,
    att_date   DATE NOT NULL,
    status     ENUM('Present','Absent') DEFAULT 'Present',
    marked_by  INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_att (group_id, student_id, att_date)
)");
$results[] = ['status'=>'ok','label'=>'group_attendance table','msg'=>'Ensured'];

// ── subjects table (create if missing) ────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS subjects (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_name (name)
)");
$results[] = ['status'=>'ok','label'=>'subjects table','msg'=>'Ensured'];

// ── programmes table (create if missing) ──────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS programmes (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    UNIQUE KEY uq_name (name)
)");
$results[] = ['status'=>'ok','label'=>'programmes table','msg'=>'Ensured'];

// ── sessions table (create if missing) ───────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS sessions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(200) NOT NULL,
    group_id     INT NOT NULL,
    day          VARCHAR(20) DEFAULT NULL,
    session_time TIME DEFAULT NULL,
    location     VARCHAR(200) DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$results[] = ['status'=>'ok','label'=>'sessions table','msg'=>'Ensured'];

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"/>
<title>LSGS Migration</title>
<style>
body{font-family:sans-serif;background:#0f1f3d;color:#fff;padding:40px;max-width:700px;margin:0 auto}
h1{font-size:22px;margin-bottom:6px}
p{color:rgba(255,255,255,.5);margin-bottom:24px;font-size:13px}
.row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:8px;margin-bottom:6px;font-size:13px}
.ok{background:rgba(18,183,106,.15);border:1px solid rgba(18,183,106,.3)}
.skip{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1)}
.badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;flex-shrink:0}
.badge-ok{background:#12b76a;color:#fff}
.badge-skip{background:rgba(255,255,255,.15);color:rgba(255,255,255,.5)}
.label{font-weight:600;flex:1}
.msg{color:rgba(255,255,255,.4);font-size:11.5px}
.done-box{margin-top:28px;background:rgba(18,183,106,.15);border:1px solid rgba(18,183,106,.3);border-radius:10px;padding:18px 22px}
.done-box h2{font-size:16px;margin-bottom:6px;color:#6ee7b7}
.done-box p{color:rgba(255,255,255,.5);font-size:13px;margin:0}
</style>
</head>
<body>
<h1>🛠 LSGS Database Migration</h1>
<p>Running all column and table checks...</p>

<?php foreach($results as $r): ?>
  <div class="row <?= $r['status'] ?>">
    <span class="badge badge-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span>
    <span class="label"><?= htmlspecialchars($r['label']) ?></span>
    <span class="msg"><?= htmlspecialchars($r['msg']) ?></span>
  </div>
<?php endforeach; ?>

<div class="done-box">
  <h2>✅ Migration Complete</h2>
  <p>All tables and columns are up to date. <strong>Delete migrate.php from your project now</strong> — it should not stay on your server.</p>
</div>
</body>
</html>
