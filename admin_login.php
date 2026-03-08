<?php
// ============================================================
//  LSGS  |  admin_login.php — Admin Login
//  Login: email + password
//  Eswatini College of Technology
// ============================================================
session_start();
if (isset($_SESSION['user'])) {
    header('Location: ' . ($_SESSION['user']['role'] === 'admin' ? 'admin_dashboard.php' : 'dashboard.php'));
    exit;
}

require_once __DIR__.'/db.php';

// ── Single-device login: add session_token column if not exists ──
$migrations_admin = [
    "ALTER TABLE admin ADD COLUMN session_token VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE admin ADD COLUMN last_active DATETIME DEFAULT NULL",
    "ALTER TABLE admin ADD COLUMN security_question VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE admin ADD COLUMN security_answer VARCHAR(255) DEFAULT NULL",
];
foreach ($migrations_admin as $sql) { try { $conn->query($sql); } catch (Exception $e) {} }

$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $email=trim(strtolower($_POST['email']??''));
    $pw   =$_POST['password']??'';
    $st   =$conn->prepare("SELECT * FROM admin WHERE LOWER(email)=? LIMIT 1");
    $st->bind_param('s',$email);$st->execute();
    $admin=$st->get_result()->fetch_assoc();
    if($admin&&password_verify($pw,$admin['password'])){
        // Block login if another session is already active
        if (!empty($admin['session_token'])) {
            $error = 'This admin account is already logged in on another device. Please wait until that session is logged out.';
        } else {
            $token = bin2hex(random_bytes(32));
            $upd = $conn->prepare("UPDATE admin SET session_token=? WHERE id=?");
            $upd->bind_param('si',$token,$admin['id']); $upd->execute();
            $_SESSION['user']=['id'=>$admin['id'],'first_name'=>'Admin','last_name'=>'','name'=>$admin['name'],'email'=>$admin['email'],'role'=>'admin','year'=>'','session_token'=>$token];
            header('Location: admin_dashboard.php');exit;
        }
    } elseif (!$error) {
        $error='Incorrect email or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0"/>
<meta name="theme-color" content="#0a1628"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<title>Admin Login </title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--navy:#0a1628;--blue:#1e6fff;--cyan:#00c2f3;--border:#e2e8f0;--text:#1a2540;--muted:#6b7a99;--surface:#f4f6fb;--green:#12b76a;--red:#f04438;--orange:#f79009}
html,body{min-height:100vh;font-family:'DM Sans',sans-serif;-webkit-font-smoothing:antialiased}
body{
  background:radial-gradient(ellipse at 60% 20%,#0d2a5e 0%,#071124 55%,#030b18 100%);
  display:flex;align-items:center;justify-content:center;
  padding:24px;position:relative;overflow:hidden;min-height:100vh;
}
body::before{content:'';position:absolute;width:700px;height:700px;border-radius:50%;background:radial-gradient(circle,rgba(0,194,243,.06) 0%,transparent 65%);top:-200px;right:-200px;pointer-events:none}
body::after {content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(30,111,255,.07) 0%,transparent 65%);bottom:-150px;left:-150px;pointer-events:none}

.card{
  position:relative;z-index:1;
  width:100%;max-width:420px;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.1);
  border-radius:20px;
  padding:clamp(28px,5vw,44px) clamp(24px,5vw,40px);
  backdrop-filter:blur(20px);
  -webkit-backdrop-filter:blur(20px);
  animation:fadeIn .5s ease;
}
@keyframes fadeIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* Logo */
.logo-wrap{
  width:clamp(72px,12vw,96px);height:clamp(72px,12vw,96px);
  margin:0 auto 20px;background:#fff;
  border-radius:clamp(14px,3vw,18px);padding:8px;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 8px 32px rgba(0,0,0,.4),0 0 0 1px rgba(255,255,255,.08);
}
.logo-wrap img{width:100%;height:100%;object-fit:contain}

.card-title{font-size:clamp(20px,4vw,26px);font-weight:800;color:#fff;text-align:center;margin-bottom:6px}
.card-sub  {font-size:13px;color:rgba(255,255,255,.4);text-align:center;margin-bottom:clamp(22px,4vw,30px);font-family:'DM Mono',monospace;letter-spacing:.5px}

/* Admin badge */
.admin-badge{
  display:inline-flex;align-items:center;gap:6px;
  background:rgba(0,194,243,.15);border:1px solid rgba(0,194,243,.25);
  color:var(--cyan);font-size:11.5px;font-weight:700;
  padding:4px 12px;border-radius:20px;
  margin:0 auto 22px;display:flex;justify-content:center;width:fit-content;
  margin-left:auto;margin-right:auto;
  text-transform:uppercase;letter-spacing:.8px;
}

/* Alert */
.alert{padding:12px 16px;border-radius:9px;font-size:13.5px;font-weight:500;margin-bottom:16px;border:1px solid;display:flex;align-items:flex-start;gap:8px;line-height:1.5}
.alert-err{background:rgba(240,68,56,.15);color:#fca5a5;border-color:rgba(240,68,56,.3)}

/* Fields */
.fg{margin-bottom:14px}
.fl{display:block;font-size:11px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.fi-wrap{position:relative}
.fi{
  width:100%;padding:11px 40px 11px 14px;
  border:1.5px solid rgba(255,255,255,.12);border-radius:9px;
  font-family:inherit;font-size:14px;color:#fff;
  background:rgba(255,255,255,.07);outline:none;
  transition:border-color .15s,box-shadow .15s;
  -webkit-appearance:none;appearance:none;
}
.fi::placeholder{color:rgba(255,255,255,.25)}
.fi:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(30,111,255,.2)}
.fi.valid  {border-color:var(--green)}
.fi.invalid{border-color:var(--red)}
.fi:disabled{background:rgba(255,255,255,.03);color:rgba(255,255,255,.25);cursor:not-allowed;border-color:rgba(255,255,255,.06)}
.fi-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:15px;pointer-events:none}
.fhint{font-size:11.5px;margin-top:4px;display:none;align-items:center;gap:4px}
.fhint.err {color:#fca5a5;display:flex}
.fhint.ok  {color:#6ee7b7;display:flex}
.fhint.warn{color:#fcd34d;display:flex}
.fhint.info{color:rgba(255,255,255,.4);display:flex}
.flock{font-size:11.5px;color:rgba(255,255,255,.3);display:flex;align-items:center;gap:5px;margin-top:4px}

/* Button */
.btn-sub{
  width:100%;padding:clamp(12px,3vw,14px);
  background:linear-gradient(135deg,var(--blue),#1257cc);
  color:#fff;border:none;border-radius:10px;
  font-family:inherit;font-size:clamp(14px,3vw,15px);font-weight:700;
  cursor:pointer;transition:all .15s;margin-bottom:16px;
  box-shadow:0 4px 16px rgba(30,111,255,.4);
  -webkit-tap-highlight-color:transparent;
}
.btn-sub:hover{transform:translateY(-1px);box-shadow:0 6px 22px rgba(30,111,255,.5)}
.btn-sub:active{transform:scale(.99)}
.btn-sub:disabled{background:rgba(255,255,255,.1);box-shadow:none;cursor:not-allowed;color:rgba(255,255,255,.4)}

/* Back link */
.back-row{text-align:center;margin-top:4px}
.back-lnk{color:rgba(255,255,255,.35);font-size:12.5px;text-decoration:none;transition:color .15s;-webkit-tap-highlight-color:transparent}
.back-lnk:hover{color:rgba(255,255,255,.7)}

/* Security notice */
.security-notice{
  margin-top:20px;padding:10px 14px;
  border-radius:8px;background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);
  font-size:11.5px;color:rgba(255,255,255,.3);
  text-align:center;line-height:1.5;
  font-family:'DM Mono',monospace;
}

@media(max-width:420px){
  .card{border-radius:16px;padding:24px 18px}
}
</style>
</head>
<body>

<div class="card">
  <div class="logo-wrap"><img src="assets/ecot.jpg" alt="ECOT"/></div>
  <div class="card-title">Admin Access</div>
  <div class="card-sub">Eswatini College of Technology</div>
  <div class="admin-badge">🛡 Administrator Portal</div>

  <?php if($error):?>
    <div class="alert alert-err">⚠ <?=htmlspecialchars($error)?></div>
  <?php endif;?>

  <form method="POST" id="admin-form" novalidate>

    <!-- Email -->
    <div class="fg">
      <label class="fl" for="ad-em">Admin Email</label>
      <div class="fi-wrap">
        <input class="fi" type="email" id="ad-em" name="email"
               placeholder=" "
               autocomplete="username"
               oninput="adEmInput()"
               value="<?=htmlspecialchars($_POST['email']??'')?>"/>
        <span class="fi-icon" id="ad-em-ico"></span>
      </div>
      <div class="fhint info" id="ad-em-hint" style="display:flex">Enter your admin email address</div>
    </div>

    <!-- Password — locked until email valid -->
    <div class="fg">
      <label class="fl" for="ad-pw">Password</label>
      <div class="fi-wrap">
        <input class="fi" type="password" id="ad-pw" name="password"
               placeholder=" " disabled
               autocomplete="current-password"
               oninput="adPwInput()"/>
        <span class="fi-icon" id="ad-pw-ico"></span>
      </div>
      <div class="flock" id="ad-pw-lock">🔒 Enter your email address first</div>
      <div class="fhint" id="ad-pw-hint"></div>
    </div>

    <button class="btn-sub" id="ad-btn" type="submit" disabled>Sign In </button>
  </form>

  <div class="back-row">
    <a class="back-lnk" href="welcome.php">← Back </a>
    &nbsp;·&nbsp;
    <a class="back-lnk" href="admin_forgot.php">Forgot password?</a>
  </div>

  <div class="security-notice">
    🔒 This area is restricted to authorised administrators only.
  </div>
</div>

<script>
function vEM(e){if(!e)return{ok:false};const ok=/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(e);return{ok}}
function hint(id,msg,type){const e=document.getElementById(id);if(!e)return;e.className='fhint'+(msg?' '+type:'');e.textContent=msg;e.style.display=msg?'flex':'none'}
function ico(id,v){const e=document.getElementById(id);if(e)e.textContent=v||''}
function markV(fid,iid,ok){const e=document.getElementById(fid);if(!e||!e.value)return;e.classList.toggle('valid',ok);e.classList.toggle('invalid',!ok);ico(iid,ok?'✅':'❌')}

function adEmInput(){
  const em=document.getElementById('ad-em').value.trim();
  const pw=document.getElementById('ad-pw');
  const lock=document.getElementById('ad-pw-lock');
  const btn=document.getElementById('ad-btn');
  if(!em){document.getElementById('ad-em').classList.remove('valid','invalid');ico('ad-em-ico','');hint('ad-em-hint','Enter your admin email address','info');pw.disabled=true;lock.style.display='flex';btn.disabled=true;return}
  const ok=/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(em);
  if(ok){markV('ad-em','ad-em-ico',true);hint('ad-em-hint','Email entered is correct ✓','ok');pw.disabled=false;lock.style.display='none';hint('ad-pw-hint','','');adPwInput()}
  else  {markV('ad-em','ad-em-ico',false);hint('ad-em-hint','Enter a valid email address','err');pw.disabled=true;lock.style.display='flex';pw.value='';btn.disabled=true}
}
function adPwInput(){
  const pw=document.getElementById('ad-pw').value;
  const em=document.getElementById('ad-em').value.trim();
  const emOk=/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(em);
  if(!emOk){document.getElementById('ad-btn').disabled=true;return}
  if(!pw){hint('ad-pw-hint','','');document.getElementById('ad-btn').disabled=true;return}
  if(pw.length<6){hint('ad-pw-hint','Password is too short','warn');document.getElementById('ad-btn').disabled=true}
  else           {hint('ad-pw-hint','','');document.getElementById('ad-btn').disabled=false}
}
window.addEventListener('DOMContentLoaded',()=>{
  if(document.getElementById('ad-em').value)adEmInput();
});
</script>
</body>
</html>
