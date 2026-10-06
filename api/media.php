<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

try {
    $type = (string) ($_GET['type'] ?? '');
    if ($type === 'initials') {
        $name = (string) ($_GET['name'] ?? 'WC');
        $clean = preg_replace('/[^A-Za-z0-9 ]/', '', $name) ?? '';
        $parts = preg_split('/\s+/', trim($clean)) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= strtoupper($part[0]);
            if (strlen($letters) >= 2) {
                break;
            }
        }
        if ($letters === '') {
            $letters = 'WC';
        }
        $palette = ['#e4572e', '#c2410c', '#059669', '#d97706', '#e11d48', '#0f766e'];
        $index = (int) (sprintf('%u', crc32($letters)) % count($palette));
        $color = $palette[$index];
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">';
        echo '<rect width="128" height="128" rx="64" fill="' . $color . '"/>';
        echo '<text x="64" y="78" text-anchor="middle" font-family="Segoe UI, sans-serif" font-size="48" font-weight="700" fill="#ffffff">' . $letters . '</text>';
        echo '</svg>';
        exit;
    }

    $user = require_api_user();
    $userId = (int) $user['id'];
    $download = isset($_GET['download']) && (string) $_GET['download'] === '1';

    if ($type === 'avatar') {
        $owner = find_user((int) ($_GET['user'] ?? 0));
        if (!$owner || empty($owner['avatar_path'])) {
            throw new AppException('File not found.', 404);
        }
        stream_upload((string) $owner['avatar_path'], 'avatar', true);
    }

    if ($type === 'group_avatar') {
        $groupId = (int) ($_GET['group'] ?? 0);
        require_group_member($groupId, $userId);
        $group = find_group($groupId);
        if (!$group || empty($group['avatar_path'])) {
            throw new AppException('File not found.', 404);
        }
        stream_upload((string) $group['avatar_path'], 'group', true);
    }

    if ($type === 'message') {
        $statement = db()->prepare('SELECT * FROM messages WHERE id = ? LIMIT 1');
        $statement->execute([(int) ($_GET['id'] ?? 0)]);
        $message = $statement->fetch();
        if (!$message || (int) $message['is_deleted'] === 1 || empty($message['file_path'])) {
            throw new AppException('File not found.', 404);
        }
        $participants = [(int) $message['sender_id'], (int) $message['receiver_id']];
        if (!in_array($userId, $participants, true) || is_blocked_either((int) $message['sender_id'], (int) $message['receiver_id'])) {
            throw new AppException('You do not have permission to view this file.', 403);
        }
        $mime = (string) ($message['mime_type'] ?? '');
        $inline = !$download && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || $mime === 'video/webm');
        stream_upload((string) $message['file_path'], (string) ($message['file_name'] ?: 'download'), $inline);
    }

    if ($type === 'group_message') {
        $statement = db()->prepare('SELECT * FROM group_messages WHERE id = ? LIMIT 1');
        $statement->execute([(int) ($_GET['id'] ?? 0)]);
        $message = $statement->fetch();
        if (!$message || (int) $message['is_deleted'] === 1 || empty($message['file_path'])) {
            throw new AppException('File not found.', 404);
        }
        require_group_member((int) $message['group_id'], $userId);
        $mime = (string) ($message['mime_type'] ?? '');
        $inline = !$download && (str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || $mime === 'video/webm');
        stream_upload((string) $message['file_path'], (string) ($message['file_name'] ?: 'download'), $inline);
    }

    throw new AppException('File not found.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
