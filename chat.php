<?php
// ============================================================
//  LSGS  |  chat.php — Group Chat + Video Call
//  Eswatini College of Technology
// ============================================================
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header('Location: index.php'); exit;
}

require_once __DIR__.'/db.php';

$user      = $_SESSION['user'];
$uid       = (int)$user['id'];
$initials  = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$full_name = htmlspecialchars($user['first_name'].' '.$user['last_name']);

// Ensure chat_messages table exists
$conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  student_id INT NOT NULL,
  message TEXT NOT NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (group_id) REFERENCES study_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  INDEX idx_group_time (group_id, sent_at)
)");
// Safely add columns — compatible with all MySQL versions
if ($conn->query("SHOW COLUMNS FROM study_groups LIKE 'is_online'")->num_rows === 0)
    $conn->query("ALTER TABLE study_groups ADD COLUMN is_online TINYINT(1) DEFAULT 0");
if ($conn->query("SHOW COLUMNS FROM students LIKE 'last_seen'")->num_rows === 0)
    $conn->query("ALTER TABLE students ADD COLUMN last_seen DATETIME DEFAULT NULL");
// Update current user's last_seen
$conn->query("UPDATE students SET last_seen=NOW() WHERE id=$uid");

$gid = (int)($_GET['gid'] ?? 0);
if (!$gid) { header('Location: groups.php'); exit; }

// Verify student is active member of this group
$chk = $conn->prepare("SELECT status FROM group_members WHERE group_id=? AND student_id=?");
$chk->bind_param('ii',$gid,$uid); $chk->execute();
$mem = $chk->get_result()->fetch_assoc();
if (!$mem || $mem['status'] !== 'active') {
    header('Location: groups.php?err=notmember'); exit;
}

// Get group info
$gq = $conn->prepare("SELECT g.*, s.first_name creator_fn, s.last_name creator_ln
    FROM study_groups g
    LEFT JOIN students s ON g.created_by = s.id
    WHERE g.id=?");
$gq->bind_param('i',$gid); $gq->execute();
$group = $gq->get_result()->fetch_assoc();
if (!$group) { header('Location: groups.php'); exit; }

$is_admin = ((int)($group['created_by'] ?? 0) === $uid);

// Load last 60 messages
$msgs_q = $conn->prepare("
    SELECT cm.*, CONCAT(s.first_name,' ',s.last_name) sender_name,
           s.first_name sender_fn, s.last_name sender_ln
    FROM chat_messages cm
    JOIN students s ON cm.student_id = s.id
    WHERE cm.group_id = ?
    ORDER BY cm.sent_at DESC LIMIT 60
");
$msgs_q->bind_param('i',$gid); $msgs_q->execute();
$msgs_raw = $msgs_q->get_result()->fetch_all(MYSQLI_ASSOC);
$messages = array_reverse($msgs_raw);

// Member count + full list
$mc_q = $conn->prepare("SELECT COUNT(*) c FROM group_members WHERE group_id=? AND status='active'");
$mc_q->bind_param('i',$gid); $mc_q->execute();
$mc = $mc_q->get_result()->fetch_assoc()['c'];

$mem_q = $conn->prepare("
    SELECT s.id, s.first_name, s.last_name, s.student_num, s.programme, s.year,
           gm.joined_at, s.last_seen,
           CASE WHEN g.created_by = s.id THEN 1 ELSE 0 END AS is_admin
    FROM group_members gm
    JOIN students s ON gm.student_id = s.id
    JOIN study_groups g ON gm.group_id = g.id
    WHERE gm.group_id = ? AND gm.status = 'active'
    ORDER BY is_admin DESC, s.first_name ASC
");
$mem_q->bind_param('i', $gid); $mem_q->execute();
$group_members_list = $mem_q->get_result()->fetch_all(MYSQLI_ASSOC);

// Jitsi room name — based on group id for consistency
$jitsi_room = 'LSGS-Group-' . $gid . '-' . preg_replace('/[^a-zA-Z0-9]/','',$group['name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= htmlspecialchars($group['name']) ?> — Chat</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap');
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--navy:#0f1f3d;--blue:#1e6fff;--cyan:#00c2f3;--surface:#f4f6fb;--white:#fff;--border:#e2e8f0;--text:#1a2540;--muted:#6b7a99;--green:#12b76a;--orange:#f79009;--red:#f04438;--chat-bg:#ece5dd;--bubble-out:#dcf8c6;--bubble-in:#fff}
body{font-family:'DM Sans',sans-serif;background:var(--navy);color:var(--text);height:100vh;height:100dvh;display:flex;flex-direction:column;overflow:hidden}

/* TOP BAR */
.chat-topbar{background:var(--navy);padding:0 20px;height:60px;display:flex;align-items:center;gap:14px;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,.08)}
.back-btn{color:rgba(255,255,255,.6);text-decoration:none;font-size:20px;line-height:1}
.back-btn:hover{color:#fff}
.chat-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}
.chat-info{flex:1;min-width:0}
.chat-name{font-size:15px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-sub{font-size:11.5px;color:rgba(255,255,255,.45)}
.chat-actions{display:flex;gap:8px;flex-shrink:0}
.icon-btn{background:rgba(255,255,255,.08);border:none;color:rgba(255,255,255,.7);border-radius:8px;padding:7px 12px;font-size:13px;cursor:pointer;transition:all .15s;white-space:nowrap}
.icon-btn:hover{background:rgba(255,255,255,.16);color:#fff}
.icon-btn.video{background:var(--green);color:#fff}
.icon-btn.video:hover{background:#0da85e}

/* CHAT AREA */
.chat-body{flex:1;display:flex;overflow:hidden;background:var(--surface)}
.chat-messages{flex:1;overflow-y:auto;padding:20px 16px;display:flex;flex-direction:column;gap:4px;background:var(--chat-bg) url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23000000' fill-opacity='0.02'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E")}
.chat-messages::-webkit-scrollbar{width:4px}
.chat-messages::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px}

/* Date separator */
.date-sep{text-align:center;margin:12px 0 8px}
.date-sep span{background:rgba(255,255,255,.85);padding:4px 14px;border-radius:12px;font-size:11.5px;color:var(--muted);font-weight:500}

/* Bubbles */
.msg-row{display:flex;gap:8px;max-width:72%;margin-bottom:2px}
.msg-row.out{align-self:flex-end;flex-direction:row-reverse}
.msg-row.in{align-self:flex-start}
.msg-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;align-self:flex-end}
.bubble{padding:8px 12px;border-radius:12px;max-width:100%;position:relative;word-break:break-word}
.msg-row.out .bubble{background:var(--bubble-out);border-bottom-right-radius:3px}
.msg-row.in  .bubble{background:var(--bubble-in);border-bottom-left-radius:3px;box-shadow:0 1px 2px rgba(0,0,0,.1)}
.bubble-sender{font-size:11px;font-weight:700;color:var(--blue);margin-bottom:3px}
.bubble-text{font-size:13.5px;line-height:1.5;color:var(--text)}
.bubble-time{font-size:10px;color:var(--muted);text-align:right;margin-top:4px}

/* System message */
.sys-msg{text-align:center;font-size:11.5px;color:var(--muted);padding:4px 0}

/* Video panel */
.video-panel{display:none;width:420px;flex-shrink:0;background:var(--navy);border-left:1px solid rgba(255,255,255,.08);flex-direction:column}
.video-panel.open{display:flex}
.video-header{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between}
.video-title{color:#fff;font-size:14px;font-weight:700}
.video-frame{flex:1}
.video-frame iframe{width:100%;height:100%;border:none}

/* Input bar */
.chat-input-bar{background:var(--white);padding:12px 16px;display:flex;align-items:flex-end;gap:10px;flex-shrink:0;border-top:1px solid var(--border)}
.msg-input{flex:1;border:1.5px solid var(--border);border-radius:22px;padding:10px 16px;font-family:inherit;font-size:14px;color:var(--text);outline:none;resize:none;max-height:120px;line-height:1.5;transition:border-color .15s}
.msg-input:focus{border-color:var(--blue)}
.send-btn{width:44px;height:44px;border-radius:50%;background:var(--green);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;transition:background .15s;flex-shrink:0}
.send-btn:hover{background:#0da85e}
.send-btn:disabled{background:var(--border);cursor:not-allowed}

/* Connection status */
.conn-bar{padding:6px 16px;font-size:12px;font-weight:600;text-align:center;display:none}
.conn-bar.connecting{background:#fff4e5;color:#92400e;display:block}
.conn-bar.connected{background:#e6f9f1;color:#065f46;display:block}
.conn-bar.disconnected{background:#fff0f0;color:#b91c1c;display:block}

/* Members panel */
.members-panel{display:none;width:280px;flex-shrink:0;background:var(--white);border-left:1px solid var(--border);flex-direction:column;overflow:hidden}
.members-panel.open{display:flex}
.members-hdr{padding:16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.members-hdr-title{font-size:14px;font-weight:700;color:var(--text)}
.members-hdr-count{font-size:12px;color:var(--muted)}
.members-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);line-height:1}
.members-list{flex:1;overflow-y:auto;padding:8px 0}
.member-item{display:flex;align-items:center;gap:10px;padding:10px 16px}
.member-item:hover{background:var(--surface)}
.m-av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0}
.m-av.admin-av{background:linear-gradient(135deg,#f79009,#fbbf24)}
.m-info{flex:1;min-width:0}
.m-name{font-size:13.5px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.m-status{font-size:11.5px;color:var(--muted);margin-top:1px}
.m-status.online{color:var(--green);font-weight:600}
.m-badge{font-size:10px;background:#f0f4ff;color:var(--blue);padding:2px 6px;border-radius:6px;font-weight:700;margin-left:4px}
.m-badge.admin-badge{background:#fff4e5;color:#b45309}
.m-dot{width:10px;height:10px;border-radius:50%;background:#d1d5db;flex-shrink:0;border:2px solid #fff}
.m-dot.online{background:var(--green)}
.members-hdr{padding:16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.members-hdr-title{font-size:14px;font-weight:700;color:var(--text)}
.members-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--muted);line-height:1}
.members-close:hover{color:var(--text)}
.members-list{flex:1;overflow-y:auto;padding:10px 0}
.members-list::-webkit-scrollbar{width:3px}
.members-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.member-item{display:flex;align-items:center;gap:10px;padding:10px 16px;transition:background .12s}
.member-item:hover{background:var(--surface)}
.m-av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
.m-av.admin-av{background:linear-gradient(135deg,#f79009,#fbbf24)}
.m-info{flex:1;min-width:0}
.m-name{font-size:13.5px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.m-status{font-size:11.5px;color:var(--muted);margin-top:1px}
.m-status.online{color:var(--green);font-weight:600}
.m-badge{font-size:10px;background:#f0f4ff;color:var(--blue);padding:2px 7px;border-radius:8px;font-weight:700;margin-left:4px;vertical-align:middle}
.m-online-dot{width:10px;height:10px;border-radius:50%;background:#ccc;flex-shrink:0;border:2px solid #fff;margin-left:auto}
.m-online-dot.online{background:var(--green)}
.members-hdr-count{font-size:12px;color:var(--muted);font-weight:400}
.m-name{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.m-sub{font-size:11px;color:var(--muted)}
.m-badge{font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:4px;background:#fff4e5;color:#b45309;border:1px solid #fde68a;margin-left:4px}
.members-footer{padding:12px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--muted);text-align:center}

@media(max-width:820px){
  .video-panel{position:fixed;inset:0;width:100%;z-index:200}
  .chat-actions .icon-btn span{display:none}
  .members-panel{position:fixed;right:0;top:60px;bottom:0;z-index:200;width:260px;box-shadow:-4px 0 20px rgba(0,0,0,.15)}
}
@media(max-width:600px){
  body{height:100%;min-height:100vh;min-height:100dvh;position:fixed;width:100%;top:0;left:0}
  .chat-body{height:calc(100dvh - 60px - 44px);min-height:0}
  .chat-input-bar{position:fixed;bottom:0;left:0;right:0;z-index:100;background:#fff;padding:10px 12px;padding-bottom:max(10px, env(safe-area-inset-bottom))}
  .chat-messages{padding-bottom:80px}
  .conn-bar{position:sticky;top:0;z-index:50}
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="chat-topbar">
  <a href="groups.php" class="back-btn">←</a>
  <div class="chat-avatar"><?= strtoupper(substr($group['name'],0,2)) ?></div>
  <div class="chat-info">
    <div class="chat-name"><?= htmlspecialchars($group['name']) ?></div>
    <div class="chat-sub"><?= $mc ?> members · <?= htmlspecialchars($group['subject']??'') ?><?= !empty($group['is_online']) ? ' · 🌐 Online Group' : '' ?></div>
  </div>
  <div class="chat-actions">
    <button class="icon-btn video" onclick="toggleVideo()">📹 <span>Video Call</span></button>
    <button class="icon-btn" onclick="toggleMembers()">👥 <span><?= $mc ?></span></button>
  </div>
</div>

<!-- CONNECTION STATUS -->
<div class="conn-bar connecting" id="conn-bar">Connecting to chat server...</div>

<!-- BODY -->
<div class="chat-body">

  <!-- MESSAGES -->
  <div class="chat-messages" id="chat-messages">
    <?php
    $prev_date = '';
    foreach ($messages as $m):
      $is_me   = ((int)$m['student_id'] === $uid);
      $row_cls = $is_me ? 'out' : 'in';
      $av_ini  = strtoupper(substr($m['sender_fn'],0,1).substr($m['sender_ln'],0,1));
      $msg_date = date('d M Y', strtotime($m['sent_at']));
      $msg_time = date('H:i', strtotime($m['sent_at']));
      if ($msg_date !== $prev_date):
        $prev_date = $msg_date;
    ?>
      <div class="date-sep"><span><?= $msg_date === date('d M Y') ? 'Today' : $msg_date ?></span></div>
    <?php endif; ?>
      <div class="msg-row <?= $row_cls ?>">
        <?php if (!$is_me): ?><div class="msg-av"><?= $av_ini ?></div><?php endif; ?>
        <div class="bubble">
          <?php if (!$is_me): ?><div class="bubble-sender"><?= htmlspecialchars($m['sender_name']) ?></div><?php endif; ?>
          <div class="bubble-text"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
          <div class="bubble-time"><?= $msg_time ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($messages)): ?>
      <div class="sys-msg" style="margin-top:40px">
        <div style="font-size:40px;margin-bottom:12px">💬</div>
        No messages yet. Say hello!
      </div>
    <?php endif; ?>
  </div>

  <!-- VIDEO PANEL (Jitsi) -->
  <div class="video-panel" id="video-panel">
    <div class="video-header">
      <div class="video-title">📹 Video Call</div>
      <button onclick="toggleVideo()" style="background:none;border:none;color:rgba(255,255,255,.6);font-size:20px;cursor:pointer">✕</button>
    </div>
    <div class="video-frame" id="video-frame"></div>
  </div>

  <!-- MEMBERS PANEL -->
  <div class="members-panel" id="members-panel">
    <div class="members-hdr">
      <div>
        <div class="members-hdr-title">👥 Members</div>
        <div class="members-hdr-count"><?= $mc ?> member<?= $mc != 1 ? 's' : '' ?></div>
      </div>
      <button class="members-close" onclick="toggleMembers()">✕</button>
    </div>
    <div class="members-list">
      <?php foreach ($group_members_list as $m):
        $m_ini   = strtoupper(substr($m['first_name'],0,1).substr($m['last_name'],0,1));
        $is_me_m = ((int)$m['id'] === $uid);
        $is_adm  = (int)($m['is_admin'] ?? 0);
        $is_onl  = false;
        $st_txt  = 'Never active';
        if (!empty($m['last_seen'])) {
          $diff = time() - strtotime($m['last_seen']);
          if ($diff < 180)        { $is_onl = true; $st_txt = 'Online'; }
          elseif ($diff < 3600)   { $st_txt = 'Last seen '.floor($diff/60).' min ago'; }
          elseif ($diff < 86400)  { $st_txt = 'Last seen '.date('H:i', strtotime($m['last_seen'])); }
          else                    { $st_txt = 'Last seen '.date('d M', strtotime($m['last_seen'])); }
        }
      ?>
      <div class="member-item">
        <div class="m-av <?= $is_adm ? 'admin-av' : '' ?>"><?= htmlspecialchars($m_ini) ?></div>
        <div class="m-info">
          <div class="m-name">
            <?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?>
            <?php if ($is_me_m): ?><span class="m-badge">You</span><?php endif; ?>
            <?php if ($is_adm): ?><span class="m-badge admin-badge">Admin</span><?php endif; ?>
          </div>
          <div class="m-status <?= $is_onl ? 'online' : '' ?>"><?= $st_txt ?></div>
        </div>
        <div class="m-dot <?= $is_onl ? 'online' : '' ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div><!-- /chat-body -->

<!-- INPUT BAR -->
<div class="chat-input-bar">
  <textarea class="msg-input" id="msg-input" placeholder="Type a message..." rows="1"
    onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
  <button class="send-btn" id="send-btn" onclick="sendMessage()" disabled>➤</button>
</div>

<script>
// ── Config ────────────────────────────────────────────────────
var GID       = <?= $gid ?>;
var UID       = <?= $uid ?>;
var UNAME     = <?= json_encode($user['first_name'].' '.$user['last_name']) ?>;
var INITIALS  = <?= json_encode($initials) ?>;
// Use the same host as the web server — works for all devices on the network
<?php
// On Railway: WS_URL env var is set to wss://your-node-service.railway.app
// Locally: falls back to your machine IP
$ws_url = getenv('WS_URL') ?: 'ws://192.168.43.98:8080';
?>
var WS_URL = '<?= $ws_url ?>';
var JITSI_ROOM= <?= json_encode($jitsi_room) ?>;

// ── WebSocket ─────────────────────────────────────────────────
var ws, reconnectTimer;
var connBar  = document.getElementById('conn-bar');
var sendBtn  = document.getElementById('send-btn');
var msgInput = document.getElementById('msg-input');

function connect() {
  connBar.className = 'conn-bar connecting';
  connBar.textContent = 'Connecting to chat server...';

  try {
    ws = new WebSocket(WS_URL);
  } catch(e) {
    fallbackMode();
    return;
  }

  ws.onopen = function() {
    connBar.className = 'conn-bar connected';
    connBar.textContent = '✓ Connected';
    setTimeout(function(){ connBar.style.display='none'; }, 2000);
    sendBtn.disabled = false;
    // Register with server
    ws.send(JSON.stringify({ type:'join', gid: GID, uid: UID, name: UNAME }));
  };

  ws.onmessage = function(e) {
    try {
      var data = JSON.parse(e.data);
      if (data.type === 'message') appendBubble(data, false);
      if (data.type === 'system')  appendSystem(data.text);
    } catch(ex){}
  };

  ws.onclose = function() {
    connBar.className = 'conn-bar disconnected';
    connBar.textContent = '⚠ Disconnected. Reconnecting...';
    connBar.style.display = 'block';
    sendBtn.disabled = true;
    reconnectTimer = setTimeout(connect, 3000);
  };

  ws.onerror = function() {
    fallbackMode();
  };
}

// Fallback: if WS server not running, still allow sending via HTTP (saves to DB only, no real-time)
function fallbackMode() {
  connBar.className = 'conn-bar disconnected';
  connBar.textContent = '⚠ Real-time server offline. Messages will still be saved. Start ws-server.js for live chat.';
  connBar.style.display = 'block';
  sendBtn.disabled = false;
  // Override send to use HTTP POST fallback
  window.WS_FALLBACK = true;
}

// ── Send message ──────────────────────────────────────────────
function sendMessage() {
  var text = msgInput.value.trim();
  if (!text) return;

  if (window.WS_FALLBACK) {
    // HTTP fallback
    fetch('chat_send.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: 'gid='+GID+'&uid='+UID+'&msg='+encodeURIComponent(text)
    }).then(function(r){ return r.json(); }).then(function(d){
      if (d.ok) {
        appendBubble({ message: text, sender_name: UNAME, initials: INITIALS, sent_at: new Date().toISOString() }, true);
      }
    }).catch(function(){});
  } else if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ type:'message', gid: GID, uid: UID, name: UNAME, message: text }));
    appendBubble({ message: text, sender_name: UNAME, initials: INITIALS, sent_at: new Date().toISOString() }, true);
  }

  msgInput.value = '';
  msgInput.style.height = 'auto';
}

// ── Append bubble to DOM ──────────────────────────────────────
function appendBubble(data, isMe) {
  var container = document.getElementById('chat-messages');
  var now = new Date();
  var timeStr = now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0');

  var row = document.createElement('div');
  row.className = 'msg-row ' + (isMe ? 'out' : 'in');

  var ini = data.initials || (data.sender_name ? data.sender_name.split(' ').map(function(w){return w[0];}).join('').toUpperCase() : '??');

  row.innerHTML =
    (!isMe ? '<div class="msg-av">'+esc(ini)+'</div>' : '') +
    '<div class="bubble">' +
      (!isMe ? '<div class="bubble-sender">'+esc(data.sender_name || 'Unknown')+'</div>' : '') +
      '<div class="bubble-text">'+esc(data.message).replace(/\n/g,'<br>')+'</div>' +
      '<div class="bubble-time">'+timeStr+'</div>' +
    '</div>';

  // Remove empty state if present
  var emptySys = container.querySelector('.sys-msg');
  if (emptySys) emptySys.remove();

  container.appendChild(row);
  container.scrollTop = container.scrollHeight;
}

function appendSystem(text) {
  var container = document.getElementById('chat-messages');
  var div = document.createElement('div');
  div.className = 'sys-msg';
  div.textContent = text;
  container.appendChild(div);
  container.scrollTop = container.scrollHeight;
}

function esc(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Input helpers ─────────────────────────────────────────────
function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}
function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

// ── Video (Jitsi) ─────────────────────────────────────────────
var videoOpen = false;
function toggleVideo() {
  videoOpen = !videoOpen;
  var panel = document.getElementById('video-panel');
  var frame = document.getElementById('video-frame');
  if (videoOpen) {
    panel.classList.add('open');
    // Close members panel if open
    document.getElementById('members-panel').classList.remove('open');
    // Load Jitsi iframe
    frame.innerHTML = '<iframe src="https://meet.jit.si/' + encodeURIComponent(JITSI_ROOM) +
      '#config.startWithAudioMuted=false&config.startWithVideoMuted=false&userInfo.displayName=' +
      encodeURIComponent(UNAME) + '" allow="camera; microphone; fullscreen; display-capture" ' +
      'style="width:100%;height:100%;border:none"></iframe>';
  } else {
    panel.classList.remove('open');
    frame.innerHTML = '';
  }
}

// ── Members panel toggle ─────────────────────────────────────
function toggleMembers() {
  var panel = document.getElementById('members-panel');
  panel.classList.toggle('open');
  // Close video panel if open
  if (panel.classList.contains('open')) {
    document.getElementById('video-panel').classList.remove('open');
    document.getElementById('video-frame').innerHTML = '';
  }
}

// ── Scroll to bottom on load ──────────────────────────────────
window.addEventListener('DOMContentLoaded', function() {
  var c = document.getElementById('chat-messages');
  c.scrollTop = c.scrollHeight;
  connect();
});
</script>
</body>
</html>
