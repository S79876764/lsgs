<?php
// ============================================================
//  LSGS  |  PAGE 2 — admin_dashboard.php
//  Admin Dashboard
//  Eswatini College of Technology
// ============================================================

session_start();

// ── Auth guard — admin only ──────────────────────────────────
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

// ── Database connection ──────────────────────────────────────
require_once __DIR__.'/db.php';

$admin = $_SESSION['user'];

// ── Safe migrations ───────────────────────────────────────────
try{$conn->query("ALTER TABLE group_members ADD COLUMN  status ENUM('active','blocked') NOT NULL DEFAULT 'active'");}catch(Exception $e){}
try{$conn->query("CREATE TABLE  group_attendance (id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, student_id INT NOT NULL, att_date DATE NOT NULL, status ENUM('Present','Absent') DEFAULT 'Present', marked_by INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_att (group_id,student_id,att_date))");}catch(Exception $e){}
try{$conn->query("CREATE TABLE  chat_messages (id INT AUTO_INCREMENT PRIMARY KEY, group_id INT NOT NULL, student_id INT NOT NULL, message TEXT NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP)");}catch(Exception $e){}
try{$conn->query("ALTER TABLE resources ADD COLUMN  file_path VARCHAR(500) DEFAULT NULL");}catch(Exception $e){}
try{$conn->query("ALTER TABLE subjects ADD COLUMN programme VARCHAR(200) DEFAULT NULL");}catch(Exception $e){}
try{$conn->query("ALTER TABLE subjects ADD COLUMN year VARCHAR(20) DEFAULT NULL");}catch(Exception $e){}

// ── Handle POST actions ──────────────────────────────────────
$msg = '';
$msg_type = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add Student
    if (isset($_POST['add_student'])) {
        $first  = trim($_POST['first_name']  ?? '');
        $last   = trim($_POST['last_name']   ?? '');
        $stunum = trim($_POST['student_num'] ?? '');
        $email  = trim(strtolower($_POST['email'] ?? ''));
        $prog   = trim($_POST['programme']   ?? '');
        $year   = trim($_POST['year']        ?? '');
        $pw     = $_POST['password'] ?? 'student123';
        $phone  = trim($_POST['phone'] ?? '');
        $hash   = password_hash($pw, PASSWORD_DEFAULT);
        // Safely add phone column if not exists
        if ($conn->query("SHOW COLUMNS FROM students LIKE 'phone'")->num_rows === 0)
            $conn->query("ALTER TABLE students ADD COLUMN phone VARCHAR(30) DEFAULT NULL");
        $chk    = $conn->prepare("SELECT id FROM students WHERE LOWER(email)=? LIMIT 1");
        $chk->bind_param('s', $email); $chk->execute();
        $chk2   = $conn->prepare("SELECT id FROM students WHERE student_num=? LIMIT 1");
        $chk2->bind_param('s', $stunum); $chk2->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $msg = 'Email already registered.'; $msg_type = 'err';
        } elseif ($stunum && $chk2->get_result()->fetch_assoc()) {
            $msg = 'Student number '.htmlspecialchars($stunum).' is already in the database.'; $msg_type = 'err';
        } else {
            $st = $conn->prepare("INSERT INTO students (first_name,last_name,student_num,email,programme,year,password,phone) VALUES (?,?,?,?,?,?,?,?)");
            $st->bind_param('ssssssss', $first, $last, $stunum, $email, $prog, $year, $hash, $phone);
            $st->execute();
            $msg = 'Student added successfully.';
        }
    }

    // Delete Student — wipe everything related to this student
    if (isset($_POST['edit_student'])) {
        $id   = (int)$_POST['student_id'];
        $fn   = trim($_POST['first_name']  ?? '');
        $ln   = trim($_POST['last_name']   ?? '');
        $prog = trim($_POST['programme']   ?? '');
        $yr   = trim($_POST['year']        ?? '');
        $snum = trim($_POST['student_num'] ?? '');
        $email= trim(strtolower($_POST['email'] ?? ''));
        $phone= trim($_POST['phone'] ?? '');
        if ($fn && $ln) {
            // Check if student_num already taken by another student
            if ($snum) {
                $sc = $conn->prepare("SELECT id FROM students WHERE student_num=? AND id!=? LIMIT 1");
                $sc->bind_param('si',$snum,$id); $sc->execute();
                if ($sc->get_result()->fetch_assoc()) {
                    $msg = 'Student number '.$snum.' is already used by another student.'; $msg_type='err';
                    goto end_edit_student;
                }
            }
            // Safely add phone column if missing
            if ($conn->query("SHOW COLUMNS FROM students LIKE 'phone'")->num_rows === 0)
                $conn->query("ALTER TABLE students ADD COLUMN phone VARCHAR(30) DEFAULT NULL");
            $st = $conn->prepare("UPDATE students SET first_name=?,last_name=?,programme=?,year=?,student_num=?,email=?,phone=? WHERE id=?");
            $st->bind_param('sssssssi',$fn,$ln,$prog,$yr,$snum,$email,$phone,$id); $st->execute();
            $msg = 'Student updated.';
        } else { $msg = 'First and last name are required.'; $msg_type='err'; }
        end_edit_student:;
    }

    if (isset($_POST['delete_student'])) {
        $del_id = (int)$_POST['student_id'];

        // Safety check — must be a valid positive ID
        if ($del_id <= 0) {
            $msg = 'Invalid student ID.'; $msg_type = 'err';
        } else {
            // Verify student actually exists before doing anything
            $chk_del = $conn->prepare("SELECT id FROM students WHERE id=? LIMIT 1");
            $chk_del->bind_param('i', $del_id); $chk_del->execute();
            if (!$chk_del->get_result()->fetch_assoc()) {
                $msg = 'Student not found.'; $msg_type = 'err';
            } else {
                // Delete physical resource files first
                $del_files = $conn->prepare("SELECT file_path FROM resources WHERE student_id=?");
                $del_files->bind_param('i', $del_id); $del_files->execute();
                $del_fres = $del_files->get_result();
                while ($del_frow = $del_fres->fetch_assoc()) {
                    if (!empty($del_frow['file_path']) && file_exists(__DIR__.'/'.$del_frow['file_path'])) {
                        unlink(__DIR__.'/'.$del_frow['file_path']);
                    }
                }

                // Wipe all DB records for THIS student only
                $del_sqls = [
                    "DELETE FROM group_attendance WHERE student_id=?",
                    "DELETE FROM group_attendance WHERE marked_by=?",
                    "DELETE FROM chat_messages    WHERE student_id=?",
                    "DELETE FROM attendance       WHERE student_id=?",
                    "DELETE FROM resources        WHERE student_id=?",
                    "DELETE FROM group_members    WHERE student_id=?",
                    "UPDATE study_groups SET created_by=NULL WHERE created_by=?",
                    "DELETE FROM students         WHERE id=?",
                ];
                foreach ($del_sqls as $del_sql) {
                    $del_st = $conn->prepare($del_sql);
                    if ($del_st) { $del_st->bind_param('i', $del_id); $del_st->execute(); $del_st->close(); }
                }

                $msg = 'Student and all associated data have been removed.';
            }
        }
    }

    // Add Group
    if (isset($_POST['add_group'])) {
        $name  = trim($_POST['g_name']        ?? '');
        $subj  = trim($_POST['g_subject']     ?? '');
        $level = trim($_POST['g_skill_level'] ?? 'Intermediate');
        $days  = trim($_POST['g_days']        ?? '');
        $time  = trim($_POST['g_time']        ?? '');
        $loc   = trim($_POST['g_location']    ?? '');
        if ($name && $subj) {
            $st = $conn->prepare("INSERT INTO study_groups (name,subject,skill_level,days,meeting_time,location) VALUES (?,?,?,?,?,?)");
            $st->bind_param('ssssss', $name, $subj, $level, $days, $time, $loc);
            $st->execute();
            $msg = 'Study group created.';
        } else { $msg = 'Group name and subject are required.'; $msg_type='err'; }
    }

    // Edit Group (admin)
    if (isset($_POST['edit_group'])) {
        $id    = (int)$_POST['group_id'];
        $name  = trim($_POST['g_name']        ?? '');
        $subj  = trim($_POST['g_subject']     ?? '');
        $level = trim($_POST['g_skill_level'] ?? 'Intermediate');
        $days  = trim($_POST['g_days']        ?? '');
        $time  = trim($_POST['g_time']        ?? '');
        $loc   = trim($_POST['g_location']    ?? '');
        $online= isset($_POST['g_online']) ? 1 : 0;
        if ($name && $subj) {
            try{$conn->query("ALTER TABLE study_groups ADD COLUMN  is_online TINYINT(1) DEFAULT 0");}catch(Exception $e){}
            $st = $conn->prepare("UPDATE study_groups SET name=?,subject=?,skill_level=?,days=?,meeting_time=?,location=?,is_online=? WHERE id=?");
            $st->bind_param('ssssssii',$name,$subj,$level,$days,$time,$loc,$online,$id); $st->execute();
            $msg = 'Group updated.';
        } else { $msg = 'Group name and subject are required.'; $msg_type='err'; }
    }

    // Delete Group — wipe everything
    if (isset($_POST['delete_group'])) {
        $id = (int)$_POST['group_id'];
        $fq = $conn->prepare("SELECT file_path FROM resources WHERE group_id=?");
        if ($fq) {
            $fq->bind_param('i',$id); $fq->execute();
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
            if ($st) { $st->bind_param('i',$id); $st->execute(); }
        }
        $msg = 'Group and all related data deleted.';
    }

    // Add Subject
    if (isset($_POST['add_subject'])) {
        $name  = trim($_POST['subject_name'] ?? '');
        $prog  = trim($_POST['subject_programme'] ?? '');
        $year  = trim($_POST['subject_year'] ?? '');
        if ($name && $prog && $year) {
            $st = $conn->prepare("INSERT INTO subjects (name, programme, year) VALUES (?,?,?)");
            $st->bind_param('sss', $name, $prog, $year); $st->execute();
            $msg = 'Subject added.';
        } elseif ($name) {
            $msg = 'Please select a programme and year for this subject.'; $msg_type = 'err';
        }
    }

    // Delete Subject
    if (isset($_POST['delete_subject'])) {
        $id = (int)$_POST['subject_id'];
        $st = $conn->prepare("DELETE FROM subjects WHERE id=?");
        $st->bind_param('i',$id); $st->execute();
        $msg = 'Subject removed.';
    }

    // Add Programme
    if (isset($_POST['add_programme'])) {
        $name = trim($_POST['prog_name'] ?? '');
        if ($name) {
            $st = $conn->prepare("INSERT IGNORE INTO programmes (name) VALUES (?)");
            $st->bind_param('s',$name); $st->execute();
            $msg = 'Programme added.';
        }
    }

    // Delete Programme
    if (isset($_POST['delete_programme'])) {
        $id = (int)$_POST['prog_id'];
        $st = $conn->prepare("DELETE FROM programmes WHERE id=?");
        $st->bind_param('i',$id); $st->execute();
        $msg = 'Programme removed.';
    }

    // Add Session
    if (isset($_POST['add_session'])) {
        $title = trim($_POST['s_title']   ?? '');
        $gid   = (int)$_POST['s_group_id'];
        $day   = trim($_POST['s_day']     ?? '');
        $time  = trim($_POST['s_time']    ?? '');
        $loc   = trim($_POST['s_location']?? '');
        if ($title && $gid) {
            $st = $conn->prepare("INSERT INTO sessions (title,group_id,day,session_time,location) VALUES (?,?,?,?,?)");
            $st->bind_param('sisss', $title, $gid, $day, $time, $loc);
            $st->execute();
            $msg = 'Session scheduled.';
        } else { $msg = 'Title and group are required.'; $msg_type='err'; }
    }

    // Edit Session
    if (isset($_POST['edit_session'])) {
        $id    = (int)$_POST['session_id'];
        $title = trim($_POST['s_title']    ?? '');
        $gid2  = (int)$_POST['s_group_id'];
        $day   = trim($_POST['s_day']      ?? '');
        $time  = trim($_POST['s_time']     ?? '');
        $loc   = trim($_POST['s_location'] ?? '');
        if ($title && $gid2) {
            $st = $conn->prepare("UPDATE sessions SET title=?,group_id=?,day=?,session_time=?,location=? WHERE id=?");
            $st->bind_param('sisssi',$title,$gid2,$day,$time,$loc,$id); $st->execute();
            $msg = 'Session updated.';
        } else { $msg = 'Title and group are required.'; $msg_type='err'; }
    }

    // Delete Session + attendance
    if (isset($_POST['delete_session'])) {
        $id = (int)$_POST['session_id'];
        foreach ([
            "DELETE FROM attendance WHERE session_id=?",
            "DELETE FROM sessions   WHERE id=?",
        ] as $sql) {
            $st = $conn->prepare($sql);
            if ($st) { $st->bind_param('i',$id); $st->execute(); }
        }
        $msg = 'Session deleted.';
    }

    // Save Security Question
    if (isset($_POST['save_security_question'])) {
        $sq   = trim($_POST['security_question'] ?? '');
        $sa   = strtolower(trim($_POST['security_answer']  ?? ''));
        $conf = strtolower(trim($_POST['confirm_answer']   ?? ''));
        if (!$sq || strlen($sa) < 2) {
            $msg = 'Please select a question and enter an answer.'; $msg_type = 'err';
        } elseif ($sa !== $conf) {
            $msg = 'Answers do not match.'; $msg_type = 'err';
        } else {
            $hash = password_hash($sa, PASSWORD_DEFAULT);
            $st   = $conn->prepare("UPDATE admin SET security_question=?, security_answer=? WHERE id=?");
            $st->bind_param('ssi',$sq,$hash,$admin['id']); $st->execute();
            $msg = 'Security question updated successfully.';
        }
    }

    // Save Settings
    if (isset($_POST['save_settings'])) {
        $name  = trim($_POST['admin_name']  ?? '');
        $email = trim($_POST['admin_email'] ?? '');
        $pw    = $_POST['admin_pw'] ?? '';
        if ($pw) {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $st   = $conn->prepare("UPDATE admin SET name=?,email=?,password=? WHERE id=?");
            $st->bind_param('sssi', $name, $email, $hash, $admin['id']);
        } else {
            $st = $conn->prepare("UPDATE admin SET name=?,email=? WHERE id=?");
            $st->bind_param('ssi', $name, $email, $admin['id']);
        }
        $st->execute();
        $_SESSION['user']['name'] = $name;
        $msg = 'Settings saved.';
    }

    // Reset all students
    if (isset($_POST['reset_students'])) {
        $conn->query("DELETE FROM attendance");
        $conn->query("DELETE FROM group_members");
        $conn->query("DELETE FROM students");
        $msg = 'All student data has been reset.';
    }
}

// ── Fetch all data ───────────────────────────────────────────
// Stats
$stat_students = $conn->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
$stat_groups   = $conn->query("SELECT COUNT(*) c FROM study_groups")->fetch_assoc()['c'];
$stat_subjects = $conn->query("SELECT COUNT(*) c FROM subjects")->fetch_assoc()['c'];
$stat_sessions = $conn->query("SELECT COUNT(*) c FROM sessions")->fetch_assoc()['c'];

// Students
$students = [];
$r = $conn->query("SELECT * FROM students ORDER BY created_at DESC");
while ($row = $r->fetch_assoc()) $students[] = $row;

// Groups
$groups = [];
$r = $conn->query("SELECT g.*, COUNT(gm.student_id) mc FROM study_groups g LEFT JOIN group_members gm ON g.id=gm.group_id GROUP BY g.id ORDER BY g.name");
while ($row = $r->fetch_assoc()) $groups[] = $row;

// Subjects
$subjects = [];
$r = $conn->query("SELECT * FROM subjects ORDER BY programme, year, name");
while ($row = $r->fetch_assoc()) $subjects[] = $row;

// Programmes
$programmes = [];
$r = $conn->query("SELECT * FROM programmes ORDER BY name");
while ($row = $r->fetch_assoc()) $programmes[] = $row;

// Sessions
$sessions = [];
$r = $conn->query("SELECT s.*, g.name gname FROM sessions s LEFT JOIN study_groups g ON s.group_id=g.id ORDER BY FIELD(s.day,'Mon','Tue','Wed','Thu','Fri','Sat'), s.session_time");
while ($row = $r->fetch_assoc()) $sessions[] = $row;

// Recent students for overview
$recent_students = array_slice($students, 0, 6);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard — LSGS</title>
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
      flex-direction: column;
    }

    /* ══ TOP BAR ══════════════════════════════════════════════ */
    .topbar {
      background: var(--navy);
      height: 58px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 28px;
      flex-shrink: 0;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .topbar-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .topbar-logo {
      width: 36px; height: 36px;
      background: #fff;
      border-radius: 8px;
      padding: 3px;
      display: flex; align-items: center; justify-content: center;
    }
    .topbar-logo img { width: 100%; height: 100%; object-fit: contain; }
    .topbar-title { font-size: 15px; font-weight: 800; color: #fff; }
    .adm-badge {
      background: var(--blue);
      color: #fff;
      font-size: 10px;
      font-weight: 800;
      padding: 3px 9px;
      border-radius: 6px;
      letter-spacing: .5px;
    }
    .topbar-right { display: flex; align-items: center; gap: 12px; }
    .topbar-admin-name { font-size: 13px; color: rgba(255,255,255,.5); }

    /* ══ LAYOUT ════════════════════════════════════════════════ */
    .body-wrap {
      display: flex;
      flex: 1;
      overflow: hidden;
      height: calc(100vh - 58px);
    }

    /* ══ SIDEBAR ═══════════════════════════════════════════════ */
    .sidebar {
      width: 215px;
      background: var(--white);
      border-right: 1px solid var(--border);
      padding: 14px 10px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      overflow-y: auto;
      flex-shrink: 0;
    }
    .sb-section-label {
      font-size: 10px;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 10px 8px 4px;
    }
    .sb-nav {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 9px 10px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 13.5px;
      font-weight: 500;
      color: var(--muted);
      transition: all .13s;
      border: none;
      background: transparent;
      width: 100%;
      text-align: left;
      font-family: inherit;
    }
    .sb-nav:hover  { background: var(--surface); color: var(--text); }
    .sb-nav.active { background: #e8f0ff; color: var(--blue); font-weight: 700; }
    .sb-nav .ico   { font-size: 15px; width: 18px; text-align: center; }

    /* ══ MAIN CONTENT ══════════════════════════════════════════ */
    .main-content {
      flex: 1;
      overflow-y: auto;
      padding: 28px;
    }

    /* ══ SECTIONS ══════════════════════════════════════════════ */
    .section       { display: none; }
    .section.active{ display: block; }

    .page-title {
      font-size: 20px;
      font-weight: 800;
      margin-bottom: 22px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* ══ STAT CARDS ════════════════════════════════════════════ */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 26px;
    }
    .stat-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
    }
    .stat-label { font-size: 11.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .6px; margin-bottom: 8px; }
    .stat-value { font-size: 32px; font-weight: 800; color: var(--text); line-height: 1; }
    .stat-sub   { font-size: 12px; color: var(--muted); margin-top: 6px; }

    /* ══ CARDS ═════════════════════════════════════════════════ */
    .card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 22px;
      margin-bottom: 20px;
    }
    .card-title {
      font-size: 12px;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .7px;
      margin-bottom: 16px;
    }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }

    /* ══ BUTTONS ═══════════════════════════════════════════════ */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
      border: none;
      font-family: inherit;
      transition: all .15s;
      text-decoration: none;
    }
    .btn-primary { background: var(--blue);  color: #fff; }
    .btn-primary:hover { background: #1559d4; }
    .btn-ghost   { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .btn-ghost:hover { background: var(--surface); color: var(--text); }
    .btn-danger  { background: transparent; color: var(--red); border: 1.5px solid #fecaca; font-size: 12px; padding: 5px 10px; }
    .btn-danger:hover { background: #fff0f0; }
    .btn-sm      { padding: 5px 12px; font-size: 12.5px; }
    .btn-red     { background: var(--red); color: #fff; }
    .btn-red:hover { background: #c0392b; }

    /* ══ TABLES ════════════════════════════════════════════════ */
    .tbl-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th {
      font-size: 11.5px; font-weight: 700; color: var(--muted);
      text-align: left; padding: 10px 14px;
      background: var(--surface); border-bottom: 1px solid var(--border);
      text-transform: uppercase; letter-spacing: .3px;
    }
    td { font-size: 13.5px; padding: 12px 14px; border-bottom: 1px solid var(--border); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafbff; }

    .pill {
      display: inline-block; padding: 2px 9px;
      border-radius: 6px; font-size: 11.5px; font-weight: 600;
    }
    .pill-blue   { background: #e8f0ff; color: var(--blue); }
    .pill-green  { background: #e6f9f1; color: #0a9457; }
    .pill-orange { background: #fff4e5; color: #b45309; }
    .pill-red    { background: #fff0f0; color: #b91c1c; }

    /* ══ FORMS ═════════════════════════════════════════════════ */
    .fg  { margin-bottom: 14px; }
    .fl  {
      display: block;
      font-size: 11px; font-weight: 700; color: var(--muted);
      text-transform: uppercase; letter-spacing: .6px; margin-bottom: 5px;
    }
    .fi, .fs {
      width: 100%; padding: 10px 13px;
      border: 1.5px solid var(--border); border-radius: 8px;
      font-family: inherit; font-size: 13.5px; color: var(--text);
      background: var(--white); outline: none; transition: border-color .15s;
    }
    .fi:focus, .fs:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(30,111,255,.09);
    }
    .fr2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .form-actions { display: flex; gap: 10px; margin-top: 6px; }

    /* ══ MODAL ═════════════════════════════════════════════════ */
    .modal-overlay {
      display: none;
      position: fixed; inset: 0;
      background: rgba(15,31,61,.55);
      z-index: 500;
      align-items: center;
      justify-content: center;
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
    .modal-close {
      font-size: 20px; cursor: pointer; color: var(--muted);
      background: none; border: none;
    }

    /* ══ ALERTS ════════════════════════════════════════════════ */
    .alert {
      padding: 12px 16px; border-radius: 9px;
      font-size: 13.5px; font-weight: 500;
      margin-bottom: 18px; border: 1px solid;
    }
    .alert-ok  { background: #e6f9f1; color: #065f46; border-color: #a7f3d0; }
    .alert-err { background: #fff0f0; color: #b91c1c; border-color: #fecaca; }

    /* ══ SUBJECT / PROGRAMME LIST ══════════════════════════════ */
    .list-item {
      display: flex; align-items: center;
      justify-content: space-between;
      padding: 10px 0;
      border-bottom: 1px solid var(--border);
      font-size: 13.5px;
    }
    .list-item:last-child { border-bottom: none; }

    /* ══ SECTION HEADER ════════════════════════════════════════ */
    .sec-header {
      display: flex; align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }
    .sec-title { font-size: 15px; font-weight: 700; }

    /* ══ DANGER ZONE ═══════════════════════════════════════════ */
    .danger-zone {
      border: 1.5px solid #fecaca;
      border-radius: 12px;
      padding: 20px;
      background: #fffbfb;
    }
    .danger-title {
      font-size: 13px; font-weight: 700;
      color: var(--red); text-transform: uppercase;
      letter-spacing: .6px; margin-bottom: 8px;
    }

    /* ══ TOPBAR BUTTONS ════════════════════════════════════════ */
    .tb-btn {
      padding: 7px 14px; border-radius: 8px;
      font-size: 13px; font-weight: 600;
      cursor: pointer; border: none; font-family: inherit;
      transition: all .15s; text-decoration: none;
    }
    .tb-btn-ghost  { background: rgba(255,255,255,.1); color: #fff; border: 1px solid rgba(255,255,255,.2); }
    .tb-btn-ghost:hover { background: rgba(255,255,255,.18); }
    .tb-btn-blue   { background: var(--blue); color: #fff; }
    .tb-btn-blue:hover { background: #1559d4; }

    /* ══ HEALTH BAR ════════════════════════════════════════════ */
    .health-wrap { margin-top: 4px; }
    .track { height: 5px; background: #eef1f8; border-radius: 10px; overflow: hidden; margin-top: 3px; }
    .fill  { height: 100%; border-radius: 10px; background: linear-gradient(90deg, var(--blue), var(--cyan)); }

    /* ══ RESPONSIVE ════════════════════════════════════════════ */
    @media (max-width: 1100px) { .stat-grid { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 768px)  { .grid-2 { grid-template-columns: 1fr; } .sidebar { display: none; } }
  </style>
</head>
<body>

<!-- ══ TOP BAR ══════════════════════════════════════════════ -->
<div class="topbar">
  <div class="topbar-left">
    <div class="topbar-logo">
      <img src="assets/ecot.jpg" alt="ECOT"/>
    </div>
    <span class="topbar-title">Admin</span>
    <span class="adm-badge">ADMINISTRATOR</span>
  </div>
  <div class="topbar-right">
    <span class="topbar-admin-name">
      <?= htmlspecialchars($admin['name'] ?? 'Admin') ?>
    </span>
    <a href="logout.php"    class="tb-btn tb-btn-ghost">Sign Out</a>
  </div>
</div>

<!-- ══ BODY WRAP ═════════════════════════════════════════════ -->
<div class="body-wrap">

  <!-- ══ SIDEBAR ════════════════════════════════════════════ -->
  <aside class="sidebar">
    <div class="sb-section-label">Overview</div>
    <button class="sb-nav active" onclick="showSection('overview',this)">
      <span class="ico">📊</span> Dashboard
    </button>

    <div class="sb-section-label">Manage</div>
    <button class="sb-nav" onclick="showSection('students',this)">
      <span class="ico">👥</span> Students
    </button>
    <button class="sb-nav" onclick="showSection('groups',this)">
      <span class="ico">◎</span> Study Groups
    </button>
    <button class="sb-nav" onclick="showSection('subjects',this)">
      <span class="ico">📚</span> Subjects
    </button>
    <button class="sb-nav" onclick="showSection('sessions',this)">
      <span class="ico">◷</span> Sessions
    </button>

    <div class="sb-section-label">System</div>
    <button class="sb-nav" onclick="showSection('settings',this)">
      <span class="ico">⚙</span> Settings
    </button>
  </aside>

  <!-- ══ MAIN CONTENT ════════════════════════════════════════ -->
  <div class="main-content">

    <?php if ($msg): ?>
      <div class="alert alert-<?= $msg_type === 'err' ? 'err' : 'ok' ?>">
        <?= $msg_type === 'err' ? '⚠ ' : '✓ ' ?><?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════
         SECTION 1 — OVERVIEW / DASHBOARD
    ══════════════════════════════════════════════════════ -->
    <div class="section active" id="sec-overview">
      <div class="page-title">📊 Admin Dashboard</div>

      <!-- Stats -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-label">Total Students</div>
          <div class="stat-value"><?= $stat_students ?></div>
          <div class="stat-sub">registered accounts</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Study Groups</div>
          <div class="stat-value"><?= $stat_groups ?></div>
          <div class="stat-sub">active groups</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Subjects</div>
          <div class="stat-value"><?= $stat_subjects ?></div>
          <div class="stat-sub">available subjects</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Sessions</div>
          <div class="stat-value"><?= $stat_sessions ?></div>
          <div class="stat-sub">scheduled this week</div>
        </div>
      </div>

      <!-- Recent students + Groups overview -->
      <div class="grid-2">
        <div class="card">
          <div class="card-title">Recent Registrations</div>
          <?php if (empty($recent_students)): ?>
            <p style="color:var(--muted);font-size:13px">No students registered yet.</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Name</th><th>Programme</th><th>Joined</th></tr></thead>
              <tbody>
                <?php foreach ($recent_students as $s): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></strong></td>
                    <td><?= htmlspecialchars($s['programme'] ?? '—') ?></td>
                    <td style="font-size:11.5px;color:var(--muted)"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-title">Study Groups Overview</div>
          <?php if (empty($groups)): ?>
            <p style="color:var(--muted);font-size:13px">No groups created yet.</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Group</th><th>Subject</th><th>Members</th><th>Health</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($groups,0,5) as $g): ?>
                  <?php $h = $g['health'] ?? 80; $hc = $h>=80?'var(--green)':($h>=60?'var(--orange)':'var(--red)'); ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
                    <td><?= htmlspecialchars($g['subject'] ?? '—') ?></td>
                    <td><?= $g['mc'] ?></td>
                    <td>
                      <span style="color:<?=$hc?>;font-weight:700;font-size:12px"><?=$h?>%</span>
                      <div class="track" style="width:60px;display:inline-block;vertical-align:middle;margin-left:6px">
                        <div class="fill" style="width:<?=$h?>%"></div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         SECTION 2 — STUDENTS
    ══════════════════════════════════════════════════════ -->
    <div class="section" id="sec-students">
      <div class="sec-header">
        <div class="page-title" style="margin:0">👥 Students</div>
        <button class="btn btn-primary" onclick="openModal('modal-add-student')">+ Add Student</button>
      </div>

      <div class="card" style="padding:0;overflow:hidden">
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th><th>Student #</th><th>Email</th>
                <th>Programme</th><th>Year</th><th>Joined</th><th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($students)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">No students registered yet.</td></tr>
              <?php else: foreach ($students as $s): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></strong></td>
                  <td style="font-family:'DM Mono',monospace;font-size:12px"><?= htmlspecialchars($s['student_num'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($s['email']) ?></td>
                  <td><?= htmlspecialchars($s['programme'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($s['year'] ?? '—') ?></td>
                  <td style="font-size:11.5px;color:var(--muted)"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                  <td>
                    <button class="btn btn-ghost btn-sm" style="margin-right:5px"
                      onclick='openEditStudent(<?= $s["id"] ?>, <?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)'>
                      ✏ Edit
                    </button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('WARNING: This will permanently delete this student and ALL their data. This cannot be undone. Continue?')">
                      <input type="hidden" name="delete_student" value="1"/>
                      <input type="hidden" name="student_id" value="<?= $s['id'] ?>"/>
                      <button class="btn btn-danger btn-sm" type="submit">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         SECTION 3 — STUDY GROUPS
    ══════════════════════════════════════════════════════ -->
    <div class="section" id="sec-groups">
      <div class="sec-header">
        <div class="page-title" style="margin:0">◎ Study Groups</div>
        <button class="btn btn-primary" onclick="openModal('modal-add-group')">+ New Group</button>
      </div>

      <div class="card" style="padding:0;overflow:hidden">
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>Group Name</th><th>Subject</th><th>Days / Time</th>
                <th>Location</th><th>Members</th><th>Health</th><th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($groups)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">No groups created yet.</td></tr>
              <?php else: foreach ($groups as $g): $h=$g['health']??80; $hc=$h>=80?'var(--green)':($h>=60?'var(--orange)':'var(--red)'); ?>
                <tr>
                  <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
                  <td><?= htmlspecialchars($g['subject'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($g['days'] ?? '—') ?> <?= htmlspecialchars($g['meeting_time'] ?? '') ?></td>
                  <td><?= htmlspecialchars($g['location'] ?? '—') ?></td>
                  <td><?= $g['mc'] ?></td>
                  <td><span style="color:<?=$hc?>;font-weight:700"><?=$h?>%</span></td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-ghost btn-sm" style="margin-right:5px"
                      onclick='openEditGroup(<?= $g["id"] ?>, <?= htmlspecialchars(json_encode($g), ENT_QUOTES) ?>)'>
                      ✏ Edit
                    </button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this group and ALL its data (members, chat, resources, sessions)? Cannot be undone.')">
                      <input type="hidden" name="delete_group" value="1"/>
                      <input type="hidden" name="group_id" value="<?= $g['id'] ?>"/>
                      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         SECTION 4 — SUBJECTS & PROGRAMMES
    ══════════════════════════════════════════════════════ -->
    <div class="section" id="sec-subjects">
      <div class="page-title">📚 Subjects &amp; Programmes</div>
      <div class="grid-2">

        <!-- Subjects -->
        <div class="card">
          <div class="sec-header">
            <div class="card-title" style="margin:0">Subjects</div>
            <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-subject')">+ Add</button>
          </div>
          <?php if (empty($subjects)): ?>
            <p style="color:var(--muted);font-size:13px">No subjects yet.</p>
          <?php else: ?>
            <?php foreach ($subjects as $s): ?>
              <div class="list-item">
                <div style="flex:1;min-width:0">
                  <div style="font-weight:600;font-size:13.5px"><?= htmlspecialchars($s['name']) ?></div>
                  <div style="font-size:11.5px;color:var(--muted);margin-top:2px">
                    <?= htmlspecialchars($s['programme'] ?? '—') ?> &nbsp;·&nbsp; <?= htmlspecialchars($s['year'] ?? '—') ?>
                  </div>
                </div>
                <form method="POST" style="display:inline" onsubmit="return confirm('Remove subject?')">
                  <input type="hidden" name="delete_subject" value="1"/>
                  <input type="hidden" name="subject_id" value="<?= $s['id'] ?>"/>
                  <button class="btn btn-danger btn-sm" type="submit">✕</button>
                </form>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Programmes -->
        <div class="card">
          <div class="sec-header">
            <div class="card-title" style="margin:0">Programmes</div>
            <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-prog')">+ Add</button>
          </div>
          <?php if (empty($programmes)): ?>
            <p style="color:var(--muted);font-size:13px">No programmes yet.</p>
          <?php else: ?>
            <?php foreach ($programmes as $p): ?>
              <div class="list-item">
                <span><?= htmlspecialchars($p['name']) ?></span>
                <form method="POST" style="display:inline" onsubmit="return confirm('Remove programme?')">
                  <input type="hidden" name="delete_programme" value="1"/>
                  <input type="hidden" name="prog_id" value="<?= $p['id'] ?>"/>
                  <button class="btn btn-danger btn-sm" type="submit">✕</button>
                </form>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         SECTION 5 — SESSIONS
    ══════════════════════════════════════════════════════ -->
    <div class="section" id="sec-sessions">
      <div class="sec-header">
        <div class="page-title" style="margin:0">◷ Sessions</div>
        <button class="btn btn-primary" onclick="openModal('modal-add-session')">+ Schedule Session</button>
      </div>

      <div class="card" style="padding:0;overflow:hidden">
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr><th>Title</th><th>Group</th><th>Day</th><th>Time</th><th>Location</th><th></th></tr>
            </thead>
            <tbody>
              <?php if (empty($sessions)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:30px">No sessions scheduled yet.</td></tr>
              <?php else: foreach ($sessions as $s): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($s['title']) ?></strong></td>
                  <td><?= htmlspecialchars($s['gname'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($s['day'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($s['session_time'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($s['location'] ?? '—') ?></td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-ghost btn-sm" style="margin-right:5px"
                      onclick='openEditSession(<?= $s["id"] ?>, <?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>)'>
                      ✏ Edit
                    </button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this session?')">
                      <input type="hidden" name="delete_session" value="1"/>
                      <input type="hidden" name="session_id" value="<?= $s['id'] ?>"/>
                      <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         SECTION 6 — SETTINGS
    ══════════════════════════════════════════════════════ -->
    <div class="section" id="sec-settings">
      <div class="page-title">⚙ System Settings</div>

      <!-- Security Question -->
      <div class="card" style="max-width:520px;margin-bottom:24px">
        <div class="card-title" style="margin-bottom:4px">🔐 Security Question</div>
        <p style="color:var(--muted);font-size:13px;margin-bottom:18px">Set or update your security question for password recovery.</p>
        <?php
        try{$conn->query("ALTER TABLE admin ADD COLUMN  security_question VARCHAR(255) DEFAULT NULL");}catch(Exception $e){}
        try{$conn->query("ALTER TABLE admin ADD COLUMN  security_answer VARCHAR(255) DEFAULT NULL");}catch(Exception $e){}
        $admin_sq = $conn->query("SELECT security_question FROM admin WHERE id={$admin['id']} LIMIT 1")->fetch_assoc();
        ?>
        <?php if (!empty($admin_sq['security_question'])): ?>
          <div style="background:#e6f9f1;border:1px solid #a7f3d0;border-radius:8px;padding:10px 14px;font-size:13px;color:#065f46;margin-bottom:14px">
            ✅ Current question: <strong><?= htmlspecialchars($admin_sq['security_question']) ?></strong>
          </div>
        <?php else: ?>
          <div style="background:#fff4e5;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:13px;color:#92400e;margin-bottom:14px">
            ⚠ No security question set. Set one below to enable password recovery.
          </div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="save_security_question" value="1"/>
          <div class="fg" style="margin-bottom:12px">
            <label class="fl">Security Question</label>
            <select class="fi" name="security_question" required>
              <option value="">— Choose a question —</option>
              <?php
              $questions = [
                "What is the name of your first pet?",
                "What is your mother's maiden name?",
                "What was the name of your primary school?",
                "What is the name of the town where you were born?",
                "What was the make of your first car?",
                "What is your oldest sibling's middle name?",
                "What was the name of your childhood best friend?",
              ];
              foreach ($questions as $q):
                $sel = ($admin_sq['security_question'] ?? '') === $q ? 'selected' : '';
              ?>
                <option <?= $sel ?>><?= htmlspecialchars($q) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg" style="margin-bottom:12px">
            <label class="fl">New Answer</label>
            <input class="fi" type="text" name="security_answer" placeholder="Your answer (not case-sensitive)" autocomplete="off"/>
          </div>
          <div class="fg" style="margin-bottom:16px">
            <label class="fl">Confirm Answer</label>
            <input class="fi" type="text" name="confirm_answer" placeholder="Repeat your answer" autocomplete="off"/>
          </div>
          <button class="btn btn-primary" type="submit">Save Security Question</button>
        </form>
      </div>

      <!-- Admin account settings -->
      <div class="card" style="max-width:520px;margin-bottom:24px">
        <div class="card-title">Admin Account</div>
        <form method="POST">
          <input type="hidden" name="save_settings" value="1"/>
          <div class="fg">
            <label class="fl">Name</label>
            <input class="fi" type="text" name="admin_name"
                   value="<?= htmlspecialchars($admin['name'] ?? 'Administrator') ?>" required/>
          </div>
          <div class="fg">
            <label class="fl">Email</label>
            <input class="fi" type="email" name="admin_email"
                   value="<?= htmlspecialchars($admin['email'] ?? '') ?>" required/>
          </div>
          <div class="fg">
            <label class="fl">New Password <span style="text-transform:none;font-weight:400">(leave blank to keep current)</span></label>
            <input class="fi" type="password" name="admin_pw" placeholder="Enter new password"/>
          </div>
          <button class="btn btn-primary" type="submit">Save Settings</button>
        </form>
      </div>

      <!-- Danger zone -->
      <div class="danger-zone" style="max-width:520px">
        <div class="danger-title">⚠ Danger Zone</div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px">
          Permanently delete all student accounts, group memberships and attendance records. This cannot be undone.
        </p>
        <form method="POST" onsubmit="return confirm('Are you sure? This will delete ALL student data and cannot be undone.')">
          <input type="hidden" name="reset_students" value="1"/>
          <button class="btn btn-red" type="submit">Reset All Student Data</button>
        </form>
      </div>
    </div>

  </div><!-- /main-content -->
</div><!-- /body-wrap -->

<!-- ══════════════════════════════════════════════════════════
     MODALS
══════════════════════════════════════════════════════════ -->

<!-- Add Student -->
<div class="modal-overlay" id="modal-add-student">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">Add Student</div>
      <button class="modal-close" onclick="closeModal('modal-add-student')">✕</button>
    </div>
    <form method="POST" autocomplete="off" onsubmit="return validateAddStudent()">
      <input type="hidden" name="add_student" value="1"/>
      <!-- hidden dummy fields to trick browser autofill away from real fields -->
      <input type="text" name="fake_user" style="display:none"/>
      <input type="password" name="fake_pass" style="display:none"/>
      <div class="fr2">
        <div class="fg"><label class="fl">First Name *</label><input class="fi" type="text" id="as-fn" name="first_name" autocomplete="off" required/></div>
        <div class="fg"><label class="fl">Last Name *</label><input class="fi" type="text" id="as-ln" name="last_name" autocomplete="off" required/></div>
      </div>
      <div class="fg"><label class="fl">Student Number *</label><input class="fi" type="text" id="as-snum" name="student_num" placeholder="ECO2024001" autocomplete="off" required/></div>
      <div class="fg"><label class="fl">Email *</label><input class="fi" type="email" id="as-email" name="email" autocomplete="off" required/></div>
      <div class="fg">
        <label class="fl">Programme</label>
        <select class="fs" id="as-prog" name="programme">
          <option value="">Select programme...</option>
          <?php foreach ($programmes as $p): ?>
            <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Year</label>
        <select class="fs" id="as-year" name="year">
          <option value="">Select year...</option>
          <?php foreach (['Year 1','Year 2','Year 3','Year 4','Postgraduate'] as $y): ?>
            <option value="<?=$y?>"><?=$y?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg"><label class="fl">Phone Number</label><input class="fi" type="text" id="as-phone" name="phone" placeholder="+268 7XXXXXXX" autocomplete="off"/></div>
      <div class="fg"><label class="fl">Temp Password</label><input class="fi" type="text" id="as-pw" name="password" value="student123" autocomplete="off"/></div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="resetAddStudentForm();closeModal('modal-add-student')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Add Student</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Group -->
<div class="modal-overlay" id="modal-add-group">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">Create Study Group</div>
      <button class="modal-close" onclick="closeModal('modal-add-group')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_group" value="1"/>
      <div class="fg"><label class="fl">Group Name *</label><input class="fi" type="text" name="g_name" placeholder="e.g. MATH301 Crew" required/></div>
      <div class="fr2">
        <div class="fg">
          <label class="fl">Subject *</label>
          <select class="fs" name="g_subject" required>
            <option value="">Select...</option>
            <?php foreach ($subjects as $s): ?>
              <option value="<?= htmlspecialchars($s['name']) ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Skill Level</label>
          <select class="fs" name="g_skill_level">
            <option>Beginner</option><option selected>Intermediate</option><option>Advanced</option>
          </select>
        </div>
      </div>
      <div class="fr2">
        <div class="fg"><label class="fl">Meeting Days</label><input class="fi" type="text" name="g_days" placeholder="Mon & Wed"/></div>
        <div class="fg"><label class="fl">Time</label><input class="fi" type="time" name="g_time"/></div>
      </div>
      <div class="fg"><label class="fl">Location</label><input class="fi" type="text" name="g_location" placeholder="Room 4B or Online"/></div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-add-group')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Create Group</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Subject -->
<div class="modal-overlay" id="modal-add-subject">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">Add Subject</div>
      <button class="modal-close" onclick="closeModal('modal-add-subject')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_subject" value="1"/>
      <div class="fg">
        <label class="fl">Programme *</label>
        <select class="fs" name="subject_programme" required>
          <option value="">— Select Programme —</option>
          <?php foreach ($programmes as $p): ?>
            <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Year *</label>
        <select class="fs" name="subject_year" required>
          <option value="">— Select Year —</option>
          <?php foreach (['Year 1','Year 2','Year 3','Year 4','Postgraduate'] as $y): ?>
            <option value="<?= $y ?>"><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Subject Name *</label>
        <input class="fi" type="text" name="subject_name" placeholder="e.g. Financial Accounting" required/>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-add-subject')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Add Subject</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Programme -->
<div class="modal-overlay" id="modal-add-prog">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">Add Programme</div>
      <button class="modal-close" onclick="closeModal('modal-add-prog')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_programme" value="1"/>
      <div class="fg"><label class="fl">Programme Name *</label><input class="fi" type="text" name="prog_name" placeholder="e.g. Bachelor of Accounting" required/></div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-add-prog')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Add Programme</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Session -->
<div class="modal-overlay" id="modal-add-session">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">Schedule Session</div>
      <button class="modal-close" onclick="closeModal('modal-add-session')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="add_session" value="1"/>
      <div class="fg"><label class="fl">Session Title *</label><input class="fi" type="text" name="s_title" placeholder="e.g. Linear Algebra Drill" required/></div>
      <div class="fg">
        <label class="fl">Group *</label>
        <select class="fs" name="s_group_id" required>
          <option value="">Select group...</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fr2">
        <div class="fg">
          <label class="fl">Day</label>
          <select class="fs" name="s_day">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
              <option value="<?=$d?>"><?=$d?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label class="fl">Time</label><input class="fi" type="time" name="s_time"/></div>
      </div>
      <div class="fg"><label class="fl">Location</label><input class="fi" type="text" name="s_location" placeholder="Room or Zoom link"/></div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-add-session')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Schedule</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════ -->
<script>
  // ── Section switching ──────────────────────────────────────
  function showSection(id, btn) {
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.sb-nav').forEach(b => b.classList.remove('active'));
    document.getElementById('sec-' + id).classList.add('active');
    btn.classList.add('active');
  }

  // ── Modal ──────────────────────────────────────────────────
  function openModal(id) {
    document.getElementById(id).classList.add('open');
    if (id === 'modal-add-student') resetAddStudentForm();
  }
  function closeModal(id) {
    document.getElementById(id).classList.remove('open');
  }
  document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => {
      if (e.target === m) m.classList.remove('open');
    });
  });

  // ── Auto-open section if message present ──────────────────
  <?php if ($msg && isset($_POST)): ?>
    // Keep the correct section open after form submit
    <?php
      $open_sec = 'overview';
      if (isset($_POST['add_student']) || isset($_POST['delete_student']))       $open_sec = 'students';
      elseif (isset($_POST['add_group']) || isset($_POST['delete_group']))       $open_sec = 'groups';
      elseif (isset($_POST['add_subject']) || isset($_POST['delete_subject'])
           || isset($_POST['add_programme']) || isset($_POST['delete_programme'])) $open_sec = 'subjects';
      elseif (isset($_POST['add_session']) || isset($_POST['delete_session']))   $open_sec = 'sessions';
      elseif (isset($_POST['save_settings']) || isset($_POST['reset_students']) || isset($_POST['save_security_question'])) $open_sec = 'settings';
    ?>
    window.addEventListener('DOMContentLoaded', () => {
      const sec = '<?= $open_sec ?>';
      const btn = document.querySelector(`.sb-nav[onclick*="${sec}"]`);
      if (btn) showSection(sec, btn);
    });
  <?php endif; ?>
</script>


<!-- ══ EDIT STUDENT MODAL ══════════════════════════════════ -->
<div class="modal-overlay" id="modal-edit-student">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">✏ Edit Student</div>
      <button class="modal-close" onclick="closeModal('modal-edit-student')">✕</button>
    </div>
    <form method="POST" autocomplete="off">
      <input type="hidden" name="edit_student" value="1"/>
      <input type="hidden" name="student_id" id="es-id"/>
      <div class="form-grid">
        <div class="fg">
          <label class="fl">First Name</label>
          <input class="fi" type="text" name="first_name" id="es-fn" autocomplete="off" required/>
        </div>
        <div class="fg">
          <label class="fl">Last Name</label>
          <input class="fi" type="text" name="last_name" id="es-ln" autocomplete="off" required/>
        </div>
        <div class="fg">
          <label class="fl">Student Number</label>
          <input class="fi" type="text" name="student_num" id="es-snum" autocomplete="off"/>
        </div>
        <div class="fg">
          <label class="fl">Email</label>
          <input class="fi" type="email" name="email" id="es-email" autocomplete="off"/>
        </div>
        <div class="fg">
          <label class="fl">Programme</label>
          <select class="fi" name="programme" id="es-prog">
            <option value="">— Select —</option>
            <?php foreach ($programmes as $p): ?>
              <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Year</label>
          <select class="fi" name="year" id="es-year">
            <option value="">— Select —</option>
            <option>Year 1</option><option>Year 2</option>
            <option>Year 3</option><option>Year 4</option>
            <option>Postgraduate</option>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Phone Number</label>
          <input class="fi" type="text" name="phone" id="es-phone" autocomplete="off"/>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-edit-student')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT GROUP MODAL ════════════════════════════════════ -->
<div class="modal-overlay" id="modal-edit-group">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">✏ Edit Group</div>
      <button class="modal-close" onclick="closeModal('modal-edit-group')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="edit_group" value="1"/>
      <input type="hidden" name="group_id" id="eg-id"/>
      <div class="form-grid">
        <div class="fg">
          <label class="fl">Group Name</label>
          <input class="fi" type="text" name="g_name" id="eg-name" required/>
        </div>
        <div class="fg">
          <label class="fl">Subject</label>
          <input class="fi" type="text" name="g_subject" id="eg-subj" required/>
        </div>
        <div class="fg">
          <label class="fl">Skill Level</label>
          <select class="fi" name="g_skill_level" id="eg-level">
            <option>Beginner</option><option>Intermediate</option><option>Advanced</option>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Meeting Days</label>
          <input class="fi" type="text" name="g_days" id="eg-days" placeholder="e.g. Mon, Wed"/>
        </div>
        <div class="fg">
          <label class="fl">Meeting Time</label>
          <input class="fi" type="time" name="g_time" id="eg-time"/>
        </div>
        <div class="fg">
          <label class="fl">Location</label>
          <input class="fi" type="text" name="g_location" id="eg-loc"/>
        </div>
        <div class="fg" style="flex-direction:row;align-items:center;gap:10px">
          <input type="checkbox" name="g_online" id="eg-online" style="width:auto"/>
          <label class="fl" for="eg-online" style="margin:0">Online Group</label>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-edit-group')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ EDIT SESSION MODAL ══════════════════════════════════ -->
<div class="modal-overlay" id="modal-edit-session">
  <div class="modal">
    <div class="modal-hdr">
      <div class="modal-title">✏ Edit Session</div>
      <button class="modal-close" onclick="closeModal('modal-edit-session')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="edit_session" value="1"/>
      <input type="hidden" name="session_id" id="ess-id"/>
      <div class="form-grid">
        <div class="fg">
          <label class="fl">Session Title</label>
          <input class="fi" type="text" name="s_title" id="ess-title" required/>
        </div>
        <div class="fg">
          <label class="fl">Group</label>
          <select class="fi" name="s_group_id" id="ess-gid">
            <?php foreach ($groups as $g): ?>
              <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Day</label>
          <select class="fi" name="s_day" id="ess-day">
            <option value="">— Select —</option>
            <option>Monday</option><option>Tuesday</option><option>Wednesday</option>
            <option>Thursday</option><option>Friday</option><option>Saturday</option><option>Sunday</option>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Time</label>
          <input class="fi" type="time" name="s_time" id="ess-time"/>
        </div>
        <div class="fg">
          <label class="fl">Location</label>
          <input class="fi" type="text" name="s_location" id="ess-loc"/>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeModal('modal-edit-session')">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Edit modal openers ────────────────────────────────────────
function openEditStudent(id, data) {
  document.getElementById('es-id').value    = id;
  document.getElementById('es-fn').value    = data.first_name  || '';
  document.getElementById('es-ln').value    = data.last_name   || '';
  document.getElementById('es-snum').value  = data.student_num || '';
  document.getElementById('es-email').value = data.email       || '';
  document.getElementById('es-phone').value = data.phone       || '';
  // Programme dropdown
  var pg = document.getElementById('es-prog');
  for (var i=0;i<pg.options.length;i++)
    pg.options[i].selected = (pg.options[i].value === (data.programme||''));
  // Year dropdown
  var yr = document.getElementById('es-year');
  for (var i=0;i<yr.options.length;i++)
    yr.options[i].selected = (yr.options[i].value === (data.year||''));
  openModal('modal-edit-student');
}

function resetAddStudentForm() {
  ['as-fn','as-ln','as-snum','as-email','as-phone'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.value = '';
  });
  var pg = document.getElementById('as-prog');
  if (pg) pg.selectedIndex = 0;
  var yr = document.getElementById('as-year');
  if (yr) yr.selectedIndex = 0;
  var pw = document.getElementById('as-pw');
  if (pw) pw.value = 'student123';
}

function validateAddStudent() {
  var snum = document.getElementById('as-snum').value.trim();
  if (!snum) { alert('Student number is required.'); return false; }
  return true;
}

function openEditGroup(id, data) {
  document.getElementById('eg-id').value   = id;
  document.getElementById('eg-name').value = data.name        || '';
  document.getElementById('eg-subj').value = data.subject     || '';
  document.getElementById('eg-days').value = data.days        || '';
  document.getElementById('eg-time').value = data.meeting_time|| '';
  document.getElementById('eg-loc').value  = data.location    || '';
  document.getElementById('eg-online').checked = data.is_online == 1;
  var lv = document.getElementById('eg-level');
  for (var i=0;i<lv.options.length;i++) {
    lv.options[i].selected = (lv.options[i].value === (data.skill_level||'Intermediate'));
  }
  openModal('modal-edit-group');
}

function openEditSession(id, data) {
  document.getElementById('ess-id').value    = id;
  document.getElementById('ess-title').value = data.title    || '';
  document.getElementById('ess-time').value  = data.session_time || '';
  document.getElementById('ess-loc').value   = data.location || '';
  var gsel = document.getElementById('ess-gid');
  for (var i=0;i<gsel.options.length;i++) {
    gsel.options[i].selected = (gsel.options[i].value == data.group_id);
  }
  var dsel = document.getElementById('ess-day');
  for (var i=0;i<dsel.options.length;i++) {
    dsel.options[i].selected = (dsel.options[i].value === (data.day||''));
  }
  openModal('modal-edit-session');
}
</script>
</body>
</html>
