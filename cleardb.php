<?php
// ============================================================
//  LSGS  |  cleardb.php — Clear all data except admin
//  DELETE THIS FILE immediately after running it!
// ============================================================
require_once __DIR__.'/db.php';

$conn->query("SET FOREIGN_KEY_CHECKS = 0");

$tables = [
    'students',
    'study_groups',
    'group_members',
    'group_attendance',
    'attendance',
    'chat_messages',
    'resources',
    'sessions',
    'subjects',
    'programmes',
];

$results = [];
foreach ($tables as $t) {
    $conn->query("TRUNCATE TABLE $t");
    $results[] = $conn->error ? "❌ $t — " . $conn->error : "✅ $t cleared";
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"/><title>Clear DB</title>
<style>body{font-family:sans-serif;background:#0f1f3d;color:#fff;padding:40px;max-width:600px;margin:0 auto}
p{padding:10px 16px;border-radius:8px;margin-bottom:8px;background:rgba(255,255,255,.07);font-size:14px}
.done{margin-top:24px;background:rgba(18,183,106,.2);border:1px solid #12b76a;border-radius:10px;padding:18px;color:#6ee7b7}
</style></head>
<body>
<h2>🗑 Database Clear</h2>
<?php foreach($results as $r): ?>
  <p><?= htmlspecialchars($r) ?></p>
<?php endforeach; ?>
<div class="done">
  ✅ Done. Admin credentials are untouched.<br><br>
  <strong>⚠ Delete cleardb.php from your project now!</strong>
</div>
</body>
</html>
