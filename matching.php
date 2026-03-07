<?php
// ============================================================
//  LSGS  |  PAGE 5 — matching.php
//  Smart Group Matching
//  Eswatini College of Technology
// ============================================================

session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: index.php'); exit;
}

require_once __DIR__.'/db.php';

$user      = $_SESSION['user'];
$uid       = (int)$user['id'];
$initials  = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$full_name = htmlspecialchars($user['first_name'].' '.$user['last_name']);
$msg=''; $msg_type='ok';

// ── Migrations ───────────────────────────────────────────────
$conn->query("ALTER TABLE study_groups ADD COLUMN IF NOT EXISTS programme VARCHAR(150) DEFAULT NULL");
$conn->query("ALTER TABLE study_groups ADD COLUMN IF NOT EXISTS is_online TINYINT(1) DEFAULT 0");
// Fix groups with no programme — assign to their creator's programme
$conn->query("UPDATE study_groups g
    JOIN students s ON g.created_by = s.id
    SET g.programme = s.programme
    WHERE (g.programme IS NULL OR g.programme = '') AND g.created_by IS NOT NULL");
$conn->query("ALTER TABLE study_groups ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL");
$conn->query("ALTER TABLE study_groups ADD COLUMN IF NOT EXISTS is_online TINYINT(1) DEFAULT 0");
$conn->query("ALTER TABLE group_members ADD COLUMN IF NOT EXISTS status ENUM('active','blocked') NOT NULL DEFAULT 'active'");

// Current student's programme
$my_programme = $user['programme'] ?? '';

// ── Handle join ──────────────────────────────────────────────
if (isset($_POST['join_group'])) {
    $gid = (int)$_POST['group_id'];
    // Check if blocked
    $chk = $conn->prepare("SELECT status FROM group_members WHERE group_id=? AND student_id=?");
    $chk->bind_param('ii',$gid,$uid); $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    if ($existing && $existing['status'] === 'blocked') {
        $msg = 'You have been blocked from joining this group.'; $msg_type='err';
    } else {
        $st = $conn->prepare("INSERT INTO group_members (group_id,student_id,status) VALUES (?,?,'active') ON DUPLICATE KEY UPDATE status='active'");
        $st->bind_param('ii',$gid,$uid); $st->execute();
        if ($st->affected_rows > 0) {
            $msg = 'You have successfully joined the group!';
        } else {
            $msg = 'You are already a member of this group.'; $msg_type='err';
        }
    }
}

// ── Groups not yet joined — same programme only ───────────────
$available = [];
$st = $conn->prepare("
    SELECT g.*, COUNT(gm2.student_id) AS mc,
           COALESCE(blk.status,'none') AS my_status
    FROM study_groups g
    LEFT JOIN group_members gm2 ON g.id = gm2.group_id AND gm2.status='active'
    LEFT JOIN group_members blk ON g.id = blk.group_id AND blk.student_id = ?
    WHERE g.id NOT IN (
            SELECT group_id FROM group_members WHERE student_id=? AND status='active'
          )
      AND (
        LOWER(TRIM(g.programme)) = LOWER(TRIM(?))
        OR LOWER(TRIM(g.programme)) LIKE CONCAT('%', LOWER(TRIM(?)), '%')
        OR LOWER(TRIM(?)) LIKE CONCAT('%', LOWER(TRIM(g.programme)), '%')
        OR g.programme IS NULL OR g.programme = ''
      )
    GROUP BY g.id ORDER BY g.health DESC, g.name
");
$st->bind_param('issss',$uid,$uid,$my_programme,$my_programme,$my_programme); $st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $available[] = $row;

// ── Joined count ─────────────────────────────────────────────
$jc = $conn->prepare("SELECT COUNT(*) c FROM group_members WHERE student_id=? AND status='active'");
$jc->bind_param('i',$uid); $jc->execute();
$joined_count = $jc->get_result()->fetch_assoc()['c'];

// ── Score algorithm ──────────────────────────────────────────
function calc_score($g) {
    $score  = round(($g['health'] ?? 80) * 0.40);
    $mc     = $g['mc'] ?? 0;
    if ($mc >= 3 && $mc <= 8)    $score += 30;
    elseif ($mc >= 1 && $mc < 3) $score += 20;
    elseif ($mc > 8)             $score += 15;
    else                         $score += 5;
    if ($g['days'] && $g['meeting_time']) $score += 30;
    elseif ($g['days'])                   $score += 15;
    return min($score, 99);
}

foreach ($available as &$g) $g['score'] = calc_score($g);
usort($available, fn($a,$b) => $b['score'] - $a['score']);

// ── Helpers ──────────────────────────────────────────────────
function subj_class($s){
    $m=['Mathematics'=>'s-blue','Computer Science'=>'s-purple','Physics'=>'s-orange',
        'Chemistry'=>'s-green','Biology'=>'s-green','Engineering'=>'s-red',
        'Information Technology'=>'s-blue','Electronics'=>'s-orange','Statistics'=>'s-purple'];
    return $m[$s]??'s-blue';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Smart Match — LSGS</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--navy:#0f1f3d;--blue:#1e6fff;--cyan:#00c2f3;--surface:#f4f6fb;--white:#fff;--border:#e2e8f0;--text:#1a2540;--muted:#6b7a99;--green:#12b76a;--orange:#f79009;--red:#f04438}
body{font-family:'DM Sans',sans-serif;background:var(--surface);color:var(--text);min-height:100vh;display:flex}

/* SIDEBAR */
.sidebar{width:240px;background:var(--navy);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;overflow-y:auto}
.sb-logo{padding:20px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px}
.sb-logo img{width:38px;height:38px;object-fit:contain;background:#fff;border-radius:7px;padding:2px}
.sb-logo-text{font-size:14px;font-weight:800;color:#fff;line-height:1.2}
.sb-logo-sub{font-size:9px;color:rgba(255,255,255,.35);font-family:'DM Mono',monospace}
.sb-nav-wrap{flex:1;padding:14px 12px}
.sb-label{font-size:10px;font-weight:600;letter-spacing:1.2px;color:rgba(255,255,255,.3);text-transform:uppercase;padding:8px 8px 5px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;font-size:13.5px;font-weight:500;color:rgba(255,255,255,.55);transition:all .15s;margin-bottom:2px;text-decoration:none}
.nav-item:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-item.active{background:var(--blue);color:#fff}
.nav-item .ico{font-size:15px;width:18px;text-align:center}
.sb-user{padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px}
.avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0}
.u-name{font-size:13px;font-weight:600;color:#fff}
.u-role{font-size:11px;color:rgba(255,255,255,.4)}
.logout-btn{margin-left:auto;font-size:16px;cursor:pointer;opacity:.4;transition:opacity .15s;background:none;border:none;color:#fff;text-decoration:none}
.logout-btn:hover{opacity:.9}

/* MAIN */
.main{margin-left:240px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:var(--white);border-bottom:1px solid var(--border);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-size:16px;font-weight:700}
.topbar-right{display:flex;align-items:center;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--blue);color:#fff}.btn-primary:hover{background:#1559d4}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface);color:var(--text)}
.btn-sm{padding:5px 12px;font-size:12.5px}
.btn-success{background:var(--green);color:#fff}.btn-success:hover{background:#0da85e}
.btn-blocked{background:#f1f3f8;color:#aab2c8;border:1.5px solid var(--border);cursor:not-allowed;opacity:.7}
.content{padding:28px;flex:1}

/* ALERT */
.alert{padding:12px 16px;border-radius:9px;font-size:13.5px;font-weight:500;margin-bottom:20px;border:1px solid}
.alert-ok{background:#e6f9f1;color:#065f46;border-color:#a7f3d0}
.alert-err{background:#fff0f0;color:#b91c1c;border-color:#fecaca}

/* HERO BANNER */
.hero{background:linear-gradient(135deg,#060f1e 0%,#0f2247 60%,#0a3570 100%);border-radius:16px;padding:28px 32px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:20px;position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(0,194,243,.07) 0%,transparent 65%);top:-100px;right:-100px;pointer-events:none}
.hero-text h2{font-size:22px;font-weight:800;color:#fff;margin-bottom:8px}
.hero-text p{font-size:13.5px;color:rgba(255,255,255,.55);max-width:460px;line-height:1.65}
.hero-stats{display:flex;gap:16px;flex-shrink:0}
.h-stat{background:rgba(255,255,255,.08);border-radius:12px;padding:16px 22px;text-align:center}
.h-stat-num{font-size:26px;font-weight:800;color:var(--cyan)}
.h-stat-lbl{font-size:10.5px;color:rgba(255,255,255,.45);margin-top:3px;text-transform:uppercase;letter-spacing:.6px}

/* FILTER */
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.filter-label{font-size:12px;font-weight:700;color:var(--muted);white-space:nowrap}
.chip{padding:5px 14px;border-radius:20px;font-size:12.5px;font-weight:500;border:1px solid var(--border);background:var(--white);cursor:pointer;color:var(--muted);transition:all .12s}
.chip.active,.chip:hover{background:var(--blue);color:#fff;border-color:var(--blue)}

/* SEARCH */
.search-bar{display:flex;align-items:center;gap:10px;background:var(--white);border:1px solid var(--border);border-radius:10px;padding:10px 16px;margin-bottom:22px}
.search-bar input{border:none;background:transparent;font-family:inherit;font-size:13.5px;color:var(--text);flex:1;outline:none}

/* SECTION HEADER */
.sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.sec-title{font-size:15px;font-weight:700}
.result-count{font-size:13px;color:var(--muted)}

/* MATCH CARDS */
.match-list{display:flex;flex-direction:column;gap:14px}
.match-card{background:var(--white);border:1px solid var(--border);border-radius:14px;padding:20px 22px;display:flex;align-items:center;gap:20px;transition:all .15s}
.match-card:hover{border-color:var(--blue);box-shadow:0 4px 20px rgba(30,111,255,.1);transform:translateY(-1px)}

/* Score circle */
.score-circle{width:60px;height:60px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;border:3px solid}
.score-num{font-size:16px;font-weight:800;line-height:1}
.score-pct{font-size:9px;font-weight:600;opacity:.75;margin-top:1px}

.match-info{flex:1;min-width:0}
.match-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px}
.match-name{font-size:15px;font-weight:700}
.subj-tag{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.s-blue{background:#e8f0ff;color:var(--blue)}.s-green{background:#e6f9f1;color:#0a9457}
.s-orange{background:#fff4e5;color:#b45309}.s-purple{background:#f3e8ff;color:#7c3aed}
.s-red{background:#fff0f0;color:#b91c1c}
.match-quality{font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px}
.mq-great{background:#e6f9f1;color:#065f46}
.mq-good{background:#fff4e5;color:#92400e}
.mq-ok{background:#fff0f0;color:#b91c1c}
.match-meta{font-size:12.5px;color:var(--muted);display:flex;gap:16px;flex-wrap:wrap;margin-bottom:8px}
.match-tags{display:flex;gap:6px;flex-wrap:wrap}
.tag{padding:2px 9px;border-radius:6px;font-size:11px;font-weight:500;background:var(--surface);color:var(--muted);border:1px solid var(--border)}
.match-action{flex-shrink:0}

/* HEALTH BAR */
.mini-health{margin-top:8px;display:flex;align-items:center;gap:8px}
.mini-track{flex:1;height:4px;background:#eef1f8;border-radius:10px;overflow:hidden}
.mini-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--blue),var(--cyan))}
.mini-pct{font-size:11px;color:var(--muted);font-family:'DM Mono',monospace;white-space:nowrap}

/* EMPTY */
.empty-card{background:var(--white);border:1px solid var(--border);border-radius:14px;padding:60px 20px;text-align:center;color:var(--muted)}
.empty-icon{font-size:52px;margin-bottom:16px}
.empty-title{font-size:17px;font-weight:700;color:var(--text);margin-bottom:8px}
.empty-sub{font-size:13.5px;color:var(--muted);margin-bottom:24px}

@media(max-width:820px){
  .sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}
  .main{margin-left:0}.hero{flex-direction:column}.hero-stats{width:100%;justify-content:center}
  .match-card{flex-wrap:wrap}.match-action{width:100%}.match-action .btn{width:100%;justify-content:center}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-logo">
    <img src="assets/ecot.jpg" alt="ECOT Logo"/>
    <div><div class="sb-logo-text">LSGS</div><div class="sb-logo-sub">ECOT STUDY GROUPS</div></div>
  </div>
  <nav class="sb-nav-wrap">
    <div class="sb-label">Main</div>
    <a class="nav-item" href="dashboard.php"><span class="ico">⊞</span> Dashboard</a>
    <a class="nav-item" href="groups.php"><span class="ico">◎</span> My Groups</a>
    <a class="nav-item active" href="matching.php"><span class="ico">⌖</span> Smart Match</a>
    <a class="nav-item" href="schedule.php"><span class="ico">◷</span> Schedule</a>
    <div class="sb-label" style="margin-top:10px">Resources</div>
    <a class="nav-item" href="resources.php"><span class="ico">⊟</span> Shared Files</a>
    <a class="nav-item" href="progress.php"><span class="ico">◈</span> Progress</a>
    <a class="nav-item" href="attendance.php"><span class="ico">✓</span> Attendance</a>
    <a class="nav-item" href="badges.php"><span class="ico">◆</span> Badges</a>
  </nav>
  <div class="sb-user">
    <div class="avatar"><?= $initials ?></div>
    <div><div class="u-name"><?= $full_name ?></div><div class="u-role"><?= htmlspecialchars($user['year']??'Student') ?></div></div>
    <a href="logout.php" class="logout-btn" title="Sign out">⏻</a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:14px">
      <button id="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')"
        style="display:none;background:none;border:none;cursor:pointer;font-size:22px">☰</button>
      <div class="topbar-title">Smart Match</div>
    </div>
    <div class="topbar-right">
      <a href="groups.php" class="btn btn-ghost btn-sm">My Groups (<?= $joined_count ?>)</a>
    </div>
  </header>

  <div class="content">

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msg_type ?>">
        <?= $msg_type==='ok'?'✓':'⚠' ?> <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <!-- HERO BANNER -->
    <div class="hero">
      <div class="hero-text">
        <h2>⌖ Smart Group Matching</h2>
        <p>Showing groups from your programme: <strong style="color:var(--cyan)"><?= htmlspecialchars($my_programme ?: 'All') ?></strong>. Groups are scored by health, size and schedule.</p>
      </div>
      <div class="hero-stats">
        <div class="h-stat">
          <div class="h-stat-num"><?= count($available) ?></div>
          <div class="h-stat-lbl">Available</div>
        </div>
        <div class="h-stat">
          <div class="h-stat-num"><?= $joined_count ?></div>
          <div class="h-stat-lbl">Joined</div>
        </div>
      </div>
    </div>

    <!-- SEARCH -->
    <div class="search-bar">
      <span style="color:var(--muted);font-size:16px">🔍</span>
      <input type="text" id="search-input" placeholder="Search by group name or subject..." oninput="filterCards()"/>
    </div>

    <!-- FILTER CHIPS -->
    <div class="filter-bar">
      <span class="filter-label">Filter:</span>
      <div class="chip active" data-f="All" onclick="setFilter(this)">All Groups</div>
      <div class="chip" data-f="high"  onclick="setFilter(this)">⭐ Top Match (80%+)</div>
      <div class="chip" data-f="small" onclick="setFilter(this)">👥 Small Groups</div>
      <div class="chip" data-f="sched" onclick="setFilter(this)">📅 Has Schedule</div>
    </div>

    <!-- RESULTS HEADER -->
    <div class="sec-header">
      <div class="sec-title">Recommended Groups</div>
      <div class="result-count" id="result-count"><?= count($available) ?> group<?= count($available)!==1?'s':'' ?> found</div>
    </div>

    <!-- MATCH LIST -->
    <?php if (empty($available)): ?>
      <div class="empty-card">
        <div class="empty-icon">🎉</div>
        <div class="empty-title">No groups available in your programme!</div>
        <div class="empty-sub">No groups from <strong><?= htmlspecialchars($my_programme ?: 'your programme') ?></strong> yet, or you've joined them all. Create your own!</div>
        <a href="groups.php" class="btn btn-primary">Create a New Group</a>
      </div>
    <?php else: ?>
      <div class="match-list" id="match-list">
        <?php foreach ($available as $g):
          $sc  = $g['score'];
          $hc  = $sc >= 80 ? '#12b76a' : ($sc >= 60 ? '#f79009' : '#f04438');
          $ql  = $sc >= 80 ? 'mq-great' : ($sc >= 60 ? 'mq-good' : 'mq-ok');
          $qlb = $sc >= 80 ? 'Excellent Match' : ($sc >= 60 ? 'Good Match' : 'Possible Match');
          $h   = $g['health'] ?? 80;
          $hbc = $h >= 80 ? 'var(--blue)' : ($h >= 60 ? 'var(--orange)' : 'var(--red)');
        ?>
          <div class="match-card"
               data-name="<?= strtolower(htmlspecialchars($g['name'])) ?>"
               data-subj="<?= strtolower(htmlspecialchars($g['subject']??'')) ?>"
               data-score="<?= $sc ?>"
               data-mc="<?= $g['mc'] ?>"
               data-sched="<?= ($g['days'] ? '1' : '0') ?>">

            <!-- Score circle -->
            <div class="score-circle" style="border-color:<?= $hc ?>;color:<?= $hc ?>">
              <div class="score-num"><?= $sc ?></div>
              <div class="score-pct">MATCH</div>
            </div>

            <!-- Info -->
            <div class="match-info">
              <div class="match-top">
                <div class="match-name"><?= htmlspecialchars($g['name']) ?></div>
                <span class="subj-tag <?= subj_class($g['subject']??'') ?>">
                  <?= htmlspecialchars($g['subject']??'General') ?>
                </span>
                <span class="match-quality <?= $ql ?>"><?= $qlb ?></span>
              </div>

              <div class="match-meta">
                <span>👥 <?= $g['mc'] ?> member<?= $g['mc']!=1?'s':'' ?></span>
                <?php if ($g['days']): ?>
                  <span>📅 <?= htmlspecialchars($g['days']) ?> <?= htmlspecialchars($g['meeting_time']??'') ?></span>
                <?php endif; ?>
                <?php if ($g['location']): ?>
                  <span>📍 <?= htmlspecialchars($g['location']) ?></span>
                <?php endif; ?>
              </div>

              <div class="match-tags">
                <span class="tag"><?= htmlspecialchars($g['skill_level']??'Intermediate') ?></span>
                <?php if ($g['days']): ?><span class="tag">Has Schedule</span><?php endif; ?>
                <?php if ($g['mc'] <= 5): ?><span class="tag">Small Group</span><?php endif; ?>
                <?php if ($h >= 80): ?><span class="tag">🔥 High Activity</span><?php endif; ?>
              </div>

              <div class="mini-health">
                <span style="font-size:11px;color:var(--muted)">Health</span>
                <div class="mini-track">
                  <div class="mini-fill" style="width:<?= $h ?>%;background:<?= $hbc ?>"></div>
                </div>
                <div class="mini-pct"><?= $h ?>%</div>
              </div>
            </div>

            <!-- Join button -->
            <div class="match-action">
              <?php if (($g['my_status'] ?? 'none') === 'blocked'): ?>
                <button class="btn btn-blocked" disabled title="You are blocked from this group">
                  🚫 Blocked
                </button>
              <?php else: ?>
              <form method="POST">
                <input type="hidden" name="join_group" value="1"/>
                <input type="hidden" name="group_id" value="<?= $g['id'] ?>"/>
                <button class="btn btn-primary" type="submit">Join Group</button>
              </form>
              <?php endif; ?>
            </div>

          </div>
        <?php endforeach; ?>
      </div>

      <!-- No results -->
      <div id="no-results" style="display:none">
        <div class="empty-card">
          <div class="empty-icon">🔍</div>
          <div class="empty-title">No groups match your search</div>
          <div class="empty-sub">Try a different keyword or remove filters.</div>
          <button class="btn btn-ghost" onclick="clearSearch()">Clear Search</button>
        </div>
      </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
  let activeFilter = 'All';

  function setFilter(el) {
    document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    activeFilter = el.dataset.f;
    filterCards();
  }

  function filterCards() {
    const q     = document.getElementById('search-input').value.toLowerCase();
    const cards = document.querySelectorAll('#match-list .match-card');
    let visible = 0;

    cards.forEach(card => {
      const matchQ = !q || card.dataset.name.includes(q) || card.dataset.subj.includes(q);
      let matchF   = true;
      if (activeFilter === 'high')  matchF = parseInt(card.dataset.score) >= 80;
      if (activeFilter === 'small') matchF = parseInt(card.dataset.mc) <= 5;
      if (activeFilter === 'sched') matchF = card.dataset.sched === '1';

      const show = matchQ && matchF;
      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    const noRes = document.getElementById('no-results');
    const count = document.getElementById('result-count');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
    if (count) count.textContent = visible + ' group' + (visible !== 1 ? 's' : '') + ' found';
  }

  function clearSearch() {
    document.getElementById('search-input').value = '';
    document.querySelectorAll('.chip').forEach((c,i) => c.classList.toggle('active', i===0));
    activeFilter = 'All';
    filterCards();
  }

  if (window.innerWidth <= 820) document.getElementById('menu-btn').style.display = 'block';
  window.addEventListener('resize', () => {
    document.getElementById('menu-btn').style.display = window.innerWidth <= 820 ? 'block' : 'none';
  });
</script>
</body>
</html>
