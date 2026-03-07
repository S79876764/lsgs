// ============================================================
//  LSGS  |  ws-server.js — WebSocket Chat Server
//  Eswatini College of Technology
//
//  SETUP:
//  1. Make sure Node.js is installed (https://nodejs.org)
//  2. Open terminal/cmd in your LSGS folder
//  3. Run:  npm install ws mysql2
//  4. Run:  node ws-server.js
//  5. Keep this terminal open while using the app
//
//  The server runs on ws://localhost:8080
// ============================================================

const WebSocket = require('ws');
const mysql     = require('mysql2/promise');

// ── MySQL connection pool ─────────────────────────────────────
const db = mysql.createPool({
  host:     process.env.MYSQLHOST     || 'localhost',
  user:     process.env.MYSQLUSER     || 'root',
  password: process.env.MYSQLPASSWORD || '',
  database: process.env.MYSQLDATABASE || 'lsgs_db',
  port:     process.env.MYSQLPORT     || 3306,
  waitForConnections: true,
  connectionLimit: 10
});

// ── WebSocket server ──────────────────────────────────────────
const PORT = process.env.PORT || 8080;
const wss  = new WebSocket.Server({ port: PORT, host: '0.0.0.0' }); // listen on all interfaces

// Track clients: Map<ws, { uid, gid, name }>
const clients = new Map();

console.log(`✅ LSGS WebSocket server running on port ${PORT}`);
console.log('   Keep this window open while using group chat.\n');

wss.on('connection', function(ws) {

  ws.on('message', async function(raw) {
    let data;
    try { data = JSON.parse(raw); } catch(e) { return; }

    // ── JOIN: register this client to a group ─────────────────
    if (data.type === 'join') {
      const { gid, uid, name } = data;

      // Verify membership in DB
      try {
        const [rows] = await db.execute(
          "SELECT status FROM group_members WHERE group_id=? AND student_id=?",
          [gid, uid]
        );
        if (!rows.length || rows[0].status !== 'active') {
          ws.send(JSON.stringify({ type:'error', text:'Not a member of this group.' }));
          ws.close();
          return;
        }
      } catch(e) {
        console.error('DB error on join:', e.message);
      }

      clients.set(ws, { uid: parseInt(uid), gid: parseInt(gid), name });
      console.log(`[JOIN] ${name} (uid:${uid}) joined group ${gid}`);

      // Notify other members
      broadcast(parseInt(gid), {
        type: 'system',
        text: `${name} joined the chat`
      }, ws);
    }

    // ── MESSAGE: save to DB, broadcast to group ───────────────
    if (data.type === 'message') {
      const client = clients.get(ws);
      if (!client) return;

      const { gid, uid, name } = client;
      const message = String(data.message || '').trim().slice(0, 2000);
      if (!message) return;

      // Save to database
      try {
        await db.execute(
          "INSERT INTO chat_messages (group_id, student_id, message) VALUES (?, ?, ?)",
          [gid, uid, message]
        );
      } catch(e) {
        console.error('DB error saving message:', e.message);
      }

      const payload = {
        type:        'message',
        uid:         uid,
        sender_name: name,
        initials:    name.split(' ').map(w => w[0]).join('').toUpperCase(),
        message:     message,
        sent_at:     new Date().toISOString()
      };

      // Broadcast to ALL group members (including sender — sender handles own display)
      broadcast(gid, payload, null);
      console.log(`[MSG] Group ${gid} | ${name}: ${message.slice(0,50)}`);
    }
  });

  ws.on('close', function() {
    const client = clients.get(ws);
    if (client) {
      broadcast(client.gid, {
        type: 'system',
        text: `${client.name} left the chat`
      }, ws);
      console.log(`[LEAVE] ${client.name} left group ${client.gid}`);
      clients.delete(ws);
    }
  });

  ws.on('error', function(e) {
    console.error('WS error:', e.message);
    clients.delete(ws);
  });
});

// ── Broadcast to all clients in a group ──────────────────────
function broadcast(gid, payload, exclude) {
  const msg = JSON.stringify(payload);
  clients.forEach(function(info, clientWs) {
    if (info.gid === gid && clientWs !== exclude && clientWs.readyState === WebSocket.OPEN) {
      clientWs.send(msg);
    }
  });
}

// ── Graceful shutdown ─────────────────────────────────────────
process.on('SIGINT', function() {
  console.log('\n⛔ Shutting down WebSocket server...');
  wss.close(function() {
    db.end();
    process.exit(0);
  });
});
