<?php
// ============================================================
//  LSGS  |  chat_send.php — HTTP fallback for chat messages
//  Called by chat.php when WebSocket server is offline
// ============================================================
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

require_once __DIR__.'/db.php';

$uid = (int)$_SESSION['user']['id'];
$gid = (int)($_POST['gid'] ?? 0);
$msg = trim($_POST['msg'] ?? '');

if (!$gid || !$msg) { echo json_encode(['ok'=>false,'error'=>'Missing data']); exit; }

// Verify membership
$chk = $conn->prepare("SELECT status FROM group_members WHERE group_id=? AND student_id=?");
$chk->bind_param('ii',$gid,$uid); $chk->execute();
$mem = $chk->get_result()->fetch_assoc();
if (!$mem || $mem['status'] !== 'active') {
    echo json_encode(['ok'=>false,'error'=>'Not a member']); exit;
}

// Save message
$st = $conn->prepare("INSERT INTO chat_messages (group_id,student_id,message) VALUES (?,?,?)");
$st->bind_param('iis',$gid,$uid,$msg); $st->execute();

echo json_encode(['ok'=>true,'id'=>$conn->insert_id]);
