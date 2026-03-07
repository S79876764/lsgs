<?php
// ============================================================
//  LSGS  |  PAGE 10 — badges.php
//  Achievement Badges
//  Eswatini College of Technology
// ============================================================
session_start();
if(!isset($_SESSION['user'])||$_SESSION['user']['role']!=='student'){header('Location: index.php');exit;}
require_once __DIR__.'/db.php';
$user=$_SESSION['user'];$uid=(int)$user['id'];
$initials=strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$full_name=htmlspecialchars($user['first_name'].' '.$user['last_name']);

// ── Fetch real data for badge criteria ───────────────────────
$groups_joined=$conn->query("SELECT COUNT(*) c FROM group_members WHERE student_id=$uid")->fetch_assoc()['c'];
$att_total=$conn->query("SELECT COUNT(*) c FROM attendance WHERE student_id=$uid")->fetch_assoc()['c'];
$att_present=$conn->query("SELECT COUNT(*) c FROM attendance WHERE student_id=$uid AND status='Present'")->fetch_assoc()['c'];
$att_rate=$att_total>0?round($att_present/$att_total*100):0;
$res_shared=$conn->query("SELECT COUNT(*) c FROM resources WHERE student_id=$uid")->fetch_assoc()['c'];
$reminders=$conn->query("SELECT COUNT(*) c FROM reminders WHERE student_id=$uid")->fetch_assoc()['c'];
$days_since=0;
$reg=$conn->query("SELECT DATEDIFF(NOW(),created_at) d FROM students WHERE id=$uid")->fetch_assoc();
if($reg)$days_since=(int)$reg['d'];

// ── Define all badges ─────────────────────────────────────────
$badges=[
  // Onboarding
  ['id'=>'welcome',   'icon'=>'👋','name'=>'Welcome!',         'desc'=>'Created your LSGS account',     'cat'=>'Onboarding','earned'=>true,                   'color'=>'#1e6fff'],
  ['id'=>'profile',   'icon'=>'✨','name'=>'Profile Set',       'desc'=>'Filled in your programme & year','cat'=>'Onboarding','earned'=>!empty($user['year']),  'color'=>'#7c3aed'],
  // Groups
  ['id'=>'joiner',    'icon'=>'◎', 'name'=>'Joiner',            'desc'=>'Joined your first study group', 'cat'=>'Groups',    'earned'=>$groups_joined>=1,       'color'=>'#1e6fff'],
  ['id'=>'crew',      'icon'=>'👥','name'=>'Crew Member',        'desc'=>'Joined 3 study groups',         'cat'=>'Groups',    'earned'=>$groups_joined>=3,       'color'=>'#1e6fff'],
  ['id'=>'social',    'icon'=>'🌐','name'=>'Social Butterfly',   'desc'=>'Joined 5 study groups',         'cat'=>'Groups',    'earned'=>$groups_joined>=5,       'color'=>'#0891b2'],
  // Attendance
  ['id'=>'att1',      'icon'=>'✓', 'name'=>'First Step',        'desc'=>'Attended your first session',   'cat'=>'Attendance','earned'=>$att_present>=1,         'color'=>'#12b76a'],
  ['id'=>'att5',      'icon'=>'🎯','name'=>'On Track',           'desc'=>'Attended 5 sessions',           'cat'=>'Attendance','earned'=>$att_present>=5,         'color'=>'#12b76a'],
  ['id'=>'att10',     'icon'=>'🏅','name'=>'Dedicated',          'desc'=>'Attended 10 sessions',          'cat'=>'Attendance','earned'=>$att_present>=10,        'color'=>'#f59e0b'],
  ['id'=>'att80',     'icon'=>'⭐','name'=>'Consistent',         'desc'=>'80%+ attendance rate',          'cat'=>'Attendance','earned'=>$att_rate>=80,           'color'=>'#f59e0b'],
  ['id'=>'att100',    'icon'=>'💎','name'=>'Perfect Attendance', 'desc'=>'100% attendance rate (5+ sessions)','cat'=>'Attendance','earned'=>$att_rate>=100&&$att_total>=5,'color'=>'#00c2f3'],
  // Resources
  ['id'=>'sharer',    'icon'=>'📤','name'=>'Sharer',             'desc'=>'Shared your first resource',    'cat'=>'Resources', 'earned'=>$res_shared>=1,          'color'=>'#7c3aed'],
  ['id'=>'library',   'icon'=>'📚','name'=>'Library Builder',    'desc'=>'Shared 5 resources',            'cat'=>'Resources', 'earned'=>$res_shared>=5,          'color'=>'#7c3aed'],
  ['id'=>'scholar',   'icon'=>'🏫','name'=>'Scholar',            'desc'=>'Shared 10 resources',           'cat'=>'Resources', 'earned'=>$res_shared>=10,         'color'=>'#7c3aed'],
  // Engagement
  ['id'=>'remind',    'icon'=>'🔔','name'=>'Planner',            'desc'=>'Set your first reminder',       'cat'=>'Engagement','earned'=>$reminders>=1,           'color'=>'#f79009'],
  ['id'=>'veteran',   'icon'=>'🎖','name'=>'Veteran',            'desc'=>'Been on LSGS for 30 days',      'cat'=>'Engagement','earned'=>$days_since>=30,         'color'=>'#f79009'],
  ['id'=>'legend',    'icon'=>'🏆','name'=>'Legend',             'desc'=>'Earned 10 badges',              'cat'=>'Special',   'earned'=>false,                   'color'=>'#f59e0b'],
];

// Count earned
$earned_count=count(array_filter($badges,fn($b)=>$b['earned']));
// Update legend badge
foreach($badges as &$b){if($b['id']==='legend')$b['earned']=$earned_count>=10;}
$earned_count=count(array_filter($badges,fn($b)=>$b['earned']));
$total_badges=count($badges);
$categories=array_unique(array_column($badges,'cat'));

// Latest earned
$latest=array_filter($badges,fn($b)=>$b['earned']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Badges — LSGS</title>
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
.content{padding:28px;flex:1}

/* HERO */
.badge-hero{background:linear-gradient(135deg,#060f1e,#0f2247);border-radius:16px;padding:28px 32px;margin-bottom:28px;display:flex;align-items:center;gap:32px;position:relative;overflow:hidden}
.badge-hero::before{content:'🏆';position:absolute;right:32px;top:50%;transform:translateY(-50%);font-size:100px;opacity:.07}
.hero-ring{width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.08);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;border:4px solid rgba(0,194,243,.3)}
.hr-num{font-size:32px;font-weight:800;color:var(--cyan);line-height:1}
.hr-of{font-size:11px;color:rgba(255,255,255,.4);margin-top:2px}
.hero-info h2{font-size:22px;font-weight:800;color:#fff;margin-bottom:6px}
.hero-info p{font-size:13.5px;color:rgba(255,255,255,.55);max-width:500px;line-height:1.65;margin-bottom:16px}
.hero-prog-wrap{max-width:400px}
.hp-label{display:flex;justify-content:space-between;font-size:12px;color:rgba(255,255,255,.4);margin-bottom:6px}
.hp-track{height:8px;background:rgba(255,255,255,.1);border-radius:10px;overflow:hidden}
.hp-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--blue),var(--cyan))}

/* FILTER */
.filter-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:22px}
.chip{padding:5px 14px;border-radius:20px;font-size:12.5px;font-weight:500;border:1px solid var(--border);background:var(--white);cursor:pointer;color:var(--muted);transition:all .12s}
.chip.active,.chip:hover{background:var(--blue);color:#fff;border-color:var(--blue)}

/* BADGE GRID */
.badge-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.badge-card{background:var(--white);border:1px solid var(--border);border-radius:14px;padding:22px 16px;text-align:center;transition:all .15s;position:relative;overflow:hidden}
.badge-card.earned{border-color:transparent;box-shadow:0 2px 16px rgba(0,0,0,.08)}
.badge-card.earned:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.12)}
.badge-card.locked{opacity:.45;filter:grayscale(.5)}
.badge-card.locked:hover{opacity:.6}
.earned-ribbon{position:absolute;top:10px;right:10px;font-size:14px}
.badge-icon-wrap{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 12px}
.badge-name{font-size:13px;font-weight:700;margin-bottom:5px}
.badge-desc{font-size:11.5px;color:var(--muted);line-height:1.4}
.badge-cat{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;margin-top:8px;padding:2px 8px;border-radius:10px;display:inline-block}
.locked-overlay{font-size:22px;margin-bottom:6px}

/* SECTION TITLE */
.sec-title{font-size:15px;font-weight:700;margin-bottom:16px}

@media(max-width:1200px){.badge-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.badge-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:820px){.sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.badge-hero{flex-direction:column}.badge-grid{grid-template-columns:repeat(2,1fr)}}
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
    <a class="nav-item" href="attendance.php"><span class="ico">✓</span> Attendance</a>
    <a class="nav-item active" href="badges.php"><span class="ico">◆</span> Badges</a>
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
      <div class="topbar-title">Badges & Achievements</div>
    </div>
    <div class="topbar-right">
      <span style="font-size:13px;color:var(--muted)"><?=$earned_count?>/<?=$total_badges?> earned</span>
    </div>
  </header>

  <div class="content">

    <!-- HERO -->
    <div class="badge-hero">
      <div class="hero-ring">
        <div class="hr-num"><?=$earned_count?></div>
        <div class="hr-of">of <?=$total_badges?></div>
      </div>
      <div class="hero-info">
        <h2>Your Badge Collection</h2>
        <p>Earn badges by joining groups, attending sessions, sharing resources and staying consistent. Each badge tracks real activity — the more you engage, the more you earn!</p>
        <div class="hero-prog-wrap">
          <div class="hp-label"><span>Collection Progress</span><span><?=round($earned_count/$total_badges*100)?>%</span></div>
          <div class="hp-track"><div class="hp-fill" style="width:<?=round($earned_count/$total_badges*100)?>%"></div></div>
        </div>
      </div>
    </div>

    <!-- FILTER CHIPS -->
    <div class="filter-row" id="cat-chips">
      <div class="chip active" data-cat="All" onclick="filterBadges(this)">All (<?=$total_badges?>)</div>
      <div class="chip" data-cat="earned" onclick="filterBadges(this)">✅ Earned (<?=$earned_count?>)</div>
      <?php foreach($categories as $cat):
        $cnt=count(array_filter($badges,fn($b)=>$b['cat']===$cat));
      ?>
        <div class="chip" data-cat="<?=htmlspecialchars($cat)?>" onclick="filterBadges(this)"><?=htmlspecialchars($cat)?> (<?=$cnt?>)</div>
      <?php endforeach;?>
    </div>

    <!-- BADGE GRID -->
    <div class="badge-grid" id="badge-grid">
      <?php foreach($badges as $b):?>
        <div class="badge-card <?=$b['earned']?'earned':'locked'?>"
             data-cat="<?=htmlspecialchars($b['cat'])?>"
             data-earned="<?=$b['earned']?'1':'0'?>"
             title="<?=$b['earned']?'Earned!':'Locked — '.$b['desc']?>">

          <?php if($b['earned']):?>
            <div class="earned-ribbon">✅</div>
          <?php else:?>
            <div class="earned-ribbon" style="opacity:.3">🔒</div>
          <?php endif;?>

          <div class="badge-icon-wrap" style="background:<?=$b['color']?>22;<?=$b['earned']?'border:2px solid '.$b['color'].'44':''?>">
            <?php if(!$b['earned']):?><div class="locked-overlay">🔒</div><?php else:?>
              <span><?=$b['icon']?></span>
            <?php endif;?>
          </div>

          <div class="badge-name" style="color:<?=$b['earned']?$b['color']:'var(--muted)'?>"><?=$b['name']?></div>
          <div class="badge-desc"><?=$b['desc']?></div>
          <div class="badge-cat" style="background:<?=$b['color']?>18;color:<?=$b['color']?>"><?=$b['cat']?></div>
        </div>
      <?php endforeach;?>
    </div>

    <!-- NO RESULTS -->
    <div id="no-badge-results" style="display:none;background:var(--white);border:1px solid var(--border);border-radius:14px;padding:50px 20px;text-align:center;color:var(--muted)">
      <div style="font-size:40px;margin-bottom:12px">🔍</div>
      <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px">No badges in this category yet</div>
      <div style="font-size:13px">Keep engaging with LSGS to earn more badges!</div>
    </div>

  </div>
</div>

<script>
function filterBadges(el){
  document.querySelectorAll('#cat-chips .chip').forEach(c=>c.classList.remove('active'));
  el.classList.add('active');
  const cat=el.dataset.cat;
  const cards=document.querySelectorAll('#badge-grid .badge-card');
  let vis=0;
  cards.forEach(card=>{
    let show=false;
    if(cat==='All')show=true;
    else if(cat==='earned')show=card.dataset.earned==='1';
    else show=card.dataset.cat===cat;
    card.style.display=show?'':'none';
    if(show)vis++;
  });
  document.getElementById('no-badge-results').style.display=vis===0?'block':'none';
}
if(window.innerWidth<=820)document.getElementById('menu-btn').style.display='block';
window.addEventListener('resize',()=>{document.getElementById('menu-btn').style.display=window.innerWidth<=820?'block':'none'});
</script>
</body>
</html>
