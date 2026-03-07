<?php
// ============================================================
//  LSGS  |  PAGE 6 — schedule.php
//  Schedule & Reminders
//  Eswatini College of Technology
// ============================================================
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') { header('Location: index.php'); exit; }
require_once __DIR__.'/db.php';
$user=$_SESSION['user'];$uid=(int)$user['id'];
$initials=strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$full_name=htmlspecialchars($user['first_name'].' '.$user['last_name']);
$msg='';$msg_type='ok';

// ── Handle reminder add/delete ────────────────────────────────
if(isset($_POST['add_reminder'])){
    $title=trim($_POST['r_title']??'');
    $at=trim($_POST['r_datetime']??'');
    $grp=trim($_POST['r_group']??'');
    $loc=trim($_POST['r_location']??'');
    if($title&&$at){
        $st=$conn->prepare("INSERT INTO reminders (student_id,title,remind_at,group_name,location) VALUES (?,?,?,?,?)");
        $st->bind_param('issss',$uid,$title,$at,$grp,$loc);$st->execute();
        $msg='Reminder saved!';
    } else { $msg='Title and date/time are required.';$msg_type='err'; }
}
if(isset($_POST['del_reminder'])){
    $rid=(int)$_POST['rid'];
    $st=$conn->prepare("DELETE FROM reminders WHERE id=? AND student_id=?");
    $st->bind_param('ii',$rid,$uid);$st->execute();
    $msg='Reminder deleted.';$msg_type='err';
}

// ── Fetch sessions ────────────────────────────────────────────
$sessions=[];
$r=$conn->query("SELECT s.*,g.name gn,g.subject sub FROM sessions s JOIN study_groups g ON s.group_id=g.id ORDER BY FIELD(s.day,'Mon','Tue','Wed','Thu','Fri','Sat'),s.session_time");
while($row=$r->fetch_assoc())$sessions[]=$row;

// ── Fetch my reminders ────────────────────────────────────────
$reminders=[];
$st=$conn->prepare("SELECT * FROM reminders WHERE student_id=? ORDER BY remind_at ASC");
$st->bind_param('i',$uid);$st->execute();$r=$st->get_result();
while($row=$r->fetch_assoc())$reminders[]=$row;

// ── My groups for dropdown ────────────────────────────────────
$my_groups=[];
$st=$conn->prepare("SELECT g.id,g.name FROM study_groups g JOIN group_members gm ON g.id=gm.group_id WHERE gm.student_id=? ORDER BY g.name");
$st->bind_param('i',$uid);$st->execute();$r=$st->get_result();
while($row=$r->fetch_assoc())$my_groups[]=$row;

function sx($s){$m=['Mathematics'=>'#1e6fff','Computer Science'=>'#7c3aed','Physics'=>'#f79009','Chemistry'=>'#12b76a','Biology'=>'#0891b2','Engineering'=>'#f04438','Information Technology'=>'#1e6fff','Electronics'=>'#f79009','Statistics'=>'#7c3aed'];return $m[$s]??'#1e6fff';}
$days_order=['Mon','Tue','Wed','Thu','Fri','Sat'];
$grouped=[];foreach($sessions as $s)$grouped[$s['day']][]=$s;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Schedule — LSGS</title>
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
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface);color:var(--text)}
.btn-danger{background:transparent;color:var(--red);border:1.5px solid #fecaca}.btn-danger:hover{background:#fff0f0}
.btn-sm{padding:5px 12px;font-size:12.5px}
.content{padding:28px;flex:1}
.alert{padding:12px 16px;border-radius:9px;font-size:13.5px;font-weight:500;margin-bottom:20px;border:1px solid}
.alert-ok{background:#e6f9f1;color:#065f46;border-color:#a7f3d0}
.alert-err{background:#fff0f0;color:#b91c1c;border-color:#fecaca}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:24px}
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:22px}
.card-title{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:16px}
.sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.sec-title{font-size:15px;font-weight:700}

/* WEEK VIEW */
.week-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:24px}
.day-col{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.day-header{padding:10px 12px;border-bottom:1px solid var(--border);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);background:var(--surface)}
.day-header.has-sessions{background:var(--navy);color:rgba(255,255,255,.7)}
.day-sessions{padding:8px}
.session-pill{border-radius:8px;padding:8px 10px;margin-bottom:6px;border-left:3px solid}
.session-pill:last-child{margin-bottom:0}
.sp-time{font-size:10px;font-family:'DM Mono',monospace;color:var(--muted);margin-bottom:3px}
.sp-title{font-size:12px;font-weight:600;line-height:1.3}
.sp-group{font-size:11px;color:var(--muted);margin-top:2px}
.day-empty{padding:12px;text-align:center;font-size:11px;color:var(--muted);opacity:.6}

/* SESSIONS LIST */
.sched-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.sched-item:last-child{border-bottom:none}
.sched-time{min-width:70px;font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);font-weight:500}
.sched-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.sched-title{font-size:13.5px;font-weight:600}
.sched-sub{font-size:12px;color:var(--muted);margin-top:2px}
.sched-badge{margin-left:auto;padding:2px 9px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-today{background:#e8f0ff;color:var(--blue)}
.badge-soon{background:#f0fdf4;color:#16a34a}

/* REMINDERS */
.reminder-item{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.reminder-item:last-child{border-bottom:none}
.rem-icon{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.rem-title{font-size:13.5px;font-weight:600}
.rem-meta{font-size:11.5px;color:var(--muted);margin-top:2px}
.rem-time{font-size:11px;font-family:'DM Mono',monospace;color:var(--blue);font-weight:600;margin-left:auto;white-space:nowrap}

/* FORMS */
.fg{margin-bottom:14px}
.fl{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px}
.fi,.fs{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13.5px;color:var(--text);background:var(--white);outline:none;transition:border-color .15s}
.fi:focus,.fs:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(30,111,255,.09)}
.fr2{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* EMPTY */
.empty-state{text-align:center;color:var(--muted);padding:30px 20px;font-size:13px}

@media(max-width:1200px){.week-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:820px){.sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.grid-2{grid-template-columns:1fr}.week-grid{grid-template-columns:repeat(2,1fr)}}
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
    <a class="nav-item active" href="schedule.php"><span class="ico">◷</span> Schedule</a>
    <div class="sb-label" style="margin-top:10px">Resources</div>
    <a class="nav-item" href="resources.php"><span class="ico">⊟</span> Shared Files</a>
    <a class="nav-item" href="progress.php"><span class="ico">◈</span> Progress</a>
    <a class="nav-item" href="attendance.php"><span class="ico">✓</span> Attendance</a>
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
      <div class="topbar-title">Schedule</div>
    </div>
    <div class="topbar-right">
      <button class="btn btn-primary" onclick="openModal('modal-reminder')">+ Add Reminder</button>
    </div>
  </header>

  <div class="content">
    <?php if($msg):?><div class="alert alert-<?=$msg_type?>"><?=$msg_type==='ok'?'✓ ':'⚠ '?><?=htmlspecialchars($msg)?></div><?php endif;?>

    <!-- WEEK VIEW -->
    <div class="sec-header"><div class="sec-title">Weekly Timetable</div><span style="font-size:12px;color:var(--muted)"><?=count($sessions)?> sessions scheduled</span></div>
    <div class="week-grid">
      <?php foreach($days_order as $day):
        $day_sessions=$grouped[$day]??[];
        $has=count($day_sessions)>0;
      ?>
        <div class="day-col">
          <div class="day-header <?=$has?'has-sessions':''?>"><?=$day?></div>
          <?php if(empty($day_sessions)):?>
            <div class="day-empty">No sessions</div>
          <?php else:foreach($day_sessions as $s):
            $bg=sx($s['sub']??'');
          ?>
            <div class="session-pill" style="background:<?=$bg?>18;border-color:<?=$bg?>">
              <div class="sp-time"><?=htmlspecialchars($s['session_time']??'')?></div>
              <div class="sp-title"><?=htmlspecialchars($s['title'])?></div>
              <div class="sp-group"><?=htmlspecialchars($s['gn'])?></div>
            </div>
          <?php endforeach;endif;?>
        </div>
      <?php endforeach;?>
    </div>

    <!-- LIST + REMINDERS -->
    <div class="grid-2">
      <!-- All sessions list -->
      <div class="card">
        <div class="card-title">All Sessions</div>
        <?php if(empty($sessions)):?>
          <div class="empty-state">No sessions scheduled yet.<br/>Admin can add sessions from the admin panel.</div>
        <?php else:foreach($sessions as $i=>$s):?>
          <div class="sched-item">
            <div class="sched-time"><?=htmlspecialchars($s['day'])?> <?=htmlspecialchars($s['session_time']??'')?></div>
            <div class="sched-dot" style="background:<?=sx($s['sub']??'')?>"></div>
            <div>
              <div class="sched-title"><?=htmlspecialchars($s['title'])?></div>
              <div class="sched-sub"><?=htmlspecialchars($s['gn'])?> · <?=htmlspecialchars($s['location']??'—')?></div>
            </div>
            <?php if($i===0):?><span class="sched-badge badge-today">Today</span><?php endif;?>
            <?php if($i===1):?><span class="sched-badge badge-soon">Soon</span><?php endif;?>
          </div>
        <?php endforeach;endif;?>
      </div>

      <!-- My reminders -->
      <div class="card">
        <div class="sec-header">
          <div class="card-title" style="margin:0">My Reminders</div>
          <button class="btn btn-primary btn-sm" onclick="openModal('modal-reminder')">+ Add</button>
        </div>
        <?php if(empty($reminders)):?>
          <div class="empty-state">No reminders set.<br/>Add one to stay on top of your study sessions.</div>
        <?php else:foreach($reminders as $rem):?>
          <div class="reminder-item">
            <div class="rem-icon">🔔</div>
            <div style="flex:1;min-width:0">
              <div class="rem-title"><?=htmlspecialchars($rem['title'])?></div>
              <div class="rem-meta">
                <?php if($rem['group_name']):?><?=htmlspecialchars($rem['group_name'])?> · <?php endif;?>
                <?php if($rem['location']):?>📍 <?=htmlspecialchars($rem['location'])?><?php endif;?>
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div class="rem-time"><?=date('d M',strtotime($rem['remind_at']))?><br/><?=date('H:i',strtotime($rem['remind_at']))?></div>
              <form method="POST" style="margin-top:4px">
                <input type="hidden" name="del_reminder" value="1"/>
                <input type="hidden" name="rid" value="<?=$rem['id']?>"/>
                <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Delete reminder?')">✕</button>
              </form>
            </div>
          </div>
        <?php endforeach;endif;?>
      </div>
    </div>

  </div>
</div>

<!-- ADD REMINDER MODAL -->
<div class="modal-overlay" id="modal-reminder" style="display:none;position:fixed;inset:0;background:rgba(15,31,61,.55);z-index:500;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;width:460px;max-width:95vw;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
      <div style="font-size:17px;font-weight:700">Add Reminder</div>
      <button onclick="closeModal('modal-reminder')" style="font-size:20px;cursor:pointer;color:var(--muted);background:none;border:none">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_reminder" value="1"/>
      <div class="fg"><label class="fl">Session Title *</label><input class="fi" type="text" name="r_title" placeholder="e.g. Calculus Mock Test" required/></div>
      <div class="fg"><label class="fl">Date & Time *</label><input class="fi" type="datetime-local" name="r_datetime" required/></div>
      <div class="fg"><label class="fl">Group (optional)</label>
        <select class="fs" name="r_group">
          <option value="">No specific group</option>
          <?php foreach($my_groups as $g):?><option value="<?=htmlspecialchars($g['name'])?>"><?=htmlspecialchars($g['name'])?></option><?php endforeach;?>
        </select>
      </div>
      <div class="fg"><label class="fl">Location / Link</label><input class="fi" type="text" name="r_location" placeholder="Room or Zoom link"/></div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-reminder')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Reminder</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id){const m=document.getElementById(id);m.style.display='flex'}
function closeModal(id){document.getElementById(id).style.display='none'}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.style.display='none'}));
if(window.innerWidth<=820)document.getElementById('menu-btn').style.display='block';
window.addEventListener('resize',()=>{document.getElementById('menu-btn').style.display=window.innerWidth<=820?'block':'none'});
</script>
</body>
</html>
