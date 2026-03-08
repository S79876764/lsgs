<?php
// ============================================================
//  LSGS  |  PAGE 4 — groups.php
//  My Study Groups
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

$msg          = '';
$msg_type     = 'ok';
$my_programme = $user['programme'] ?? '';

// ── Safe migrations ───────────────────────────────────────────
try{$conn->query("ALTER TABLE study_groups ADD COLUMN  created_by INT DEFAULT NULL");}catch(Exception $e){}
try{$conn->query("ALTER TABLE study_groups ADD COLUMN  programme VARCHAR(150) DEFAULT NULL");}catch(Exception $e){}
// Fix existing groups with no programme — assign creator's programme
$conn->query("UPDATE study_groups g
    JOIN students s ON g.created_by = s.id
    SET g.programme = s.programme
    WHERE (g.programme IS NULL OR g.programme = '') AND g.created_by IS NOT NULL");
try{$conn->query("ALTER TABLE study_groups ADD COLUMN  is_online TINYINT(1) DEFAULT 0");}catch(Exception $e){}
try{$conn->query("ALTER TABLE group_members ADD COLUMN  status ENUM('active','blocked') NOT NULL DEFAULT 'active'");}catch(Exception $e){}
try{$conn->query("CREATE TABLE  group_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  student_id INT NOT NULL,
  att_date DATE NOT NULL,
  status ENUM('Present','Absent') DEFAULT 'Present',
  marked_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (group_id, student_id, att_date),
  FOREIGN KEY (group_id) REFERENCES study_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
)");}catch(Exception $e){}
try{$conn->query("CREATE TABLE  chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  student_id INT NOT NULL,
  message TEXT NOT NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (group_id) REFERENCES study_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
)");}catch(Exception $e){}

// ── Helper: is current user the admin of a group ─────────────
function is_grp_admin($conn, $gid, $uid) {
    $st = $conn->prepare("SELECT id FROM study_groups WHERE id=? AND created_by=?");
    $st->bind_param('ii', $gid, $uid); $st->execute();
    return (bool)$st->get_result()->fetch_assoc();
}

// ── Handle POST actions ──────────────────────────────────────

// Leave a group (non-admin: direct leave | admin: must transfer first)
if (isset($_POST['leave_group'])) {
    $gid = (int)$_POST['group_id'];
    if (is_grp_admin($conn, $gid, $uid)) {
        // Check if there are other members to nominate
        $other_q = $conn->prepare("SELECT COUNT(*) c FROM group_members WHERE group_id=? AND student_id!=? AND status='active'");
        $other_q->bind_param('ii',$gid,$uid); $other_q->execute();
        $others = $other_q->get_result()->fetch_assoc()['c'];
        if ($others > 0) {
            $msg = 'You are the admin. Please nominate a new admin before leaving.'; $msg_type = 'err';
        } else {
            // No other members — admin can leave (group will have no admin)
            $st = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND student_id=?");
            $st->bind_param('ii',$gid,$uid); $st->execute();
            $conn->prepare("UPDATE study_groups SET created_by=NULL WHERE id=?")->bind_param('i',$gid) && true;
            $upd = $conn->prepare("UPDATE study_groups SET created_by=NULL WHERE id=?");
            $upd->bind_param('i',$gid); $upd->execute();
            $msg = 'You have left the group.';
        }
    } else {
        $st = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND student_id=?");
        $st->bind_param('ii', $gid, $uid); $st->execute();
        $msg = 'You have left the group.';
    }
}

// Transfer admin role to another member then leave
if (isset($_POST['transfer_and_leave'])) {
    $gid    = (int)$_POST['group_id'];
    $new_admin = (int)$_POST['new_admin_id'];
    if (is_grp_admin($conn, $gid, $uid) && $new_admin && $new_admin !== $uid) {
        // Verify new admin is active member
        $chk = $conn->prepare("SELECT id FROM group_members WHERE group_id=? AND student_id=? AND status='active'");
        $chk->bind_param('ii',$gid,$new_admin); $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            // Transfer ownership
            $upd = $conn->prepare("UPDATE study_groups SET created_by=? WHERE id=?");
            $upd->bind_param('ii',$new_admin,$gid); $upd->execute();
            // Remove current user from group
            $del = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND student_id=?");
            $del->bind_param('ii',$gid,$uid); $del->execute();
            $msg = 'Admin transferred. You have left the group.';
        } else { $msg = 'Selected member is not active in this group.'; $msg_type='err'; }
    } else { $msg = 'Invalid transfer request.'; $msg_type='err'; }
}

// Delete group (admin only — wipes everything)
if (isset($_POST['delete_my_group'])) {
    $gid = (int)$_POST['group_id'];
    if (is_grp_admin($conn, $gid, $uid)) {
        // Delete uploaded files
        $fq = $conn->prepare("SELECT file_path FROM resources WHERE group_id=?");
        if ($fq) {
            $fq->bind_param('i',$gid); $fq->execute();
            $fr = $fq->get_result();
            while ($frow = $fr->fetch_assoc()) {
                if (!empty($frow['file_path']) && file_exists(__DIR__.'/'.$frow['file_path']))
                    unlink(__DIR__.'/'.$frow['file_path']);
            }
        }
        foreach ([
            "DELETE FROM group_attendance WHERE group_id=?",
            "DELETE FROM chat_messages    WHERE group_id=?",
            "DELETE FROM resources        WHERE group_id=?",
            "DELETE FROM group_members    WHERE group_id=?",
            "DELETE FROM sessions         WHERE group_id=?",
            "DELETE FROM study_groups     WHERE id=?",
        ] as $sql) {
            $st = $conn->prepare($sql);
            if ($st) { $st->bind_param('i',$gid); $st->execute(); }
        }
        $msg = 'Group deleted successfully.';
    } else { $msg = 'Only the group admin can delete this group.'; $msg_type='err'; }
}

// Create a new group
if (isset($_POST['create_group'])) {
    $name  = trim($_POST['g_name']        ?? '');
    $subj  = trim($_POST['g_subject']     ?? '');
    $level = trim($_POST['g_skill_level'] ?? 'Intermediate');
    $days  = trim($_POST['g_days']        ?? '');
    $time  = trim($_POST['g_time']        ?? '');
    $loc   = trim($_POST['g_location']    ?? '');

    if ($name && $subj) {
        $st = $conn->prepare("INSERT INTO study_groups (name,subject,skill_level,days,meeting_time,location,created_by) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param('ssssssi', $name, $subj, $level, $days, $time, $loc, $uid);
        $st->execute();
        $gid = $conn->insert_id;

        $st2 = $conn->prepare("INSERT IGNORE INTO group_members (group_id,student_id,status) VALUES (?,?,'active')");
        $st2->bind_param('ii', $gid, $uid); $st2->execute();

        $msg = 'Group created! You are the group admin.';
    } else {
        $msg = 'Group name and subject are required.'; $msg_type = 'err';
    }
}

// Edit group details (admin only)
if (isset($_POST['edit_group'])) {
    $gid = (int)$_POST['group_id'];
    if (is_grp_admin($conn, $gid, $uid)) {
        $name  = trim($_POST['g_name']        ?? '');
        $subj  = trim($_POST['g_subject']     ?? '');
        $level = trim($_POST['g_skill_level'] ?? 'Intermediate');
        $days  = trim($_POST['g_days']        ?? '');
        $time  = trim($_POST['g_time']        ?? '');
        $loc   = trim($_POST['g_location']    ?? '');
        $st = $conn->prepare("UPDATE study_groups SET name=?,subject=?,skill_level=?,days=?,meeting_time=?,location=? WHERE id=?");
        $st->bind_param('ssssssi', $name, $subj, $level, $days, $time, $loc, $gid); $st->execute();
        $msg = 'Group details updated successfully.';
    } else { $msg = 'Only the group admin can edit group details.'; $msg_type = 'err'; }
}

// Add member by student number (admin only)
if (isset($_POST['add_member'])) {
    $gid  = (int)$_POST['group_id'];
    $snum = trim($_POST['student_num'] ?? '');
    if (is_grp_admin($conn, $gid, $uid)) {
        $st = $conn->prepare("SELECT id FROM students WHERE student_num=? LIMIT 1");
        $st->bind_param('s', $snum); $st->execute();
        $target = $st->get_result()->fetch_assoc();
        if (!$target) {
            $msg = 'No student found with that student number.'; $msg_type = 'err';
        } else {
            $tid = (int)$target['id'];
            $chk = $conn->prepare("SELECT status FROM group_members WHERE group_id=? AND student_id=?");
            $chk->bind_param('ii', $gid, $tid); $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            if ($existing && $existing['status'] === 'blocked') {
                $msg = 'This student is blocked from this group. Unblock them first.'; $msg_type = 'err';
            } elseif ($existing && $existing['status'] === 'active') {
                $msg = 'This student is already a member.'; $msg_type = 'err';
            } else {
                $st2 = $conn->prepare("INSERT INTO group_members (group_id,student_id,status) VALUES (?,?,'active') ON DUPLICATE KEY UPDATE status='active'");
                $st2->bind_param('ii', $gid, $tid); $st2->execute();
                $msg = 'Student added to the group successfully.';
            }
        }
    } else { $msg = 'Only the group admin can add members.'; $msg_type = 'err'; }
}

// Remove member (admin only)
if (isset($_POST['remove_member'])) {
    $gid = (int)$_POST['group_id'];
    $tid = (int)$_POST['target_id'];
    if (is_grp_admin($conn, $gid, $uid) && $tid !== $uid) {
        $st = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND student_id=?");
        $st->bind_param('ii', $gid, $tid); $st->execute();
        $msg = 'Member removed from group.'; $msg_type = 'err';
    } else { $msg = 'Cannot perform this action.'; $msg_type = 'err'; }
}

// Block member by student number (admin only)
if (isset($_POST['block_member'])) {
    $gid  = (int)$_POST['group_id'];
    $snum = trim($_POST['block_num'] ?? '');
    if (is_grp_admin($conn, $gid, $uid)) {
        $st = $conn->prepare("SELECT id FROM students WHERE student_num=? LIMIT 1");
        $st->bind_param('s', $snum); $st->execute();
        $target = $st->get_result()->fetch_assoc();
        if (!$target) {
            $msg = 'No student found with that student number.'; $msg_type = 'err';
        } elseif ((int)$target['id'] === $uid) {
            $msg = 'You cannot block yourself.'; $msg_type = 'err';
        } else {
            $tid = (int)$target['id'];
            $st2 = $conn->prepare("INSERT INTO group_members (group_id,student_id,status) VALUES (?,?,'blocked') ON DUPLICATE KEY UPDATE status='blocked'");
            $st2->bind_param('ii', $gid, $tid); $st2->execute();
            $msg = 'Student blocked from this group.'; $msg_type = 'err';
        }
    } else { $msg = 'Only the group admin can block members.'; $msg_type = 'err'; }
}

// Unblock member (admin only)
if (isset($_POST['unblock_member'])) {
    $gid = (int)$_POST['group_id'];
    $tid = (int)$_POST['target_id'];
    if (is_grp_admin($conn, $gid, $uid)) {
        $st = $conn->prepare("DELETE FROM group_members WHERE group_id=? AND student_id=? AND status='blocked'");
        $st->bind_param('ii', $gid, $tid); $st->execute();
        $msg = 'Student unblocked. They can now rejoin the group.';
    } else { $msg = 'Only the group admin can unblock members.'; $msg_type = 'err'; }
}

// ── Fetch my groups ──────────────────────────────────────────
$my_groups = [];
$st = $conn->prepare("
    SELECT g.*, COUNT(gm2.student_id) AS mc
    FROM study_groups g
    JOIN group_members gm  ON g.id = gm.group_id AND gm.student_id = ? AND gm.status = 'active'
    LEFT JOIN group_members gm2 ON g.id = gm2.group_id AND gm2.status = 'active'
    GROUP BY g.id
    ORDER BY g.name
");
$st->bind_param('i', $uid);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) $my_groups[] = $row;

// ── Fetch subjects for create form ───────────────────────────
$subjects = [];
$r = $conn->query("SELECT name FROM subjects ORDER BY name");
while ($row = $r->fetch_assoc()) $subjects[] = $row['name'];

// ── Fetch members for groups I admin ─────────────────────────
$members_map = [];
foreach ($my_groups as $g) {
    if ((int)($g['created_by'] ?? 0) === $uid) {
        $gid = (int)$g['id'];
        $st  = $conn->prepare("
            SELECT gm.student_id, gm.status, s.first_name, s.last_name, s.student_num
            FROM group_members gm
            JOIN students s ON gm.student_id = s.id
            WHERE gm.group_id = ?
            ORDER BY gm.status ASC, s.first_name ASC
        ");
        $st->bind_param('i', $gid); $st->execute();
        $res = $st->get_result(); $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $members_map[$gid] = $rows;
    }
}

// ── Helpers ──────────────────────────────────────────────────
function subj_class($s) {
    $m = ['Mathematics'=>'s-blue','Computer Science'=>'s-purple','Physics'=>'s-orange',
          'Chemistry'=>'s-green','Biology'=>'s-green','Engineering'=>'s-red',
          'Information Technology'=>'s-blue','Electronics'=>'s-orange','Statistics'=>'s-purple'];
    return $m[$s] ?? 's-blue';
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
  <title>My Groups — LSGS</title>
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
      display: flex; align-items: center; gap: 10px;
    }
    .sb-logo img {
      width: 38px; height: 38px; object-fit: contain;
      background: #fff; border-radius: 7px; padding: 2px;
    }
    .sb-logo-text { font-size: 14px; font-weight: 800; color: #fff; line-height: 1.2; }
    .sb-logo-sub  { font-size: 9px; color: rgba(255,255,255,.35); font-family: 'DM Mono',monospace; }
    .sb-nav-wrap  { flex: 1; padding: 14px 12px; }
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
      background: none; border: none; color: #fff; text-decoration: none;
    }
    .logout-btn:hover { opacity: .9; }

    /* ══ MAIN ═════════════════════════════════════════════════ */
    .main { margin-left: 240px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

    /* ══ TOPBAR ═══════════════════════════════════════════════ */
    .topbar {
      background: var(--white); border-bottom: 1px solid var(--border);
      padding: 0 28px; height: 60px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 50;
    }
    .topbar-title { font-size: 16px; font-weight: 700; }
    .topbar-right { display: flex; align-items: center; gap: 12px; }

    /* ══ BUTTONS ══════════════════════════════════════════════ */
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px;
      font-size: 13.5px; font-weight: 600; cursor: pointer;
      border: none; font-family: inherit; transition: all .15s; text-decoration: none;
    }
    .btn-primary { background: var(--blue); color: #fff; }
    .btn-primary:hover { background: #1559d4; }
    .btn-ghost   { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .btn-ghost:hover { background: var(--surface); color: var(--text); }
    .btn-danger  { background: transparent; color: var(--red); border: 1.5px solid #fecaca; }
    .btn-danger:hover { background: #fff0f0; }
    .btn-sm { padding: 5px 12px; font-size: 12.5px; }
    .btn-block { display: block; text-align: center; width: 100%; }

    /* ══ CONTENT ══════════════════════════════════════════════ */
    .content { padding: 28px; flex: 1; }

    /* ══ ALERT ════════════════════════════════════════════════ */
    .alert {
      padding: 12px 16px; border-radius: 9px;
      font-size: 13.5px; font-weight: 500;
      margin-bottom: 20px; border: 1px solid;
    }
    .alert-ok  { background: #e6f9f1; color: #065f46; border-color: #a7f3d0; }
    .alert-err { background: #fff0f0; color: #b91c1c; border-color: #fecaca; }

    /* ══ SEARCH BAR ═══════════════════════════════════════════ */
    .search-bar {
      display: flex; align-items: center; gap: 10px;
      background: var(--white); border: 1px solid var(--border);
      border-radius: 10px; padding: 10px 16px; margin-bottom: 16px;
    }
    .search-bar input {
      border: none; background: transparent;
      font-family: inherit; font-size: 13.5px;
      color: var(--text); flex: 1; outline: none;
    }
    .search-icon { color: var(--muted); font-size: 16px; }

    /* ══ FILTER CHIPS ═════════════════════════════════════════ */
    .filter-row  { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }
    .chip {
      padding: 5px 14px; border-radius: 20px; font-size: 12.5px;
      font-weight: 500; border: 1px solid var(--border);
      background: var(--white); cursor: pointer; color: var(--muted);
      transition: all .12s;
    }
    .chip.active, .chip:hover { background: var(--blue); color: #fff; border-color: var(--blue); }

    /* ══ GRID ═════════════════════════════════════════════════ */
    .grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; }

    /* ══ GROUP CARDS ══════════════════════════════════════════ */
    .group-card {
      background: var(--white); border: 1px solid var(--border);
      border-radius: 12px; padding: 18px; transition: all .15s;
    }
    .group-card:hover { border-color: var(--blue); box-shadow: 0 4px 18px rgba(30,111,255,.1); }
    .gc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .subj-tag {
      display: inline-block; padding: 3px 10px;
      border-radius: 20px; font-size: 11px; font-weight: 600;
    }
    .s-blue   { background: #e8f0ff; color: var(--blue); }
    .s-green  { background: #e6f9f1; color: #0a9457; }
    .s-orange { background: #fff4e5; color: #b45309; }
    .s-purple { background: #f3e8ff; color: #7c3aed; }
    .s-red    { background: #fff0f0; color: #b91c1c; }
    .status-dot { font-size: 11px; font-weight: 600; }
    .dot-green  { color: var(--green); }
    .dot-orange { color: var(--orange); }
    .gc-name { font-size: 14.5px; font-weight: 700; margin-bottom: 6px; }
    .gc-meta {
      font-size: 12px; color: var(--muted);
      display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 4px;
    }
    .health-wrap { margin-top: 10px; }
    .health-row {
      display: flex; justify-content: space-between;
      font-size: 11px; color: var(--muted); margin-bottom: 4px;
    }
    .track { height: 5px; background: #eef1f8; border-radius: 10px; overflow: hidden; }
    .fill  { height: 100%; border-radius: 10px; background: linear-gradient(90deg, var(--blue), var(--cyan)); }
    .gc-actions { display: flex; gap: 8px; margin-top: 14px; }

    /* ══ EMPTY STATE ══════════════════════════════════════════ */
    .empty-state {
      text-align: center; color: var(--muted);
      padding: 60px 20px; font-size: 13.5px;
    }
    .empty-state a { color: var(--blue); font-weight: 600; }
    .empty-icon { font-size: 48px; margin-bottom: 16px; }

    /* ══ MODAL ════════════════════════════════════════════════ */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(15,31,61,.55); z-index: 500;
      align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal {
      background: var(--white); border-radius: 16px;
      padding: 28px; width: 500px;
      max-width: 95vw; max-height: 90vh; overflow-y: auto;
    }
    .modal-hdr {
      display: flex; justify-content: space-between;
      align-items: center; margin-bottom: 22px;
    }
    .modal-title { font-size: 17px; font-weight: 700; }
    .modal-close { font-size: 20px; cursor: pointer; color: var(--muted); background: none; border: none; }
    .fg  { margin-bottom: 14px; }
    .fl  {
      display: block; font-size: 11px; font-weight: 700;
      color: var(--muted); text-transform: uppercase;
      letter-spacing: .6px; margin-bottom: 5px;
    }
    .fi, .fs {
      width: 100%; padding: 10px 13px;
      border: 1.5px solid var(--border); border-radius: 8px;
      font-family: inherit; font-size: 13.5px; color: var(--text);
      background: var(--white); outline: none; transition: border-color .15s;
    }
    .fi:focus, .fs:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(30,111,255,.09); }
    .fr2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-actions { display: flex; gap: 10px; margin-top: 6px; }

    /* ══ PAGE HEADER ══════════════════════════════════════════ */
    .page-header {
      display: flex; align-items: center;
      justify-content: space-between; margin-bottom: 22px;
    }
    .page-title { font-size: 20px; font-weight: 800; }
    .page-sub   { font-size: 13px; color: var(--muted); margin-top: 3px; }

    /* ══ ONLINE / CHAT / ATTENDANCE ══════════════════════════ */
    .online-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:700;background:#e6f9f1;color:#065f46;border:1px solid #a7f3d0}
    .btn-chat{background:linear-gradient(135deg,var(--blue),var(--cyan));color:#fff}
    .btn-chat:hover{opacity:.9}
    .att-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border)}
    .att-row:last-child{border-bottom:none}
    .att-toggle{display:flex;gap:6px}
    .att-toggle label{display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:500;cursor:pointer;padding:4px 10px;border-radius:6px;border:1.5px solid var(--border);transition:all .12s}
    .att-toggle input[type=checkbox]{display:none}
    .att-toggle input:checked + span{color:var(--green)}
    .att-toggle label:has(input:checked){background:#e6f9f1;border-color:var(--green);color:var(--green)}
    .modal-wide{width:600px}

    /* ══ ADMIN ELEMENTS ══════════════════════════════════════ */
    .btn-warn    { background: transparent; color: var(--orange); border: 1.5px solid #fde68a; }
    .btn-warn:hover { background: #fffbeb; }
    .btn-success { background: transparent; color: var(--green); border: 1.5px solid #a7f3d0; }
    .btn-success:hover { background: #e6f9f1; }
    .admin-badge {
      display: inline-flex; align-items: center; gap: 3px;
      padding: 2px 8px; border-radius: 20px; font-size: 10.5px;
      font-weight: 700; background: #fff4e5; color: #b45309;
      border: 1px solid #fde68a;
    }
    .member-row {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 0; border-bottom: 1px solid var(--border);
    }
    .member-row:last-child { border-bottom: none; }
    .member-av {
      width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
      background: linear-gradient(135deg, var(--blue), var(--cyan));
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; color: #fff;
    }
    .member-av.blocked { background: linear-gradient(135deg, #f04438, #fca5a5); }
    .member-info { flex: 1; min-width: 0; }
    .member-name { font-size: 13px; font-weight: 600; }
    .member-num  { font-size: 11px; color: var(--muted); font-family: 'DM Mono', monospace; }
    .blocked-tag {
      font-size: 10px; font-weight: 700; color: var(--red);
      background: #fff0f0; border: 1px solid #fecaca;
      padding: 1px 6px; border-radius: 4px; margin-left: 6px;
    }
    .section-lbl {
      font-size: 10.5px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .8px; color: var(--muted); margin: 14px 0 8px;
    }
    .divider { height: 1px; background: var(--border); margin: 16px 0; }
    .warn-box {
      background: #fff8f8; border: 1px solid #fecaca;
      border-radius: 10px; padding: 14px; margin-top: 16px;
    }

    /* ══ RESPONSIVE ═══════════════════════════════════════════ */
    @media (max-width: 1100px) { .grid-3 { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 820px)  {
      .sidebar { transform: translateX(-100%); transition: transform .25s; }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; }
      .grid-3 { grid-template-columns: 1fr; }
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
    <a class="nav-item" href="dashboard.php"><span class="ico">⊞</span> Dashboard</a>
    <a class="nav-item active" href="groups.php"><span class="ico">◎</span> My Groups</a>
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
      <div class="topbar-title">My Groups</div>
    </div>
    <div class="topbar-right">
      <a href="matching.php" class="btn btn-ghost btn-sm">Find Groups</a>
      <button class="btn btn-primary" onclick="openModal('modal-create')">+ New Group</button>
    </div>
  </header>

  <!-- CONTENT -->
  <div class="content">

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msg_type ?>">
        <?= $msg_type === 'ok' ? '✓' : '⚠' ?> <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-header">
      <div>
        <div class="page-title">My Study Groups</div>
        <div class="page-sub">
          <?= count($my_groups) ?> group<?= count($my_groups) !== 1 ? 's' : '' ?> joined
        </div>
      </div>
    </div>

    <!-- Search bar -->
    <div class="search-bar">
      <span class="search-icon">🔍</span>
      <input type="text" id="search-input"
             placeholder="Search groups by name or subject..."
             oninput="filterGroups()"/>
    </div>

    <!-- Filter chips -->
    <div class="filter-row" id="filter-row">
      <div class="chip active" data-f="All" onclick="setFilter(this)">All</div>
      <?php
        $unique_subjects = array_unique(array_column($my_groups, 'subject'));
        foreach ($unique_subjects as $subj):
          if ($subj):
      ?>
        <div class="chip" data-f="<?= htmlspecialchars($subj) ?>"
             onclick="setFilter(this)"><?= htmlspecialchars($subj) ?></div>
      <?php endif; endforeach; ?>
    </div>

    <!-- Groups grid -->
    <?php if (empty($my_groups)): ?>
      <div style="background:var(--white);border:1px solid var(--border);border-radius:12px;padding:20px">
        <div class="empty-state">
          <div class="empty-icon">◎</div>
          <strong style="font-size:16px;color:var(--text)">No groups joined yet</strong>
          <p style="margin-top:8px;margin-bottom:24px">
            Find a group that matches your subjects and skill level.
          </p>
          <a href="matching.php" class="btn btn-primary">Go to Smart Match →</a>
        </div>
      </div>
    <?php else: ?>
      <div class="grid-3" id="groups-grid">
        <?php foreach ($my_groups as $g):
          $h  = $g['health'] ?? 80;
          $hc = health_color($h);
        ?>
          <div class="group-card"
               data-name="<?= strtolower(htmlspecialchars($g['name'])) ?>"
               data-subj="<?= htmlspecialchars($g['subject'] ?? '') ?>">

            <div class="gc-header">
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <span class="subj-tag <?= subj_class($g['subject'] ?? '') ?>">
                  <?= htmlspecialchars($g['subject'] ?? 'General') ?>
                </span>
                <?php if ((int)($g['created_by'] ?? 0) === $uid): ?>
                  <span class="admin-badge">👑 Admin</span>
                <?php endif; ?>
              </div>
              <span class="status-dot <?= $h >= 70 ? 'dot-green' : 'dot-orange' ?>">
                <?= $h >= 70 ? '● Active' : '● Needs Attention' ?>
              </span>
            </div>

            <div class="gc-name" style="display:flex;align-items:center;gap:8px">
              <?= htmlspecialchars($g['name']) ?>
              <?php if (!empty($g['is_online'])): ?>
                <span class="online-badge">🌐 Online</span>
              <?php endif; ?>
            </div>

            <div class="gc-meta">
              <span>👥 <?= $g['mc'] ?> members</span>
              <?php if ($g['days']): ?>
                <span>📅 <?= htmlspecialchars($g['days']) ?>
                  <?= $g['meeting_time'] ? ' · ' . htmlspecialchars($g['meeting_time']) : '' ?>
                </span>
              <?php endif; ?>
            </div>
            <div class="gc-meta">
              <?php if ($g['location']): ?>
                <span>📍 <?= htmlspecialchars($g['location']) ?></span>
              <?php endif; ?>
              <?php if ($g['skill_level']): ?>
                <span>📚 <?= htmlspecialchars($g['skill_level']) ?></span>
              <?php endif; ?>
            </div>

            <div class="health-wrap">
              <div class="health-row">
                <span>Group Health</span>
                <span style="color:<?= $hc ?>;font-weight:700"><?= $h ?>%</span>
              </div>
              <div class="track">
                <div class="fill" style="width:<?= $h ?>%;
                  <?= $h < 70 ? 'background:linear-gradient(90deg,#f79009,#f59e0b)' : '' ?>">
                </div>
              </div>
            </div>

            <div class="gc-actions">
              <?php if ((int)($g['created_by'] ?? 0) === $uid): ?>
                <button class="btn btn-ghost btn-sm" style="flex:1"
                  onclick="openEditModal(<?= $g['id'] ?>, <?= htmlspecialchars(json_encode($g), ENT_QUOTES) ?>)">
                  ✏ Edit
                </button>
                <button class="btn btn-primary btn-sm" style="flex:1"
                  onclick="openManageModal(<?= $g['id'] ?>)">
                  👥 Manage
                </button>
              </div>
              <div class="gc-actions" style="margin-top:6px">
                <a href="chat.php?gid=<?= $g['id'] ?>" class="btn btn-chat btn-sm" style="flex:1">
                  💬 Group Chat
                </a>
                <button class="btn btn-ghost btn-sm" style="flex:1"
                  onclick="openAttModal(<?= $g['id'] ?>)">
                  ✓ Attendance
                </button>
              </div>
              <div class="gc-actions" style="margin-top:6px">
                <button class="btn btn-ghost btn-sm" style="flex:1;color:var(--orange);border-color:var(--orange)"
                  onclick="openTransferModal(<?= $g['id'] ?>)">
                  🔄 Transfer & Leave
                </button>
                <form method="POST" style="flex:1"
                  onsubmit="return confirm('Permanently delete this group and ALL its data? This cannot be undone.')">
                  <input type="hidden" name="delete_my_group" value="1"/>
                  <input type="hidden" name="group_id" value="<?= $g['id'] ?>"/>
                  <button class="btn btn-danger btn-sm btn-block" type="submit">🗑 Delete Group</button>
                </form>
              <?php else: ?>
                <a href="chat.php?gid=<?= $g['id'] ?>" class="btn btn-chat btn-sm" style="flex:1">
                  💬 Chat
                </a>
                <form method="POST" style="flex:1"
                      onsubmit="return confirm('Are you sure you want to leave this group?')">
                  <input type="hidden" name="leave_group" value="1"/>
                  <input type="hidden" name="group_id" value="<?= $g['id'] ?>"/>
                  <button class="btn btn-danger btn-sm btn-block" type="submit">Leave</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- No results message (hidden by default) -->
      <div id="no-results" style="display:none">
        <div style="background:var(--white);border:1px solid var(--border);border-radius:12px;padding:20px">
          <div class="empty-state">No groups match your search.</div>
        </div>
      </div>

    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ CREATE GROUP MODAL ════════════════════════════════════ -->
<div class="modal-overlay" id="modal-create">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">Create New Study Group</div>
      <button class="modal-close" onclick="closeModal('modal-create')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="create_group" value="1"/>

      <div class="fg">
        <label class="fl">Group Name *</label>
        <input class="fi" type="text" name="g_name"
               placeholder="e.g. MATH301 Study Crew" required/>
      </div>

      <div class="fg">
        <label class="fl">Subject *</label>
        <select class="fs" name="g_subject" required>
          <option value="">Select subject...</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label class="fl">Skill Level</label>
        <select class="fs" name="g_skill_level">
          <option value="Beginner">Beginner</option>
          <option value="Intermediate" selected>Intermediate</option>
          <option value="Advanced">Advanced</option>
        </select>
      </div>

      <div class="fr2">
        <div class="fg">
          <label class="fl">Meeting Days</label>
          <input class="fi" type="text" name="g_days" placeholder="e.g. Mon & Wed"/>
        </div>
        <div class="fg">
          <label class="fl">Time</label>
          <input class="fi" type="time" name="g_time"/>
        </div>
      </div>

      <div class="fg">
        <label class="fl">Location / Link</label>
        <input class="fi" type="text" name="g_location" placeholder="Room 4B or Zoom link"/>
      </div>

      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1"
                onclick="closeModal('modal-create')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">
          Create Group
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT GROUP MODAL ═════════════════════════════════════ -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">✏ Edit Group</div>
      <button class="modal-close" onclick="closeModal('modal-edit')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="edit_group" value="1"/>
      <input type="hidden" name="group_id" id="edit-gid"/>
      <div class="fg"><label class="fl">Group Name *</label>
        <input class="fi" type="text" name="g_name" id="edit-name" required/>
      </div>
      <div class="fg"><label class="fl">Subject *</label>
        <select class="fs" name="g_subject" id="edit-subject">
          <?php foreach ($subjects as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg"><label class="fl">Skill Level</label>
        <select class="fs" name="g_skill_level" id="edit-level">
          <option value="Beginner">Beginner</option>
          <option value="Intermediate">Intermediate</option>
          <option value="Advanced">Advanced</option>
        </select>
      </div>
      <div class="fr2">
        <div class="fg"><label class="fl">Meeting Days</label>
          <input class="fi" type="text" name="g_days" id="edit-days" placeholder="e.g. Mon & Wed"/>
        </div>
        <div class="fg"><label class="fl">Time</label>
          <input class="fi" type="time" name="g_time" id="edit-time"/>
        </div>
      </div>
      <div class="fg"><label class="fl">Location / Link</label>
        <input class="fi" type="text" name="g_location" id="edit-loc" placeholder="Room 4B or Zoom link"/>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MANAGE MEMBERS MODAL ══════════════════════════════════ -->
<div class="modal-overlay" id="modal-manage">
  <div class="modal" style="width:560px">
    <div class="modal-hdr">
      <div class="modal-title">👥 Manage Members</div>
      <button class="modal-close" onclick="closeModal('modal-manage')">✕</button>
    </div>

    <?php foreach ($my_groups as $g):
      if ((int)($g['created_by'] ?? 0) !== $uid) continue;
      $gid_m  = (int)$g['id'];
      $all_m  = $members_map[$gid_m] ?? [];
      $active_m  = array_values(array_filter($all_m, fn($m) => $m['status'] === 'active'));
      $blocked_m = array_values(array_filter($all_m, fn($m) => $m['status'] === 'blocked'));
    ?>
    <div class="manage-section" id="manage-<?= $gid_m ?>" style="display:none">

      <!-- Add member -->
      <div style="background:var(--surface);border-radius:10px;padding:14px;margin-bottom:4px">
        <div class="section-lbl">➕ Add Member by Student Number</div>
        <form method="POST" style="display:flex;gap:8px;align-items:center">
          <input type="hidden" name="add_member" value="1"/>
          <input type="hidden" name="group_id" value="<?= $gid_m ?>"/>
          <input class="fi" type="text" name="student_num"
                 placeholder="e.g. 220001234" style="flex:1;margin:0" required/>
          <button class="btn btn-primary btn-sm" type="submit">Add</button>
        </form>
      </div>

      <!-- Active members -->
      <div class="section-lbl">Active Members (<?= count($active_m) ?>)</div>
      <?php if (empty($active_m)): ?>
        <p style="font-size:12.5px;color:var(--muted);margin-bottom:8px">No active members yet.</p>
      <?php else: foreach ($active_m as $m):
        $ini = strtoupper(substr($m['first_name'],0,1).substr($m['last_name'],0,1));
        $is_self = ((int)$m['student_id'] === $uid);
      ?>
        <div class="member-row">
          <div class="member-av"><?= $ini ?></div>
          <div class="member-info">
            <div class="member-name"><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?><?= $is_self ? ' <span style="color:var(--muted);font-weight:400;font-size:11px">(You)</span>' : '' ?></div>
            <div class="member-num"><?= htmlspecialchars($m['student_num']) ?></div>
          </div>
          <?php if (!$is_self): ?>
          <div style="display:flex;gap:6px;flex-shrink:0">
            <form method="POST" onsubmit="return confirm('Remove this member?')" style="display:inline">
              <input type="hidden" name="remove_member" value="1"/>
              <input type="hidden" name="group_id" value="<?= $gid_m ?>"/>
              <input type="hidden" name="target_id" value="<?= $m['student_id'] ?>"/>
              <button class="btn btn-danger btn-sm" type="submit">Remove</button>
            </form>
            <form method="POST" onsubmit="return confirm('Block this student? They cannot rejoin until unblocked.')" style="display:inline">
              <input type="hidden" name="block_member" value="1"/>
              <input type="hidden" name="group_id" value="<?= $gid_m ?>"/>
              <input type="hidden" name="block_num" value="<?= htmlspecialchars($m['student_num']) ?>"/>
              <button class="btn btn-warn btn-sm" type="submit">Block</button>
            </form>
          </div>
          <?php else: ?>
            <span style="font-size:11px;color:var(--muted)">Group Admin</span>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>

      <!-- Blocked members -->
      <?php if (!empty($blocked_m)): ?>
        <div class="divider"></div>
        <div class="section-lbl" style="color:var(--red)">🚫 Blocked Members (<?= count($blocked_m) ?>)</div>
        <?php foreach ($blocked_m as $m):
          $ini = strtoupper(substr($m['first_name'],0,1).substr($m['last_name'],0,1));
        ?>
          <div class="member-row">
            <div class="member-av blocked"><?= $ini ?></div>
            <div class="member-info">
              <div class="member-name"><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?><span class="blocked-tag">BLOCKED</span></div>
              <div class="member-num"><?= htmlspecialchars($m['student_num']) ?></div>
            </div>
            <form method="POST" onsubmit="return confirm('Unblock this student?')" style="flex-shrink:0">
              <input type="hidden" name="unblock_member" value="1"/>
              <input type="hidden" name="group_id" value="<?= $gid_m ?>"/>
              <input type="hidden" name="target_id" value="<?= $m['student_id'] ?>"/>
              <button class="btn btn-success btn-sm" type="submit">Unblock</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Block by student number -->
      <div class="warn-box">
        <div class="section-lbl" style="color:var(--red);margin-top:0">🚫 Block by Student Number</div>
        <p style="font-size:12px;color:var(--muted);margin-bottom:10px">
          Blocks a student even if they are not currently a member. They will be rejected if they try to join.
        </p>
        <form method="POST" style="display:flex;gap:8px;align-items:center"
              onsubmit="return confirm('Block this student from the group?')">
          <input type="hidden" name="block_member" value="1"/>
          <input type="hidden" name="group_id" value="<?= $gid_m ?>"/>
          <input class="fi" type="text" name="block_num"
                 placeholder="e.g. 220001234" style="flex:1;margin:0" required/>
          <button class="btn btn-danger btn-sm" type="submit">Block</button>
        </form>
      </div>

    </div><!-- /manage-section -->
    <?php endforeach; ?>

  </div>
</div>

<!-- ══ ATTENDANCE MODAL ═════════════════════════════════════ -->
<div class="modal-overlay" id="modal-attendance">
  <div class="modal modal-wide">
    <div class="modal-hdr">
      <div class="modal-title">✓ Mark Attendance</div>
      <button class="modal-close" onclick="closeModal('modal-attendance')">✕</button>
    </div>
    <?php foreach ($my_groups as $g):
      if ((int)($g['created_by'] ?? 0) !== $uid) continue;
      $gid_a   = (int)$g['id'];
      $att_members = $members_map[$gid_a] ?? [];
      $active_att  = array_values(array_filter($att_members, fn($m) => $m['status'] === 'active'));
    ?>
    <div class="manage-section" id="att-<?= $gid_a ?>" style="display:none">
      <form method="POST">
        <input type="hidden" name="mark_attendance" value="1"/>
        <input type="hidden" name="group_id" value="<?= $gid_a ?>"/>
        <div class="fg" style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
          <label class="fl" style="margin:0;white-space:nowrap">Date:</label>
          <input class="fi" type="date" name="att_date"
                 value="<?= date('Y-m-d') ?>" style="max-width:200px;margin:0"/>
        </div>
        <div class="section-lbl">Members (<?= count($active_att) ?>)</div>
        <?php if (empty($active_att)): ?>
          <p style="color:var(--muted);font-size:13px">No active members to mark.</p>
        <?php else: foreach ($active_att as $m):
          $ini = strtoupper(substr($m['first_name'],0,1).substr($m['last_name'],0,1));
        ?>
          <div class="att-row">
            <div style="display:flex;align-items:center;gap:10px">
              <div class="member-av"><?= $ini ?></div>
              <div>
                <div class="member-name"><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?></div>
                <div class="member-num"><?= htmlspecialchars($m['student_num']) ?></div>
              </div>
            </div>
            <div class="att-toggle">
              <label>
                <input type="checkbox" name="att[<?= $m['student_id'] ?>]" value="1" checked/>
                <span>✓ Present</span>
              </label>
            </div>
          </div>
        <?php endforeach; endif; ?>
        <div class="form-actions" style="margin-top:18px">
          <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-attendance')">Cancel</button>
          <button type="submit" class="btn btn-primary" style="flex:2">Save Attendance</button>
        </div>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ JAVASCRIPT ════════════════════════════════════════════ -->
<script>
  function openModal(id)  { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('open'); });
  });

  // Open edit modal and pre-fill fields
  function openEditModal(gid, data) {
    document.getElementById('edit-gid').value  = gid;
    document.getElementById('edit-name').value = data.name || '';
    document.getElementById('edit-days').value = data.days || '';
    document.getElementById('edit-time').value = data.meeting_time || '';
    document.getElementById('edit-loc').value  = data.location || '';
    var subj = document.getElementById('edit-subject');
    for (var i = 0; i < subj.options.length; i++)
      subj.options[i].selected = (subj.options[i].value === data.subject);
    var lvl = document.getElementById('edit-level');
    for (var i = 0; i < lvl.options.length; i++)
      lvl.options[i].selected = (lvl.options[i].value === data.skill_level);
    openModal('modal-edit');
  }

  // Open manage modal and show the right group's section
  function openManageModal(gid) {
    document.querySelectorAll('.manage-section').forEach(function(s) { s.style.display = 'none'; });
    var sec = document.getElementById('manage-' + gid);
    if (sec) sec.style.display = 'block';
    openModal('modal-manage');
  }

  // Open attendance modal
  function openAttModal(gid) {
    document.querySelectorAll('.manage-section').forEach(function(s) { s.style.display = 'none'; });
    var sec = document.getElementById('att-' + gid);
    if (sec) sec.style.display = 'block';
    openModal('modal-attendance');
  }

  // Filter & Search
  var activeFilter = 'All';
  function setFilter(el) {
    document.querySelectorAll('.chip').forEach(function(c) { c.classList.remove('active'); });
    el.classList.add('active');
    activeFilter = el.dataset.f;
    filterGroups();
  }
  function filterGroups() {
    var q = document.getElementById('search-input').value.toLowerCase();
    var cards = document.querySelectorAll('#groups-grid .group-card');
    var visible = 0;
    cards.forEach(function(card) {
      var show = card.dataset.name.includes(q) && (activeFilter === 'All' || card.dataset.subj === activeFilter);
      card.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    var noRes = document.getElementById('no-results');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
  }

  // Mobile menu
  if (window.innerWidth <= 820) document.getElementById('menu-btn').style.display = 'block';
  window.addEventListener('resize', function() {
    document.getElementById('menu-btn').style.display = window.innerWidth <= 820 ? 'block' : 'none';
  });
</script>


<!-- ══ TRANSFER ADMIN MODAL ══════════════════════════════════ -->
<div class="modal-overlay" id="modal-transfer">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">🔄 Transfer Admin & Leave</div>
      <button class="modal-close" onclick="closeModal('modal-transfer')">✕</button>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
      Choose a member to become the new group admin. Once you transfer, you will be removed from the group.
    </p>
    <?php foreach ($my_groups as $g):
      if ((int)($g['created_by'] ?? 0) !== $uid) continue;
      $gid_t = (int)$g['id'];
      $t_members = array_values(array_filter($members_map[$gid_t] ?? [], fn($m) => $m['status']==='active' && (int)$m['student_id'] !== $uid));
    ?>
    <div class="manage-section" id="transfer-<?= $gid_t ?>" style="display:none">
      <div class="section-lbl" style="margin-bottom:12px">Group: <strong><?= htmlspecialchars($g['name']) ?></strong></div>
      <?php if (empty($t_members)): ?>
        <p style="color:var(--muted);font-size:13px;margin-bottom:16px">No other members in this group. You can leave directly.</p>
        <form method="POST">
          <input type="hidden" name="leave_group" value="1"/>
          <input type="hidden" name="group_id" value="<?= $gid_t ?>"/>
          <div class="form-actions">
            <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-transfer')">Cancel</button>
            <button type="submit" class="btn btn-danger" style="flex:2">Leave Group</button>
          </div>
        </form>
      <?php else: ?>
        <form method="POST">
          <input type="hidden" name="transfer_and_leave" value="1"/>
          <input type="hidden" name="group_id" value="<?= $gid_t ?>"/>
          <div class="fg" style="margin-bottom:16px">
            <label class="fl">Nominate New Admin</label>
            <select class="fi" name="new_admin_id" required>
              <option value="">— Select a member —</option>
              <?php foreach ($t_members as $m): ?>
                <option value="<?= $m['student_id'] ?>">
                  <?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?> (<?= htmlspecialchars($m['student_num']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-actions">
            <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-transfer')">Cancel</button>
            <button type="submit" class="btn btn-danger" style="flex:2"
              onclick="return confirm('Transfer admin and leave this group?')">
              Transfer & Leave
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function openTransferModal(gid) {
  document.querySelectorAll('[id^="transfer-"]').forEach(function(s){ s.style.display='none'; });
  var sec = document.getElementById('transfer-' + gid);
  if (sec) sec.style.display = 'block';
  openModal('modal-transfer');
}
</script>
</body>
</html>
