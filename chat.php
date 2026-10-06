<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_page_user();
$userId = (int) $user['id'];
$with = (int) ($_GET['with'] ?? 0);
$conversations = list_conversations($userId);

$peer = null;
$initialMessages = [];
$canMessage = false;
if ($with > 0) {
    try {
        $other = find_user($with);
        if ($other && !is_blocked_either($userId, $with)) {
            $peer = user_public($other);
            $canMessage = are_friends($userId, $with);
            $initialMessages = private_history($userId, $with, 0, 50);
        } else {
            $with = 0;
        }
    } catch (AppException $exception) {
        flash_set('danger', $exception->getMessage());
        $with = 0;
        $peer = null;
        $initialMessages = [];
    }
}

$pageTitle = 'Messages';
$activeNav = 'chat';
$fullBleed = true;
$pageScript = 'chat.js';
$bootExtra = [
    'chat' => [
        'peerId' => $with,
        'peer' => $peer,
        'canMessage' => $canMessage,
        'messages' => $initialMessages,
    ],
];
require __DIR__ . '/includes/layout_start.php';
?>
<div class="chat-layout<?= $with > 0 ? ' is-thread' : '' ?>" id="chat-app" data-mode="private" data-peer-id="<?= $with > 0 ? (int) $with : '0' ?>">
    <section class="pane pane-list">
        <header class="pane-head">
            <h2>Conversations</h2>
            <label class="visually-hidden" for="conversation-search">Search conversations</label>
            <input id="conversation-search" type="search" placeholder="Search conversations" autocomplete="off">
        </header>
        <div id="conversation-list" class="list-scroll" role="list">
            <?php if ($conversations === []): ?>
                <div class="empty-state">
                    <img class="empty-visual" src="<?= e(app_url('assets/images/empty-inbox.svg')) ?>" alt="">
                    <strong>No conversations yet</strong>
                    <p>Message a friend from the Friends page to begin.</p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $item): ?>
                    <?php
                    $person = $item['user'];
                    $peerId = (int) $person['id'];
                    $active = $with === $peerId;
                    ?>
                    <a class="conversation<?= $active ? ' is-active' : '' ?>"
                       role="listitem"
                       href="<?= e(app_url('chat.php?with=' . $peerId)) ?>"
                       data-user="<?= $peerId ?>">
                        <img src="<?= e($person['avatar_url']) ?>" alt="">
                        <span>
                            <strong><?= e($person['username']) ?></strong>
                            <p><?= e($item['last_message']) ?></p>
                        </span>
                        <span>
                            <?php if ((int) $item['unread'] > 0): ?>
                                <em class="nav-badge"><?= (int) $item['unread'] ?></em>
                            <?php elseif (!empty($item['last_label'])): ?>
                                <small><?= e($item['last_label']) ?></small>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
    <section class="pane pane-thread">
        <header class="pane-head thread-head">
            <button type="button" class="icon-btn mobile-only" id="chat-back" aria-label="Back to conversations"><i class="fa-solid fa-arrow-left"></i></button>
            <div class="thread-title" id="thread-title" role="button" tabindex="0" title="Contact info">
                <img id="thread-avatar" alt="" <?= $peer ? 'src="' . e($peer['avatar_url']) . '"' : 'hidden' ?>>
                <div>
                    <h2 id="thread-name"><?= $peer ? e($peer['username']) : 'Select a conversation' ?></h2>
                    <p id="thread-presence" class="<?= $peer ? 'presence presence-' . e($peer['presence']) : '' ?>"><?= $peer ? e($peer['last_seen_label']) : '' ?></p>
                </div>
            </div>
            <div class="thread-actions">
                <button type="button" class="icon-btn icon-chip chip-call" id="start-voice" <?= $peer && $canMessage ? '' : 'hidden' ?> aria-label="Voice call"><i class="fa-solid fa-phone"></i></button>
                <button type="button" class="icon-btn icon-chip chip-video" id="start-video" <?= $peer && $canMessage ? '' : 'hidden' ?> aria-label="Video call"><i class="fa-solid fa-video"></i></button>
                <div class="thread-menu-wrap" id="thread-menu-wrap" <?= $peer ? '' : 'hidden' ?>>
                    <button type="button" class="icon-btn" id="thread-menu-btn" aria-label="Chat menu" aria-haspopup="true" aria-expanded="false"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                    <div id="thread-menu" class="thread-menu d-none" role="menu">
                        <button type="button" role="menuitem" data-thread-action="contact"><i class="fa-solid fa-circle-info"></i> Contact info</button>
                        <button type="button" role="menuitem" data-thread-action="select"><i class="fa-regular fa-square-check"></i> Select messages</button>
                        <button type="button" role="menuitem" data-thread-action="clear"><i class="fa-solid fa-broom"></i> Clear chat</button>
                        <button type="button" role="menuitem" data-thread-action="delete-chat" class="is-danger"><i class="fa-solid fa-trash"></i> Delete chat</button>
                        <button type="button" role="menuitem" data-thread-action="block" class="is-danger"><i class="fa-solid fa-ban"></i> Block</button>
                        <button type="button" role="menuitem" data-thread-action="report" class="is-danger"><i class="fa-solid fa-flag"></i> Report</button>
                    </div>
                </div>
            </div>
        </header>
        <div id="select-bar" class="select-bar d-none" hidden>
            <button type="button" class="icon-btn" id="select-cancel" aria-label="Cancel selection"><i class="fa-solid fa-xmark"></i></button>
            <span id="select-count">0 selected</span>
            <button type="button" class="icon-btn" id="select-delete" aria-label="Delete selected"><i class="fa-solid fa-trash"></i></button>
        </div>
        <div id="message-list" class="message-scroll" aria-live="polite">
            <?php if ($peer === null): ?>
                <div class="empty-state empty-state-thread">
                    <img class="empty-visual" src="<?= e(app_url('assets/images/empty-chat.svg')) ?>" alt="">
                    <strong>Pick a conversation</strong>
                    <p>Select someone on the left to read and send messages.</p>
                </div>
            <?php elseif ($initialMessages === []): ?>
                <div class="empty-state empty-state-thread">
                    <img class="empty-visual" src="<?= e(app_url('assets/images/empty-chat.svg')) ?>" alt="">
                    <strong><?= $canMessage ? 'Say hello' : 'Friendship required' ?></strong>
                    <p><?= $canMessage ? 'No messages yet. Send the first one.' : 'You can read this conversation, but new messages require a friendship.' ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($initialMessages as $message): ?>
                    <?php
                    $mine = (int) $message['sender_id'] === $userId;
                    $deleted = !empty($message['is_deleted']);
                    ?>
                    <article class="message<?= $mine ? ' mine' : '' ?>" data-id="<?= (int) $message['id'] ?>">
                        <img class="avatar" src="<?= e($message['sender_avatar']) ?>" alt="">
                        <div class="bubble-wrap">
                            <div class="msg-bubble">
                                <?php if (!$mine): ?>
                                    <div class="sender-name"><?= e($message['sender_username']) ?></div>
                                <?php endif; ?>
                                <?php if ($deleted): ?>
                                    <p class="deleted">This message was deleted</p>
                                <?php else: ?>
                                    <?php if (!empty($message['reply_to_id'])): ?>
                                        <button type="button" class="reply-quote<?= !empty($message['reply_image_url']) ? ' has-thumb' : '' ?>" data-jump-to="<?= (int) $message['reply_to_id'] ?>" title="Go to message">
                                            <span class="reply-quote-copy">
                                                <?php if (empty($message['reply_deleted']) && !empty($message['reply_sender'])): ?>
                                                    <strong class="reply-quote-name"><?= e($message['reply_sender']) ?></strong>
                                                <?php endif; ?>
                                                <span class="reply-quote-text">
                                                    <?php if (empty($message['reply_deleted']) && ($message['reply_type'] ?? '') === 'image'): ?>
                                                        <i class="fa-solid fa-camera" aria-hidden="true"></i>
                                                    <?php endif; ?>
                                                    <?= e(!empty($message['reply_deleted']) ? 'Deleted message' : ($message['reply_excerpt'] ?? 'Message')) ?>
                                                </span>
                                            </span>
                                            <?php if (!empty($message['reply_image_url'])): ?>
                                                <img class="reply-quote-thumb" src="<?= e($message['reply_image_url']) ?>" alt="">
                                            <?php endif; ?>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (($message['message_type'] ?? '') === 'image' && !empty($message['file_url'])): ?>
                                        <img class="preview" src="<?= e($message['file_url']) ?>" alt="<?= e($message['file_name'] ?? 'Image') ?>">
                                    <?php elseif (($message['message_type'] ?? '') === 'file' && !empty($message['file_url'])): ?>
                                        <p><a href="<?= e($message['file_url']) ?>&download=1"><?= e($message['file_name'] ?? 'Download file') ?></a><?php if (!empty($message['file_size_label'])): ?> · <?= e($message['file_size_label']) ?><?php endif; ?></p>
                                    <?php elseif (($message['message_type'] ?? '') === 'voice' && !empty($message['file_url'])): ?>
                                        <audio controls preload="metadata" src="<?= e($message['file_url']) ?>"></audio>
                                    <?php endif; ?>
                                    <?php if (!empty($message['body'])): ?>
                                        <p><?= nl2br(e($message['body'])) ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="msg-meta"><span><?= e($message['created_at'] ?? '') ?></span></div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form id="composer" class="composer" autocomplete="off" action="#" method="post" onsubmit="return false;">
            <div id="reply-bar" class="reply-bar d-none">
                <div>
                    <strong>Replying to</strong>
                    <p id="reply-excerpt"></p>
                </div>
                <button type="button" id="reply-cancel" aria-label="Cancel reply">&times;</button>
            </div>
            <div id="attach-preview" class="attach-preview d-none">
                <img id="attach-preview-img" alt="" hidden>
                <div class="attach-preview-copy">
                    <strong id="attach-preview-name">Attachment</strong>
                    <span id="attach-preview-meta"></span>
                </div>
                <button type="button" id="attach-preview-clear" aria-label="Remove attachment">&times;</button>
            </div>
            <div id="voice-bar" class="voice-bar d-none">
                <span><i class="fa-solid fa-circle text-danger"></i> Recording <strong id="voice-timer">0:00</strong></span>
                <button type="button" class="btn btn-light btn-sm" id="voice-cancel">Cancel</button>
                <button type="button" class="btn btn-accent btn-sm" id="voice-send">Send</button>
            </div>
            <div class="composer-row" id="composer-row">
                <button type="button" class="icon-btn icon-chip chip-emoji" id="emoji-toggle" aria-label="Insert emoji" <?= $canMessage || $peer === null ? '' : 'disabled' ?>><i class="fa-regular fa-face-smile"></i></button>
                <label class="icon-btn icon-chip chip-image" for="image-input" aria-label="Add image"><i class="fa-solid fa-image"></i></label>
                <input id="image-input" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp" hidden <?= $canMessage || $peer === null ? '' : 'disabled' ?>>
                <label class="icon-btn icon-chip chip-file" for="file-input" aria-label="Add file"><i class="fa-solid fa-paperclip"></i></label>
                <input id="file-input" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" hidden <?= $canMessage || $peer === null ? '' : 'disabled' ?>>
                <label class="visually-hidden" for="message-input">Message</label>
                <textarea id="message-input" rows="1" maxlength="4000" placeholder="<?= $peer && !$canMessage ? 'Add this person as a friend to send messages' : 'Type a message' ?>" <?= $canMessage || $peer === null ? '' : 'disabled' ?>></textarea>
                <button type="button" class="icon-btn icon-chip chip-mic" id="voice-toggle" aria-label="Record voice message" <?= $canMessage || $peer === null ? '' : 'disabled' ?>><i class="fa-solid fa-microphone"></i></button>
                <button type="button" class="send-btn" id="send-btn" aria-label="Send message" <?= $canMessage || $peer === null ? '' : 'disabled' ?>><i class="fa-solid fa-paper-plane"></i></button>
            </div>
            <div id="emoji-picker" class="emoji-picker d-none"></div>
        </form>
    </section>
    <aside class="pane pane-info" id="contact-pane">
        <?php if ($peer): ?>
            <div class="info-pane-head">
                <strong>Profile</strong>
                <button type="button" class="icon-btn" data-info-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="info-card info-profile">
                <img class="info-avatar" src="<?= e($peer['avatar_url']) ?>" alt="">
                <h2><?= e($peer['username']) ?></h2>
                <p class="presence presence-<?= e($peer['presence']) ?>"><?= e($peer['last_seen_label']) ?></p>
                <div class="info-section">
                    <strong>About</strong>
                    <p><?= e($peer['status_message'] !== '' ? $peer['status_message'] : 'No status message yet.') ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="info-empty">Choose a conversation to see their profile.</div>
        <?php endif; ?>
    </aside>
</div>
<script>
window.WC_BOOT = Object.assign(window.WC_BOOT || {}, <?= json_encode($bootExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>);
</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
