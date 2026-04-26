<?php
// ============================================================
//  LSGS  |  forgot_password.php — Student Password Reset
//  Uses DB token instead of PHP sessions for Railway compatibility
// ============================================================
session_start();
if (isset($_SESSION['user'])) { header('Location: dashboard.php'); exit; }

require_once __DIR__.'/db.php';

// Ensure reset_token columns exist
$existing = [];
$res = $conn->query("SHOW COLUMNS FROM students");
while ($row = $res->fetch_assoc()) $existing[] = $row['Field'];
if (!in_array('reset_token', $existing))
    $conn->query("ALTER TABLE students ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL");
if (!in_array('reset_token_exp', $existing))
    $conn->query("ALTER TABLE students ADD COLUMN reset_token_exp DATETIME DEFAULT NULL");

$step  = 1;
$error = '';
$trow  = null;
$token = trim($_POST['token'] ?? '');

// If token provided, load student
if ($token) {
    $st = $conn->prepare("SELECT id, first_name, security_question, reset_token_exp FROM students WHERE reset_token=? LIMIT 1");
    $st->bind_param('s', $token); $st->execute();
    $trow = $st->get_result()->fetch_assoc();
    if (!$trow) { $error = 'Invalid or expired link. Please start again.'; $token = ''; $step = 1; }
    elseif (strtotime($trow['reset_token_exp']) < time()) { $error = 'Link expired. Please start again.'; $token = ''; $step = 1; }
    else { $step = isset($_SESSION['reset_verified_'.$token]) ? 3 : 2; }
}

// STEP 1
if (isset($_POST['step1'])) {
    $sn = trim($_POST['student_num'] ?? '');
    $st = $conn->prepare("SELECT id, first_name, security_question, security_answer FROM students WHERE student_num=? LIMIT 1");
    $st->bind_param('s', $sn); $st->execute();
    $student = $st->get_result()->fetch_assoc();
    if (!$student) { $error = 'No account found with that student number.'; $step = 1; }
    elseif (empty($student['security_question']) || empty($student['security_answer'])) { $error = 'No security question set. Contact administrator.'; $step = 1; }
    else {
        $token = bin2hex(random_bytes(24));
        $exp   = date('Y-m-d H:i:s', time() + 1800);
        $upd   = $conn->prepare("UPDATE students SET reset_token=?, reset_token_exp=? WHERE id=?");
        $upd->bind_param('ssi', $token, $exp, $student['id']); $upd->execute();
        $trow  = ['id'=>$student['id'],'first_name'=>$student['first_name'],'security_question'=>$student['security_question']];
        $step  = 2;
    }
}

// STEP 2
if (isset($_POST['step2']) && $token && $trow) {
    $answer = strtolower(trim($_POST['security_answer'] ?? ''));
    $st = $conn->prepare("SELECT id, security_answer FROM students WHERE reset_token=? LIMIT 1");
    $st->bind_param('s', $token); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) { $error = 'Invalid token. Please start again.'; $token = ''; $step = 1; }
    elseif (password_verify($answer, $row['security_answer'])) { $_SESSION['reset_verified_'.$token] = true; $step = 3; }
    else { $error = 'Incorrect answer. Please try again.'; $step = 2; }
}

// STEP 3
if (isset($_POST['step3']) && $token) {
    if (empty($_SESSION['reset_verified_'.$token])) { $error = 'Session expired. Please start again.'; $token = ''; $step = 1; }
    else {
        $pw=$_POST['new_password']??''; $pw2=$_POST['confirm_password']??'';
        if (strlen($pw)<8) { $error='Password must be at least 8 characters.'; $step=3; }
        elseif ($pw!==$pw2) { $error='Passwords do not match.'; $step=3; }
        else {
            $hash=password_hash($pw,PASSWORD_DEFAULT);
            $upd=$conn->prepare("UPDATE students SET password=?,session_token=NULL,reset_token=NULL,reset_token_exp=NULL WHERE reset_token=?");
            $upd->bind_param('ss',$hash,$token); $upd->execute();
            unset($_SESSION['reset_verified_'.$token]);
            $token=''; $step=4;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Forgot Password — LSGS</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--blue:#1e6fff;--cyan:#00c2f3;--green:#12b76a;--red:#f04438}
body{font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:radial-gradient(ellipse at 60% 20%,#0d2a5e 0%,#071124 55%,#030b18 100%)}
.card{width:100%;max-width:440px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:40px 36px;backdrop-filter:blur(20px);animation:fadeIn .4s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.logo-wrap{width:72px;height:72px;margin:0 auto 18px;background:#fff;border-radius:14px;padding:8px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,0,0,.4)}
.logo-wrap img{width:100%;height:100%;object-fit:contain}
.card-title{font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:4px}
.card-sub{font-size:13px;color:rgba(255,255,255,.45);text-align:center;margin-bottom:24px}
.steps{display:flex;align-items:center;justify-content:center;margin-bottom:28px}
.step-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid rgba(255,255,255,.15);color:rgba(255,255,255,.3)}
.step-dot.active{background:var(--blue);border-color:var(--blue);color:#fff}
.step-dot.done{background:var(--green);border-color:var(--green);color:#fff}
.step-line{width:36px;height:2px;background:rgba(255,255,255,.1)}
.step-line.done{background:var(--green)}
.alert{padding:12px 14px;border-radius:9px;font-size:13px;font-weight:500;margin-bottom:16px;border:1px solid}
.alert-err{background:rgba(240,68,56,.15);color:#fca5a5;border-color:rgba(240,68,56,.3)}
.fg{margin-bottom:14px}
.fl{display:block;font-size:11px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.fi{width:100%;padding:11px 14px;border:1.5px solid rgba(255,255,255,.12);border-radius:9px;font-family:inherit;font-size:14px;color:#fff;background:rgba(255,255,255,.07);outline:none;transition:border-color .15s}
.fi::placeholder{color:rgba(255,255,255,.25)}
.fi:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(30,111,255,.2)}
.q-box{background:rgba(0,194,243,.08);border:1px solid rgba(0,194,243,.2);border-radius:10px;padding:14px 16px;margin-bottom:16px}
.q-label{font-size:10.5px;font-weight:700;color:var(--cyan);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px}
.q-text{font-size:14px;color:#fff;font-weight:500}
.btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue),#1257cc);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;transition:all .15s;box-shadow:0 4px 16px rgba(30,111,255,.4);margin-top:4px}
.btn:hover{transform:translateY(-1px)}
.btn:disabled{background:rgba(255,255,255,.1);box-shadow:none;cursor:not-allowed;color:rgba(255,255,255,.3)}
.btn-ghost{background:rgba(255,255,255,.06);box-shadow:none;margin-top:10px}
.btn-ghost:hover{background:rgba(255,255,255,.1);transform:none;box-shadow:none}
.success-icon{font-size:56px;text-align:center;margin-bottom:16px}
.success-title{font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:8px}
.success-sub{font-size:14px;color:rgba(255,255,255,.45);text-align:center;margin-bottom:24px;line-height:1.6}
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap"><img src="assets/ecot.jpg" alt="ECOT"/></div>
  <div class="card-title">Password Recovery</div>
  <div class="card-sub">Student Account · ECOT</div>
  <div class="steps">
    <div class="step-dot <?= $step>=1?($step>1?'done':'active'):'' ?>">1</div>
    <div class="step-line <?= $step>1?'done':'' ?>"></div>
    <div class="step-dot <?= $step>=2?($step>2?'done':'active'):'' ?>">2</div>
    <div class="step-line <?= $step>2?'done':'' ?>"></div>
    <div class="step-dot <?= $step>=3?($step>3?'done':'active'):'' ?>">3</div>
    <div class="step-line <?= $step>3?'done':'' ?>"></div>
    <div class="step-dot <?= $step>=4?'done':'' ?>">✓</div>
  </div>
  <?php if ($error): ?><div class="alert alert-err">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($step===1): ?>
  <form method="POST">
    <input type="hidden" name="step1" value="1"/>
    <div class="fg"><label class="fl">Student Number</label>
    <input class="fi" type="text" name="student_num" placeholder="e.g. 220001234" maxlength="9" inputmode="numeric" autocomplete="off" required/></div>
    <button class="btn" type="submit">Continue →</button>
    <a href="index.php"><button class="btn btn-ghost" type="button">← Back to Login</button></a>
  </form>

  <?php elseif ($step===2): ?>
  <form method="POST">
    <input type="hidden" name="step2" value="1"/>
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>
    <p style="color:rgba(255,255,255,.6);font-size:13.5px;margin-bottom:14px">Hello <strong style="color:#fff"><?= htmlspecialchars($trow['first_name']??'') ?></strong>, answer your security question.</p>
    <div class="q-box"><div class="q-label">Your Security Question</div><div class="q-text"><?= htmlspecialchars($trow['security_question']??'') ?></div></div>
    <div class="fg"><label class="fl">Your Answer</label>
    <input class="fi" type="text" name="security_answer" placeholder="Type your answer" autocomplete="off" required/></div>
    <button class="btn" type="submit">Verify Answer →</button>
    <a href="forgot_password.php"><button class="btn btn-ghost" type="button">← Start Over</button></a>
  </form>

  <?php elseif ($step===3): ?>
  <form method="POST">
    <input type="hidden" name="step3" value="1"/>
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>
    <p style="color:rgba(255,255,255,.6);font-size:13.5px;margin-bottom:16px">✓ Identity verified. Set your new password.</p>
    <div class="fg"><label class="fl">New Password</label>
    <input class="fi" type="password" id="np" name="new_password" placeholder="At least 8 characters" oninput="chk()" required/></div>
    <div class="fg"><label class="fl">Confirm Password</label>
    <input class="fi" type="password" id="np2" name="confirm_password" placeholder="Repeat password" oninput="chk()" required/>
    <div style="font-size:12px;margin-top:4px" id="ph"></div></div>
    <button class="btn" id="sb" type="submit" disabled>Set New Password</button>
  </form>

  <?php elseif ($step===4): ?>
  <div class="success-icon">🎉</div>
  <div class="success-title">Password Reset!</div>
  <div class="success-sub">Your password has been updated.<br/>You can now sign in.</div>
  <a href="index.php"><button class="btn" type="button">Go to Login →</button></a>
  <?php endif; ?>
</div>
<script>
function chk(){var p=document.getElementById('np').value,p2=document.getElementById('np2').value,h=document.getElementById('ph'),b=document.getElementById('sb');
if(!p){h.textContent='';b.disabled=true;return}
if(p.length<8){h.style.color='#fca5a5';h.textContent='Too short';b.disabled=true;return}
if(p2&&p!==p2){h.style.color='#fca5a5';h.textContent='Passwords do not match';b.disabled=true;return}
if(p===p2&&p2.length>=8){h.style.color='#6ee7b7';h.textContent='✓ Match';b.disabled=false;return}
h.textContent='';b.disabled=true;}
</script>
</body>
</html>
