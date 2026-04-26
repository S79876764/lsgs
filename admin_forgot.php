<?php
// ============================================================
//  LSGS  |  admin_forgot.php — Admin Password Reset
// ============================================================
session_start();
if (isset($_SESSION['user'])) { header('Location: admin_dashboard.php'); exit; }

require_once __DIR__.'/db.php';

// Ensure security columns exist (compatible with MySQL 8)
$existing = [];
$res = $conn->query("SHOW COLUMNS FROM admin");
while ($row = $res->fetch_assoc()) $existing[] = $row['Field'];
if (!in_array('session_token',    $existing)) $conn->query("ALTER TABLE admin ADD COLUMN session_token VARCHAR(64) DEFAULT NULL");
if (!in_array('security_question',$existing)) $conn->query("ALTER TABLE admin ADD COLUMN security_question VARCHAR(255) DEFAULT NULL");
if (!in_array('security_answer',  $existing)) $conn->query("ALTER TABLE admin ADD COLUMN security_answer VARCHAR(255) DEFAULT NULL");

$step  = 1;
$error = '';

// ── STEP 1: Verify email ───────────────────────────────────────
if (isset($_POST['step1'])) {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $st    = $conn->prepare("SELECT id, name, security_question, security_answer FROM admin WHERE LOWER(email)=? LIMIT 1");
    $st->bind_param('s',$email); $st->execute();
    $admin = $st->get_result()->fetch_assoc();
    if (!$admin) {
        $error = 'No admin account found with that email.'; $step = 1;
    } elseif (empty($admin['security_question']) || empty($admin['security_answer'])) {
        $error = 'No security question set for this account. Run setup_admin_security.php first.'; $step = 1;
    } else {
        $_SESSION['areset_id']       = $admin['id'];
        $_SESSION['areset_name']     = $admin['name'];
        $_SESSION['areset_question'] = $admin['security_question'];
        $_SESSION['areset_attempts'] = 0;
        $step = 2;
    }
}

// ── STEP 2: Verify answer ──────────────────────────────────────
if (isset($_POST['step2'])) {
    $answer = strtolower(trim($_POST['security_answer'] ?? ''));
    $rid    = (int)($_SESSION['areset_id'] ?? 0);
    if (!$rid) { $step = 1; $error = 'Session expired. Please start again.'; }
    else {
        $_SESSION['areset_attempts'] = ($_SESSION['areset_attempts'] ?? 0) + 1;
        if ($_SESSION['areset_attempts'] > 5) {
            session_unset(); $error = 'Too many attempts. Please start again.'; $step = 1;
        } else {
            $st = $conn->prepare("SELECT security_answer FROM admin WHERE id=? LIMIT 1");
            $st->bind_param('i',$rid); $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if ($row && password_verify($answer, $row['security_answer'])) {
                $_SESSION['areset_verified'] = true; $step = 3;
            } else {
                $remaining = 5 - $_SESSION['areset_attempts'];
                $error = 'Incorrect answer. '.($remaining > 0 ? "$remaining attempt(s) remaining." : '');
                $step  = 2;
            }
        }
    }
}

// ── STEP 3: New password ───────────────────────────────────────
if (isset($_POST['step3'])) {
    $rid = (int)($_SESSION['areset_id'] ?? 0);
    if (!$rid || empty($_SESSION['areset_verified'])) {
        $step = 1; $error = 'Session expired.';
    } else {
        $pw  = $_POST['new_password']     ?? '';
        $pw2 = $_POST['confirm_password'] ?? '';
        if (strlen($pw) < 8)  { $error = 'Password must be at least 8 characters.'; $step = 3; }
        elseif ($pw !== $pw2) { $error = 'Passwords do not match.'; $step = 3; }
        else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $upd  = $conn->prepare("UPDATE admin SET password=?, session_token=NULL WHERE id=?");
            $upd->bind_param('si',$hash,$rid); $upd->execute();
            unset($_SESSION['areset_id'], $_SESSION['areset_name'], $_SESSION['areset_question'],
                  $_SESSION['areset_attempts'], $_SESSION['areset_verified']);
            $step = 4;
        }
    }
}

if ($step === 1 && isset($_SESSION['areset_id']) && !isset($_POST['step1'])) {
    $step = empty($_SESSION['areset_verified']) ? 2 : 3;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Admin — Forgot Password</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--blue:#1e6fff;--cyan:#00c2f3;--green:#12b76a;--red:#f04438}
body{font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
  background:radial-gradient(ellipse at 60% 20%,#0d2a5e 0%,#071124 55%,#030b18 100%)}
.card{width:100%;max-width:420px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:40px 36px;backdrop-filter:blur(20px);animation:fadeIn .4s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.logo-wrap{width:72px;height:72px;margin:0 auto 16px;background:#fff;border-radius:14px;padding:8px;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 32px rgba(0,0,0,.4)}
.logo-wrap img{width:100%;height:100%;object-fit:contain}
.card-title{font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:4px}
.card-sub{font-size:13px;color:rgba(255,255,255,.4);text-align:center;margin-bottom:24px}
.admin-badge{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(0,194,243,.12);border:1px solid rgba(0,194,243,.2);color:var(--cyan);font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:.8px;width:fit-content;margin:0 auto 20px}
.steps{display:flex;align-items:center;justify-content:center;margin-bottom:26px}
.step-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid rgba(255,255,255,.15);color:rgba(255,255,255,.3)}
.step-dot.active{background:var(--blue);border-color:var(--blue);color:#fff}
.step-dot.done{background:var(--green);border-color:var(--green);color:#fff}
.step-line{width:32px;height:2px;background:rgba(255,255,255,.1)}
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
.success-icon{font-size:52px;text-align:center;margin-bottom:14px}
.success-title{font-size:22px;font-weight:800;color:#fff;text-align:center;margin-bottom:8px}
.success-sub{font-size:13.5px;color:rgba(255,255,255,.5);text-align:center;margin-bottom:24px;line-height:1.6}
</style>
</head>
<body>
<div class="card">
  <div class="logo-wrap"><img src="assets/ecot.jpg" alt="ECOT"/></div>
  <div class="card-title">Password Recovery</div>
  <div class="admin-badge">🛡 Administrator</div>

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
  <form method="POST">
    <input type="hidden" name="step1" value="1"/>
    <div class="fg">
      <label class="fl">Admin Email</label>
      <input class="fi" type="email" name="email" placeholder="admin@ecot.ac.sz" autocomplete="off" required/>
    </div>
    <button class="btn" type="submit">Continue →</button>
    <a href="admin_login.php"><button class="btn btn-ghost" type="button">← Back to Login</button></a>
  </form>

  <?php elseif ($step === 2): ?>
  <form method="POST">
    <input type="hidden" name="step2" value="1"/>
    <p style="color:rgba(255,255,255,.6);font-size:13.5px;margin-bottom:14px">
      Hello <strong style="color:#fff"><?= htmlspecialchars($_SESSION['areset_name'] ?? '') ?></strong>, answer your security question.
    </p>
    <div class="q-box">
      <div class="q-label">Security Question</div>
      <div class="q-text"><?= htmlspecialchars($_SESSION['areset_question'] ?? '') ?></div>
    </div>
    <div class="fg">
      <label class="fl">Your Answer</label>
      <input class="fi" type="text" name="security_answer" placeholder="Your answer" autocomplete="off" required/>
    </div>
    <button class="btn" type="submit">Verify →</button>
    <a href="admin_forgot.php"><button class="btn btn-ghost" type="button">← Start Over</button></a>
  </form>

  <?php elseif ($step === 3): ?>
  <form method="POST" id="reset-form">
    <input type="hidden" name="step3" value="1"/>
    <p style="color:rgba(255,255,255,.6);font-size:13.5px;margin-bottom:16px">✓ Identity verified. Set your new password.</p>
    <div class="fg">
      <label class="fl">New Password</label>
      <input class="fi" type="password" id="np" name="new_password" placeholder="At least 8 characters" oninput="chk()" required/>
    </div>
    <div class="fg">
      <label class="fl">Confirm Password</label>
      <input class="fi" type="password" id="np2" name="confirm_password" placeholder="Repeat password" oninput="chk()" required/>
      <div style="font-size:12px;margin-top:4px;color:rgba(255,255,255,.3)" id="ph"></div>
    </div>
    <button class="btn" id="sbtn" type="submit" disabled>Set New Password</button>
  </form>

  <?php elseif ($step === 4): ?>
  <div class="success-icon">🎉</div>
  <div class="success-title">Password Reset!</div>
  <div class="success-sub">Admin password updated successfully.<br/>You can now sign in with your new password.</div>
  <a href="admin_login.php"><button class="btn" type="button">Go to Login →</button></a>
  <?php endif; ?>
</div>
<script>
function chk(){
  var p=document.getElementById('np').value,p2=document.getElementById('np2').value,h=document.getElementById('ph'),b=document.getElementById('sbtn');
  if(!p){h.textContent='';b.disabled=true;return}
  if(p.length<8){h.style.color='#fca5a5';h.textContent='Too short (min 8)';b.disabled=true;return}
  if(p2&&p!==p2){h.style.color='#fca5a5';h.textContent='Passwords do not match';b.disabled=true;return}
  if(p===p2&&p2.length>=8){h.style.color='#6ee7b7';h.textContent='✓ Passwords match';b.disabled=false;return}
  h.textContent='';b.disabled=true;
}
</script>
</body>
</html>
