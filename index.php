<?php
// ============================================================
//  LSGS  |  index.php — Student Login + Register
//  Login: student number + password
//  Eswatini College of Technology
// ============================================================
session_start();
if (isset($_SESSION['user'])) {
    header('Location: ' . ($_SESSION['user']['role'] === 'admin' ? 'admin_dashboard.php' : 'dashboard.php'));
    exit;
}

require_once __DIR__.'/db.php';

// ── Migrations ───────────────────────────────────────────────
$conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS session_token VARCHAR(64) DEFAULT NULL");
$conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS last_active DATETIME DEFAULT NULL");
$conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS security_question VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS security_answer VARCHAR(255) DEFAULT NULL");

$error='';$tab=$_GET['tab']??'login';
if (isset($_GET['kicked'])) $error = 'You were logged out because your account was signed in on another device.';
$valid_prefixes=['20','21','22','23','24','25','26'];

function validate_sn($num,$vp){
    if(strlen($num)!==9)     return 'Student number must be exactly 9 digits.';
    if(!ctype_digit($num))   return 'Student number must contain digits only.';
    $p=substr($num,0,2);
    if(!in_array($p,$vp))    return 'Student number starting with "'.$p.'" is not accepted. Only students enrolled from 2020 onwards are allowed.';
    return '';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    // LOGIN
    if($_POST['action']==='login'){
        $tab='login';
        $sn=trim($_POST['student_num']??'');
        $pw=$_POST['password']??'';
        $st=$conn->prepare("SELECT * FROM students WHERE student_num=? LIMIT 1");
        $st->bind_param('s',$sn);$st->execute();
        $student=$st->get_result()->fetch_assoc();
        if($student&&password_verify($pw,$student['password'])){
            // Block login if another session is already active for this account
            // Auto-expiry: if token exists but last_active was more than 5 min ago, clear it
            if (!empty($student['session_token'])) {
                $last = $student['last_active'] ?? null;
                $inactive = !$last || (time() - strtotime($last)) > 300; // 5 minutes
                if (!$inactive) {
                    // Active session — kick it and take over (last device wins)
                    // The old session will get a "logged out" notice on next page load via session_check.php
                }
                // Whether expired or we're kicking — clear old token and log in fresh
            }
            // Allow login — set new token
            $token = bin2hex(random_bytes(32));
            $upd = $conn->prepare("UPDATE students SET session_token=?, last_active=NOW() WHERE id=?");
            $upd->bind_param('si',$token,$student['id']); $upd->execute();
            $_SESSION['user']=['id'=>$student['id'],'first_name'=>$student['first_name'],'last_name'=>$student['last_name'],'email'=>$student['email'],'role'=>'student','year'=>$student['year'],'programme'=>$student['programme'],'session_token'=>$token];
            header('Location: dashboard.php');exit;
        } elseif (!$student || !password_verify($pw, $student['password'] ?? '')) {
            if (!$error) $error='Incorrect student number or password. Please try again.';
        }
    }
    // REGISTER
    if($_POST['action']==='register'){
        $tab='register';
        $first=trim($_POST['first_name']??'');
        $last =trim($_POST['last_name'] ??'');
        $sn   =trim($_POST['student_num']??'');
        $email=trim(strtolower($_POST['email']??''));
        $prog =trim($_POST['programme']??'');
        $year =trim($_POST['year']??'');
        $pw   =$_POST['password'] ??'';
        $pw2  =$_POST['password2']??'';
        $snerr=validate_sn($sn,$valid_prefixes);
        if(!$first||!$last)                          $error='Please enter your first and last name.';
        elseif($snerr)                               $error=$snerr;
        elseif(!$email||!filter_var($email,FILTER_VALIDATE_EMAIL)) $error='Please enter a valid email address.';
        elseif(!$prog)                               $error='Please select your programme.';
        elseif(!$year)                               $error='Please select your year of study.';
        elseif(strlen($pw)<8)                        $error='Password must be at least 8 characters.';
        elseif($pw!==$pw2)                           $error='Passwords do not match.';
        elseif(empty(trim($_POST['security_question']??''))) $error='Please select a security question.';
        elseif(strlen(trim($_POST['security_answer']??''))<2) $error='Please provide a security answer.';
        else{
            $c1=$conn->prepare("SELECT id FROM students WHERE student_num=? LIMIT 1");
            $c1->bind_param('s',$sn);$c1->execute();
            if($c1->get_result()->fetch_assoc()){$error='This student number is already registered.';}
            else{
                $c2=$conn->prepare("SELECT id FROM students WHERE LOWER(email)=? LIMIT 1");
                $c2->bind_param('s',$email);$c2->execute();
                if($c2->get_result()->fetch_assoc()){$error='This email is already registered.';}
                else{
                    $hash   = password_hash($pw, PASSWORD_DEFAULT);
                    $sq     = trim($_POST['security_question'] ?? '');
                    $sa     = strtolower(trim($_POST['security_answer'] ?? ''));
                    $sa_hash= password_hash($sa, PASSWORD_DEFAULT);
                    $ins=$conn->prepare("INSERT INTO students (first_name,last_name,student_num,email,programme,year,password,security_question,security_answer) VALUES (?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param('sssssssss',$first,$last,$sn,$email,$prog,$year,$hash,$sq,$sa_hash);
                    if($ins->execute()){
                        $new_id = $conn->insert_id;
                        $token  = bin2hex(random_bytes(32));
                        $utok   = $conn->prepare("UPDATE students SET session_token=? WHERE id=?");
                        $utok->bind_param('si',$token,$new_id); $utok->execute();
                        $_SESSION['user']=['id'=>$new_id,'first_name'=>$first,'last_name'=>$last,'email'=>$email,'role'=>'student','year'=>$year,'programme'=>$prog,'session_token'=>$token];
                        header('Location: dashboard.php');exit;
                    }else{$error='Registration failed. Please try again.';}
                }
            }
        }
    }
}

$programmes=[];
$r=$conn->query("SELECT name FROM programmes ORDER BY name");
while($row=$r->fetch_assoc())$programmes[]=$row['name'];
$stat_groups  =$conn->query("SELECT COUNT(*) c FROM study_groups")->fetch_assoc()['c'];
$stat_students=$conn->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
$stat_sessions=$conn->query("SELECT COUNT(*) c FROM sessions")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0"/>
<meta name="theme-color" content="#0f1f3d"/>
<meta name="apple-mobile-web-app-capable" content="yes"/>
<title>Student Login — LSGS</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--navy:#0f1f3d;--blue:#1e6fff;--cyan:#00c2f3;--surface:#f4f6fb;--border:#e2e8f0;--text:#1a2540;--muted:#6b7a99;--green:#12b76a;--red:#f04438;--orange:#f79009}
body{font-family:'DM Sans',sans-serif;min-height:100vh;color:var(--text);background:var(--surface);-webkit-font-smoothing:antialiased;display:flex}

/* SPLASH */
.splash{width:46%;background:linear-gradient(160deg,#05101f 0%,#0a1c3a 40%,#0e2d60 100%);display:flex;align-items:center;justify-content:center;padding:clamp(36px,5vw,70px) clamp(24px,4vw,56px);position:relative;overflow:hidden;flex-shrink:0}
.splash::before{content:'';position:absolute;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(0,194,243,.07) 0%,transparent 65%);top:-200px;right:-150px;pointer-events:none}
.splash::after{content:'';position:absolute;width:450px;height:450px;border-radius:50%;background:radial-gradient(circle,rgba(30,111,255,.08) 0%,transparent 65%);bottom:-100px;left:-100px;pointer-events:none}
.splash-inner{position:relative;z-index:1;max-width:380px;width:100%;text-align:center}
.logo-wrap{width:clamp(88px,11vw,148px);height:clamp(88px,11vw,148px);margin:0 auto 22px;background:#fff;border-radius:22px;padding:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 14px 50px rgba(0,0,0,.4),0 0 0 1px rgba(255,255,255,.1);animation:floatLogo 4s ease-in-out infinite}
@keyframes floatLogo{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.logo-wrap img{width:100%;height:100%;object-fit:contain}
.splash-college{font-size:clamp(10px,1.1vw,13px);font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:1.6px;margin-bottom:4px}
.splash-motto{font-size:10px;color:rgba(255,255,255,.35);font-family:'DM Mono',monospace;letter-spacing:2.5px;margin-bottom:clamp(20px,3vw,34px);text-transform:uppercase}
.splash-headline{font-size:clamp(24px,3vw,38px);font-weight:800;line-height:1.15;color:#fff;margin-bottom:12px;letter-spacing:-.5px}
.splash-headline span{background:linear-gradient(90deg,var(--cyan),var(--blue));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.splash-copy{font-size:clamp(12px,1.3vw,14px);line-height:1.75;color:rgba(255,255,255,.5);margin-bottom:clamp(26px,4vw,44px)}
.splash-stats{display:flex;border-top:1px solid rgba(255,255,255,.1);padding-top:22px}
.splash-stat{flex:1;text-align:center;padding:0 6px;border-right:1px solid rgba(255,255,255,.08)}
.splash-stat:last-child{border-right:none}
.ss-num{font-size:clamp(18px,2.2vw,26px);font-weight:800;color:var(--cyan);line-height:1}
.ss-lbl{font-size:10px;color:rgba(255,255,255,.38);margin-top:4px;text-transform:uppercase;letter-spacing:.8px}
.back-btn{display:inline-flex;align-items:center;gap:6px;color:rgba(255,255,255,.45);font-size:12.5px;font-weight:600;text-decoration:none;margin-bottom:22px;transition:color .15s;-webkit-tap-highlight-color:transparent}
.back-btn:hover{color:#fff}

/* AUTH PANEL */
.auth-panel{flex:1;display:flex;align-items:center;justify-content:center;padding:clamp(28px,4vw,50px) clamp(18px,5vw,56px);overflow-y:auto;background:var(--surface)}
.auth-box{width:100%;max-width:430px}
.auth-heading{font-size:clamp(20px,3vw,26px);font-weight:800;margin-bottom:6px}
.auth-sub{font-size:13.5px;color:var(--muted);margin-bottom:22px}

/* Tabs */
.auth-tabs{display:flex;background:#fff;border:1px solid var(--border);border-radius:12px;padding:5px;margin-bottom:22px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.auth-tab{flex:1;padding:10px 8px;border:none;background:transparent;font-family:inherit;font-size:14px;font-weight:600;color:var(--muted);cursor:pointer;border-radius:8px;transition:all .2s;-webkit-tap-highlight-color:transparent}
.auth-tab.active{background:var(--navy);color:#fff;box-shadow:0 2px 10px rgba(15,31,61,.25)}

.auth-form{display:none}
.auth-form.active{display:block;animation:fadeUp .22s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

.alert{padding:12px 16px;border-radius:9px;font-size:13.5px;font-weight:500;margin-bottom:16px;border:1px solid;display:flex;align-items:flex-start;gap:8px;line-height:1.5}
.alert-err{background:#fff0f0;color:#b91c1c;border-color:#fecaca}

/* Fields */
.fg{margin-bottom:13px}
.fl{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.fi-wrap{position:relative}
.fi,.fs{width:100%;padding:11px 40px 11px 14px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:14px;color:var(--text);background:#fff;outline:none;transition:border-color .15s,box-shadow .15s;-webkit-appearance:none;appearance:none}
.fi:focus,.fs:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(30,111,255,.1)}
.fi.valid  {border-color:var(--green)}
.fi.invalid{border-color:var(--red)}
.fi.valid:focus  {box-shadow:0 0 0 3px rgba(18,183,106,.1)}
.fi.invalid:focus{box-shadow:0 0 0 3px rgba(240,68,56,.1)}
.fi:disabled,.fs:disabled{background:#f8f9fb;color:#b0bac9;cursor:not-allowed;border-color:#dde3ed}
.fs{padding:11px 14px}
.fi-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:15px;pointer-events:none}
.fhint{font-size:11.5px;margin-top:4px;min-height:15px;display:none;align-items:center;gap:4px;line-height:1.4}
.fhint.err {color:var(--red);display:flex}
.fhint.ok  {color:var(--green);display:flex}
.fhint.warn{color:var(--orange);display:flex}
.fhint.info{color:var(--muted);display:flex}
.flock{font-size:11.5px;color:var(--muted);display:flex;align-items:center;gap:5px;margin-top:4px;opacity:.8}
.fr2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.f-check{display:flex;align-items:flex-start;gap:9px;font-size:13px;color:var(--muted);margin-bottom:14px;cursor:pointer;line-height:1.5}
.f-check input{accent-color:var(--blue);width:16px;height:16px;flex-shrink:0;margin-top:2px}
.btn-sub{width:100%;padding:clamp(12px,3vw,14px);background:var(--blue);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:clamp(14px,3vw,15px);font-weight:700;cursor:pointer;transition:all .15s;margin-bottom:14px;box-shadow:0 4px 14px rgba(30,111,255,.35);-webkit-tap-highlight-color:transparent}
.btn-sub:hover{background:#1559d4}
.btn-sub:active{transform:scale(.99)}
.btn-sub:disabled{background:#b0bac9;box-shadow:none;cursor:not-allowed}
.auth-alt{text-align:center;font-size:13px;color:var(--muted)}
.auth-link{color:var(--blue);font-weight:600;cursor:pointer}
.auth-link:hover{text-decoration:underline}

/* Register steps */
.reg-steps{display:flex;margin-bottom:18px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.step{flex:1;padding:8px 4px;text-align:center;font-size:10px;font-weight:700;color:var(--muted);border-right:1px solid var(--border);transition:all .2s;line-height:1.3}
.step:last-child{border-right:none}
.step .sn{display:block;font-size:13px;margin-bottom:2px}
.step.active{background:var(--navy);color:#fff}
.step.done  {background:#e6f9f1;color:var(--green)}

@media(max-width:720px){
  body{flex-direction:column}
  .splash{width:100%;min-height:auto;padding:32px 20px 28px}
  .auth-panel{padding:24px 18px 40px;align-items:flex-start}
  .auth-box{max-width:100%}
  .fr2{grid-template-columns:1fr;gap:0}
}
@media(max-width:400px){
  .splash{padding:26px 14px 22px}
  .auth-panel{padding:18px 14px 36px}
  .reg-steps{display:none}
  .auth-tab{font-size:13px;padding:9px 4px}
}
</style>
</head>
<body>

<!-- SPLASH -->
<section class="splash">
  <div class="splash-inner">
    <a class="back-btn" href="welcome.php">← Back</a>
    <div class="logo-wrap"><img src="assets/ecot.jpg" alt="ECOT"/></div>
    <p class="splash-college">Eswatini College of Technology</p>
    <p class="splash-motto">Looking to the Future</p>
    <h1 class="splash-headline">Study <span>smarter</span>,<br/>together.</h1>
    <p class="splash-copy">Join study groups, attend sessions, share resources and track your academic progress.</p>
    <div class="splash-stats">
      <div class="splash-stat"><div class="ss-num"><?=$stat_groups?></div><div class="ss-lbl">Groups</div></div>
      <div class="splash-stat"><div class="ss-num"><?=$stat_students?></div><div class="ss-lbl">Students</div></div>
      <div class="splash-stat"><div class="ss-num"><?=$stat_sessions?></div><div class="ss-lbl">Sessions</div></div>
    </div>
  </div>
</section>

<!-- AUTH PANEL -->
<section class="auth-panel">
  <div class="auth-box">

    <div class="auth-heading">Student Portal</div>
    <div class="auth-sub">Sign in or create an account to get started</div>

    <div class="auth-tabs">
      <button class="auth-tab <?=$tab==='login'?'active':''?>" onclick="switchTab('login')">Sign In</button>
      <button class="auth-tab <?=$tab==='register'?'active':''?>" onclick="switchTab('register')">Register</button>
    </div>

    <?php if($error):?>
      <div class="alert alert-err">⚠ <?=htmlspecialchars($error)?></div>
    <?php endif;?>

    <!-- ══ LOGIN ══ -->
    <form class="auth-form <?=$tab==='login'?'active':''?>" id="form-login" method="POST" novalidate>
      <input type="hidden" name="action" value="login"/>

      <div class="fg">
        <label class="fl" for="li-sn">Student Number</label>
        <div class="fi-wrap">
          <input class="fi" type="text" id="li-sn" name="student_num"
                 placeholder="student number e.g. 220001234"
                 maxlength="9" inputmode="numeric" autocomplete="username"
                 oninput="liSnInput()"
                 value="<?=htmlspecialchars($_POST['student_num']??'')?>"/>
          <span class="fi-icon" id="li-sn-ico"></span>
        </div>
        <div class="fhint info" id="li-sn-hint" style="display:flex">Enter your student number</div>
      </div>

      <div class="fg">
        <label class="fl" for="li-pw">Password</label>
        <div class="fi-wrap">
          <input class="fi" type="password" id="li-pw" name="password"
                 placeholder="Your password" disabled
                 autocomplete="current-password"
                 oninput="liPwInput()"/>
          <span class="fi-icon" id="li-pw-ico"></span>
        </div>
        <div class="flock" id="li-pw-lock">🔒 Enter a valid student number first</div>
        <div class="fhint" id="li-pw-hint"></div>
      </div>

      <button class="btn-sub" id="li-btn" type="submit" disabled>Sign In</button>
      <div class="auth-alt">No account? <span class="auth-link" onclick="switchTab('register')">Register here</span></div>
      <div class="auth-alt" style="margin-top:6px"><a href="forgot_password.php" style="color:var(--blue);font-size:13px;text-decoration:none">Forgot your password?</a></div>
    </form>

    <!-- ══ REGISTER ══ -->
    <form class="auth-form <?=$tab==='register'?'active':''?>" id="form-register" method="POST" novalidate>
      <input type="hidden" name="action" value="register"/>

      <div class="reg-steps">
        <div class="step active" id="s1"><span class="sn">①</span>Name</div>
        <div class="step"        id="s2"><span class="sn">②</span>Stu #</div>
        <div class="step"        id="s3"><span class="sn">③</span>Email</div>
        <div class="step"        id="s4"><span class="sn">④</span>Course</div>
        <div class="step"        id="s5"><span class="sn">⑤</span>Password</div>
      </div>

      <!-- Step 1: Name -->
      <div class="fr2">
        <div class="fg">
          <label class="fl" for="rg-fn">First Name *</label>
          <div class="fi-wrap">
            <input class="fi" type="text" id="rg-fn" name="first_name" placeholder="First name"
                   autocomplete="given-name" oninput="rgStep1()"
                   value="<?=htmlspecialchars($_POST['first_name']??'')?>"/>
            <span class="fi-icon" id="rg-fn-ico"></span>
          </div>
        </div>
        <div class="fg">
          <label class="fl" for="rg-ln">Last Name *</label>
          <div class="fi-wrap">
            <input class="fi" type="text" id="rg-ln" name="last_name" placeholder="Last name"
                   autocomplete="family-name" oninput="rgStep1()"
                   value="<?=htmlspecialchars($_POST['last_name']??'')?>"/>
            <span class="fi-icon" id="rg-ln-ico"></span>
          </div>
        </div>
      </div>

      <!-- Step 2: Student Number -->
      <div class="fg">
        <label class="fl" for="rg-sn">Student Number *</label>
        <div class="fi-wrap">
          <input class="fi" type="text" id="rg-sn" name="student_num"
                 placeholder="student number" maxlength="9"
                 inputmode="numeric" disabled oninput="rgStep2()"
                 value="<?=htmlspecialchars($_POST['student_num']??'')?>"/>
          <span class="fi-icon" id="rg-sn-ico"></span>
        </div>
        <div class="flock" id="rg-sn-lock">🔒 Enter your name first</div>
        <div class="fhint" id="rg-sn-hint"></div>
      </div>

      <!-- Step 3: Email -->
      <div class="fg">
        <label class="fl" for="rg-em">Email Address *</label>
        <div class="fi-wrap">
          <input class="fi" type="email" id="rg-em" name="email"
                 placeholder="your@email.com" disabled
                 autocomplete="email" oninput="rgStep3()"
                 value="<?=htmlspecialchars($_POST['email']??'')?>"/>
          <span class="fi-icon" id="rg-em-ico"></span>
        </div>
        <div class="flock" id="rg-em-lock">🔒 Enter a valid student number first</div>
        <div class="fhint" id="rg-em-hint"></div>
      </div>

      <!-- Step 4: Programme + Year -->
      <div class="fg">
        <label class="fl" for="rg-pr">Programme *</label>
        <select class="fs" id="rg-pr" name="programme" disabled onchange="rgStep4()">
          <option value="">Select your programme...</option>
          <?php foreach($programmes as $p):?>
            <option value="<?=htmlspecialchars($p)?>" <?=(($_POST['programme']??'')===$p)?'selected':''?>><?=htmlspecialchars($p)?></option>
          <?php endforeach;?>
        </select>
        <div class="flock" id="rg-pr-lock">🔒 Enter a valid email first</div>
      </div>

      <div class="fg">
        <label class="fl" for="rg-yr">Year of Study *</label>
        <select class="fs" id="rg-yr" name="year" disabled onchange="rgStep4b()">
          <option value="">Select year...</option>
          <?php foreach(['Year 1','Year 2','Year 3'] as $y):?>
            <option value="<?=$y?>" <?=(($_POST['year']??'')===$y)?'selected':''?>><?=$y?></option>
          <?php endforeach;?>
        </select>
        <div class="flock" id="rg-yr-lock">🔒 Select your programme first</div>
      </div>

      <!-- Step 5: Password -->
      <div class="fr2">
        <div class="fg">
          <label class="fl" for="rg-pw">Password *</label>
          <div class="fi-wrap">
            <input class="fi" type="password" id="rg-pw" name="password"
                   placeholder="Min. 8 characters" disabled
                   autocomplete="new-password" oninput="rgStep5()"/>
            <span class="fi-icon" id="rg-pw-ico"></span>
          </div>
          <div class="flock" id="rg-pw-lock">🔒 Complete above steps first</div>
          <div class="fhint" id="rg-pw-hint"></div>
        </div>
        <div class="fg">
          <label class="fl" for="rg-pw2">Confirm *</label>
          <div class="fi-wrap">
            <input class="fi" type="password" id="rg-pw2" name="password2"
                   placeholder="Repeat password" disabled
                   autocomplete="new-password" oninput="rgStep5b()"/>
            <span class="fi-icon" id="rg-pw2-ico"></span>
          </div>
          <div class="flock" id="rg-pw2-lock">🔒 Enter password first</div>
          <div class="fhint" id="rg-pw2-hint"></div>
        </div>
      </div>

      <!-- Security Question -->
      <div class="fg">
        <label class="fl" for="rg-sq">Security Question *</label>
        <select class="fi" id="rg-sq" name="security_question" onchange="rgSecQ()">
          <option value="">— Choose a question —</option>
          <option>What is the name of your first pet?</option>
          <option>What is your mother's maiden name?</option>
          <option>What was the name of your primary school?</option>
          <option>What is the name of the town where you were born?</option>
          <option>What was the make of your first car?</option>
          <option>What is your oldest sibling's middle name?</option>
          <option>What was the name of your childhood best friend?</option>
        </select>
        <div class="fhint info" id="rg-sq-hint" style="display:flex">Choose a question you will remember</div>
      </div>
      <div class="fg">
        <label class="fl" for="rg-sa">Your Answer *</label>
        <div class="fi-wrap">
          <input class="fi" type="text" id="rg-sa" name="security_answer"
                 placeholder="Your answer (case-insensitive)" disabled
                 autocomplete="off" oninput="rgSecA()"/>
          <span class="fi-icon" id="rg-sa-ico"></span>
        </div>
        <div class="flock" id="rg-sa-lock">🔒 Select a question first</div>
        <div class="fhint info" id="rg-sa-hint" style="display:none">Answer is stored securely and is not case-sensitive</div>
      </div>

      <label class="f-check">
        <input type="checkbox" id="rg-terms" onchange="rgCheck()"/>
        I agree to the ECOT study system's Terms of Use
      </label>

      <button class="btn-sub" id="rg-btn" type="submit" disabled>Create My Account</button>
      <div class="auth-alt">Already registered? <span class="auth-link" onclick="switchTab('login')">Sign in</span></div>
    </form>

  </div>
</section>

<script>
const VP=['20','21','22','23','24','25','26'];
function hint(id,msg,type){const e=document.getElementById(id);if(!e)return;e.className='fhint'+(msg?' '+type:'');e.textContent=msg;e.style.display=msg?'flex':'none'}
function ico(id,v){const e=document.getElementById(id);if(e)e.textContent=v||''}
function lock(id,show){const e=document.getElementById(id);if(e)e.style.display=show?'flex':'none'}
function markV(fid,iid,ok){const e=document.getElementById(fid);if(!e||!e.value)return;e.classList.toggle('valid',ok);e.classList.toggle('invalid',!ok);ico(iid,ok?'✅':'❌')}
function dis(id,lockId){const e=document.getElementById(id);if(!e)return;e.disabled=true;e.value='';e.classList.remove('valid','invalid');ico(id+'-ico','');if(lockId)lock(lockId,true)}
function enb(id,lockId){const e=document.getElementById(id);if(!e)return;e.disabled=false;if(lockId)lock(lockId,false)}
function vstep(id,state){const e=document.getElementById(id);if(e)e.className='step'+(state?' '+state:'')}
function vSN(n){
  if(!n)return{ok:false,msg:''};
  if(!/^\d+$/.test(n))return{ok:false,msg:'Digits only — no letters or spaces.'};
  if(n.length<9)return{ok:false,msg:n.length+'/9 digits — need '+(9-n.length)+' more.'};
  if(n.length>9)return{ok:false,msg:'Too long — exactly 9 digits required.'};
  const p=n.substring(0,2);
  if(!VP.includes(p))return{ok:false,msg:'Year prefix "'+p+'" not accepted. Only students from 2020–2026 may register.'};
  return{ok:true,msg:''};
}
function vEM(e){if(!e)return{ok:false,msg:''};const ok=/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(e);return ok?{ok:true,msg:''}:{ok:false,msg:'Invalid email — use format: name@domain.com'}}

// LOGIN
function liSnInput(){
  const el=document.getElementById('li-sn');
  el.value=el.value.replace(/\D/g,'');
  const n=el.value,r=vSN(n);
  if(!n){el.classList.remove('valid','invalid');ico('li-sn-ico','');hint('li-sn-hint','Enter your 9-digit student number','info');dis('li-pw','li-pw-lock');document.getElementById('li-btn').disabled=true;return}
  if(r.ok){markV('li-sn','li-sn-ico',true);hint('li-sn-hint','Valid student number ✓','ok');enb('li-pw','li-pw-lock');hint('li-pw-hint','','');liPwInput()}
  else    {markV('li-sn','li-sn-ico',false);hint('li-sn-hint',r.msg,'err');dis('li-pw','li-pw-lock');document.getElementById('li-pw').value='';document.getElementById('li-btn').disabled=true}
}
function liPwInput(){
  const pw=document.getElementById('li-pw').value;
  const snOk=vSN(document.getElementById('li-sn').value.trim()).ok;
  if(!snOk){document.getElementById('li-btn').disabled=true;return}
  if(!pw){hint('li-pw-hint','','');document.getElementById('li-btn').disabled=true;return}
  if(pw.length<8){hint('li-pw-hint','Password too short — min. 8 characters','warn');document.getElementById('li-btn').disabled=true}
  else           {hint('li-pw-hint','','');document.getElementById('li-btn').disabled=false}
}

// REGISTER — chain
function rgStep1(){
  const fn=document.getElementById('rg-fn').value.trim();
  const ln=document.getElementById('rg-ln').value.trim();
  ico('rg-fn-ico',fn?'✅':'');ico('rg-ln-ico',ln?'✅':'');
  document.getElementById('rg-fn').classList.toggle('valid',fn.length>0);
  document.getElementById('rg-ln').classList.toggle('valid',ln.length>0);
  if(fn&&ln){vstep('s1','done');enb('rg-sn','rg-sn-lock');hint('rg-sn-hint','Enter your 9-digit student number','info');vstep('s2','active')}
  else      {vstep('s1','active');lockFrom('sn')}
}
function rgStep2(){
  const el=document.getElementById('rg-sn');el.value=el.value.replace(/\D/g,'');
  const n=el.value,r=vSN(n);
  if(!n){el.classList.remove('valid','invalid');ico('rg-sn-ico','');hint('rg-sn-hint','Enter your 9-digit student number','info');lockFrom('em');return}
  if(r.ok){markV('rg-sn','rg-sn-ico',true);hint('rg-sn-hint','Valid student number ✓','ok');vstep('s2','done');vstep('s3','active');enb('rg-em','rg-em-lock');hint('rg-em-hint','Enter a real, working email address','info')}
  else    {markV('rg-sn','rg-sn-ico',false);hint('rg-sn-hint',r.msg,'err');lockFrom('em')}
}
function rgStep3(){
  const em=document.getElementById('rg-em').value.trim(),r=vEM(em);
  if(!em){document.getElementById('rg-em').classList.remove('valid','invalid');ico('rg-em-ico','');lockFrom('pr');return}
  if(r.ok){markV('rg-em','rg-em-ico',true);hint('rg-em-hint','Valid email ✓','ok');vstep('s3','done');vstep('s4','active');enb('rg-pr','rg-pr-lock')}
  else    {markV('rg-em','rg-em-ico',false);hint('rg-em-hint',r.msg,'err');lockFrom('pr')}
}
function rgStep4(){
  const pr=document.getElementById('rg-pr').value;
  if(pr){enb('rg-yr','rg-yr-lock')}else{dis('rg-yr','rg-yr-lock');lockFrom('pw')}
}
function rgStep4b(){
  const yr=document.getElementById('rg-yr').value;
  if(yr){vstep('s4','done');vstep('s5','active');enb('rg-pw','rg-pw-lock');hint('rg-pw-hint','Min. 8 characters','info')}
  else  {lockFrom('pw')}
}
function rgStep5(){
  const pw=document.getElementById('rg-pw').value;
  if(!pw){document.getElementById('rg-pw').classList.remove('valid','invalid');ico('rg-pw-ico','');dis('rg-pw2','rg-pw2-lock');document.getElementById('rg-btn').disabled=true;return}
  if(pw.length<8){markV('rg-pw','rg-pw-ico',false);hint('rg-pw-hint','Too short — at least 8 characters needed','err');dis('rg-pw2','rg-pw2-lock');document.getElementById('rg-btn').disabled=true}
  else           {markV('rg-pw','rg-pw-ico',true);hint('rg-pw-hint','Password strength: good ✓','ok');enb('rg-pw2','rg-pw2-lock');hint('rg-pw2-hint','Re-enter your password','info');rgStep5b()}
}
function rgStep5b(){
  const pw=document.getElementById('rg-pw').value,pw2=document.getElementById('rg-pw2').value;
  if(!pw2){document.getElementById('rg-pw2').classList.remove('valid','invalid');ico('rg-pw2-ico','');document.getElementById('rg-btn').disabled=true;return}
  if(pw===pw2){markV('rg-pw2','rg-pw2-ico',true);hint('rg-pw2-hint','Passwords match ✓','ok');vstep('s5','done')}
  else        {markV('rg-pw2','rg-pw2-ico',false);hint('rg-pw2-hint','Passwords do not match','err')}
  rgCheck();
}
function rgCheck(){
  const fn=document.getElementById('rg-fn').value.trim(),ln=document.getElementById('rg-ln').value.trim();
  const sn=document.getElementById('rg-sn').value.trim(),em=document.getElementById('rg-em').value.trim();
  const pr=document.getElementById('rg-pr').value,yr=document.getElementById('rg-yr').value;
  const pw=document.getElementById('rg-pw').value,pw2=document.getElementById('rg-pw2').value;
  const terms=document.getElementById('rg-terms').checked;
  document.getElementById('rg-btn').disabled=!(fn&&ln&&vSN(sn).ok&&vEM(em).ok&&pr&&yr&&pw.length>=8&&pw===pw2&&terms);
}
function lockFrom(from){
  const chain=['sn','em','pr','yr','pw','pw2'];
  const map={sn:['rg-sn','rg-sn-lock'],em:['rg-em','rg-em-lock'],pr:['rg-pr','rg-pr-lock'],yr:['rg-yr','rg-yr-lock'],pw:['rg-pw','rg-pw-lock'],pw2:['rg-pw2','rg-pw2-lock']};
  const hints={sn:'rg-sn-hint',em:'rg-em-hint',pw:'rg-pw-hint',pw2:'rg-pw2-hint'};
  const steps={sn:2,em:3,pr:4,pw:5};
  let go=false;
  chain.forEach(k=>{
    if(k===from)go=true;if(!go)return;
    dis(map[k][0],map[k][1]);
    if(hints[k])hint(hints[k],'','');
    if(steps[k])vstep('s'+steps[k],'');
  });
  document.getElementById('rg-btn').disabled=true;
  document.getElementById('rg-terms').checked=false;
}
function switchTab(t){
  document.querySelectorAll('.auth-tab').forEach((b,i)=>b.classList.toggle('active',(i===0&&t==='login')||(i===1&&t==='register')));
  document.getElementById('form-login').classList.toggle('active',t==='login');
  document.getElementById('form-register').classList.toggle('active',t==='register');
}
window.addEventListener('DOMContentLoaded',()=>{
  if(document.getElementById('li-sn').value)liSnInput();
  const fn=document.getElementById('rg-fn');
  if(fn&&fn.value){rgStep1();const sn=document.getElementById('rg-sn');if(sn&&sn.value){rgStep2();const em=document.getElementById('rg-em');if(em&&em.value){rgStep3();const pr=document.getElementById('rg-pr');if(pr&&pr.value){rgStep4();const yr=document.getElementById('rg-yr');if(yr&&yr.value)rgStep4b();}}}}
});
// SECURITY QUESTION
function rgSecQ() {
  const sq = document.getElementById('rg-sq').value;
  const sa = document.getElementById('rg-sa');
  const lock = document.getElementById('rg-sa-lock');
  const hint = document.getElementById('rg-sa-hint');
  if (sq) {
    sa.disabled = false;
    lock.style.display = 'none';
    hint.style.display = 'flex';
    hint('rg-sq-hint','Question selected ✓','ok');
  } else {
    sa.disabled = true; sa.value = '';
    lock.style.display = 'flex';
    hint('rg-sq-hint','Choose a question you will remember','info');
  }
  rgCheck();
}
function rgSecA() {
  const val = document.getElementById('rg-sa').value.trim();
  if (val.length >= 2) {
    markV('rg-sa','rg-sa-ico',true);
    hint('rg-sa-hint','Answer saved ✓','ok');
  } else {
    document.getElementById('rg-sa').classList.remove('valid','invalid');
    ico('rg-sa-ico','');
    hint('rg-sa-hint','Answer is stored securely and is not case-sensitive','info');
  }
  rgCheck();
}
</script>
</body>
</html>
