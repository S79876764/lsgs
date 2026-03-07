<?php
// ============================================================
//  LSGS  |  PAGE 9 — attendance.php
//  Session Attendance Tracker
//  Eswatini College of Technology
// ============================================================
session_start();
if(!isset($_SESSION['user'])||$_SESSION['user']['role']!=='student'){header('Location: index.php');exit;}
require_once __DIR__.'/db.php';
$user=$_SESSION['user'];$uid=(int)$user['id'];
$initials=strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$full_name=htmlspecialchars($user['first_name'].' '.$user['last_name']);
$msg='';$msg_type='ok';

// ── Handle mark attendance ────────────────────────────────────
if(isset($_POST['mark_attendance'])){
    $sid=(int)$_POST['session_id'];
    $status=in_array($_POST['status'],['Present','Absent'])?$_POST['status']:'Present';
    $st=$conn->prepare("INSERT INTO attendance (student_id,session_id,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=?");
    $st->bind_param('iiss',$uid,$sid,$status,$status);$st->execute();
    $msg='Attendance recorded as '.$status.'!';
    if($status==='Absent')$msg_type='err';
}

// ── Fetch all sessions ────────────────────────────────────────
$sessions=[];
$r=$conn->query("SELECT s.*,g.name gn,g.subject sub FROM sessions s JOIN study_groups g ON s.group_id=g.id ORDER BY FIELD(s.day,'Mon','Tue','Wed','Thu','Fri','Sat'),s.session_time");
while($row=$r->fetch_assoc())$sessions[]=$row;

// ── Fetch my attendance records ───────────────────────────────
$my_att=[];
$st=$conn->prepare("SELECT session_id,status FROM attendance WHERE student_id=?");
$st->bind_param('i',$uid);$st->execute();$r=$st->get_result();
while($row=$r->fetch_assoc())$my_att[$row['session_id']]=$row['status'];

// ── Stats ─────────────────────────────────────────────────────
$att_total=count($my_att);
$att_present=count(array_filter($my_att,fn($s)=>$s==='Present'));
$att_absent=$att_total-$att_present;
$att_rate=$att_total>0?round($att_present/$att_total*100):0;
$not_marked=count($sessions)-$att_total;

// ── Per-day breakdown ─────────────────────────────────────────
$days_order=['Mon','Tue','Wed','Thu','Fri','Sat'];
$day_stats=[];
foreach($days_order as $d){
    $day_sessions=array_filter($sessions,fn($s)=>$s['day']===$d);
    $day_total=count($day_sessions);
    $day_present=0;
    foreach($day_sessions as $s){
        if(($my_att[$s['id']]??'')===('Present'))$day_present++;
    }
    if($day_total>0)$day_stats[$d]=['total'=>$day_total,'present'=>$day_present,'rate'=>round($day_present/$day_total*100)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Attendance — LSGS</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--navy:#0f1f3d;--blue:#1e6fff;--cyan:#00c2f3;--surface:#f4f6fb;--white:#fff;--border:#e2e8f0;--text:#1a2540;--muted:#6b7a99;--green:#12b76a;--orange:#f79009;--red:#f04438}
body{font-family:'DM Sans',sans-serif;background:var(--surface);color:var(--text);min-height:100vh;display:flex}
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
.u-name{font-size:13px;font-weight:600;color:#fff}.u-role{font-size:11px;color:rgba(255,255,255,.4)}
.logout-btn{margin-left:auto;font-size:16px;cursor:pointer;opacity:.4;transition:opacity .15s;background:none;border:none;color:#fff;text-decoration:none}
.logout-btn:hover{opacity:.9}
.main{margin-left:240px;flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:var(--white);border-bottom:1px solid var(--border);padding:0 28px;height:60px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-size:16px;font-weight:700}
.topbar-right{display:flex;align-items:center;gap:12px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--blue);color:#fff}.btn-primary:hover{background:#1559d4}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface)}
.btn-success{background:var(--green);color:#fff;font-size:12px;padding:5px 12px}.btn-success:hover{background:#0da85e}
.btn-absent{background:transparent;color:var(--red);border:1.5px solid #fecaca;font-size:12px;padding:5px 12px}.btn-absent:hover{background:#fff0f0}
.btn-sm{padding:5px 12px;font-size:12.5px}
.content{padding:28px;flex:1}
.alert{padding:12px 16px;border-radius:9px;font-size:13.5px;font-weight:500;margin-bottom:20px;border:1px solid}
.alert-ok{background:#e6f9f1;color:#065f46;border-color:#a7f3d0}
.alert-err{background:#fff0f0;color:#b91c1c;border-color:#fecaca}

/* RATE CARD */
.rate-card{background:linear-gradient(135deg,#060f1e,#0f2247);border-radius:16px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:32px}
.rate-circle{width:110px;height:110px;border-radius:50%;border:6px solid rgba(255,255,255,.1);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;position:relative}
.rate-inner{position:absolute;inset:0;border-radius:50%;border:6px solid transparent}
.rate-num{font-size:30px;font-weight:800;color:#fff;line-height:1}
.rate-lbl{font-size:11px;color:rgba(255,255,255,.45);margin-top:2px}
.rate-info h3{font-size:20px;font-weight:800;color:#fff;margin-bottom:6px}
.rate-info p{font-size:13.5px;color:rgba(255,255,255,.55);line-height:1.6;margin-bottom:14px}
.rate-pills{display:flex;gap:12px;flex-wrap:wrap}
.r-pill{background:rgba(255,255,255,.08);border-radius:8px;padding:8px 16px;text-align:center}
.r-pill-num{font-size:20px;font-weight:800}
.r-pill-lbl{font-size:10px;color:rgba(255,255,255,.4);margin-top:2px;text-transform:uppercase;letter-spacing:.4px}

/* STAT GRID */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px}
.stat-lbl{font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.stat-val{font-size:28px;font-weight:800;color:var(--text)}
.stat-sub{font-size:12px;color:var(--muted);margin-top:4px}

/* DAY BREAKDOWN */
.day-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:24px}
.day-card{background:var(--white);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center}
.day-name{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px}
.day-rate{font-size:22px;font-weight:800;margin-bottom:4px}
.day-sub{font-size:11px;color:var(--muted)}
.day-bar{height:4px;background:#eef1f8;border-radius:10px;overflow:hidden;margin-top:8px}
.day-fill{height:100%;border-radius:10px}

/* TABLE */
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:22px}
.card-title{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:16px}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{font-size:11.5px;font-weight:700;color:var(--muted);text-align:left;padding:10px 14px;background:var(--surface);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.3px}
td{font-size:13.5px;padding:12px 14px;border-bottom:1px solid var(--border)}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafbff}
.pill{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600}
.pill-green{background:#e6f9f1;color:#0a9457}
.pill-red{background:#fff0f0;color:#b91c1c}
.pill-muted{background:var(--surface);color:var(--muted)}
.att-actions{display:flex;gap:6px}

@media(max-width:1200px){.day-grid{grid-template-columns:repeat(3,1fr)}.stat-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:820px){.sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.rate-card{flex-direction:column}.day-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<aside class="sidebar" id="sidebar">
  <div class="sb-logo"><img src="assets/ecot.jpg" alt="ECOT"/><div><div class="sb-logo-text">LSGS</div><div class="sb-logo-sub">ECOT STUDY GROUPS</div></div></div>
  <nav class="sb-nav-wrap">
    <div class="sb-label">Main</div>
    <a class="nav-item" href="dashboard.php"><span class="ico">⊞</span> Dashboard</a>
    <a class="nav-item" href="groups.php"><span class="ico">◎</span> My Groups</a>
    <a class="nav-item" href="matching.php"><span class="ico">⌖</span> Smart Match</a>
    <a class="nav-item" href="schedule.php"><span class="ico">◷</span> Schedule</a>
    <div class="sb-label" style="margin-top:10px">Resources</div>
    <a class="nav-item" href="resources.php"><span class="ico">⊟</span> Shared Files</a>
    <a class="nav-item" href="progress.php"><span class="ico">◈</span> Progress</a>
    <a class="nav-item active" href="attendance.php"><span class="ico">✓</span> Attendance</a>
    <a class="nav-item" href="badges.php"><span class="ico">◆</span> Badges</a>
  </nav>
  <div class="sb-user">
    <div class="avatar"><?=$initials?></div>
    <div><div class="u-name"><?=$full_name?></div><div class="u-role"><?=htmlspecialchars($user['year']??'Student')?></div></div>
    <a href="logout.php" class="logout-btn">⏻</a>
  </div>
</aside>

<div class="main">
  <header class="topbar">
    <div style="display:flex;align-items:center;gap:14px">
      <button id="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')" style="display:none;background:none;border:none;cursor:pointer;font-size:22px">☰</button>
      <div class="topbar-title">Attendance</div>
    </div>
    <div class="topbar-right">
      <span style="font-size:13px;color:var(--muted)"><?=$not_marked?> session<?=$not_marked!=1?'s':''?> not marked</span>
    </div>
  </header>

  <div class="content">
    <?php if($msg):?><div class="alert alert-<?=$msg_type?>"><?=$msg_type==='ok'?'✓ ':'⚠ '?><?=htmlspecialchars($msg)?></div><?php endif;?>

    <!-- RATE CARD -->
    <?php
    $rc=$att_rate>=80?'#12b76a':($att_rate>=60?'#f79009':'#f04438');
    $grade=$att_rate>=90?'Excellent':($att_rate>=75?'Good':($att_rate>=60?'Fair':'Needs Improvement'));
    ?>
    <div class="rate-card">
      <div class="rate-circle" style="border-color:<?=$rc?>40">
        <div style="position:absolute;inset:0;border-radius:50%;border:6px solid <?=$rc?>;clip-path:inset(0 <?=100-$att_rate?>% 0 0 round 50%)"></div>
        <div class="rate-num" style="color:<?=$rc?>"><?=$att_rate?>%</div>
        <div class="rate-lbl">Rate</div>
      </div>
      <div class="rate-info">
        <h3>Attendance: <?=$grade?></h3>
        <p>You have attended <?=$att_present?> out of <?=$att_total?> recorded sessions.
          <?php if($att_rate>=80):?>Keep it up — excellent consistency!
          <?php elseif($att_rate>=60):?>Good progress, try to attend more sessions.
          <?php else:?>You need to attend more sessions to improve your score.<?php endif;?>
        </p>
        <div class="rate-pills">
          <div class="r-pill"><div class="r-pill-num" style="color:var(--cyan)"><?=$att_present?></div><div class="r-pill-lbl">Present</div></div>
          <div class="r-pill"><div class="r-pill-num" style="color:#f87171"><?=$att_absent?></div><div class="r-pill-lbl">Absent</div></div>
          <div class="r-pill"><div class="r-pill-num" style="color:rgba(255,255,255,.6)"><?=$not_marked?></div><div class="r-pill-lbl">Not Marked</div></div>
          <div class="r-pill"><div class="r-pill-num" style="color:rgba(255,255,255,.6)"><?=count($sessions)?></div><div class="r-pill-lbl">Total</div></div>
        </div>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-lbl">Present</div><div class="stat-val" style="color:var(--green)"><?=$att_present?></div><div class="stat-sub">sessions attended</div></div>
      <div class="stat-card"><div class="stat-lbl">Absent</div><div class="stat-val" style="color:var(--red)"><?=$att_absent?></div><div class="stat-sub">sessions missed</div></div>
      <div class="stat-card"><div class="stat-lbl">Not Marked</div><div class="stat-val" style="color:var(--orange)"><?=$not_marked?></div><div class="stat-sub">awaiting attendance</div></div>
      <div class="stat-card"><div class="stat-lbl">Total Sessions</div><div class="stat-val"><?=count($sessions)?></div><div class="stat-sub">in the system</div></div>
    </div>

    <!-- PER-DAY BREAKDOWN -->
    <?php if(!empty($day_stats)):?>
    <div style="font-size:15px;font-weight:700;margin-bottom:14px">Attendance by Day</div>
    <div class="day-grid">
      <?php foreach($day_stats as $d=>$ds):
        $dc=$ds['rate']>=80?'var(--green)':($ds['rate']>=60?'var(--orange)':'var(--red)');
      ?>
        <div class="day-card">
          <div class="day-name"><?=$d?></div>
          <div class="day-rate" style="color:<?=$dc?>"><?=$ds['rate']?>%</div>
          <div class="day-sub"><?=$ds['present']?>/<?=$ds['total']?> present</div>
          <div class="day-bar"><div class="day-fill" style="width:<?=$ds['rate']?>%;background:<?=$dc?>"></div></div>
        </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>

    <!-- SESSIONS TABLE -->
    <div class="card">
      <div class="card-title">All Sessions — Mark Your Attendance</div>
      <?php if(empty($sessions)):?>
        <div style="text-align:center;color:var(--muted);padding:40px;font-size:13px">No sessions scheduled yet. Admin can add sessions from the admin panel.</div>
      <?php else:?>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr><th>Session</th><th>Group</th><th>Day</th><th>Time</th><th>Location</th><th>Status</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php foreach($sessions as $s):
              $status=$my_att[$s['id']]??null;
            ?>
              <tr>
                <td><strong><?=htmlspecialchars($s['title'])?></strong></td>
                <td><?=htmlspecialchars($s['gn'])?></td>
                <td><?=htmlspecialchars($s['day']??'—')?></td>
                <td style="font-family:'DM Mono',monospace;font-size:12px"><?=htmlspecialchars($s['session_time']??'—')?></td>
                <td><?=htmlspecialchars($s['location']??'—')?></td>
                <td>
                  <?php if($status==='Present'):?>
                    <span class="pill pill-green">✓ Present</span>
                  <?php elseif($status==='Absent'):?>
                    <span class="pill pill-red">✗ Absent</span>
                  <?php else:?>
                    <span class="pill pill-muted">Not Marked</span>
                  <?php endif;?>
                </td>
                <td>
                  <div class="att-actions">
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="mark_attendance" value="1"/>
                      <input type="hidden" name="session_id" value="<?=$s['id']?>"/>
                      <input type="hidden" name="status" value="Present"/>
                      <button class="btn btn-success" type="submit">Present</button>
                    </form>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="mark_attendance" value="1"/>
                      <input type="hidden" name="session_id" value="<?=$s['id']?>"/>
                      <input type="hidden" name="status" value="Absent"/>
                      <button class="btn btn-absent" type="submit">Absent</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      </div>
      <?php endif;?>
    </div>

  </div>
</div>
<script>
if(window.innerWidth<=820)document.getElementById('menu-btn').style.display='block';
window.addEventListener('resize',()=>{document.getElementById('menu-btn').style.display=window.innerWidth<=820?'block':'none'});
</script>
</body>
</html>
