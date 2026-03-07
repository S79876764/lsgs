<?php
// ============================================================
//  LSGS  |  PAGE 8 — progress.php
//  Student Progress Tracker
//  Eswatini College of Technology
// ============================================================
session_start();
if(!isset($_SESSION['user'])||$_SESSION['user']['role']!=='student'){header('Location: index.php');exit;}
require_once __DIR__.'/db.php';
$user=$_SESSION['user'];$uid=(int)$user['id'];
$initials=strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$full_name=htmlspecialchars($user['first_name'].' '.$user['last_name']);

// ── Fetch real data ───────────────────────────────────────────
// My groups
$my_groups=[];
$st=$conn->prepare("SELECT g.*,COUNT(gm2.student_id) mc FROM study_groups g JOIN group_members gm ON g.id=gm.group_id AND gm.student_id=? LEFT JOIN group_members gm2 ON g.id=gm2.group_id GROUP BY g.id ORDER BY g.subject");
$st->bind_param('i',$uid);$st->execute();$r=$st->get_result();
while($row=$r->fetch_assoc())$my_groups[]=$row;

// Attendance
$att_total=$conn->query("SELECT COUNT(*) c FROM attendance WHERE student_id=$uid")->fetch_assoc()['c'];
$att_present=$conn->query("SELECT COUNT(*) c FROM attendance WHERE student_id=$uid AND status='Present'")->fetch_assoc()['c'];
$att_rate=$att_total>0?round($att_present/$att_total*100):0;

// Resources I shared
$res_shared=$conn->query("SELECT COUNT(*) c FROM resources WHERE student_id=$uid")->fetch_assoc()['c'];

// Total sessions
$total_sessions=$conn->query("SELECT COUNT(*) c FROM sessions")->fetch_assoc()['c'];

// Groups joined count
$groups_joined=count($my_groups);

// Build subject progress from groups
$subject_progress=[];
foreach($my_groups as $g){
    $subj=$g['subject']??'General';
    if(!isset($subject_progress[$subj])){
        $subject_progress[$subj]=['groups'=>0,'health_sum'=>0];
    }
    $subject_progress[$subj]['groups']++;
    $subject_progress[$subj]['health_sum']+=$g['health']??80;
}
foreach($subject_progress as $subj=>&$data){
    $data['avg_health']=round($data['health_sum']/$data['groups']);
}

// Overall score
$overall=0;
if($groups_joined>0)$overall+=min(30,round($groups_joined*10));
if($att_rate>0)$overall+=round($att_rate*0.4);
if($res_shared>0)$overall+=min(10,$res_shared*2);
if($att_total>0)$overall+=min(20,round($att_total*2));
$overall=min($overall,100);

// Milestones
$milestones=[
    ['icon'=>'◎','label'=>'Joined first group',         'done'=>$groups_joined>=1],
    ['icon'=>'◎','label'=>'Joined 3 groups',             'done'=>$groups_joined>=3],
    ['icon'=>'✓','label'=>'Attended first session',      'done'=>$att_total>=1],
    ['icon'=>'✓','label'=>'Attended 5 sessions',         'done'=>$att_total>=5],
    ['icon'=>'✓','label'=>'90%+ attendance rate',        'done'=>$att_rate>=90],
    ['icon'=>'⊟','label'=>'Shared first resource',       'done'=>$res_shared>=1],
    ['icon'=>'⊟','label'=>'Shared 5 resources',          'done'=>$res_shared>=5],
];
$done_count=count(array_filter($milestones,fn($m)=>$m['done']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Progress — LSGS</title>
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
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;border:none;font-family:inherit;transition:all .15s;text-decoration:none}
.btn-primary{background:var(--blue);color:#fff}.btn-primary:hover{background:#1559d4}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{background:var(--surface)}
.btn-sm{padding:5px 12px;font-size:12.5px}
.content{padding:28px;flex:1}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:24px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:22px}
.card-title{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:16px}
.sec-title{font-size:15px;font-weight:700}
.stat-lbl{font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.stat-val{font-size:30px;font-weight:800;color:var(--text)}
.stat-sub{font-size:12px;color:var(--muted);margin-top:4px}

/* OVERALL SCORE RING */
.score-section{background:linear-gradient(135deg,#060f1e,#0f2247);border-radius:16px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;gap:32px}
.score-ring-wrap{flex-shrink:0;position:relative;width:120px;height:120px}
.score-ring-wrap svg{transform:rotate(-90deg)}
.score-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.score-num-big{font-size:32px;font-weight:800;color:#fff;line-height:1}
.score-pct{font-size:12px;color:rgba(255,255,255,.5);margin-top:2px}
.score-info h3{font-size:20px;font-weight:800;color:#fff;margin-bottom:8px}
.score-info p{font-size:13.5px;color:rgba(255,255,255,.55);line-height:1.6;max-width:420px}
.score-breakdown{display:flex;gap:20px;margin-top:16px;flex-wrap:wrap}
.sb-item{background:rgba(255,255,255,.08);border-radius:8px;padding:10px 16px;text-align:center}
.sb-val{font-size:18px;font-weight:800;color:var(--cyan)}
.sb-lbl{font-size:10.5px;color:rgba(255,255,255,.4);margin-top:2px;text-transform:uppercase;letter-spacing:.5px}

/* PROGRESS BARS */
.prog-item{margin-bottom:16px}
.prog-label{display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-bottom:6px}
.prog-track{height:8px;background:#eef1f8;border-radius:10px;overflow:hidden}
.prog-fill{height:100%;border-radius:10px;transition:width .5s ease}

/* MILESTONES */
.milestone-item{display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid var(--border)}
.milestone-item:last-child{border-bottom:none}
.ms-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.ms-done{background:#e6f9f1}.ms-todo{background:var(--surface);opacity:.5}
.ms-label{font-size:13.5px;font-weight:500}
.ms-check{margin-left:auto;font-size:18px}

/* SUBJECT CARDS */
.subj-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px}
.subj-name{font-size:13.5px;font-weight:700;margin-bottom:8px}
.subj-meta{font-size:12px;color:var(--muted);margin-bottom:10px}
.track{height:6px;background:#eef1f8;border-radius:10px;overflow:hidden;margin-top:4px}
.fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--blue),var(--cyan))}

@media(max-width:1100px){.grid-4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:820px){.sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.grid-2{grid-template-columns:1fr}.score-section{flex-direction:column}}
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
    <a class="nav-item active" href="progress.php"><span class="ico">◈</span> Progress</a>
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
      <div class="topbar-title">My Progress</div>
    </div>
  </header>

  <div class="content">

    <!-- OVERALL SCORE -->
    <?php
    $r=120;$circumference=2*pi()*50;$dash=round($circumference*$overall/100);
    $grade=$overall>=80?'Excellent':($overall>=60?'Good':($overall>=40?'Developing':'Getting Started'));
    ?>
    <div class="score-section">
      <div class="score-ring-wrap">
        <svg width="120" height="120" viewBox="0 0 120 120">
          <circle cx="60" cy="60" r="50" fill="none" stroke="rgba(255,255,255,.1)" stroke-width="10"/>
          <circle cx="60" cy="60" r="50" fill="none" stroke="url(#scoreGrad)" stroke-width="10"
                  stroke-dasharray="<?=$dash?> <?=round($circumference)?>" stroke-linecap="round"/>
          <defs><linearGradient id="scoreGrad" x1="0%" y1="0%" x2="100%" y2="0%"><stop offset="0%" style="stop-color:#1e6fff"/><stop offset="100%" style="stop-color:#00c2f3"/></linearGradient></defs>
        </svg>
        <div class="score-center"><div class="score-num-big"><?=$overall?></div><div class="score-pct">/ 100</div></div>
      </div>
      <div class="score-info">
        <h3>Overall Score: <?=$grade?></h3>
        <p>Your score is calculated from groups joined, attendance rate, sessions attended and resources shared. Keep attending sessions and sharing resources to improve your score!</p>
        <div class="score-breakdown">
          <div class="sb-item"><div class="sb-val"><?=$groups_joined?></div><div class="sb-lbl">Groups</div></div>
          <div class="sb-item"><div class="sb-val"><?=$att_rate?>%</div><div class="sb-lbl">Attendance</div></div>
          <div class="sb-item"><div class="sb-val"><?=$att_total?></div><div class="sb-lbl">Sessions</div></div>
          <div class="sb-item"><div class="sb-val"><?=$res_shared?></div><div class="sb-lbl">Shared</div></div>
        </div>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="grid-4">
      <div class="card"><div class="stat-lbl">Groups Joined</div><div class="stat-val"><?=$groups_joined?></div><div class="stat-sub">of <?=$conn->query("SELECT COUNT(*) c FROM study_groups")->fetch_assoc()['c']?> total</div></div>
      <div class="card"><div class="stat-lbl">Sessions Attended</div><div class="stat-val"><?=$att_present?></div><div class="stat-sub">out of <?=$att_total?> recorded</div></div>
      <div class="card"><div class="stat-lbl">Attendance Rate</div><div class="stat-val" style="color:<?=$att_rate>=80?'var(--green)':($att_rate>=60?'var(--orange)':'var(--red)')?>"><?=$att_rate?>%</div><div class="stat-sub"><?=$att_rate>=80?'Excellent':($att_rate>=60?'Good':'Needs improvement')?></div></div>
      <div class="card"><div class="stat-lbl">Resources Shared</div><div class="stat-val"><?=$res_shared?></div><div class="stat-sub">files uploaded</div></div>
    </div>

    <div class="grid-2">
      <!-- SUBJECT BREAKDOWN -->
      <div class="card">
        <div class="card-title">Subject Breakdown</div>
        <?php if(empty($subject_progress)):?>
          <div style="text-align:center;color:var(--muted);padding:30px;font-size:13px">Join groups to see subject progress.</div>
        <?php else:
          $colors=['Mathematics'=>'#1e6fff','Computer Science'=>'#7c3aed','Physics'=>'#f79009','Chemistry'=>'#12b76a','Engineering'=>'#f04438','Information Technology'=>'#1e6fff','Electronics'=>'#f79009','Statistics'=>'#7c3aed'];
          foreach($subject_progress as $subj=>$data):
            $col=$colors[$subj]??'#1e6fff';
            $h=$data['avg_health'];
        ?>
          <div class="subj-card">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div class="subj-name"><?=htmlspecialchars($subj)?></div>
              <span style="font-size:12px;font-weight:700;color:<?=$col?>"><?=$h?>%</span>
            </div>
            <div class="subj-meta"><?=$data['groups']?> group<?=$data['groups']!=1?'s':''?> · Avg health <?=$h?>%</div>
            <div class="track"><div class="fill" style="width:<?=$h?>%;background:<?=$col?>"></div></div>
          </div>
        <?php endforeach;endif;?>
      </div>

      <!-- MILESTONES -->
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
          <div class="card-title" style="margin:0">Milestones</div>
          <span style="font-size:12px;font-weight:700;color:var(--blue)"><?=$done_count?>/<?=count($milestones)?> completed</span>
        </div>
        <?php foreach($milestones as $m):?>
          <div class="milestone-item">
            <div class="ms-icon <?=$m['done']?'ms-done':'ms-todo'?>"><?=$m['icon']?></div>
            <div class="ms-label" style="<?=$m['done']?'':'opacity:.5'?>"><?=$m['label']?></div>
            <div class="ms-check"><?=$m['done']?'✅':'⬜'?></div>
          </div>
        <?php endforeach;?>
        <!-- Progress bar of milestones -->
        <div style="margin-top:16px">
          <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:6px"><span>Overall milestone progress</span><span><?=round($done_count/count($milestones)*100)?>%</span></div>
          <div class="prog-track"><div class="prog-fill" style="width:<?=round($done_count/count($milestones)*100)?>%;background:linear-gradient(90deg,var(--blue),var(--cyan))"></div></div>
        </div>
      </div>
    </div>

  </div>
</div>
<script>
if(window.innerWidth<=820)document.getElementById('menu-btn').style.display='block';
window.addEventListener('resize',()=>{document.getElementById('menu-btn').style.display=window.innerWidth<=820?'block':'none'});
</script>
</body>
</html>
