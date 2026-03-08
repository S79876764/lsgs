<?php
// ============================================================
//  LSGS  |  welcome.php
//  First Screen — Role Selector
//  Eswatini College of Technology
// ============================================================
session_start();
if (isset($_SESSION['user'])) {
    header('Location: ' . ($_SESSION['user']['role'] === 'admin' ? 'admin_dashboard.php' : 'dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0"/>
<meta name="theme-color" content="#0a1628"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<title> Eswatini College of Technology</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--navy:#0a1628;--blue:#1e6fff;--cyan:#00c2f3;--border:rgba(255,255,255,.12);--muted:rgba(255,255,255,.45)}
html{min-height:100vh;font-family:'DM Sans',sans-serif;-webkit-font-smoothing:antialiased;
  background:radial-gradient(ellipse at 70% 20%,#0d2a5e 0%,#071124 55%,#030b18 100%)}
body{min-height:100vh;font-family:'DM Sans',sans-serif;-webkit-font-smoothing:antialiased}

/* ── FULL PAGE BACKGROUND ──────────────────────────── */
body{
  background:transparent;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  min-height:100vh;padding:clamp(20px,4vw,40px) 20px;position:relative;overflow-x:hidden;overflow-y:auto;
}

/* Decorative glows */
body::before{
  content:'';position:absolute;
  width:700px;height:700px;border-radius:50%;
  background:radial-gradient(circle,rgba(0,194,243,.06) 0%,transparent 65%);
  top:-200px;right:-200px;pointer-events:none;
}
body::after{
  content:'';position:absolute;
  width:500px;height:500px;border-radius:50%;
  background:radial-gradient(circle,rgba(30,111,255,.07) 0%,transparent 65%);
  bottom:-150px;left:-150px;pointer-events:none;
}

/* Floating dots background */
.dots{position:absolute;inset:0;overflow:hidden;pointer-events:none;z-index:0}
.dot{
  position:absolute;border-radius:50%;
  background:rgba(255,255,255,.04);
  animation:floatDot linear infinite;
}

/* ── CARD ──────────────────────────────────────────── */
.card{
  position:relative;z-index:1;
  width:100%;max-width:500px;
  text-align:center;
  animation:fadeIn .7s ease;
}
@keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

/* Logo */
.logo-ring{
  width:clamp(90px,14vw,130px);
  height:clamp(90px,14vw,130px);
  margin:0 auto 18px;
  background:#fff;
  border-radius:clamp(20px,4vw,28px);
  padding:12px;
  display:flex;align-items:center;justify-content:center;
  box-shadow:
    0 0 0 1px rgba(255,255,255,.1),
    0 20px 60px rgba(0,0,0,.5),
    0 0 80px rgba(0,194,243,.12);
  animation:pulse 3s ease-in-out infinite;
}
@keyframes pulse{
  0%,100%{box-shadow:0 0 0 1px rgba(255,255,255,.1),0 20px 60px rgba(0,0,0,.5),0 0 80px rgba(0,194,243,.12)}
  50%    {box-shadow:0 0 0 1px rgba(255,255,255,.15),0 20px 60px rgba(0,0,0,.5),0 0 120px rgba(0,194,243,.2)}
}
.logo-ring img{width:100%;height:100%;object-fit:contain}

/* College name */
.college-name{
  font-size:clamp(11px,2.5vw,14px);
  font-weight:800;color:#fff;
  text-transform:uppercase;letter-spacing:2px;
  margin-bottom:5px;
}
.college-sub{
  font-size:clamp(9px,2vw,11px);
  color:var(--muted);
  font-family:'DM Mono',monospace;
  letter-spacing:3px;text-transform:uppercase;
  margin-bottom:clamp(14px,3vw,22px);
}

/* System title */
.system-title{
  font-size:clamp(28px,7vw,48px);
  font-weight:800;line-height:1.1;
  color:#fff;letter-spacing:-1px;
  margin-bottom:10px;
}
.system-title span{
  background:linear-gradient(90deg,var(--cyan) 0%,#1e6fff 100%);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}

/* Wise message */
.wise-msg{
  font-size:clamp(12px,2.5vw,15px);
  color:var(--muted);
  line-height:1.65;
  max-width:420px;
  margin:0 auto clamp(20px,4vw,32px);
  font-style:italic;
  font-weight:300;
}
.wise-msg strong{color:rgba(255,255,255,.75);font-style:normal;font-weight:600}

/* Divider */
.divider{
  display:flex;align-items:center;gap:14px;
  margin-bottom:clamp(12px,2.5vw,18px);
  font-size:11.5px;color:rgba(255,255,255,.3);
  text-transform:uppercase;letter-spacing:1.2px;
}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.1)}

/* Role options */
.role-options{
  display:flex;gap:14px;margin-bottom:clamp(14px,3vw,20px);
  flex-wrap:wrap;
}
.role-card{
  flex:1;min-width:140px;
  padding:clamp(16px,3vw,22px) 16px;
  border:2px solid rgba(255,255,255,.1);
  border-radius:16px;
  background:rgba(255,255,255,.04);
  cursor:pointer;
  transition:all .22s;
  position:relative;
  -webkit-tap-highlight-color:transparent;
  user-select:none;
}
.role-card:hover{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.2);
  transform:translateY(-2px);
}
.role-card.selected{
  background:rgba(30,111,255,.18);
  border-color:var(--blue);
  box-shadow:0 0 0 4px rgba(30,111,255,.15), 0 8px 28px rgba(30,111,255,.2);
}
.role-card.selected-admin{
  background:rgba(0,194,243,.12);
  border-color:var(--cyan);
  box-shadow:0 0 0 4px rgba(0,194,243,.12), 0 8px 28px rgba(0,194,243,.15);
}
/* Hidden radio */
.role-card input[type=radio]{
  position:absolute;opacity:0;width:0;height:0;
}
.role-icon{font-size:clamp(28px,6vw,36px);margin-bottom:10px;display:block}
.role-title{font-size:clamp(14px,3vw,16px);font-weight:700;color:#fff;margin-bottom:5px}
.role-desc{font-size:clamp(11px,2.5vw,12.5px);color:var(--muted);line-height:1.5}
.check-ring{
  width:22px;height:22px;border-radius:50%;
  border:2px solid rgba(255,255,255,.2);
  margin:12px auto 0;
  display:flex;align-items:center;justify-content:center;
  transition:all .2s;font-size:12px;
}
.role-card.selected     .check-ring{background:var(--blue);border-color:var(--blue);color:#fff}
.role-card.selected-admin .check-ring{background:var(--cyan);border-color:var(--cyan);color:#fff}

/* Tap hint */
.tap-hint{
  font-size:clamp(11px,2.5vw,13px);
  color:rgba(255,255,255,.3);
  text-align:center;
  margin-bottom:clamp(8px,2vw,12px);
  font-family:'DM Mono',monospace;
  letter-spacing:.5px;
  animation:blink 2s ease-in-out infinite;
}
@keyframes blink{0%,100%{opacity:.3}50%{opacity:.7}}

/* Footer */
.footer{
  position:relative;z-index:1;
  margin-top:clamp(16px,3vw,28px);
  font-size:11.5px;color:rgba(255,255,255,.2);
  font-family:'DM Mono',monospace;letter-spacing:.5px;
}

/* ── RESPONSIVE ──────────────────────────────────── */
@media(max-width:480px){
  .role-options{gap:10px}
  .role-card{min-width:120px;padding:16px 12px}
}
@media(max-width:360px){
  .role-options{flex-direction:column}
  .role-card{min-width:unset}
}

/* Floating dot animation */
@keyframes floatDot{
  0%  {transform:translateY(100vh) scale(0);opacity:0}
  10% {opacity:1}
  90% {opacity:.5}
  100%{transform:translateY(-100px) scale(1);opacity:0}
}
</style>
</head>
<body>

<!-- floating background dots -->
<div class="dots" id="dots"></div>

<div class="card">

  <!-- Logo -->
  <div class="logo-ring">
    <img src="assets/ecot.jpg" alt="Eswatini College of Technology"/>
  </div>

  <p class="college-name">Eswatini College of Technology</p>
  <p class="college-sub">Looking to the Future</p>

  <!-- System title -->
  <h1 class="system-title">
    <span>ECOT Study Group </span>
  </h1>

  <!-- Wise message -->
  <p class="wise-msg">
    <strong>"Alone we can do so little, together we can do so much."</strong><br/>
    The Learning &amp; Study Group System connects ECOT students for smarter, collaborative learning.
  </p>

  <!-- Role selector -->
  <div class="divider">I am a</div>

  <p class="tap-hint">Tap your role to continue</p>

  <div class="role-options" id="role-options">

    <label class="role-card" id="card-student" onclick="selectRole('student')">
      <input type="radio" name="role" value="student" id="r-student"/>
      <span class="role-icon">🎓</span>
      <div class="role-title">Student</div>
      <div class="role-desc">Access study groups, sessions &amp; resources</div>
      <div class="check-ring" id="ck-student"></div>
    </label>

    <label class="role-card" id="card-admin" onclick="selectRole('admin')">
      <input type="radio" name="role" value="admin" id="r-admin"/>
      <span class="role-icon">🛡</span>
      <div class="role-title">Administrator</div>
      <div class="role-desc">Manage students, groups &amp; system settings</div>
      <div class="check-ring" id="ck-admin"></div>
    </label>

  </div>


</div>

<div class="footer"> &copy; <?=date('Y')?> &nbsp;·&nbsp; Eswatini College of Technology</div>

<script>
function selectRole(role) {
  // Highlight selected card briefly then redirect
  document.getElementById('card-student').classList.remove('selected','selected-admin');
  document.getElementById('card-admin').classList.remove('selected','selected-admin');
  document.getElementById('ck-student').textContent = '';
  document.getElementById('ck-admin').textContent   = '';

  if (role === 'student') {
    document.getElementById('card-student').classList.add('selected');
    document.getElementById('r-student').checked = true;
    document.getElementById('ck-student').textContent = '✓';
  } else {
    document.getElementById('card-admin').classList.add('selected-admin');
    document.getElementById('r-admin').checked = true;
    document.getElementById('ck-admin').textContent = '✓';
  }

  // Short delay so user sees the selection highlight, then redirect
  setTimeout(() => {
    window.location.href = role === 'student' ? 'login.php' : 'admin_login.php';
  }, 280);
}

// Generate floating dots
const dotsWrap = document.getElementById('dots');
for (let i = 0; i < 18; i++) {
  const d = document.createElement('div');
  d.className = 'dot';
  const size = Math.random() * 6 + 3;
  d.style.cssText = `
    width:${size}px;height:${size}px;
    left:${Math.random()*100}%;
    animation-duration:${Math.random()*18+12}s;
    animation-delay:${Math.random()*10}s;
  `;
  dotsWrap.appendChild(d);
}
</script>
</body>
</html>
