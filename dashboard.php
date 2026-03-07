<?php
// ============================================================
//  LSGS  |  PAGE 3 — dashboard.php
//  Student Dashboard
//  Eswatini College of Technology
// ============================================================

session_start();

// ── Auth guard ───────────────────────────────────────────────
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

// ── Database connection ──────────────────────────────────────
require_once __DIR__.'/db.php';

$user      = $_SESSION['user'];
$uid       = (int)$user['id'];
$initials  = strtoupper(substr($user['first_name'],0,1) . substr($user['last_name'],0,1));
$full_name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);

// ── Data fetching ────────────────────────────────────────────

// My groups with member count
$my_groups = [];
$st = $conn->prepare("
    SELECT g.*, COUNT(gm2.student_id) AS mc
    FROM study_groups g
    JOIN group_members gm  ON g.id = gm.group_id AND gm.student_id = ?
    LEFT JOIN group_members gm2 ON g.id = gm2.group_id
    GROUP BY g.id
    ORDER BY g.name
");
$st->bind_param('i', $uid);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $my_groups[] = $row;

// This week's sessions
$sessions = [];
$r = $conn->query("
    SELECT s.*, g.name AS gname, g.subject AS sub
    FROM sessions s
    JOIN study_groups g ON s.group_id = g.id
    ORDER BY FIELD(s.day,'Mon','Tue','Wed','Thu','Fri','Sat'), s.session_time
    LIMIT 8
");
while ($row = $r->fetch_assoc()) $sessions[] = $row;

// Counts
$total_resources = $conn->query("SELECT COUNT(*) c FROM resources")->fetch_assoc()['c'];
$att_total       = $conn->query("SELECT COUNT(*) c FROM attendance WHERE student_id=$uid")->fetch_assoc()['c'];
$att_present     = $conn->query("SELECT COUNT(*) c FROM attendance WHERE student_id=$uid AND status='Present'")->fetch_assoc()['c'];
$att_rate        = $att_total > 0 ? round($att_present / $att_total * 100) : 0;

// ── Helpers ──────────────────────────────────────────────────
function subj_class($s) {
    $m = ['Mathematics'=>'s-blue','Computer Science'=>'s-purple','Physics'=>'s-orange',
          'Chemistry'=>'s-green','Biology'=>'s-green','Engineering'=>'s-red',
          'Information Technology'=>'s-blue','Electronics'=>'s-orange','Statistics'=>'s-purple'];
    return $m[$s] ?? 's-blue';
}
function subj_color($s) {
    $m = ['Mathematics'=>'#1e6fff','Computer Science'=>'#7c3aed','Physics'=>'#f79009',
          'Chemistry'=>'#12b76a','Biology'=>'#0891b2','Engineering'=>'#f04438',
          'Information Technology'=>'#1e6fff','Electronics'=>'#f79009','Statistics'=>'#7c3aed'];
    return $m[$s] ?? '#1e6fff';
}
function health_color($h) {
    return $h >= 80 ? '#12b76a' : ($h >= 60 ? '#f79009' : '#f04438');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard </title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');

    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --navy:    #0f1f3d;
      --blue:    #1e6fff;
      --cyan:    #00c2f3;
      --surface: #f4f6fb;
      --white:   #ffffff;
      --border:  #e2e8f0;
      --text:    #1a2540;
      --muted:   #6b7a99;
      --green:   #12b76a;
      --orange:  #f79009;
      --red:     #f04438;
    }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--surface);
      color: var(--text);
      min-height: 100vh;
      display: flex;
    }

    /* ══ SIDEBAR ══════════════════════════════════════════════ */
    .sidebar {
      width: 240px;
      background: var(--navy);
      display: flex;
      flex-direction: column;
      position: fixed;
      top: 0; left: 0; bottom: 0;
      z-index: 100;
      overflow-y: auto;
    }
    .sb-logo {
      padding: 20px 18px 16px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .sb-logo img {
      width: 38px; height: 38px;
      object-fit: contain;
      background: #fff;
      border-radius: 7px;
      padding: 2px;
    }
    .sb-logo-text { font-size: 14px; font-weight: 800; color: #fff; line-height: 1.2; }
    .sb-logo-sub  { font-size: 9px; color: rgba(255,255,255,.35); font-family: 'DM Mono',monospace; }

    .sb-nav-wrap { flex: 1; padding: 14px 12px; }
    .sb-label {
      font-size: 10px; font-weight: 600; letter-spacing: 1.2px;
      color: rgba(255,255,255,.3); text-transform: uppercase;
      padding: 8px 8px 5px;
    }
    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 10px; border-radius: 8px;
      font-size: 13.5px; font-weight: 500;
      color: rgba(255,255,255,.55);
      transition: all .15s; margin-bottom: 2px;
      text-decoration: none;
    }
    .nav-item:hover  { background: rgba(255,255,255,.07); color: #fff; }
    .nav-item.active { background: var(--blue); color: #fff; }
    .nav-item .ico   { font-size: 15px; width: 18px; text-align: center; }

    .sb-user {
      padding: 14px 16px;
      border-top: 1px solid rgba(255,255,255,.08);
      display: flex; align-items: center; gap: 10px;
    }
    .avatar {
      width: 34px; height: 34px; border-radius: 50%;
      background: linear-gradient(135deg, var(--blue), var(--cyan));
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 13px; color: #fff; flex-shrink: 0;
    }
    .u-name { font-size: 13px; font-weight: 600; color: #fff; }
    .u-role { font-size: 11px; color: rgba(255,255,255,.4); }
    .logout-btn {
      margin-left: auto; font-size: 16px; cursor: pointer;
      opacity: .4; transition: opacity .15s;
      background: none; border: none; color: #fff;
      text-decoration: none;
    }
    .logout-btn:hover { opacity: .9; }

    /* ══ MAIN ═════════════════════════════════════════════════ */
    .main {
      margin-left: 240px;
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    /* ══ TOPBAR ═══════════════════════════════════════════════ */
    .topbar {
      background: var(--white);
      border-bottom: 1px solid var(--border);
      padding: 0 28px; height: 60px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 50;
    }
    .topbar-title { font-size: 16px; font-weight: 700; }
    .topbar-right { display: flex; align-items: center; gap: 12px; }
    .notif-btn {
      width: 36px; height: 36px; border-radius: 8px;
      background: var(--surface); border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; position: relative; font-size: 17px;
    }
    .notif-dot {
      position: absolute; top: 6px; right: 6px;
      width: 7px; height: 7px; background: var(--red);
      border-radius: 50%; border: 2px solid #fff;
    }
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px;
      font-size: 13.5px; font-weight: 600; cursor: pointer;
      border: none; font-family: inherit; transition: all .15s;
      text-decoration: none;
    }
    .btn-primary { background: var(--blue); color: #fff; }
    .btn-primary:hover { background: #1559d4; }
    .btn-ghost {
      background: transparent; color: var(--muted);
      border: 1px solid var(--border);
    }
    .btn-ghost:hover { background: var(--surface); color: var(--text); }
    .btn-sm { padding: 5px 12px; font-size: 12.5px; }

    /* ══ CONTENT ══════════════════════════════════════════════ */
    .content { padding: 28px; flex: 1; }

    /* ══ STAT CARDS ═══════════════════════════════════════════ */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    .stat-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
    }
    .stat-lbl { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 6px; }
    .stat-val { font-size: 32px; font-weight: 700; color: var(--text); line-height: 1; }
    .stat-sub { font-size: 12.5px; color: var(--muted); margin-top: 6px; }

    /* ══ GRID ═════════════════════════════════════════════════ */
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
    .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }

    /* ══ CARDS ════════════════════════════════════════════════ */
    .card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
    }
    .sec-header {
      display: flex; align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }
    .sec-title { font-size: 15px; font-weight: 700; }

    /* ══ SCHEDULE ITEMS ═══════════════════════════════════════ */
    .sched-item {
      display: flex; align-items: center; gap: 12px;
      padding: 11px 0;
      border-bottom: 1px solid var(--border);
    }
    .sched-item:last-child { border-bottom: none; }
    .sched-time {
      min-width: 65px;
      font-family: 'DM Mono', monospace;
      font-size: 12px; color: var(--muted);
    }
    .sched-dot   { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .sched-title { font-size: 13.5px; font-weight: 600; }
    .sched-sub   { font-size: 12px; color: var(--muted); margin-top: 1px; }
    .sched-badge {
      margin-left: auto; padding: 2px 8px;
      border-radius: 6px; font-size: 11px; font-weight: 600;
    }
    .badge-today { background: #e8f0ff; color: var(--blue); }
    .badge-soon  { background: #f0fdf4; color: #16a34a; }

    /* ══ ACTIVITY ═════════════════════════════════════════════ */
    .act-item {
      display: flex; gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid var(--border);
    }
    .act-item:last-child { border-bottom: none; }
    .act-icon {
      width: 32px; height: 32px; border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; flex-shrink: 0;
    }
    .act-text { font-size: 13px; line-height: 1.5; }
    .act-time  { font-size: 11px; color: var(--muted); margin-top: 2px; }

    /* ══ GROUP CARDS ══════════════════════════════════════════ */
    .group-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 18px;
      transition: all .15s;
    }
    .group-card:hover {
      border-color: var(--blue);
      box-shadow: 0 4px 18px rgba(30,111,255,.1);
    }
    .gc-header {
      display: flex; align-items: center;
      justify-content: space-between;
      margin-bottom: 10px;
    }
    .subj-tag {
      display: inline-block;
      padding: 3px 10px; border-radius: 20px;
      font-size: 11px; font-weight: 600;
    }
    .s-blue   { background: #e8f0ff; color: var(--blue); }
    .s-green  { background: #e6f9f1; color: #0a9457; }
    .s-orange { background: #fff4e5; color: #b45309; }
    .s-purple { background: #f3e8ff; color: #7c3aed; }
    .s-red    { background: #fff0f0; color: #b91c1c; }

    .gc-name { font-size: 14.5px; font-weight: 700; margin-bottom: 6px; }
    .gc-meta {
      font-size: 12px; color: var(--muted);
      display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 4px;
    }
    .status-dot { font-size: 11px; font-weight: 600; }
    .dot-green  { color: var(--green); }
    .dot-orange { color: var(--orange); }

    .health-wrap { margin-top: 10px; }
    .health-row {
      display: flex; justify-content: space-between;
      font-size: 11px; color: var(--muted); margin-bottom: 4px;
    }
    .track { height: 5px; background: #eef1f8; border-radius: 10px; overflow: hidden; }
    .fill  { height: 100%; border-radius: 10px; background: linear-gradient(90deg, var(--blue), var(--cyan)); }

    /* ══ EMPTY STATE ══════════════════════════════════════════ */
    .empty-state {
      text-align: center; color: var(--muted);
      padding: 36px 20px; font-size: 13.5px;
    }
    .empty-state a { color: var(--blue); font-weight: 600; }

    /* ══ RESPONSIVE ═══════════════════════════════════════════ */
    @media (max-width: 1100px) { .stat-grid { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 820px)  {
      .sidebar { transform: translateX(-100%); transition: transform .25s; }
      .sidebar.open { transform: translateX(0); }
      .main   { margin-left: 0; }
      .grid-2, .grid-3 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- ══ SIDEBAR ═══════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
  <div class="sb-logo">
    <img src="assets/ecot.jpg" alt="ECOT Logo"/>
    <div>
      <div class="sb-logo-text"></div>
      <div class="sb-logo-sub">ECOT STUDY GROUP</div>
    </div>
  </div>

  <nav class="sb-nav-wrap">
    <div class="sb-label">Main</div>
    <a class="nav-item active" href="dashboard.php"><span class="ico">⊞</span> Dashboard</a>
    <a class="nav-item" href="groups.php"><span class="ico">◎</span> My Groups</a>
    <a class="nav-item" href="matching.php"><span class="ico">⌖</span> Smart Match</a>
    <a class="nav-item" href="schedule.php"><span class="ico">◷</span> Schedule</a>

    <div class="sb-label" style="margin-top:10px">Resources</div>
    <a class="nav-item" href="resources.php"><span class="ico">⊟</span> Shared Files</a>
    <a class="nav-item" href="progress.php"><span class="ico">◈</span> Progress</a>
    <a class="nav-item" href="attendance.php"><span class="ico">✓</span> Attendance</a>
    <a class="nav-item" href="badges.php"><span class="ico">◆</span> Badges</a>
  </nav>

  <div class="sb-user">
    <div class="avatar"><?= $initials ?></div>
    <div>
      <div class="u-name"><?= $full_name ?></div>
      <div class="u-role"><?= htmlspecialchars($user['year'] ?? 'Student') ?></div>
    </div>
    <a href="logout.php" class="logout-btn" title="Sign out">⏻</a>
  </div>
</aside>

<!-- ══ MAIN ═══════════════════════════════════════════════════ -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:14px">
      <button id="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')"
        style="display:none;background:none;border:none;cursor:pointer;font-size:22px">☰</button>
      <div class="topbar-title">Dashboard</div>
    </div>
    <div class="topbar-right">
      <div class="notif-btn">🔔<span class="notif-dot"></span></div>
      <a href="groups.php" class="btn btn-primary">+ New Group</a>
    </div>
  </header>

  <!-- CONTENT -->
  <div class="content">

    <!-- STAT CARDS -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-lbl">My Groups</div>
        <div class="stat-val"><?= count($my_groups) ?></div>
        <div class="stat-sub">currently joined</div>
      </div>
      <div class="stat-card">
        <div class="stat-lbl">Sessions This Week</div>
        <div class="stat-val"><?= count($sessions) ?></div>
        <div class="stat-sub">scheduled</div>
      </div>
      <div class="stat-card">
        <div class="stat-lbl">Attendance Rate</div>
        <div class="stat-val" style="color:var(--green)">
          <?= $att_rate ?>%
        </div>
        <div class="stat-sub"><?= $att_present ?> of <?= $att_total ?> sessions</div>
      </div>
      <div class="stat-card">
        <div class="stat-lbl">Resources</div>
        <div class="stat-val"><?= $total_resources ?></div>
        <div class="stat-sub">shared files</div>
      </div>
    </div>

    <!-- SESSIONS + ACTIVITY -->
    <div class="grid-2">

      <!-- Sessions this week -->
      <div class="card">
        <div class="sec-header">
          <div class="sec-title">This Week's Sessions</div>
          <a href="schedule.php" class="btn btn-ghost btn-sm">View all</a>
        </div>
        <?php if (empty($sessions)): ?>
          <div class="empty-state">No sessions scheduled yet.<br/>
            <a href="admin_dashboard.php">Admin can add sessions →</a>
          </div>
        <?php else: ?>
          <?php foreach (array_slice($sessions, 0, 4) as $i => $s): ?>
            <div class="sched-item">
              <div class="sched-time"><?= htmlspecialchars($s['day']) ?> <?= htmlspecialchars($s['session_time'] ?? '') ?></div>
              <div class="sched-dot" style="background:<?= subj_color($s['sub'] ?? '') ?>"></div>
              <div>
                <div class="sched-title"><?= htmlspecialchars($s['title']) ?></div>
                <div class="sched-sub"><?= htmlspecialchars($s['gname']) ?> · <?= htmlspecialchars($s['location'] ?? '—') ?></div>
              </div>
              <?php if ($i === 0): ?><span class="sched-badge badge-today">Today</span><?php endif; ?>
              <?php if ($i === 1): ?><span class="sched-badge badge-soon">Soon</span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Recent activity -->
      <div class="card">
        <div class="sec-header">
          <div class="sec-title">Recent Activity</div>
        </div>
        <?php if (empty($my_groups)): ?>
          <div class="act-item">
            <div class="act-icon" style="background:#e6f9f1">👋</div>
            <div>
              <div class="act-text">Welcome to <strong>LSGS!</strong> Head to
                <a href="matching.php" style="color:var(--blue)">Smart Match</a> to join a study group.
              </div>
              <div class="act-time">Just now</div>
            </div>
          </div>
          <div class="act-item">
            <div class="act-icon" style="background:#f3e8ff">💡</div>
            <div>
              <div class="act-text">Join a group to start attending sessions and earning badges.</div>
              <div class="act-time">Tip</div>
            </div>
          </div>
        <?php else: ?>
          <?php foreach (array_slice($my_groups, 0, 4) as $g): ?>
            <div class="act-item">
              <div class="act-icon" style="background:#e8f0ff">◎</div>
              <div>
                <div class="act-text">Member of <strong><?= htmlspecialchars($g['name']) ?></strong></div>
                <div class="act-time"><?= htmlspecialchars($g['subject'] ?? '') ?> · <?= htmlspecialchars($g['days'] ?? 'TBD') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>

    <!-- MY GROUPS PREVIEW -->
    <div class="sec-header">
      <div class="sec-title">My Groups</div>
      <a href="groups.php" class="btn btn-ghost btn-sm">See all</a>
    </div>

    <?php $preview = array_slice($my_groups, 0, 3); ?>

    <?php if (empty($preview)): ?>
      <div class="card empty-state">
        You haven't joined any groups yet.<br/>
        <a href="matching.php">Find groups to join →</a>
      </div>
    <?php else: ?>
      <div class="grid-3">
        <?php foreach ($preview as $g):
          $h  = $g['health'] ?? 80;
          $hc = health_color($h);
        ?>
          <div class="group-card">
            <div class="gc-header">
              <span class="subj-tag <?= subj_class($g['subject'] ?? '') ?>">
                <?= htmlspecialchars($g['subject'] ?? 'General') ?>
              </span>
              <span class="status-dot <?= $h >= 70 ? 'dot-green' : 'dot-orange' ?>">
                <?= $h >= 70 ? '● Active' : '● Needs Attention' ?>
              </span>
            </div>

            <div class="gc-name"><?= htmlspecialchars($g['name']) ?></div>

            <div class="gc-meta">
              <span>👥 <?= $g['mc'] ?> members</span>
              <?php if ($g['days']): ?>
                <span>📅 <?= htmlspecialchars($g['days']) ?></span>
              <?php endif; ?>
              <?php if ($g['location']): ?>
                <span>📍 <?= htmlspecialchars($g['location']) ?></span>
              <?php endif; ?>
            </div>

            <div class="health-wrap">
              <div class="health-row">
                <span>Group Health</span>
                <span style="color:<?= $hc ?>;font-weight:700"><?= $h ?>%</span>
              </div>
              <div class="track">
                <div class="fill" style="width:<?= $h ?>%;<?= $h < 70 ? 'background:linear-gradient(90deg,#f79009,#f59e0b)' : '' ?>"></div>
              </div>
            </div>

            <a href="groups.php" class="btn btn-primary btn-sm"
               style="display:block;text-align:center;margin-top:14px">
              Open Group
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
  // Mobile menu toggle
  if (window.innerWidth <= 820) {
    document.getElementById('menu-btn').style.display = 'block';
  }
  window.addEventListener('resize', () => {
    document.getElementById('menu-btn').style.display =
      window.innerWidth <= 820 ? 'block' : 'none';
  });
</script>

</body>
</html>
