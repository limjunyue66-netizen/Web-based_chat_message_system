(function () {
    var root = document.getElementById('chat-app');
    if (!root || !window.WC_BOOT || !WC_BOOT.me) {
        return;
    }

    var mode = root.getAttribute('data-mode') === 'group' ? 'group' : 'private';
    var listEl = document.getElementById('conversation-list');
    var messagesEl = document.getElementById('message-list');
    var typingEl = document.getElementById('typing-indicator');
    var composer = document.getElementById('composer');
    var input = document.getElementById('message-input');
    var replyBar = document.getElementById('reply-bar');
    var replyExcerpt = document.getElementById('reply-excerpt');
    var emojiPicker = document.getElementById('emoji-picker');
    var voiceBar = document.getElementById('voice-bar');
    var voiceTimer = document.getElementById('voice-timer');
    var threadName = document.getElementById('thread-name');
    var threadPresence = document.getElementById('thread-presence');
    var threadAvatar = document.getElementById('thread-avatar');
    var contactPane = document.getElementById('contact-pane');
    var sendBtn = document.getElementById('send-btn');
    var attachPreview = document.getElementById('attach-preview');
    var attachPreviewImg = document.getElementById('attach-preview-img');
    var attachPreviewName = document.getElementById('attach-preview-name');
    var attachPreviewMeta = document.getElementById('attach-preview-meta');
    var attachPreviewClear = document.getElementById('attach-preview-clear');
    var me = WC_BOOT.me;
    var bootChat = WC_BOOT.chat || {};
    var emojis = ['😀','😁','😂','😊','😍','😎','😢','😮','👍','👎','❤️','🔥','🎉','🙏','👏','✨'];
    var reactions = ['👍','❤️','😂','😮','😢','👏'];

    function assetUrl(path) {
        return WC.buildUrl(path);
    }

    function emptyStateHtml(imageName, title, text, extraClass) {
        return '<div class="empty-state' + (extraClass ? ' ' + extraClass : '') + '">' +
            '<img class="empty-visual" src="' + WC.escape(assetUrl('assets/images/' + imageName)) + '" alt="">' +
            '<strong>' + WC.escape(title) + '</strong>' +
            '<p>' + WC.escape(text) + '</p>' +
            '</div>';
    }

    function peerFromPage() {
        var fromData = Number(root.getAttribute('data-peer-id') || 0);
        var fromBoot = Number(bootChat.peerId || 0);
        var fromUrl = 0;
        try {
            fromUrl = Number(new URLSearchParams(window.location.search).get('with') || 0);
        } catch (error) {
            fromUrl = 0;
        }
        return fromData || fromBoot || fromUrl || 0;
    }

    var state = {
        peerId: mode === 'private' ? peerFromPage() : 0,
        groupId: Number(root.getAttribute('data-group') || 0),
        messages: {},
        reply: null,
        forwardMessage: null,
        canMessage: mode === 'group' ? true : !!bootChat.canMessage,
        selecting: false,
        selected: {},
        deleteIntent: null,
        stick: true,
        pollTimer: null,
        inboxTimer: null,
        inboxPollTimer: null,
        polling: false,
        lastTitle: document.title,
        titleTimer: null,
        inboxUnread: {},
        inboxReady: false,
        typingTimer: null,
        recorder: null,
        recordStream: null,
        chunks: [],
        recordStarted: 0,
        recordTick: null,
        peerSignature: '',
        sending: false,
        pending: null,
        presenceLabel: threadPresence ? (threadPresence.textContent || '') : '',
        presenceClass: threadPresence ? (threadPresence.className || '') : ''
    };

    function messageIds() {
        return Object.keys(state.messages).map(function (id) { return Number(id); });
    }

    function messageCount() {
        return messageIds().length;
    }

    function maxMessageId() {
        var ids = messageIds();
        if (!ids.length) {
            return 0;
        }
        return Math.max.apply(null, ids);
    }

    function setHidden(el, hidden) {
        if (!el) {
            return;
        }
        if (hidden) {
            el.setAttribute('hidden', 'hidden');
        } else {
            el.removeAttribute('hidden');
        }
    }

    function clock(value) {
        var date = new Date(String(value || '').replace(' ', 'T'));
        if (isNaN(date.getTime())) {
            return '';
        }
        try {
            return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        } catch (error) {
            return date.getHours() + ':' + String(date.getMinutes()).replace(/^(\d)$/, '0$1');
        }
    }

    function linkify(text) {
        return WC.escape(text).replace(/https?:\/\/[^\s<]+/g, function (url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
        }).replace(/\n/g, '<br>');
    }

    function ticks(status) {
        if (status === 'read') return '<span class="ticks is-read" title="Read">✓✓ Read</span>';
        if (status === 'delivered') return '<span class="ticks" title="Delivered">✓✓</span>';
        return '<span class="ticks" title="Sent">✓</span>';
    }

    function currentPeerId() {
        return Number(state.peerId || peerFromPage() || 0);
    }

    function active() {
        return mode === 'group' ? state.groupId > 0 : currentPeerId() > 0;
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function replyLabel(message) {
        if (!message || message.is_deleted) {
            return 'Deleted message';
        }
        if (message.body) {
            return message.body;
        }
        if (message.message_type === 'image') {
            return 'Photo';
        }
        if (message.message_type === 'voice') {
            return 'Voice message';
        }
        if (message.message_type === 'file') {
            return message.file_name || 'File';
        }
        return message.message_type || 'Message';
    }

    function replyQuoteIcon(type) {
        if (type === 'image') {
            return '<i class="fa-solid fa-camera" aria-hidden="true"></i> ';
        }
        if (type === 'voice') {
            return '<i class="fa-solid fa-microphone" aria-hidden="true"></i> ';
        }
        if (type === 'file') {
            return '<i class="fa-solid fa-paperclip" aria-hidden="true"></i> ';
        }
        return '';
    }

    function renderReplyQuote(message) {
        if (!message.reply_to_id) {
            return '';
        }
        var deleted = !!message.reply_deleted;
        var name = deleted ? '' : (message.reply_sender || '');
        var excerpt = deleted ? 'Deleted message' : (message.reply_excerpt || 'Message');
        var type = deleted ? '' : (message.reply_type || '');
        var thumb = (!deleted && message.reply_image_url)
            ? '<img class="reply-quote-thumb" src="' + WC.escape(message.reply_image_url) + '" alt="">'
            : '';
        return '<button type="button" class="reply-quote' + (thumb ? ' has-thumb' : '') + '" data-jump-to="' + Number(message.reply_to_id) + '" title="Go to message">' +
            '<span class="reply-quote-copy">' +
            (name ? '<strong class="reply-quote-name">' + WC.escape(name) + '</strong>' : '') +
            '<span class="reply-quote-text">' + replyQuoteIcon(type) + WC.escape(excerpt) + '</span>' +
            '</span>' + thumb +
            '</button>';
    }

    function highlightMessage(node) {
        if (!node) {
            return;
        }
        node.classList.remove('jump-highlight');
        void node.offsetWidth;
        node.classList.add('jump-highlight');
        window.setTimeout(function () {
            node.classList.remove('jump-highlight');
        }, 1600);
    }

    function findMessageNode(id) {
        return messagesEl ? messagesEl.querySelector('.message[data-id="' + Number(id) + '"]') : null;
    }

    function jumpToMessage(id) {
        var targetId = Number(id || 0);
        if (!targetId || !messagesEl) {
            return Promise.resolve(false);
        }

        function focusNode(node) {
            if (!node) {
                return false;
            }
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
            highlightMessage(node);
            return true;
        }

        var existing = findMessageNode(targetId);
        if (existing) {
            return Promise.resolve(focusNode(existing));
        }
        if (state.messages[targetId]) {
            state.stick = false;
            renderMessages();
            return Promise.resolve(focusNode(findMessageNode(targetId)));
        }

        WC.toast('Loading original message...');
        var attempts = 0;
        function step() {
            if (focusNode(findMessageNode(targetId))) {
                return Promise.resolve(true);
            }
            var ids = messageIds().sort(function (a, b) { return a - b; });
            if (!ids.length || attempts >= 12) {
                WC.toast('Original message is not available in this chat view.');
                return Promise.resolve(false);
            }
            attempts += 1;
            return loadOlder(ids[0]).then(function (result) {
                var batch = (result && result.data && result.data.messages) || [];
                if (!batch.length) {
                    WC.toast('Original message is not available in this chat view.');
                    return false;
                }
                return step();
            });
        }
        return step();
    }

    function renderMessage(message) {
        var mine = Number(message.sender_id) === Number(me.id);
        var article = document.createElement('article');
        article.className = 'message' + (mine ? ' mine' : '') + (state.selected[message.id] ? ' is-selected' : '');
        article.setAttribute('data-id', String(message.id));
        var check = '<button type="button" class="message-check" data-select-toggle aria-label="Select message">' +
            (state.selected[message.id] ? '<i class="fa-solid fa-check"></i>' : '') +
            '</button>';
        var avatar = '<img class="avatar" src="' + WC.escape(message.sender_avatar) + '" alt="">';
        var body = '';
        if (message.is_deleted) {
            body = '<p class="deleted">This message was deleted</p>';
        } else {
            body += renderReplyQuote(message);
            if (message.message_type === 'image' && message.file_url) {
                body += '<img class="preview" src="' + WC.escape(message.file_url) + '" alt="' + WC.escape(message.file_name || 'Image') + '">';
            } else if (message.message_type === 'file' && message.file_url) {
                body += '<p><a href="' + WC.escape(message.file_url) + '&download=1">' + WC.escape(message.file_name || 'Download file') + '</a>';
                if (message.file_size_label) body += ' · ' + WC.escape(message.file_size_label);
                body += '</p>';
            } else if (message.message_type === 'voice' && message.file_url) {
                body += '<audio controls preload="metadata" src="' + WC.escape(message.file_url) + '"></audio>';
                if (message.voice_duration) body += '<small>' + WC.escape(message.voice_duration) + 's</small>';
            } else if (message.message_type === 'call' && message.call_log) {
                var log = message.call_log;
                var icon = log.missed
                    ? (log.kind === 'video' ? 'fa-video-slash' : 'fa-phone-slash')
                    : (log.kind === 'video' ? 'fa-video' : 'fa-phone');
                body += '<button type="button" class="call-log-card' + (log.missed ? ' is-missed' : '') + '" data-recall="' + WC.escape(log.kind) + '">' +
                    '<i class="fa-solid ' + icon + '" aria-hidden="true"></i><span><strong>' + WC.escape(log.title) + '</strong>' +
                    (log.detail ? '<small>' + WC.escape(log.detail) + '</small>' : '') +
                    '</span></button>';
            }
            if (message.body) body += '<p>' + linkify(message.body) + '</p>';
        }
        var reactionHtml = (message.reactions || []).map(function (item) {
            return '<button type="button" data-react="' + WC.escape(item.emoji) + '">' + WC.escape(item.emoji) + ' ' + Number(item.count) + '</button>';
        }).join('');
        var seen = mode === 'group' && mine && !message.is_deleted ? '<span>Seen by ' + Number(message.seen_count || 0) + '</span>' : '';
        var status = mode === 'private' && mine && !message.is_deleted ? ticks(message.delivery_status) : '';
        article.innerHTML = check + avatar + '<div class="bubble-wrap"><div class="msg-bubble">' +
            (!mine ? '<div class="sender-name">' + WC.escape(message.sender_username) + '</div>' : '') +
            body + '<div class="msg-meta"><span>' + WC.escape(clock(message.created_at)) + '</span>' + status + seen + '</div></div>' +
            '<div class="msg-actions-row">' + reactionHtml + '</div></div>';

        var reactButtons = article.querySelectorAll('[data-react]');
        Array.prototype.forEach.call(reactButtons, function (button) {
            button.addEventListener('click', function (event) {
                if (state.selecting) {
                    event.preventDefault();
                    toggleSelectMessage(message.id);
                    return;
                }
                react(message.id, button.getAttribute('data-react'));
            });
        });
        var quoteBtn = article.querySelector('[data-jump-to]');
        if (quoteBtn) {
            quoteBtn.addEventListener('click', function (event) {
                if (state.selecting) {
                    event.preventDefault();
                    toggleSelectMessage(message.id);
                    return;
                }
                event.preventDefault();
                jumpToMessage(quoteBtn.getAttribute('data-jump-to'));
            });
        }
        var recall = article.querySelector('[data-recall]');
        if (recall) {
            recall.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (state.selecting) {
                    toggleSelectMessage(message.id);
                    return;
                }
                if (mode === 'private' && state.peerId && window.WC && WC.calls) {
                    WC.calls.start(state.peerId, recall.getAttribute('data-recall') === 'video' ? 'video' : 'voice');
                }
            });
        }
        if (!message.is_deleted) {
            article.addEventListener('contextmenu', function (event) {
                if (state.selecting) {
                    event.preventDefault();
                    toggleSelectMessage(message.id);
                    return;
                }
                event.preventDefault();
                openMessageMenu(message, event.clientX, event.clientY);
            });
        }
        article.addEventListener('click', function (event) {
            if (!state.selecting) {
                return;
            }
            var target = event.target;
            if (target && target.closest && target.closest('a, audio, video, button:not([data-select-toggle])')) {
                event.preventDefault();
            }
            toggleSelectMessage(message.id);
        });
        return article;
    }

    function renderMessages() {
        var previous = messagesEl.scrollHeight - messagesEl.scrollTop;
        var rows = messageIds().sort(function (a, b) { return a - b; }).map(function (id) {
            return state.messages[id];
        });
        messagesEl.innerHTML = '';
        if (!active()) {
            messagesEl.innerHTML = emptyStateHtml('empty-chat.svg', 'Pick a conversation', 'Select someone on the left to read and send messages.', 'empty-state-thread');
            return;
        }
        if (rows.length >= 50) {
            var older = document.createElement('button');
            older.type = 'button';
            older.className = 'older-btn';
            older.textContent = 'Load earlier messages';
            older.addEventListener('click', function () { loadOlder(rows[0].id); });
            messagesEl.appendChild(older);
        }
        if (!rows.length) {
            var empty = document.createElement('div');
            empty.innerHTML = emptyStateHtml(
                'empty-chat.svg',
                state.canMessage ? 'Say hello' : 'Friendship required',
                state.canMessage ? 'No messages yet. Send the first one.' : 'You can read this conversation, but new messages require a friendship.',
                'empty-state-thread'
            );
            messagesEl.appendChild(empty.firstChild);
        }
        rows.forEach(function (message) {
            messagesEl.appendChild(renderMessage(message));
        });
        if (state.stick) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        } else {
            messagesEl.scrollTop = messagesEl.scrollHeight - previous;
        }
    }

    function upsert(messages, stick) {
        (messages || []).forEach(function (message) {
            state.messages[Number(message.id)] = message;
        });
        if (stick !== undefined) {
            state.stick = stick;
        }
        renderMessages();
    }

    function setReply(message) {
        state.reply = message;
        replyExcerpt.textContent = replyLabel(message);
        replyBar.classList.remove('d-none');
        if (input) {
            input.focus();
        }
    }

    var contextMenu = document.getElementById('msg-context-menu');
    var contextReacts = document.getElementById('msg-context-reacts');
    var contextItems = document.getElementById('msg-context-items');
    var forwardModal = document.getElementById('forward-modal');
    var forwardList = document.getElementById('forward-list');
    var forwardForm = document.getElementById('forward-form');
    var forwardCancel = document.getElementById('forward-cancel');
    var contextMessage = null;

    function hideMessageMenu() {
        if (!contextMenu) {
            return;
        }
        contextMenu.classList.add('d-none');
        contextMenu.setAttribute('hidden', 'hidden');
        contextMessage = null;
    }

    function placeMessageMenu(x, y) {
        if (!contextMenu) {
            return;
        }
        contextMenu.classList.remove('d-none');
        contextMenu.removeAttribute('hidden');
        contextMenu.style.left = '0px';
        contextMenu.style.top = '0px';
        var rect = contextMenu.getBoundingClientRect();
        var left = Math.min(Math.max(8, x), window.innerWidth - rect.width - 8);
        var top = Math.min(Math.max(8, y), window.innerHeight - rect.height - 8);
        contextMenu.style.left = left + 'px';
        contextMenu.style.top = top + 'px';
    }

    function menuAction(icon, label, action, danger) {
        return '<button type="button" role="menuitem" data-action="' + action + '"' + (danger ? ' class="is-danger"' : '') + '>' +
            '<i class="fa-solid ' + icon + '" aria-hidden="true"></i><span>' + label + '</span></button>';
    }

    function openMessageMenu(message, x, y) {
        if (!contextMenu || !contextReacts || !contextItems || !message || message.is_deleted) {
            return;
        }
        contextMessage = message;
        var mine = Number(message.sender_id) === Number(me.id);
        contextReacts.innerHTML = reactions.map(function (emoji) {
            return '<button type="button" data-react-pick="' + emoji + '" aria-label="React ' + emoji + '">' + emoji + '</button>';
        }).join('');

        var html = menuAction('fa-reply', 'Reply', 'reply');
        if (message.body) {
            html += menuAction('fa-copy', 'Copy', 'copy');
        }
        if (message.file_url) {
            html += menuAction('fa-download', 'Download', 'download');
        }
        html += menuAction('fa-share', 'Forward', 'forward');
        html += menuAction('fa-trash', 'Delete for me', 'delete-for-me', true);
        if (mine) {
            html += menuAction('fa-trash-can', 'Delete for everyone', 'delete', true);
        } else {
            html += menuAction('fa-flag', 'Report', 'report', true);
        }
        contextItems.innerHTML = html;
        placeMessageMenu(x, y);
    }

    function copyMessageText(message) {
        var text = message && message.body ? String(message.body) : '';
        if (!text) {
            WC.toast('Nothing to copy.');
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                WC.toast('Copied');
            }).catch(function () {
                WC.toast('Unable to copy.');
            });
            return;
        }
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
            WC.toast('Copied');
        } catch (error) {
            WC.toast('Unable to copy.');
        }
        document.body.removeChild(area);
    }

    function downloadMessageFile(message) {
        if (!message || !message.file_url) {
            return;
        }
        var link = document.createElement('a');
        link.href = message.file_url + (String(message.file_url).indexOf('?') >= 0 ? '&' : '?') + 'download=1';
        link.download = message.file_name || 'download';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function closeForwardModal() {
        if (forwardModal) {
            forwardModal.classList.add('d-none');
        }
        state.forwardMessage = null;
    }

    function openForwardPicker(message) {
        if (!forwardModal || !forwardList) {
            return;
        }
        state.forwardMessage = message;
        forwardList.innerHTML = '<p class="text-secondary">Loading chats...</p>';
        forwardModal.classList.remove('d-none');
        Promise.all([
            WC.api('api/friends.php', { query: { action: 'list' } }),
            WC.api('api/groups.php', { query: { action: 'list' } })
        ]).then(function (results) {
            var friends = (results[0].data && results[0].data.friends) || [];
            var groups = (results[1].data && results[1].data.groups) || [];
            var html = '';
            friends.forEach(function (friend) {
                html += '<label class="forward-option">' +
                    '<input type="checkbox" name="forward_target" value="user:' + Number(friend.id) + '">' +
                    '<img src="' + WC.escape(friend.avatar_url || '') + '" alt="">' +
                    '<span><strong>' + WC.escape(friend.username || 'Friend') + '</strong><small>Friend</small></span>' +
                    '</label>';
            });
            groups.forEach(function (group) {
                html += '<label class="forward-option">' +
                    '<input type="checkbox" name="forward_target" value="group:' + Number(group.id) + '">' +
                    '<img src="' + WC.escape(group.avatar_url || '') + '" alt="">' +
                    '<span><strong>' + WC.escape(group.name || 'Group') + '</strong><small>Group</small></span>' +
                    '</label>';
            });
            forwardList.innerHTML = html || '<p class="text-secondary">No friends or groups available to forward to.</p>';
        }).catch(function (error) {
            forwardList.innerHTML = '<p class="text-secondary">' + WC.escape(error.message || 'Unable to load chats.') + '</p>';
        });
    }

    function submitForward(event) {
        if (event) {
            event.preventDefault();
        }
        var message = state.forwardMessage;
        if (!message || !forwardList) {
            return;
        }
        var boxes = forwardList.querySelectorAll('input[name="forward_target"]:checked');
        var targets = [];
        Array.prototype.forEach.call(boxes, function (box) {
            var parts = String(box.value || '').split(':');
            if (parts.length === 2 && Number(parts[1]) > 0) {
                targets.push({ type: parts[0], id: Number(parts[1]) });
            }
        });
        if (!targets.length) {
            WC.toast('Choose at least one chat.');
            return;
        }
        WC.api('api/messages.php?action=forward', {
            method: 'POST',
            json: {
                source: mode === 'group' ? 'group' : 'private',
                message_id: message.id,
                targets: targets
            }
        }).then(function (result) {
            closeForwardModal();
            WC.toast('Message forwarded');
            var sent = (result.data && result.data.sent) || [];
            sent.forEach(function (item) {
                if (!item || !item.message) {
                    return;
                }
                if (item.type === 'user' && mode === 'private' && Number(item.id) === currentPeerId()) {
                    upsert([item.message], true);
                }
                if (item.type === 'group' && mode === 'group' && Number(item.id) === Number(state.groupId)) {
                    upsert([item.message], true);
                }
            });
            loadInbox().catch(function () {});
        }).catch(function (error) {
            WC.toast(error.message || 'Unable to forward message.');
        });
    }

    if (contextMenu) {
        contextMenu.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var reactBtn = target.closest('[data-react-pick]');
            if (reactBtn && contextMessage) {
                react(contextMessage.id, reactBtn.getAttribute('data-react-pick'));
                hideMessageMenu();
                return;
            }
            var actionBtn = target.closest('[data-action]');
            if (!actionBtn || !contextMessage) {
                return;
            }
            var action = actionBtn.getAttribute('data-action');
            var message = contextMessage;
            hideMessageMenu();
            if (action === 'reply') {
                setReply(message);
            } else if (action === 'copy') {
                copyMessageText(message);
            } else if (action === 'download') {
                downloadMessageFile(message);
            } else if (action === 'forward') {
                openForwardPicker(message);
            } else if (action === 'delete-for-me') {
                runDeleteForMe([message.id]);
            } else if (action === 'delete') {
                removeMessage(message.id);
            } else if (action === 'report') {
                WC.openReport(mode === 'group' ? 'group_message' : 'private_message', message.id);
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (!contextMenu || contextMenu.classList.contains('d-none')) {
            return;
        }
        if (event.target && event.target.closest && event.target.closest('#msg-context-menu')) {
            return;
        }
        hideMessageMenu();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            hideMessageMenu();
            closeForwardModal();
            closeThreadMenu();
            closeDeleteConfirm();
            if (state.selecting) {
                setSelecting(false);
            }
        }
    });
    window.addEventListener('resize', hideMessageMenu);
    if (messagesEl) {
        messagesEl.addEventListener('scroll', hideMessageMenu);
    }
    if (forwardCancel) {
        forwardCancel.addEventListener('click', closeForwardModal);
    }
    if (forwardForm) {
        forwardForm.addEventListener('submit', submitForward);
    }
    if (forwardModal) {
        forwardModal.addEventListener('click', function (event) {
            if (event.target === forwardModal) {
                closeForwardModal();
            }
        });
    }

    function setComposerEnabled(enabled) {
        if (!composer) {
            return;
        }
        var controls = composer.querySelectorAll('button, textarea, input');
        Array.prototype.forEach.call(controls, function (control) {
            if (control.id === 'reply-cancel' || control.id === 'voice-cancel' || control.id === 'voice-send') {
                return;
            }
            control.disabled = !enabled;
        });
        if (input) {
            input.placeholder = enabled ? 'Write a message' : 'Add this person as a friend to send messages';
        }
    }

    function react(messageId, emoji) {
        WC.api('api/reactions.php', {
            method: 'POST',
            json: { scope: mode === 'group' ? 'group' : 'private', message_id: messageId, emoji: emoji }
        }).then(function (result) {
            var current = state.messages[Number(messageId)];
            if (current) {
                current.reactions = result.data.reactions;
                state.stick = false;
                renderMessages();
            }
        }).catch(function (error) {
            WC.toast(error.message);
        });
    }

    function removeMessage(messageId) {
        if (!window.confirm('Delete this message for everyone?')) {
            return;
        }
        var path = mode === 'group' ? 'api/groups.php?action=delete_message' : 'api/messages.php?action=delete';
        WC.api(path, { method: 'POST', json: { message_id: messageId } }).then(function () {
            var current = state.messages[Number(messageId)];
            if (current) {
                current.is_deleted = true;
                current.body = null;
                current.file_url = null;
                current.reactions = [];
                state.stick = false;
                renderMessages();
            }
        }).catch(function (error) {
            WC.toast(error.message);
        });
    }

    var selectBar = document.getElementById('select-bar');
    var selectCount = document.getElementById('select-count');
    var selectCancel = document.getElementById('select-cancel');
    var selectDelete = document.getElementById('select-delete');
    var threadMenuBtn = document.getElementById('thread-menu-btn');
    var threadMenu = document.getElementById('thread-menu');
    var deleteConfirmModal = document.getElementById('delete-confirm-modal');
    var deleteConfirmTitle = document.getElementById('delete-confirm-title');
    var deleteConfirmCopy = document.getElementById('delete-confirm-copy');
    var deleteConfirmCancel = document.getElementById('delete-confirm-cancel');
    var deleteConfirmOk = document.getElementById('delete-confirm-ok');

    function selectedIds() {
        return Object.keys(state.selected).map(function (id) { return Number(id); }).filter(function (id) {
            return !!state.selected[id];
        });
    }

    function updateSelectBar() {
        var count = selectedIds().length;
        if (selectCount) {
            selectCount.textContent = count === 1 ? '1 selected' : (count + ' selected');
        }
        if (selectDelete) {
            selectDelete.disabled = count < 1;
        }
    }

    function setSelecting(on) {
        state.selecting = !!on;
        if (!state.selecting) {
            state.selected = {};
        }
        if (state.selecting) {
            root.classList.add('is-selecting');
            hideMessageMenu();
            closeThreadMenu();
        } else {
            root.classList.remove('is-selecting');
        }
        if (selectBar) {
            if (state.selecting) {
                selectBar.classList.remove('d-none');
                selectBar.removeAttribute('hidden');
            } else {
                selectBar.classList.add('d-none');
                selectBar.setAttribute('hidden', 'hidden');
            }
        }
        updateSelectBar();
        renderMessages();
    }

    function toggleSelectMessage(id) {
        var messageId = Number(id);
        if (!messageId || !state.messages[messageId] || state.messages[messageId].is_deleted) {
            return;
        }
        if (state.selected[messageId]) {
            delete state.selected[messageId];
        } else {
            state.selected[messageId] = true;
        }
        updateSelectBar();
        renderMessages();
    }

    function closeThreadMenu() {
        if (threadMenu) {
            threadMenu.classList.add('d-none');
        }
        if (threadMenuBtn) {
            threadMenuBtn.setAttribute('aria-expanded', 'false');
        }
    }

    function openDeleteConfirm(intent) {
        state.deleteIntent = intent || null;
        if (!deleteConfirmModal || !intent) {
            return;
        }
        if (deleteConfirmTitle) {
            deleteConfirmTitle.textContent = intent.title || 'Delete?';
        }
        if (deleteConfirmCopy) {
            deleteConfirmCopy.textContent = intent.copy || '';
        }
        if (deleteConfirmOk) {
            deleteConfirmOk.textContent = intent.okLabel || 'Delete for me';
        }
        deleteConfirmModal.classList.remove('d-none');
    }

    function closeDeleteConfirm() {
        if (deleteConfirmModal) {
            deleteConfirmModal.classList.add('d-none');
        }
        state.deleteIntent = null;
    }

    function removeMessagesLocally(ids) {
        (ids || []).forEach(function (id) {
            delete state.messages[Number(id)];
            delete state.selected[Number(id)];
        });
        renderMessages();
        updateSelectBar();
    }

    function runDeleteForMe(ids) {
        if (!ids || !ids.length) {
            return;
        }
        var request = mode === 'group'
            ? WC.api('api/groups.php?action=delete_for_me', {
                method: 'POST',
                json: { group_id: state.groupId, message_ids: ids }
            })
            : WC.api('api/messages.php?action=delete_for_me', {
                method: 'POST',
                json: { message_ids: ids }
            });
        return request.then(function (result) {
            var hidden = (result.data && result.data.hidden_ids) || ids;
            removeMessagesLocally(hidden);
            setSelecting(false);
            loadInbox().catch(function () {});
            WC.toast('Deleted for you');
        }).catch(function (error) {
            WC.toast(error.message || 'Unable to delete messages.');
        });
    }

    function runClearChat(leaveAfter) {
        var request = mode === 'group'
            ? WC.api('api/groups.php?action=clear_chat', {
                method: 'POST',
                json: { group_id: state.groupId }
            })
            : WC.api('api/messages.php?action=' + (leaveAfter ? 'delete_chat' : 'clear_chat'), {
                method: 'POST',
                json: { peer_id: currentPeerId() }
            });
        return request.then(function () {
            state.messages = {};
            setSelecting(false);
            renderMessages();
            if (leaveAfter && mode === 'private') {
                state.peerId = 0;
                root.removeAttribute('data-peer-id');
                root.classList.remove('is-thread');
                if (window.history && history.pushState) {
                    try {
                        history.pushState({}, '', WC.buildUrl('chat.php'));
                    } catch (error) {}
                }
                if (threadName) {
                    threadName.textContent = 'Select a conversation';
                }
                if (threadPresence) {
                    threadPresence.textContent = '';
                    threadPresence.className = '';
                }
                setHidden(document.getElementById('start-voice'), true);
                setHidden(document.getElementById('start-video'), true);
                setHidden(document.getElementById('thread-menu-wrap'), true);
                closeInfoPane();
            }
            loadInbox().catch(function () {});
            WC.toast(leaveAfter ? 'Chat deleted' : 'Chat cleared');
        }).catch(function (error) {
            WC.toast(error.message || 'Unable to update chat.');
        });
    }

    function handleThreadAction(action) {
        closeThreadMenu();
        if (action === 'contact') {
            openInfoPane();
            return;
        }
        if (action === 'select') {
            if (!active()) {
                WC.toast('Open a conversation first.');
                return;
            }
            setSelecting(true);
            return;
        }
        if (action === 'clear') {
            if (!active()) {
                return;
            }
            openDeleteConfirm({
                kind: 'clear',
                title: 'Clear this chat?',
                copy: 'All messages in this chat will be removed from your view only. New messages will still appear.',
                okLabel: 'Clear chat'
            });
            return;
        }
        if (action === 'delete-chat') {
            if (mode !== 'private' || !currentPeerId()) {
                return;
            }
            openDeleteConfirm({
                kind: 'delete-chat',
                title: 'Delete this chat?',
                copy: 'This chat will be removed from your conversation list. New messages from this contact will open it again.',
                okLabel: 'Delete chat'
            });
            return;
        }
        if (action === 'block' && mode === 'private' && currentPeerId()) {
            if (!window.confirm('Block this user?')) {
                return;
            }
            WC.api('api/blocks.php?action=block', { method: 'POST', json: { user_id: currentPeerId() } }).then(function () {
                window.location.href = WC.buildUrl('friends.php?tab=blocked');
            }).catch(function (error) {
                WC.toast(error.message || 'Unable to block user.');
            });
            return;
        }
        if (action === 'report' && mode === 'private' && currentPeerId()) {
            WC.openReport('user', currentPeerId());
        }
    }

    if (threadMenuBtn && threadMenu) {
        threadMenuBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            if (threadMenu.classList.contains('d-none')) {
                threadMenu.classList.remove('d-none');
                threadMenuBtn.setAttribute('aria-expanded', 'true');
            } else {
                closeThreadMenu();
            }
        });
        threadMenu.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-thread-action]') : null;
            if (!button) {
                return;
            }
            handleThreadAction(button.getAttribute('data-thread-action'));
        });
    }
    document.addEventListener('click', function (event) {
        if (!threadMenu || threadMenu.classList.contains('d-none')) {
            return;
        }
        if (event.target && event.target.closest && event.target.closest('#thread-menu-wrap')) {
            return;
        }
        closeThreadMenu();
    });
    if (selectCancel) {
        selectCancel.addEventListener('click', function () { setSelecting(false); });
    }
    if (selectDelete) {
        selectDelete.addEventListener('click', function () {
            var ids = selectedIds();
            if (!ids.length) {
                return;
            }
            openDeleteConfirm({
                kind: 'messages',
                ids: ids,
                title: ids.length === 1 ? 'Delete message?' : 'Delete messages?',
                copy: 'Selected messages will be removed from this chat on your side only.',
                okLabel: 'Delete for me'
            });
        });
    }
    if (deleteConfirmCancel) {
        deleteConfirmCancel.addEventListener('click', closeDeleteConfirm);
    }
    if (deleteConfirmModal) {
        deleteConfirmModal.addEventListener('click', function (event) {
            if (event.target === deleteConfirmModal) {
                closeDeleteConfirm();
            }
        });
    }
    if (deleteConfirmOk) {
        deleteConfirmOk.addEventListener('click', function () {
            var intent = state.deleteIntent;
            closeDeleteConfirm();
            if (!intent) {
                return;
            }
            if (intent.kind === 'messages') {
                runDeleteForMe(intent.ids || []);
            } else if (intent.kind === 'clear') {
                runClearChat(false);
            } else if (intent.kind === 'delete-chat') {
                runClearChat(true);
            }
        });
    }

    function sendPayload(type, text, file, duration) {
        if (state.sending) {
            return Promise.resolve();
        }
        var peerId = currentPeerId();
        if (mode === 'private') {
            if (!peerId) {
                WC.toast('Select a conversation on the left first.');
                return Promise.resolve();
            }
            state.peerId = peerId;
            root.setAttribute('data-peer-id', String(peerId));
        } else if (!state.groupId) {
            WC.toast('Open a group chat first.');
            return Promise.resolve();
        }

        var form = new FormData();
        form.append('action', 'send');
        form.append('message_type', type);
        form.append('body', text || '');
        if (state.reply) {
            form.append('reply_to_id', String(state.reply.id));
        }
        if (file) {
            form.append('attachment', file);
        }
        if (duration) {
            form.append('voice_duration', String(duration));
        }
        var path = mode === 'group' ? 'api/groups.php?action=send' : 'api/messages.php?action=send';
        if (mode === 'group') {
            form.append('group_id', String(state.groupId));
        } else {
            form.append('receiver_id', String(peerId));
        }

        state.sending = true;
        return WC.api(path, { method: 'POST', form: form }).then(function (result) {
            state.canMessage = true;
            setComposerEnabled(true);
            state.reply = null;
            if (replyBar) {
                replyBar.classList.add('d-none');
            }
            if (input) {
                input.value = '';
            }
            var done = Promise.resolve();
            if (result.data && result.data.message) {
                upsert([result.data.message], true);
                WC.toast('Message sent');
            } else {
                done = poll(true).then(function () {
                    WC.toast('Message sent');
                });
            }
            return done.then(function () {
                clearPendingAttachment();
                sendTyping(false);
                loadInbox();
            });
        }).catch(function (error) {
            WC.toast(error.message || 'Unable to send message. Please try again.');
            throw error;
        }).then(function () {
            state.sending = false;
        }, function (error) {
            state.sending = false;
            throw error;
        });
    }

    function openInfoPane() {
        if (!active() && mode === 'private') {
            return;
        }
        root.classList.add('is-info');
        root.classList.add('is-thread');
        closeThreadMenu();
        if (mode === 'private') {
            loadContactProfile(currentPeerId());
        }
    }

    function closeInfoPane() {
        root.classList.remove('is-info');
    }

    function toggleInfoPane() {
        if (root.classList.contains('is-info')) {
            closeInfoPane();
        } else {
            openInfoPane();
        }
    }

    function relationshipLabel(value) {
        if (value === 'friend') return 'Friends';
        if (value === 'outgoing') return 'Friend request sent';
        if (value === 'incoming') return 'Incoming friend request';
        if (value === 'blocked') return 'Blocked';
        if (value === 'self') return 'You';
        return 'Not friends yet';
    }

    function loadContactProfile(userId) {
        if (!contactPane || mode !== 'private') {
            return;
        }
        var id = Number(userId || 0);
        if (!id) {
            return;
        }
        contactPane.innerHTML =
            '<div class="info-pane-head">' +
            '<strong>Profile</strong>' +
            '<button type="button" class="icon-btn" data-info-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
            '</div>' +
            '<div class="info-empty">Loading profile...</div>';
        bindInfoClose(contactPane);
        WC.api('api/profile.php', { query: { action: 'view', user_id: id } }).then(function (result) {
            var peer = (result.data && result.data.user) || null;
            if (!peer) {
                throw new Error('Profile not found.');
            }
            renderPrivateInfo(peer);
        }).catch(function (error) {
            contactPane.innerHTML =
                '<div class="info-pane-head">' +
                '<strong>Profile</strong>' +
                '<button type="button" class="icon-btn" data-info-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
                '</div>' +
                '<div class="info-empty">' + WC.escape(error.message || 'Unable to load profile.') + '</div>';
            bindInfoClose(contactPane);
        });
    }

    function renderPrivateInfo(peer) {
        if (!contactPane || mode !== 'private' || !peer) {
            return;
        }
        var about = peer.status_message ? peer.status_message : 'No status message yet.';
        var joined = peer.joined_label || '';
        var relation = peer.relationship || (state.canMessage ? 'friend' : 'none');
        var actions = '';
        if (relation === 'friend' && WC.calls && typeof WC.calls.start === 'function') {
            actions += '<button type="button" class="btn btn-accent btn-sm" data-info-call="voice">Voice call</button>';
            actions += '<button type="button" class="btn btn-light btn-sm" data-info-call="video">Video call</button>';
        } else if (relation === 'none') {
            actions += '<button type="button" class="btn btn-accent btn-sm" data-info-friend>Add friend</button>';
        } else if (relation === 'outgoing') {
            actions += '<span class="badge text-bg-light">Friend request sent</span>';
        } else if (relation === 'incoming') {
            actions += '<a class="btn btn-accent btn-sm" href="' + WC.buildUrl('friends.php', { tab: 'incoming' }) + '">Review request</a>';
        }
        if (relation === 'blocked') {
            actions += '<button type="button" class="btn btn-accent btn-sm" data-info-unblock>Unblock</button>';
        } else if (relation !== 'self') {
            actions += '<button type="button" class="btn btn-light btn-sm" data-info-block>Block</button>';
            actions += '<button type="button" class="btn btn-light btn-sm" data-info-report>Report</button>';
        }

        contactPane.innerHTML =
            '<div class="info-pane-head">' +
            '<strong>Profile</strong>' +
            '<button type="button" class="icon-btn" data-info-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
            '</div>' +
            '<div class="info-card info-profile">' +
            '<img class="info-avatar" src="' + WC.escape(peer.avatar_url) + '" alt="">' +
            '<h2>' + WC.escape(peer.username) + '</h2>' +
            '<p class="presence presence-' + WC.escape(peer.presence || 'offline') + '">' + WC.escape(peer.last_seen_label || '') + '</p>' +
            '<p class="info-relation">' + WC.escape(relationshipLabel(relation)) + '</p>' +
            '<div class="info-section">' +
            '<strong>About</strong>' +
            '<p>' + WC.escape(about) + '</p>' +
            (joined ? '<small>Joined ' + WC.escape(joined) + '</small>' : '') +
            '</div>' +
            '<div class="person-actions">' + actions + '</div>' +
            '</div>';

        bindInfoClose(contactPane);
        var voiceBtn = contactPane.querySelector('[data-info-call="voice"]');
        if (voiceBtn) {
            voiceBtn.addEventListener('click', function () { WC.calls.start(peer.id, 'voice'); });
        }
        var videoBtn = contactPane.querySelector('[data-info-call="video"]');
        if (videoBtn) {
            videoBtn.addEventListener('click', function () { WC.calls.start(peer.id, 'video'); });
        }
        var friendBtn = contactPane.querySelector('[data-info-friend]');
        if (friendBtn) {
            friendBtn.addEventListener('click', function () {
                WC.api('api/friends.php?action=send', { method: 'POST', json: { user_id: peer.id } }).then(function () {
                    WC.toast('Friend request sent');
                    loadContactProfile(peer.id);
                }).catch(function (error) {
                    WC.toast(error.message || 'Unable to send request.');
                });
            });
        }
        var blockBtn = contactPane.querySelector('[data-info-block]');
        if (blockBtn) {
            blockBtn.addEventListener('click', function () {
                if (!window.confirm('Block this user?')) {
                    return;
                }
                WC.api('api/blocks.php?action=block', { method: 'POST', json: { user_id: peer.id } }).then(function () {
                    window.location.href = WC.buildUrl('friends.php?tab=blocked');
                }).catch(function (error) {
                    WC.toast(error.message || 'Unable to block user.');
                });
            });
        }
        var unblockBtn = contactPane.querySelector('[data-info-unblock]');
        if (unblockBtn) {
            unblockBtn.addEventListener('click', function () {
                WC.api('api/blocks.php?action=unblock', { method: 'POST', json: { user_id: peer.id } }).then(function () {
                    WC.toast('User unblocked');
                    loadContactProfile(peer.id);
                }).catch(function (error) {
                    WC.toast(error.message || 'Unable to unblock user.');
                });
            });
        }
        var reportBtn = contactPane.querySelector('[data-info-report]');
        if (reportBtn) {
            reportBtn.addEventListener('click', function () {
                WC.openReport('user', peer.id);
            });
        }
    }

    function bindInfoClose(scope) {
        var rootEl = scope || document;
        var buttons = rootEl.querySelectorAll('[data-info-close]');
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                closeInfoPane();
            });
        });
    }

    function applyPeer(peer, canMessage) {
        state.canMessage = canMessage !== false;
        setComposerEnabled(state.canMessage);
        if (threadName) {
            threadName.textContent = peer.username;
        }
        state.presenceLabel = peer.last_seen_label || peer.presence_label || '';
        state.presenceClass = 'presence presence-' + (peer.presence || 'offline');
        showPresenceOrTyping([]);
        if (threadAvatar) {
            setHidden(threadAvatar, false);
            threadAvatar.src = peer.avatar_url;
            threadAvatar.alt = peer.username;
        }
        setHidden(document.getElementById('start-voice'), !state.canMessage);
        setHidden(document.getElementById('start-video'), !state.canMessage);
        setHidden(document.getElementById('thread-menu-wrap'), false);
        if (root.classList.contains('is-info')) {
            loadContactProfile(peer.id);
        }
    }

    function showPresenceOrTyping(names) {
        if (!threadPresence) {
            return;
        }
        if (names && names.length) {
            var label = names.length > 1
                ? names.join(', ') + ' are typing...'
                : (names[0] + ' is typing...');
            threadPresence.textContent = label;
            threadPresence.className = 'is-typing';
            return;
        }
        threadPresence.textContent = state.presenceLabel || '';
        threadPresence.className = state.presenceClass || '';
    }

    function formatSize(bytes) {
        var size = Number(bytes) || 0;
        if (size < 1024) {
            return size + ' B';
        }
        if (size < 1024 * 1024) {
            return (size / 1024).toFixed(1) + ' KB';
        }
        return (size / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function clearPendingAttachment() {
        if (state.pending && state.pending.url) {
            try {
                URL.revokeObjectURL(state.pending.url);
            } catch (error) {}
        }
        state.pending = null;
        if (attachPreview) {
            attachPreview.classList.add('d-none');
        }
        if (attachPreviewImg) {
            attachPreviewImg.setAttribute('hidden', 'hidden');
            attachPreviewImg.removeAttribute('src');
        }
        if (attachPreviewName) {
            attachPreviewName.textContent = 'Attachment';
        }
        if (attachPreviewMeta) {
            attachPreviewMeta.textContent = '';
        }
        if (input && state.canMessage) {
            input.placeholder = 'Type a message';
        }
    }

    function setPendingAttachment(file, type) {
        clearPendingAttachment();
        if (!file) {
            return;
        }
        var url = '';
        try {
            url = URL.createObjectURL(file);
        } catch (error) {
            url = '';
        }
        state.pending = { file: file, type: type, url: url };
        if (attachPreview) {
            attachPreview.classList.remove('d-none');
        }
        if (attachPreviewName) {
            attachPreviewName.textContent = file.name || (type === 'image' ? 'Image' : 'File');
        }
        if (attachPreviewMeta) {
            attachPreviewMeta.textContent = formatSize(file.size) + (type === 'image' ? ' · Image' : ' · File');
        }
        if (attachPreviewImg) {
            if (type === 'image' && url) {
                attachPreviewImg.src = url;
                attachPreviewImg.removeAttribute('hidden');
            } else {
                attachPreviewImg.setAttribute('hidden', 'hidden');
                attachPreviewImg.removeAttribute('src');
            }
        }
        if (input) {
            input.placeholder = type === 'image' ? 'Add a caption...' : 'Add a note (optional)...';
            input.focus();
        }
    }

    function hydrateFromBoot() {
        if (mode !== 'private') {
            return false;
        }
        var seed = Array.isArray(bootChat.messages) ? bootChat.messages : [];
        if (bootChat.peer && state.peerId) {
            try {
                applyPeer(bootChat.peer, bootChat.canMessage !== false);
                state.peerSignature = String(bootChat.peer.id) + '|' + bootChat.peer.presence + '|' + bootChat.peer.last_seen_label + '|' + (bootChat.canMessage ? '1' : '0');
            } catch (error) {
                if (window.console && console.error) {
                    console.error(error);
                }
            }
        }
        if (seed.length) {
            state.messages = {};
            seed.forEach(function (message) {
                state.messages[Number(message.id)] = message;
            });
            return true;
        }
        return state.peerId > 0 && !!bootChat.peer;
    }

    function notifyIncoming(messages) {
        var incoming = (messages || []).filter(function (message) {
            return message && Number(message.sender_id) !== Number(me.id) && !message.is_deleted;
        });
        if (!incoming.length) {
            return;
        }
        var latest = incoming[incoming.length - 1];
        var who = latest.sender_username || 'New message';
        var preview = latest.body || replyLabel(latest);
        WC.toast(who + ': ' + preview);
        if (!document.hasFocus()) {
            document.title = '● ' + who + ' messaged you';
            if (state.titleTimer) {
                clearTimeout(state.titleTimer);
            }
            state.titleTimer = setTimeout(function () {
                document.title = state.lastTitle || 'WebConnect';
            }, 4000);
        }
        if (window.Notification && Notification.permission === 'granted') {
            try {
                new Notification(who, { body: preview, tag: 'wc-msg-' + latest.id });
            } catch (error) {
                /* Ignore notification failures. */
            }
        }
    }

    function loadInbox() {
        if (mode !== 'private') {
            return Promise.resolve();
        }
        var search = document.getElementById('conversation-search');
        var query = search ? (search.value || '') : '';
        return WC.api('api/chat.php', { query: { action: 'list', q: query } }).then(function (result) {
            var conversations = (result.data && result.data.conversations) || [];
            var previous = state.inboxUnread || {};
            var next = {};
            var activePeer = currentPeerId();
            conversations.forEach(function (item) {
                var uid = Number(item.user && item.user.id);
                var unread = Number(item.unread || 0);
                next[uid] = unread;
                if (state.inboxReady && uid && uid !== activePeer && unread > (previous[uid] || 0)) {
                    WC.toast((item.user.username || 'Friend') + ': ' + (item.last_message || 'New message'));
                    if (!document.hasFocus()) {
                        document.title = '● New message';
                        if (state.titleTimer) {
                            clearTimeout(state.titleTimer);
                        }
                        state.titleTimer = setTimeout(function () {
                            document.title = state.lastTitle || 'WebConnect';
                        }, 4000);
                    }
                }
            });
            state.inboxUnread = next;
            state.inboxReady = true;
            renderInbox(conversations);
        });
    }

    function poll(reset) {
        if (!active()) {
            return Promise.resolve();
        }
        if (state.polling && !reset) {
            return Promise.resolve();
        }
        state.polling = true;
        var peerId = currentPeerId();
        var since = reset ? 0 : maxMessageId();
        var knownIds = {};
        if (!reset) {
            messageIds().forEach(function (id) {
                knownIds[id] = true;
            });
        }
        var request = mode === 'group'
            ? WC.api('api/groups.php', { query: { action: 'poll', group_id: state.groupId, since_id: since } })
            : WC.api('api/chat.php', { query: { action: 'poll', with: peerId, since_id: since } });

        return request.then(function (result) {
            var data = result.data || {};
            if (mode === 'private' && data.peer) {
                state.peerId = Number(data.peer.id) || peerId;
                root.setAttribute('data-peer-id', String(state.peerId));
                state.canMessage = data.can_message !== false;
                var peer = data.peer;
                var signature = peer.id + '|' + peer.presence + '|' + peer.last_seen_label + '|' + (state.canMessage ? '1' : '0');
                if (state.peerSignature !== signature) {
                    state.peerSignature = signature;
                    try {
                        applyPeer(peer, state.canMessage);
                    } catch (error) {
                        if (window.console && console.error) {
                            console.error(error);
                        }
                        setComposerEnabled(state.canMessage);
                    }
                } else {
                    setComposerEnabled(state.canMessage);
                }
            }

            var changed = false;
            (data.statuses || []).forEach(function (item) {
                var message = state.messages[Number(item.id)];
                if (message && message.delivery_status !== item.delivery_status) {
                    message.delivery_status = item.delivery_status;
                    changed = true;
                }
            });
            (data.deleted_ids || []).forEach(function (id) {
                var message = state.messages[Number(id)];
                if (message && !message.is_deleted) {
                    message.is_deleted = true;
                    message.body = null;
                    message.file_url = null;
                    message.reactions = [];
                    changed = true;
                }
            });

            var incoming = data.messages || [];
            var fresh = [];
            if (!reset) {
                incoming.forEach(function (message) {
                    if (!knownIds[Number(message.id)]) {
                        fresh.push(message);
                    }
                });
            }
            if (reset) {
                state.messages = {};
                upsert(incoming, true);
            } else if (incoming.length || changed) {
                var near = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 120;
                upsert(incoming, near || fresh.length > 0);
            }

            if (fresh.length) {
                notifyIncoming(fresh);
                loadInbox().catch(function () {});
            }

            if (typingEl) {
                typingEl.textContent = '';
            }
            showPresenceOrTyping(data.typing || []);
        }).then(function () {
            state.polling = false;
        }, function (error) {
            state.polling = false;
            throw error;
        });
    }

    function openConversation(userId, pushUrl) {
        var id = Number(userId || 0);
        if (!id || mode !== 'private') {
            return;
        }
        if (Number(state.peerId) === id && root.classList.contains('is-thread') && messageCount()) {
            root.classList.add('is-thread');
            return;
        }
        state.peerId = id;
        root.setAttribute('data-peer-id', String(id));
        root.classList.add('is-thread');
        closeInfoPane();
        state.messages = {};
        state.reply = null;
        state.peerSignature = '';
        if (state.selecting) {
            setSelecting(false);
        }
        if (replyBar) {
            replyBar.classList.add('d-none');
        }
        if (pushUrl !== false && window.history && history.pushState) {
            try {
                history.pushState({ peerId: id }, '', WC.buildUrl('chat.php', { with: id }));
            } catch (error) {
                /* Ignore history errors. */
            }
        }
        renderMessages();
        loadInbox().catch(function () {});
        poll(true).catch(function (error) {
            messagesEl.innerHTML = '<div class="empty-card">Unable to load this conversation. ' + WC.escape(error.message || 'Please try again.') + '</div>';
            WC.toast(error.message || 'Unable to load messages.');
        });
    }

    function renderInbox(conversations) {
        if (!listEl) {
            return;
        }
        var peerId = currentPeerId();
        listEl.innerHTML = '';
        if (!conversations.length) {
            listEl.innerHTML = emptyStateHtml('empty-inbox.svg', 'No conversations yet', 'Message a friend from the Friends page to begin.');
            return;
        }
        conversations.forEach(function (item) {
            var link = document.createElement('a');
            link.className = 'conversation' + (Number(item.user.id) === peerId ? ' is-active' : '');
            link.href = WC.buildUrl('chat.php', { with: item.user.id });
            link.setAttribute('data-user', String(item.user.id));
            link.setAttribute('role', 'listitem');
            link.innerHTML = '<img src="' + WC.escape(item.user.avatar_url) + '" alt="">' +
                '<span><strong>' + WC.escape(item.user.username) + '</strong><p>' + WC.escape(item.last_message) + '</p></span>' +
                '<span>' + (item.unread > 0 ? '<em class="nav-badge">' + Number(item.unread) + '</em>' : '<small>' + WC.escape(item.last_label || '') + '</small>') + '</span>';
            link.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                openConversation(item.user.id, true);
            });
            listEl.appendChild(link);
        });
    }

    function loadOlder(beforeId) {
        var request = mode === 'group'
            ? WC.api('api/groups.php', { query: { action: 'history', group_id: state.groupId, before_id: beforeId } })
            : WC.api('api/chat.php', { query: { action: 'history', with: currentPeerId(), before_id: beforeId } });
        return request.then(function (result) {
            upsert((result.data && result.data.messages) || [], false);
            return result;
        });
    }

    function sendTyping(activeFlag) {
        if (!active() || !state.canMessage) {
            return;
        }
        var path = mode === 'group' ? 'api/groups.php?action=typing' : 'api/chat.php?action=typing';
        var payload = mode === 'group'
            ? { group_id: state.groupId, active: activeFlag ? 1 : 0 }
            : { with: currentPeerId(), active: activeFlag ? 1 : 0 };
        WC.api(path, { method: 'POST', json: payload }).catch(function () {});
    }

    function handleSend(event) {
        if (event) {
            event.preventDefault();
            if (event.stopPropagation) {
                event.stopPropagation();
            }
        }
        if (!input) {
            return false;
        }
        var text = input.value.replace(/^\s+|\s+$/g, '');
        if (state.pending && state.pending.file) {
            sendPayload(state.pending.type, text, state.pending.file, null).catch(function () {});
            return false;
        }
        if (!text) {
            return false;
        }
        sendPayload('text', text, null, null).catch(function () {});
        return false;
    }

    if (composer) {
        composer.setAttribute('action', window.location.href);
        composer.setAttribute('method', 'post');
        composer.addEventListener('submit', handleSend);
    }
    if (sendBtn) {
        sendBtn.addEventListener('click', handleSend);
    }

    if (input) {
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                handleSend(event);
            }
        });
        input.addEventListener('input', function () {
            sendTyping(input.value.replace(/^\s+|\s+$/g, '') !== '');
            clearTimeout(state.typingTimer);
            state.typingTimer = setTimeout(function () { sendTyping(false); }, 2500);
        });
        input.addEventListener('blur', function () { sendTyping(false); });
    }

    var replyCancel = document.getElementById('reply-cancel');
    if (replyCancel) {
        replyCancel.addEventListener('click', function () {
            state.reply = null;
            if (replyBar) {
                replyBar.classList.add('d-none');
            }
        });
    }

    var emojiToggle = document.getElementById('emoji-toggle');
    if (emojiToggle && emojiPicker) {
        emojiToggle.addEventListener('click', function () {
            if (emojiPicker.classList.contains('d-none')) {
                emojiPicker.classList.remove('d-none');
            } else {
                emojiPicker.classList.add('d-none');
            }
        });
        emojis.forEach(function (emoji) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = emoji;
            button.addEventListener('click', function () {
                if (!input) {
                    return;
                }
                input.value += emoji;
                emojiPicker.classList.add('d-none');
                input.focus();
                sendTyping(true);
            });
            emojiPicker.appendChild(button);
        });
    }

    function sendFile(file, type) {
        setPendingAttachment(file, type);
    }
    var imageInput = document.getElementById('image-input');
    if (imageInput) {
        imageInput.addEventListener('change', function (event) {
            var file = event.target.files && event.target.files[0];
            event.target.value = '';
            if (file) {
                sendFile(file, 'image');
            }
        });
    }
    var fileInput = document.getElementById('file-input');
    if (fileInput) {
        fileInput.addEventListener('change', function (event) {
            var file = event.target.files && event.target.files[0];
            event.target.value = '';
            if (file) {
                sendFile(file, 'file');
            }
        });
    }
    if (attachPreviewClear) {
        attachPreviewClear.addEventListener('click', function () {
            clearPendingAttachment();
        });
    }

    function stopRecording(send) {
        clearInterval(state.recordTick);
        if (voiceBar) {
            voiceBar.classList.add('d-none');
        }
        var composerRow = document.getElementById('composer-row');
        if (composerRow) {
            composerRow.classList.remove('d-none');
        }
        var recorder = state.recorder;
        state.recorder = null;
        if (!recorder) {
            return;
        }
        recorder.onstop = function () {
            if (state.recordStream) {
                state.recordStream.getTracks().forEach(function (track) { track.stop(); });
            }
            if (!send) {
                return;
            }
            var type = recorder.mimeType || 'audio/webm';
            var extension = type.indexOf('ogg') !== -1 ? 'ogg' : 'webm';
            var blob = new Blob(state.chunks, { type: type });
            var file = new File([blob], 'voice.' + extension, { type: type });
            var duration = Math.max(1, Math.round((Date.now() - state.recordStarted) / 1000));
            sendPayload('voice', '', file, duration).catch(function () {});
        };
        if (recorder.state !== 'inactive') {
            recorder.stop();
        }
    }

    var voiceToggle = document.getElementById('voice-toggle');
    if (voiceToggle) {
        voiceToggle.addEventListener('click', function () {
            if (!navigator.mediaDevices || !window.MediaRecorder) {
                WC.toast('This browser cannot record voice messages.');
                return;
            }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                state.recordStream = stream;
                var choices = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg'];
                var mime = '';
                for (var i = 0; i < choices.length; i++) {
                    if (MediaRecorder.isTypeSupported(choices[i])) {
                        mime = choices[i];
                        break;
                    }
                }
                state.recorder = mime ? new MediaRecorder(state.recordStream, { mimeType: mime }) : new MediaRecorder(state.recordStream);
                state.chunks = [];
                state.recorder.ondataavailable = function (event) {
                    if (event.data && event.data.size) {
                        state.chunks.push(event.data);
                    }
                };
                state.recorder.start();
                state.recordStarted = Date.now();
                if (voiceBar) {
                    voiceBar.classList.remove('d-none');
                }
                var composerRow = document.getElementById('composer-row');
                if (composerRow) {
                    composerRow.classList.add('d-none');
                }
                state.recordTick = setInterval(function () {
                    var total = Math.floor((Date.now() - state.recordStarted) / 1000);
                    if (voiceTimer) {
                        voiceTimer.textContent = Math.floor(total / 60) + ':' + pad2(total % 60);
                    }
                }, 250);
            }).catch(function () {
                WC.toast('Microphone permission is required to record a voice message.');
            });
        });
    }
    var voiceCancel = document.getElementById('voice-cancel');
    if (voiceCancel) {
        voiceCancel.addEventListener('click', function () { stopRecording(false); });
    }
    var voiceSend = document.getElementById('voice-send');
    if (voiceSend) {
        voiceSend.addEventListener('click', function () { stopRecording(true); });
    }

    var chatBack = document.getElementById('chat-back');
    if (chatBack) {
        chatBack.addEventListener('click', function () {
            root.classList.remove('is-thread');
            closeInfoPane();
            window.location.href = WC.buildUrl('chat.php');
        });
    }
    var threadTitle = document.getElementById('thread-title');
    if (threadTitle) {
        threadTitle.addEventListener('click', function () {
            if (!active() && mode === 'private') {
                return;
            }
            toggleInfoPane();
        });
        threadTitle.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleInfoPane();
            }
        });
    }
    bindInfoClose(document);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && root.classList.contains('is-info')) {
            closeInfoPane();
        }
    });
    var startVoice = document.getElementById('start-voice');
    var startVideo = document.getElementById('start-video');
    var callPickModal = document.getElementById('call-pick-modal');
    var callPickList = document.getElementById('call-pick-list');
    var callPickCancel = document.getElementById('call-pick-cancel');
    var callPickTitle = document.getElementById('call-pick-title');
    var callPickCopy = document.getElementById('call-pick-copy');
    var pendingCallType = 'voice';

    function groupMembers() {
        var chat = WC_BOOT.chat || {};
        return Array.isArray(chat.members) ? chat.members : [];
    }

    function closeCallPick() {
        if (callPickModal) {
            callPickModal.classList.add('d-none');
        }
    }

    function openCallPick(type) {
        pendingCallType = type === 'video' ? 'video' : 'voice';
        var members = groupMembers().filter(function (member) {
            return Number(member.id) !== Number(me.id);
        });
        if (!members.length) {
            WC.toast('No other members to call.');
            return;
        }
        if (members.length === 1 && WC.calls) {
            WC.calls.start(Number(members[0].id), pendingCallType);
            return;
        }
        if (!callPickModal || !callPickList) {
            WC.toast('Unable to open the call picker.');
            return;
        }
        if (callPickTitle) {
            callPickTitle.textContent = pendingCallType === 'video' ? 'Video call a member' : 'Voice call a member';
        }
        if (callPickCopy) {
            callPickCopy.textContent = 'Choose who to call from this group.';
        }
        callPickList.innerHTML = '';
        members.forEach(function (member) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'forward-option';
            btn.innerHTML = '<img src="' + WC.escape(member.avatar_url || '') + '" alt="">' +
                '<span><strong>' + WC.escape(member.username || 'Member') + '</strong><small>' + WC.escape(member.role || 'member') + '</small></span>';
            btn.addEventListener('click', function () {
                closeCallPick();
                if (WC.calls) {
                    WC.calls.start(Number(member.id), pendingCallType);
                }
            });
            callPickList.appendChild(btn);
        });
        callPickModal.classList.remove('d-none');
    }

    function startCallAction(type) {
        if (!WC.calls) {
            WC.toast('Calls are unavailable right now.');
            return;
        }
        if (mode === 'group') {
            openCallPick(type);
            return;
        }
        var peerId = currentPeerId();
        if (!peerId) {
            WC.toast('Open a conversation first.');
            return;
        }
        WC.calls.start(peerId, type);
    }

    if (startVoice) {
        startVoice.addEventListener('click', function () { startCallAction('voice'); });
    }
    if (startVideo) {
        startVideo.addEventListener('click', function () { startCallAction('video'); });
    }
    if (callPickCancel) {
        callPickCancel.addEventListener('click', closeCallPick);
    }
    if (callPickModal) {
        callPickModal.addEventListener('click', function (event) {
            if (event.target === callPickModal) {
                closeCallPick();
            }
        });
    }
    var conversationSearch = document.getElementById('conversation-search');
    if (conversationSearch) {
        conversationSearch.addEventListener('input', function () {
            clearTimeout(state.inboxTimer);
            state.inboxTimer = setTimeout(function () {
                loadInbox().catch(function () {});
            }, 250);
        });
    }
    if (messagesEl) {
        messagesEl.addEventListener('scroll', function () {
            state.stick = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 90;
        });
    }

    function boot() {
        state.lastTitle = document.title;
        if (mode === 'private') {
            var hydrated = hydrateFromBoot();
            if (hydrated && messageCount()) {
                renderMessages();
            }
            loadInbox().catch(function (error) {
                WC.toast(error.message || 'Unable to refresh conversations.');
            });
            if (state.peerId) {
                root.classList.add('is-thread');
                poll(true).catch(function (error) {
                    if (!messageCount()) {
                        messagesEl.innerHTML = '<div class="empty-card">Unable to load this conversation. ' + WC.escape(error.message || 'Please try again.') + '</div>';
                        WC.toast(error.message || 'Unable to load messages.');
                    }
                });
            }
            state.inboxPollTimer = setInterval(function () {
                loadInbox().catch(function () {});
            }, 3000);
        } else {
            root.classList.add('is-thread');
            poll(true).catch(function (error) {
                WC.toast(error.message || 'Unable to load messages.');
            });
        }
        state.pollTimer = setInterval(function () {
            if (active()) {
                poll(false).catch(function () {});
            } else if (mode === 'private') {
                loadInbox().catch(function () {});
            }
        }, 1500);

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                return;
            }
            document.title = state.lastTitle || document.title;
            if (active()) {
                poll(false).catch(function () {});
            }
            if (mode === 'private') {
                loadInbox().catch(function () {});
            }
        });
        window.addEventListener('focus', function () {
            document.title = state.lastTitle || document.title;
            if (active()) {
                poll(false).catch(function () {});
            }
        });
        window.addEventListener('popstate', function () {
            if (mode !== 'private') {
                return;
            }
            var next = peerFromPage();
            if (next) {
                openConversation(next, false);
            } else {
                state.peerId = 0;
                root.removeAttribute('data-peer-id');
                root.classList.remove('is-thread');
                state.messages = {};
                renderMessages();
                loadInbox().catch(function () {});
            }
        });
        if (window.Notification && Notification.permission === 'default') {
            try {
                Notification.requestPermission();
            } catch (error) {
                /* Optional desktop notifications. */
            }
        }
    }

    boot();
})();
