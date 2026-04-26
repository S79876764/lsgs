<?php
// ============================================================
//  LSGS  |  forgot_password.php — Student Password Reset
//  Flow: Enter student number → answer security question → new password
// ============================================================
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '1');
ini_set('session.use_strict_mode', '1');
session_start();
if (isset($_SESSION['user'])) { header('Location: dashboard.php'); exit; }

require_once __DIR__.'/db.php';

$step    = 1;   // 1=enter student number, 2=answer question, 3=new password, 4=success
$error   = '';
$success = '';
$student = null;

// ── STEP 1: Verify student number ─────────────────────────────
if (isset($_POST['step1'])) {
    $sn = trim($_POST['student_num'] ?? '');
    $st = $conn->prepare("SELECT id, first_name, security_question, security_answer FROM students WHERE student_num=? LIMIT 1");
    $st->bind_param('s',$sn); $st->execute();
    $student = $st->get_result()->fetch_assoc();
    if (!$student) {
        $error = 'No account found with that student number.';
        $step = 1;
    } elseif (empty($student['security_question']) || empty($student['security_answer'])) {
        $error = 'This account has no security question set. Please contact the administrator.';
        $step = 1;
    } else {
        // Store in session temporarily
        $_SESSION['reset_id']       = $student['id'];
        $_SESSION['reset_name']     = $student['first_name'];
        $_SESSION['reset_question'] = $student['security_question'];
        $_SESSION['reset_attempts'] = 0;
        $step = 2;
    }
}

// ── STEP 2: Verify security answer ────────────────────────────
if (isset($_POST['step2'])) {
    $answer = strtolower(trim($_POST['security_answer'] ?? ''));
    $rid    = (int)($_SESSION['reset_id'] ?? 0);
    // Try to recover session from hidden field if session was lost
    if (!$rid && !empty($_POST['reset_id'])) {
        $rid = (int)$_POST['reset_id'];
        $st2 = $conn->prepare("SELECT first_name, security_question FROM students WHERE id=? LIMIT 1");
        $st2->bind_param('i',$rid); $st2->execute();
        $row2 = $st2->get_result()->fetch_assoc();
        if ($row2) {
            $_SESSION['reset_id']       = $rid;
            $_SESSION['reset_name']     = $row2['first_name'];
            $_SESSION['reset_question'] = $row2['security_question'];
            $_SESSION['reset_attempts'] = 0;
        }
    }
    if (!$rid) { $step = 1; $error = 'Session expired. Please start again.'; }
    else {
        $_SESSION['reset_attempts'] = ($_SESSION['reset_attempts'] ?? 0) + 1;
        if ($_SESSION['reset_attempts'] > 5) {
            session_unset();
            $error = 'Too many incorrect attempts. Please start again.';
            $step  = 1;
        } else {
            $st = $conn->prepare("SELECT security_answer FROM students WHERE id=? LIMIT 1");
            $st->bind_param('i',$rid); $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if ($row && password_verify($answer, $row['security_answer'])) {
                $_SESSION['reset_verified'] = true;
                $step = 3;
            } else {
                $remaining = 5 - $_SESSION['reset_attempts'];
                $error = 'Incorrect answer. ' . ($remaining > 0 ? "$remaining attempt(s) remaining." : '');
                $step  = 2;
            }
        }
    }
}

// ── STEP 3: Set new password ───────────────────────────────────
if (isset($_POST['step3'])) {
    $rid = (int)($_SESSION['reset_id'] ?? 0);
    if (!$rid || empty($_SESSION['reset_verified'])) {
        $step = 1; $error = 'Session expired. Please start again.';
    } else {
        $pw  = $_POST['new_password']    ?? '';
        $pw2 = $_POST['confirm_password'] ?? '';
        if (strlen($pw) < 8)   { $error = 'Password must be at least 8 characters.'; $step = 3; }
        elseif ($pw !== $pw2)  { $error = 'Passwords do not match.'; $step = 3; }
        else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            // Clear token too so they can log in fresh
            $upd  = $conn->prepare("UPDATE students SET password=?, session_token=NULL WHERE id=?");
            $upd->bind_param('si',$hash,$rid); $upd->execute();
            // Clear reset session data
            unset($_SESSION['reset_id'], $_SESSION['reset_name'], $_SESSION['reset_question'],
                  $_SESSION['reset_attempts'], $_SESSION['reset_verified']);
            $step = 4;
        }
    }
}

// Restore step from session if returning
if ($step === 1 && isset($_SESSION['reset_id']) && !isset($_POST['step1'])) {
    $step = empty($_SESSION['reset_verified']) ? 2 : 3;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Forgot Password — LSGS</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--navy:#0a1628;--blue:#1e6fff;--cyan:#00c2f3;--green:#12b76a;--red:#f04438;--orange:#f79009;--border:rgba(255,255,255,.12);--muted:rgba(255,255,255,.45)}
body{font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
  background:radial-gradient(ellipse at 60% 20%,#0d2a5e 0%,#071124 55%,#030b18 100%)}
.card{width:100%;max-width:440px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:40px 36px;backdrop-filter:blur(20px);animation:fadeIn .4s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.logo-wrap{width:72px;height:72px;margin:0 auto 18px;background:#fff;border-radius:14px;padding:8px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,0,0,.4)}
.logo-wrap img{width:100%;height:100%;object-fit:contain}
.card-title{font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:4px}
.card-sub{font-size:13px;color:var(--muted);text-align:center;margin-bottom:24px}

/* Steps indicator */
.steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:28px}
.step-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid rgba(255,255,255,.15);color:rgba(255,255,255,.3);transition:all .2s}
.step-dot.active{background:var(--blue);border-color:var(--blue);color:#fff}
.step-dot.done{background:var(--green);border-color:var(--green);color:#fff}
.step-line{width:36px;height:2px;background:rgba(255,255,255,.1)}
.step-line.done{background:var(--green)}

/* Alert */
.alert{padding:12px 14px;border-radius:9px;font-size:13px;font-weight:500;margin-bottom:16px;border:1px solid}
.alert-err{background:rgba(240,68,56,.15);color:#fca5a5;border-color:rgba(240,68,56,.3)}
.alert-ok{background:rgba(18,183,106,.15);color:#6ee7b7;border-color:rgba(18,183,106,.3)}

/* Fields */
.fg{margin-bottom:14px}
.fl{display:block;font-size:11px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.fi{width:100%;padding:11px 14px;border:1.5px solid rgba(255,255,255,.12);border-radius:9px;font-family:inherit;font-size:14px;color:#fff;background:rgba(255,255,255,.07);outline:none;transition:border-color .15s}
.fi::placeholder{color:rgba(255,255,255,.25)}
.fi:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(30,111,255,.2)}

/* Question display box */
.q-box{background:rgba(0,194,243,.08);border:1px solid rgba(0,194,243,.2);border-radius:10px;padding:14px 16px;margin-bottom:16px}
.q-label{font-size:10.5px;font-weight:700;color:var(--cyan);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px}
.q-text{font-size:14px;color:#fff;font-weight:500}

/* Button */
.btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue),#1257cc);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;transition:all .15s;box-shadow:0 4px 16px rgba(30,111,255,.4);margin-top:4px}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(30,111,255,.5)}
.btn-ghost{background:rgba(255,255,255,.06);box-shadow:none;margin-top:10px}
.btn-ghost:hover{background:rgba(255,255,255,.1);transform:none;box-shadow:none}

/* Success */
.success-icon{font-size:56px;text-align:center;margin-bottom:16px}
.success-title{font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:8px}
.success-sub{font-size:14px;color:var(--muted);text-align:center;margin-bottom:24px;line-height:1.6}

@media(max-width:480px){.card{padding:28px 20px}}
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap"><img src="assets/ecot.jpg" alt="ECOT"/></div>
  <div class="card-title">Password Recovery</div>
  <div class="card-sub">Student Account · ECOT</div>

  <!-- Steps -->
  <div class="steps">
    <div class="step-dot <?= $step>=1?($step>1?'done':'active'):'' ?>">1</div>
    <div class="step-line <?= $step>1?'done':'' ?>"></div>
    <div class="step-dot <?= $step>=2?($step>2?'done':'active'):'' ?>">2</div>
    <div class="step-line <?= $step>2?'done':'' ?>"></div>
    <div class="step-dot <?= $step>=3?($step>3?'done':'active'):'' ?>">3</div>
    <div class="step-line <?= $step>3?'done':'' ?>"></div>
    <div class="step-dot <?= $step>=4?'done':'' ?>">✓</div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-err">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($step === 1): ?>
  <!-- ── STEP 1: Student Number ── -->
  <form method="POST">
    <input type="hidden" name="step1" value="1"/>
    <div class="fg">
      <label class="fl">Student Number</label>
      <input class="fi" type="text" name="student_num" placeholder="e.g. 220001234"
             maxlength="9" inputmode="numeric" autocomplete="off" required/>
    </div>
    <button class="btn" type="submit">Continue →</button>
    <a href="index.php"><button class="btn btn-ghost" type="button">← Back to Login</button></a>
  </form>

  <?php elseif ($step === 2): ?>
  <!-- ── STEP 2: Security Question ── -->
  <form method="POST">
    <input type="hidden" name="step2" value="1"/>
    <input type="hidden" name="reset_id" value="<?= (int)($_SESSION['reset_id'] ?? 0) ?>"/>
    <p style="color:rgba(255,255,255,.6);font-size:13.5px;margin-bottom:14px">
      Hello <strong style="color:#fff"><?= htmlspecialchars($_SESSION['reset_name'] ?? '') ?></strong>, answer your security question to continue.
    </p>
    <div class="q-box">
      <div class="q-label">Your Security Question</div>
      <div class="q-text"><?= htmlspecialchars($_SESSION['reset_question'] ?? '') ?></div>
    </div>
    <div class="fg">
      <label class="fl">Your Answer</label>
      <input class="fi" type="text" name="security_answer"
             placeholder="Type your answer" autocomplete="off" required/>
    </div>
    <button class="btn" type="submit">Verify Answer →</button>
    <a href="forgot_password.php"><button class="btn btn-ghost" type="button">← Start Over</button></a>
  </form>

  <?php elseif ($step === 3): ?>
  <!-- ── STEP 3: New Password ── -->
  <form method="POST" id="reset-form">
    <input type="hidden" name="step3" value="1"/>
    <p style="color:rgba(255,255,255,.6);font-size:13.5px;margin-bottom:16px">
      ✓ Identity verified. Set your new password below.
    </p>
    <div class="fg">
      <label class="fl">New Password</label>
      <input class="fi" type="password" id="np" name="new_password"
             placeholder="At least 8 characters" autocomplete="new-password"
             oninput="checkPw()" required/>
    </div>
    <div class="fg">
      <label class="fl">Confirm New Password</label>
      <input class="fi" type="password" id="np2" name="confirm_password"
             placeholder="Repeat new password" autocomplete="new-password"
             oninput="checkPw()" required/>
      <div style="font-size:12px;margin-top:4px;color:rgba(255,255,255,.3)" id="pw-hint"></div>
    </div>
    <button class="btn" id="reset-btn" type="submit" disabled>Set New Password</button>
  </form>

  <?php elseif ($step === 4): ?>
  <!-- ── STEP 4: Success ── -->
  <div class="success-icon">🎉</div>
  <div class="success-title">Password Reset!</div>
  <div class="success-sub">Your password has been updated successfully.<br/>You can now sign in with your new password.</div>
  <a href="index.php"><button class="btn" type="button">Go to Login →</button></a>

  <?php endif; ?>
</div>

<script>
function checkPw() {
  var p1 = document.getElementById('np').value;
  var p2 = document.getElementById('np2').value;
  var hint = document.getElementById('pw-hint');
  var btn  = document.getElementById('reset-btn');
  if (!p1) { hint.textContent=''; btn.disabled=true; return; }
  if (p1.length < 8) { hint.style.color='#fca5a5'; hint.textContent='Password too short (min 8 characters)'; btn.disabled=true; return; }
  if (p2 && p1 !== p2) { hint.style.color='#fca5a5'; hint.textContent='Passwords do not match'; btn.disabled=true; return; }
  if (p1 === p2 && p2.length >= 8) { hint.style.color='#6ee7b7'; hint.textContent='✓ Passwords match'; btn.disabled=false; return; }
  hint.textContent=''; btn.disabled=true;
}
</script>
</body>
</html>
