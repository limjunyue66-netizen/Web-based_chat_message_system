<?php
declare(strict_types=1);

function call_elapsed_seconds(array $session): ?int
{
    if (($session['status'] ?? '') !== 'accepted' || empty($session['answered_at']) || empty($session['id'])) {
        return null;
    }
    $statement = db()->prepare('SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, answered_at, NOW())) FROM voice_call_sessions WHERE id = ?');
    $statement->execute([(int) $session['id']]);
    return (int) $statement->fetchColumn();
}

function call_duration_seconds(int $sessionId): int
{
    $statement = db()->prepare(
        "SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, answered_at, ended_at))
         FROM voice_call_sessions
         WHERE id = ? AND answered_at IS NOT NULL AND ended_at IS NOT NULL"
    );
    $statement->execute([$sessionId]);
    $seconds = $statement->fetchColumn();
    return $seconds === false ? 0 : (int) $seconds;
}

function log_call_to_chat(array $session): void
{
    if (!empty($session['logged_message_id']) || empty($session['id'])) {
        return;
    }
    if (!in_array($session['status'] ?? '', ['ended', 'missed', 'rejected', 'cancelled'], true)) {
        return;
    }
    $sessionId = (int) $session['id'];
    try {
        db()->beginTransaction();
        $claim = db()->prepare(
            "UPDATE voice_call_sessions
             SET logged_message_id = 0
             WHERE id = ? AND logged_message_id IS NULL
               AND status IN ('ended', 'missed', 'rejected', 'cancelled')"
        );
        $claim->execute([$sessionId]);
        if ($claim->rowCount() === 0) {
            db()->commit();
            return;
        }
        $seconds = ($session['status'] === 'ended') ? call_duration_seconds($sessionId) : 0;
        $body = json_encode([
            'call_type' => ($session['call_type'] ?? '') === 'video' ? 'video' : 'voice',
            'status' => $session['status'],
            'seconds' => $seconds,
        ], JSON_UNESCAPED_UNICODE);
        $insert = db()->prepare(
            "INSERT INTO messages (sender_id, receiver_id, message_type, body, voice_duration, delivery_status)
             VALUES (?, ?, 'call', ?, ?, 'sent')"
        );
        $insert->execute([(int) $session['caller_id'], (int) $session['callee_id'], $body, $seconds]);
        $messageId = (int) db()->lastInsertId();
        $mark = db()->prepare('UPDATE voice_call_sessions SET logged_message_id = ? WHERE id = ? AND logged_message_id = 0');
        $mark->execute([$messageId, $sessionId]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }
}

function sweep_missed_calls(): void
{
    $statement = db()->query(
        "SELECT id, caller_id, callee_id, call_type FROM voice_call_sessions
         WHERE status = 'ringing' AND created_at < DATE_SUB(NOW(), INTERVAL 45 SECOND)"
    );
    foreach ($statement->fetchAll() as $row) {
        $update = db()->prepare("UPDATE voice_call_sessions SET status = 'missed', ended_at = NOW() WHERE id = ? AND status = 'ringing'");
        $update->execute([(int) $row['id']]);
        if ($update->rowCount() === 0) {
            continue;
        }
        $fresh = require_call_participant((int) $row['id'], (int) $row['caller_id']);
        log_call_to_chat($fresh);
        $caller = find_user((int) $row['caller_id']);
        create_notification(
            (int) $row['callee_id'],
            'missed_call',
            'Missed call',
            'You missed a ' . $row['call_type'] . ' call from ' . ($caller['username'] ?? 'someone') . '.',
            'chat.php?with=' . (int) $row['caller_id'],
            (int) $row['caller_id'],
            false
        );
    }
}

function user_active_call(int $userId): ?array
{
    $statement = db()->prepare(
        "SELECT * FROM voice_call_sessions
         WHERE status = 'accepted' AND (caller_id = ? OR callee_id = ?)
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute([$userId, $userId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function call_between(int $a, int $b): ?array
{
    $statement = db()->prepare(
        "SELECT * FROM voice_call_sessions
         WHERE status IN ('ringing', 'accepted')
           AND ((caller_id = ? AND callee_id = ?) OR (caller_id = ? AND callee_id = ?))
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute([$a, $b, $b, $a]);
    $row = $statement->fetch();
    return $row ?: null;
}

function require_call_participant(int $sessionId, int $userId): array
{
    $statement = db()->prepare('SELECT * FROM voice_call_sessions WHERE id = ? LIMIT 1');
    $statement->execute([$sessionId]);
    $session = $statement->fetch();
    if (!$session || ((int) $session['caller_id'] !== $userId && (int) $session['callee_id'] !== $userId)) {
        throw new AppException('Call not found.', 404);
    }
    return $session;
}

function call_payload(array $session, int $viewerId): array
{
    $peerId = (int) $session['caller_id'] === $viewerId ? (int) $session['callee_id'] : (int) $session['caller_id'];
    $peer = find_user($peerId);
    return [
        'id' => (int) $session['id'],
        'caller_id' => (int) $session['caller_id'],
        'callee_id' => (int) $session['callee_id'],
        'call_type' => $session['call_type'],
        'status' => $session['status'],
        'created_at' => $session['created_at'],
        'answered_at' => $session['answered_at'],
        'ended_at' => $session['ended_at'],
        'elapsed_seconds' => call_elapsed_seconds($session),
        'direction' => (int) $session['caller_id'] === $viewerId ? 'outgoing' : 'incoming',
        'peer' => $peer ? user_public($peer) : null,
    ];
}

function start_call(array $caller, int $calleeId, string $type): array
{
    if (!in_array($type, ['voice', 'video'], true)) {
        throw new AppException('Choose a voice or video call.');
    }
    $callerId = (int) $caller['id'];
    rate_limit('call', 'user:' . $callerId, 10, 600);
    sweep_missed_calls();
    assert_can_call($callerId, $calleeId);

    if (user_active_call($callerId)) {
        throw new AppException('You are already in a call.');
    }
    if (user_active_call($calleeId)) {
        throw new AppException('This user is busy on another call.');
    }

    $existing = call_between($callerId, $calleeId);
    if ($existing && (int) $existing['caller_id'] === $callerId && $existing['status'] === 'ringing') {
        return call_payload($existing, $callerId);
    }
    if ($existing && (int) $existing['callee_id'] === $callerId && $existing['status'] === 'ringing') {
        throw new AppException('This user is already calling you. Answer the incoming call.');
    }

    $pending = db()->prepare("SELECT id FROM voice_call_sessions WHERE caller_id = ? AND status = 'ringing'");
    $pending->execute([$callerId]);
    foreach ($pending->fetchAll() as $old) {
        $cancel = db()->prepare("UPDATE voice_call_sessions SET status = 'cancelled', ended_at = NOW() WHERE id = ? AND status = 'ringing'");
        $cancel->execute([(int) $old['id']]);
        if ($cancel->rowCount() > 0) {
            log_call_to_chat(require_call_participant((int) $old['id'], $callerId));
        }
    }

    $insert = db()->prepare('INSERT INTO voice_call_sessions (caller_id, callee_id, call_type, status) VALUES (?, ?, ?, \'ringing\')');
    $insert->execute([$callerId, $calleeId, $type]);
    $sessionId = (int) db()->lastInsertId();
    create_notification(
        $calleeId,
        'incoming_call',
        'Incoming call',
        $caller['username'] . ' is calling you.',
        'chat.php?with=' . $callerId,
        $callerId,
        false
    );
    $session = require_call_participant($sessionId, $callerId);
    return call_payload($session, $callerId);
}

function incoming_call_for(int $userId): ?array
{
    sweep_missed_calls();
    $statement = db()->prepare(
        "SELECT * FROM voice_call_sessions
         WHERE callee_id = ? AND status = 'ringing'
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute([$userId]);
    $session = $statement->fetch();
    return $session ? call_payload($session, $userId) : null;
}

function accept_call(int $userId, int $sessionId): array
{
    $session = require_call_participant($sessionId, $userId);
    if ((int) $session['callee_id'] !== $userId) {
        throw new AppException('Only the receiver can accept this call.', 403);
    }
    if ($session['status'] !== 'ringing') {
        throw new AppException('This call is no longer ringing.');
    }
    assert_can_contact((int) $session['caller_id'], $userId);
    $statement = db()->prepare("UPDATE voice_call_sessions SET status = 'accepted', answered_at = NOW() WHERE id = ? AND status = 'ringing'");
    $statement->execute([$sessionId]);
    return call_payload(require_call_participant($sessionId, $userId), $userId);
}

function reject_call(int $userId, int $sessionId): array
{
    $session = require_call_participant($sessionId, $userId);
    if ((int) $session['callee_id'] !== $userId || $session['status'] !== 'ringing') {
        throw new AppException('This call cannot be rejected.');
    }
    $statement = db()->prepare("UPDATE voice_call_sessions SET status = 'rejected', ended_at = NOW() WHERE id = ?");
    $statement->execute([$sessionId]);
    $fresh = require_call_participant($sessionId, $userId);
    log_call_to_chat($fresh);
    return call_payload($fresh, $userId);
}

function cancel_call(int $userId, int $sessionId): array
{
    $session = require_call_participant($sessionId, $userId);
    if ((int) $session['caller_id'] !== $userId || $session['status'] !== 'ringing') {
        throw new AppException('This call cannot be cancelled.');
    }
    $statement = db()->prepare("UPDATE voice_call_sessions SET status = 'cancelled', ended_at = NOW() WHERE id = ?");
    $statement->execute([$sessionId]);
    $fresh = require_call_participant($sessionId, $userId);
    log_call_to_chat($fresh);
    return call_payload($fresh, $userId);
}

function end_call(int $userId, int $sessionId): array
{
    $session = require_call_participant($sessionId, $userId);
    if (!in_array($session['status'], ['ringing', 'accepted'], true)) {
        return call_payload($session, $userId);
    }
    $next = (int) $session['caller_id'] === $userId && $session['status'] === 'ringing' ? 'cancelled' : 'ended';
    if ((int) $session['callee_id'] === $userId && $session['status'] === 'ringing') {
        $next = 'rejected';
    }
    $statement = db()->prepare('UPDATE voice_call_sessions SET status = ?, ended_at = NOW() WHERE id = ?');
    $statement->execute([$next, $sessionId]);
    add_call_signal($userId, $sessionId, 'hangup', ['reason' => $next]);
    $fresh = require_call_participant($sessionId, $userId);
    log_call_to_chat($fresh);
    return call_payload($fresh, $userId);
}

function add_call_signal(int $userId, int $sessionId, string $signalType, mixed $payload): array
{
    if (!in_array($signalType, ['offer', 'answer', 'ice', 'hangup', 'control'], true)) {
        throw new AppException('Unsupported signal.');
    }
    $session = require_call_participant($sessionId, $userId);
    if (!in_array($session['status'], ['ringing', 'accepted'], true) && $signalType !== 'hangup') {
        throw new AppException('This call is no longer active.');
    }
    if ($signalType === 'offer' && (int) $session['caller_id'] !== $userId) {
        throw new AppException('Only the caller can send an offer.', 403);
    }
    if ($signalType === 'answer' && (int) $session['callee_id'] !== $userId) {
        throw new AppException('Only the receiver can send an answer.', 403);
    }

    $encoded = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 200000) {
        throw new AppException('The call signal is invalid.');
    }
    if ($signalType === 'answer' && $session['status'] === 'ringing') {
        $accept = db()->prepare("UPDATE voice_call_sessions SET status = 'accepted', answered_at = COALESCE(answered_at, NOW()) WHERE id = ?");
        $accept->execute([$sessionId]);
    }

    $insert = db()->prepare('INSERT INTO voice_call_signals (session_id, sender_id, signal_type, payload) VALUES (?, ?, ?, ?)');
    $insert->execute([$sessionId, $userId, $signalType, $encoded]);
    return ['id' => (int) db()->lastInsertId()];
}

function call_signals_after(int $userId, int $sessionId, int $afterId): array
{
    $session = require_call_participant($sessionId, $userId);
    $statement = db()->prepare(
        'SELECT id, sender_id, signal_type, payload, created_at
         FROM voice_call_signals
         WHERE session_id = ? AND id > ? AND sender_id <> ?
         ORDER BY id ASC
         LIMIT 50'
    );
    $statement->execute([$sessionId, $afterId, $userId]);
    $signals = [];
    foreach ($statement->fetchAll() as $row) {
        $decoded = json_decode((string) $row['payload'], true);
        $signals[] = [
            'id' => (int) $row['id'],
            'sender_id' => (int) $row['sender_id'],
            'signal_type' => $row['signal_type'],
            'payload' => $decoded === null ? $row['payload'] : $decoded,
            'created_at' => $row['created_at'],
        ];
    }
    return [
        'session' => call_payload($session, $userId),
        'signals' => $signals,
    ];
}
