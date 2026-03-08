<?php
// ============================================================
//  LSGS  |  PAGE 7 — resources.php
//  Shared Files & Resources
//  Eswatini College of Technology
// ============================================================
session_start();
if(!isset($_SESSION['user'])||$_SESSION['user']['role']!=='student'){header('Location: index.php');exit;}
require_once __DIR__.'/db.php';
$user=$_SESSION['user'];$uid=(int)$user['id'];
$initials=strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$full_name=htmlspecialchars($user['first_name'].' '.$user['last_name']);
$msg='';$msg_type='ok';

// ── Safe migration ────────────────────────────────────────────
try{$conn->query("ALTER TABLE resources ADD COLUMN  file_path VARCHAR(500) DEFAULT NULL");}catch(Exception $e){}
try{$conn->query("ALTER TABLE group_members ADD COLUMN  status ENUM('active','blocked') NOT NULL DEFAULT 'active'");}catch(Exception $e){}

// ── Upload directory ──────────────────────────────────────────
$upload_dir = __DIR__ . '/uploads/resources/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ── Handle file upload ────────────────────────────────────────
$upload_dir = __DIR__ . '/uploads/resources/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ── Upload directory ──────────────────────────────────────────
$upload_dir = __DIR__ . '/uploads/resources/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ── Handle file upload ────────────────────────────────────────
if(isset($_POST['add_resource'])){
    $gid   = (int)($_POST['group_id'] ?? 0);
    $cat   = trim($_POST['category']  ?? 'Notes');

    // Group required
    if($gid <= 0){
        $msg='Please select a group to share this file with.'; $msg_type='err';
    } elseif(empty($_FILES['res_file']['name'])){
        $msg='Please choose a file to upload.'; $msg_type='err';
    } elseif($_FILES['res_file']['error'] !== UPLOAD_ERR_OK){
        $errors = [1=>'File too large (server limit).',2=>'File too large.',3=>'Partial upload — try again.',4=>'No file received.'];
        $msg = $errors[$_FILES['res_file']['error']] ?? 'Upload failed.'; $msg_type='err';
    } else {
        $orig      = basename($_FILES['res_file']['name']);
        $ext       = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig);
        $unique    = time() . '_' . $uid . '_' . $safe_name;
        $dest      = $upload_dir . $unique;
        $size_bytes= $_FILES['res_file']['size'];
        $size_label= $size_bytes >= 1048576
                     ? round($size_bytes/1048576,1).' MB'
                     : round($size_bytes/1024,0).' KB';

        // Detect file type from extension
        $ftype = in_array($ext,['pdf'])                     ? 'pdf'
               : (in_array($ext,['doc','docx'])             ? 'doc'
               : (in_array($ext,['jpg','jpeg','png','gif','webp']) ? 'img'
               : 'other'));

        // Custom display name (use original filename if no label given)
        $display_name = trim($_POST['res_name'] ?? '') ?: $orig;

        if(move_uploaded_file($_FILES['res_file']['tmp_name'], $dest)){
            $file_path = 'uploads/resources/' . $unique;
            $gid_val   = $gid > 0 ? $gid : null;
            // Add file_path column if not exists (safe guard)
            try{$conn->query("ALTER TABLE resources ADD COLUMN  file_path VARCHAR(500) DEFAULT NULL");}catch(Exception $e){}
            $st = $conn->prepare("INSERT INTO resources (name,group_id,student_id,category,file_type,size_label,file_path) VALUES (?,?,?,?,?,?,?)");
            $st->bind_param('siiisss', $display_name, $gid_val, $uid, $cat, $ftype, $size_label, $file_path);
            $st->execute();
            $msg = 'File uploaded successfully!';
        } else {
            $msg = 'Could not save file. Check server permissions on uploads/resources/.'; $msg_type='err';
        }
    }
}
if(isset($_POST['del_resource'])){
    $rid=(int)$_POST['rid'];
    // Also delete the physical file
    $row=$conn->query("SELECT file_path FROM resources WHERE id=$rid AND student_id=$uid")->fetch_assoc();
    if($row && $row['file_path'] && file_exists(__DIR__.'/'.$row['file_path'])){
        unlink(__DIR__.'/'.$row['file_path']);
    }
    $st=$conn->prepare("DELETE FROM resources WHERE id=? AND student_id=?");
    $st->bind_param('ii',$rid,$uid);$st->execute();
    $msg='Resource removed.'; $msg_type='err';
}

// ── Fetch resources — only from groups I belong to ───────────
$resources=[];
$rq=$conn->prepare("
    SELECT r.*, g.name gname,
           CONCAT(s.first_name,' ',SUBSTRING(s.last_name,1,1),'.') uploader
    FROM resources r
    JOIN study_groups g ON r.group_id = g.id
    JOIN group_members gm ON g.id = gm.group_id AND gm.student_id = ? AND gm.status = 'active'
    LEFT JOIN students s ON r.student_id = s.id
    ORDER BY r.created_at DESC
");
$rq->bind_param('i',$uid);
$rq->execute();
$rres=$rq->get_result();
while($row=$rres->fetch_assoc())$resources[]=$row;

// ── My groups for form dropdown ───────────────────────────────
$my_groups=[];
$st=$conn->prepare("SELECT g.id,g.name FROM study_groups g JOIN group_members gm ON g.id=gm.group_id WHERE gm.student_id=? AND gm.status='active' ORDER BY g.name");
$st->bind_param('i',$uid);$st->execute();$r=$st->get_result();
while($row=$r->fetch_assoc())$my_groups[]=$row;

// Stats — only files in my groups
$st_t=$conn->prepare("SELECT COUNT(*) c FROM resources r JOIN group_members gm ON r.group_id=gm.group_id WHERE gm.student_id=? AND gm.status='active'");
$st_t->bind_param('i',$uid);$st_t->execute();$total=$st_t->get_result()->fetch_assoc()['c'];
$st_m=$conn->prepare("SELECT COUNT(*) c FROM resources WHERE student_id=?");
$st_m->bind_param('i',$uid);$st_m->execute();$mine=$st_m->get_result()->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Resources — LSGS</title>
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
.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px}
.stat-lbl{font-size:11.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px}
.stat-val{font-size:28px;font-weight:800;color:var(--text)}
.stat-sub{font-size:12px;color:var(--muted);margin-top:4px}
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:22px}
.sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.sec-title{font-size:15px;font-weight:700}
.filter-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.chip{padding:5px 14px;border-radius:20px;font-size:12.5px;font-weight:500;border:1px solid var(--border);background:var(--white);cursor:pointer;color:var(--muted);transition:all .12s}
.chip.active,.chip:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.search-bar{display:flex;align-items:center;gap:10px;background:var(--white);border:1px solid var(--border);border-radius:10px;padding:10px 16px;margin-bottom:20px}
.search-bar input{border:none;background:transparent;font-family:inherit;font-size:13.5px;color:var(--text);flex:1;outline:none}

/* RESOURCE ITEMS */
.res-item{display:flex;align-items:center;gap:14px;padding:14px;border-radius:10px;transition:background .12s;border:1px solid transparent}
.res-item:hover{background:var(--surface);border-color:var(--border)}
.res-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.ri-pdf{background:#fff0f0}.ri-doc{background:#e8f0ff}.ri-img{background:#f0fdf4}.ri-other{background:#f3e8ff}
.res-name{font-size:13.5px;font-weight:600;margin-bottom:3px}
.res-meta{font-size:11.5px;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.pill{display:inline-block;padding:1px 8px;border-radius:6px;font-size:11px;font-weight:600}
.pill-blue{background:#e8f0ff;color:var(--blue)}
.pill-green{background:#e6f9f1;color:#0a9457}
.pill-orange{background:#fff4e5;color:#b45309}
.pill-purple{background:#f3e8ff;color:#7c3aed}
.res-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-shrink:0}
.res-size{font-size:11.5px;color:var(--muted);font-family:'DM Mono',monospace}
.res-divider{height:1px;background:var(--border);margin:4px 0}
.empty-state{text-align:center;color:var(--muted);padding:40px 20px;font-size:13px}
.fg{margin-bottom:14px}
.fl{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px}
.fi,.fs{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13.5px;color:var(--text);background:var(--white);outline:none;transition:border-color .15s}
.fi:focus,.fs:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(30,111,255,.09)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,31,61,.55);z-index:500;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}

@media(max-width:820px){.sidebar{transform:translateX(-100%);transition:transform .25s}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.stat-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<aside class="sidebar" id="sidebar">
  <div class="sb-logo"><img src="assets/ecot.jpg" alt="ECOT"/><div><div class="sb-logo-text"></div><div class="sb-logo-sub">ECOT STUDY GROUP</div></div></div>
  <nav class="sb-nav-wrap">
    <div class="sb-label">Main</div>
    <a class="nav-item" href="dashboard.php"><span class="ico">⊞</span> Dashboard</a>
    <a class="nav-item" href="groups.php"><span class="ico">◎</span> My Groups</a>
    <a class="nav-item" href="matching.php"><span class="ico">⌖</span> Smart Match</a>
    <a class="nav-item" href="schedule.php"><span class="ico">◷</span> Schedule</a>
    <div class="sb-label" style="margin-top:10px">Resources</div>
    <a class="nav-item active" href="resources.php"><span class="ico">⊟</span> Shared Files</a>
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
      <div class="topbar-title">Shared Files</div>
    </div>
    <div class="topbar-right">
      <button class="btn btn-primary" onclick="openModal('modal-upload')">+ Upload Resource</button>
    </div>
  </header>

  <div class="content">
    <?php if($msg):?><div class="alert alert-<?=$msg_type?>"><?=$msg_type==='ok'?'✓ ':'⚠ '?><?=htmlspecialchars($msg)?></div><?php endif;?>

    <!-- STATS -->
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-lbl">Total Files</div><div class="stat-val"><?=$total?></div><div class="stat-sub">shared by all students</div></div>
      <div class="stat-card"><div class="stat-lbl">My Uploads</div><div class="stat-val"><?=$mine?></div><div class="stat-sub">files you shared</div></div>
      <div class="stat-card"><div class="stat-lbl">Groups</div><div class="stat-val"><?=count($my_groups)?></div><div class="stat-sub">groups you are in</div></div>
    </div>

    <!-- SEARCH + FILTER -->
    <div class="search-bar">
      <span style="color:var(--muted);font-size:16px">🔍</span>
      <input type="text" id="res-search" placeholder="Search files by name or group..." oninput="filterRes()"/>
    </div>
    <div class="filter-row" id="cat-filter">
      <div class="chip active" data-f="All" onclick="setCat(this)">All Files</div>
      <div class="chip" data-f="Notes" onclick="setCat(this)">📝 Notes</div>
      <div class="chip" data-f="Past Papers" onclick="setCat(this)">📄 Past Papers</div>
      <div class="chip" data-f="Assignments" onclick="setCat(this)">✏ Assignments</div>
      <div class="chip" data-f="Other" onclick="setCat(this)">📁 Other</div>
    </div>

    <!-- RESOURCES LIST -->
    <div class="card" id="res-list">
      <div class="sec-header">
        <div class="sec-title">All Resources</div>
        <span style="font-size:12px;color:var(--muted)" id="res-count"><?=count($resources)?> file<?=count($resources)!=1?'s':''?></span>
      </div>

      <?php if(empty($resources)):?>
        <div class="empty-state">
          <div style="font-size:40px;margin-bottom:12px">📂</div>
          No resources in your groups yet.<br/><small>Only files shared to your groups appear here.</small>
        </div>
      <?php else:foreach($resources as $r):
        $icon=$r['file_type']==='pdf'?'📄':($r['file_type']==='img'?'🖼️':($r['file_type']==='doc'?'📝':'📁'));
        $cls='ri-'.($r['file_type']==='pdf'?'pdf':($r['file_type']==='img'?'img':($r['file_type']==='doc'?'doc':'other')));
        $cat_pill=$r['category']==='Notes'?'pill-blue':($r['category']==='Past Papers'?'pill-green':($r['category']==='Assignments'?'pill-orange':'pill-purple'));
        $is_mine=(int)$r['student_id']===$uid;
      ?>
        <div class="res-item" data-name="<?=strtolower(htmlspecialchars($r['name']))?>" data-cat="<?=htmlspecialchars($r['category'])?>">
          <div class="res-icon <?=$cls?>"><?=$icon?></div>
          <div style="flex:1;min-width:0">
            <div class="res-name"><?=htmlspecialchars($r['name'])?></div>
            <div class="res-meta">
              <span><?=htmlspecialchars($r['gname']??'General')?></span>
              <span>by <?=htmlspecialchars($r['uploader']??'Unknown')?></span>
              <span><?=date('d M Y',strtotime($r['created_at']))?></span>
              <span class="pill <?=$cat_pill?>"><?=htmlspecialchars($r['category'])?></span>
            </div>
          </div>
          <div class="res-right">
            <?php if($r['size_label']):?>
              <span class="res-size"><?=htmlspecialchars($r['size_label'])?></span>
            <?php endif;?>
            <?php if(!empty($r['file_path']) && file_exists(__DIR__.'/'.$r['file_path'])):?>
              <a href="<?=htmlspecialchars($r['file_path'])?>" download="<?=htmlspecialchars($r['name'])?>"
                 class="btn btn-ghost btn-sm" title="Download">⬇ Download</a>
            <?php endif;?>
            <?php if($is_mine):?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="del_resource" value="1"/>
                <input type="hidden" name="rid" value="<?=$r['id']?>"/>
                <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Remove this resource?')">✕</button>
              </form>
            <?php endif;?>
          </div>
        </div>
        <div class="res-divider"></div>
      <?php endforeach;endif;?>
    </div>

  </div>
</div>

<!-- UPLOAD MODAL -->
<div class="modal-overlay" id="modal-upload">
  <div style="background:#fff;border-radius:16px;padding:28px;width:500px;max-width:95vw;max-height:92vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
      <div style="font-size:17px;font-weight:700">Upload Resource</div>
      <button onclick="closeModal('modal-upload')" style="font-size:20px;cursor:pointer;color:var(--muted);background:none;border:none">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data" id="upload-form">
      <input type="hidden" name="add_resource" value="1"/>

      <!-- FILE PICKER -->
      <div class="fg">
        <label class="fl">Choose File *</label>
        <div id="drop-zone" onclick="document.getElementById('res_file').click()"
             style="border:2px dashed var(--border);border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;transition:all .15s;background:var(--surface)">
          <div style="font-size:36px;margin-bottom:8px">📁</div>
          <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:4px">Click to browse or drag &amp; drop</div>
          <div style="font-size:12px;color:var(--muted)">PDF, Word, Images, Excel, ZIP and more · Max 20 MB</div>
          <div id="file-chosen" style="margin-top:12px;font-size:13px;font-weight:600;color:var(--blue);display:none"></div>
        </div>
        <input type="file" id="res_file" name="res_file" style="display:none"
               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.zip,.rar,.txt,.csv"
               onchange="fileChosen(this)"/>
      </div>

      <!-- DISPLAY NAME -->
      <div class="fg">
        <label class="fl">Display Name <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional — uses filename if blank)</span></label>
        <input class="fi" type="text" name="res_name" id="res_name" placeholder="e.g. Chapter 5 Linear Algebra Notes"/>
      </div>

      <!-- GROUP (required) -->
      <div class="fg">
        <label class="fl">Share With Group <span style="color:var(--red);font-weight:700">*</span></label>
        <?php if(empty($my_groups)):?>
          <div style="padding:11px 14px;background:#fff8e1;border:1px solid #fde68a;border-radius:8px;font-size:13px;color:#92400e">
            ⚠ You are not in any group yet. <a href="groups.php" style="color:var(--blue);font-weight:600">Join or create a group first.</a>
          </div>
          <input type="hidden" name="group_id" value="0"/>
        <?php else:?>
        <select class="fs" name="group_id" required>
          <option value="">— Select a group —</option>
          <?php foreach($my_groups as $g):?><option value="<?=$g['id']?>"><?=htmlspecialchars($g['name'])?></option><?php endforeach;?>
        </select>
        <?php endif;?>
      </div>

      <div class="fg">
        <label class="fl">Category</label>
        <select class="fs" name="category">
          <option value="Notes">📝 Notes</option>
          <option value="Past Papers">📄 Past Papers</option>
          <option value="Assignments">✏ Assignments</option>
          <option value="Other">📁 Other</option>
        </select>
      </div>

      <!-- PROGRESS BAR (shown during upload) -->
      <div id="upload-progress" style="display:none;margin-bottom:14px">
        <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Uploading...</div>
        <div style="height:6px;background:#eef1f8;border-radius:10px;overflow:hidden">
          <div id="progress-bar" style="height:100%;background:linear-gradient(90deg,var(--blue),var(--cyan));width:0%;transition:width .3s;border-radius:10px"></div>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-upload')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2" id="upload-btn">Upload File</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Modal open/close ─────────────────────────────────────────
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(function(m){
  m.addEventListener('click', function(e){ if(e.target===m) m.classList.remove('open'); });
});

// ── Category filter ───────────────────────────────────────────
var activeCat = 'All';
function setCat(el){
  document.querySelectorAll('.chip').forEach(function(c){ c.classList.remove('active'); });
  el.classList.add('active');
  activeCat = el.dataset.f;
  filterRes();
}
function filterRes(){
  var q = document.getElementById('res-search').value.toLowerCase();
  var items = document.querySelectorAll('.res-item');
  var vis = 0;
  items.forEach(function(item){
    var mn = item.dataset.name.includes(q);
    var mc = (activeCat==='All' || item.dataset.cat===activeCat);
    var show = mn && mc;
    item.style.display = show ? '' : 'none';
    if(item.nextElementSibling) item.nextElementSibling.style.display = show ? '' : 'none';
    if(show) vis++;
  });
  document.getElementById('res-count').textContent = vis + ' file' + (vis!==1?'s':'');
}

// ── Mobile menu ───────────────────────────────────────────────
if(window.innerWidth <= 820){ document.getElementById('menu-btn').style.display='block'; }
window.addEventListener('resize', function(){
  document.getElementById('menu-btn').style.display = window.innerWidth<=820 ? 'block' : 'none';
});

// ── File picker ───────────────────────────────────────────────
function fileChosen(input){
  var chosen = document.getElementById('file-chosen');
  var zone   = document.getElementById('drop-zone');
  var nameEl = document.getElementById('res_name');
  var btn    = document.getElementById('upload-btn');

  if(!input.files || !input.files[0]){ btn.disabled=true; return; }

  var f      = input.files[0];
  var sizeMB = f.size / 1048576;

  if(sizeMB > 20){
    chosen.textContent = '⚠ File too large (' + sizeMB.toFixed(1) + ' MB). Max is 20 MB.';
    chosen.style.color   = 'var(--red)';
    chosen.style.display = 'block';
    zone.style.borderColor = 'var(--red)';
    btn.disabled = true;
    return;
  }

  var label = sizeMB < 0.1
    ? Math.round(f.size/1024) + ' KB'
    : sizeMB.toFixed(1) + ' MB';

  chosen.textContent   = '✅ ' + f.name + ' (' + label + ')';
  chosen.style.color   = 'var(--green)';
  chosen.style.display = 'block';
  zone.style.borderColor = 'var(--green)';
  zone.style.background  = '#f0fdf4';

  if(nameEl && !nameEl.value) nameEl.value = f.name.replace(/\.[^/.]+$/, '');
  btn.disabled = false;
}

// ── Drag & drop ───────────────────────────────────────────────
var dropZone = document.getElementById('drop-zone');
if(dropZone){
  dropZone.addEventListener('dragover', function(e){
    e.preventDefault();
    dropZone.style.borderColor = 'var(--blue)';
    dropZone.style.background  = '#eef4ff';
  });
  dropZone.addEventListener('dragleave', function(){
    dropZone.style.borderColor = 'var(--border)';
    dropZone.style.background  = 'var(--surface)';
  });
  dropZone.addEventListener('drop', function(e){
    e.preventDefault();
    dropZone.style.borderColor = 'var(--border)';
    dropZone.style.background  = 'var(--surface)';
    var fi = document.getElementById('res_file');
    if(e.dataTransfer.files.length){
      try{
        var dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        fi.files = dt.files;
      }catch(ex){}
      fileChosen(fi);
    }
  });
}

// ── Progress bar on submit ────────────────────────────────────
var uploadForm = document.getElementById('upload-form');
if(uploadForm){
  uploadForm.addEventListener('submit', function(){
    var fi = document.getElementById('res_file');
    if(!fi || !fi.files || !fi.files[0]) return;
    document.getElementById('upload-progress').style.display = 'block';
    document.getElementById('upload-btn').disabled    = true;
    document.getElementById('upload-btn').textContent = 'Uploading...';
    var w = 0;
    var bar = document.getElementById('progress-bar');
    var iv = setInterval(function(){
      w = Math.min(w + Math.random()*15, 90);
      bar.style.width = w + '%';
      if(w >= 90) clearInterval(iv);
    }, 200);
  });
}
</script>
</body>
</html>
