document.addEventListener('DOMContentLoaded', function () {
    var button = document.getElementById('copy-share');
    var shareInput = document.getElementById('share-url');
    if (button) {
        button.addEventListener('click', function () {
            var url = (shareInput && shareInput.value) ? shareInput.value : window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    WC.toast('Link copied.');
                }).catch(function () {
                    WC.toast('Unable to copy the link.');
                });
            } else {
                WC.toast(url);
            }
        });
    }

    var commentInput = document.getElementById('comment');
    var thread = document.querySelector('.comment-thread');
    var emojiToggle = document.getElementById('comment-emoji-toggle');
    var emojiPicker = document.getElementById('comment-emoji-picker');
    var emojis = ['😀','😁','😂','😊','😍','😎','😢','😮','👍','👎','❤️','🔥','🎉','🙏','👏','✨','🙌','💪','🤝','👋','✅','❌','⭐','💯'];

    if (commentInput) {
        var resize = function () {
            commentInput.style.height = 'auto';
            commentInput.style.height = Math.min(commentInput.scrollHeight, 96) + 'px';
        };
        commentInput.addEventListener('input', resize);
        resize();
        commentInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                if (commentInput.value.trim()) {
                    commentInput.form.submit();
                }
            }
        });
    }

    if (emojiToggle && emojiPicker && commentInput) {
        emojis.forEach(function (emoji) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = emoji;
            btn.addEventListener('click', function () {
                var start = commentInput.selectionStart || commentInput.value.length;
                var end = commentInput.selectionEnd || commentInput.value.length;
                var before = commentInput.value.slice(0, start);
                var after = commentInput.value.slice(end);
                commentInput.value = before + emoji + after;
                var cursor = start + emoji.length;
                commentInput.focus();
                commentInput.setSelectionRange(cursor, cursor);
                commentInput.dispatchEvent(new Event('input'));
                emojiPicker.classList.add('d-none');
                emojiPicker.hidden = true;
            });
            emojiPicker.appendChild(btn);
        });
        emojiToggle.addEventListener('click', function (event) {
            event.preventDefault();
            var open = emojiPicker.classList.contains('d-none');
            if (open) {
                emojiPicker.classList.remove('d-none');
                emojiPicker.hidden = false;
            } else {
                emojiPicker.classList.add('d-none');
                emojiPicker.hidden = true;
            }
        });
        document.addEventListener('click', function (event) {
            if (!emojiPicker.classList.contains('d-none') && !emojiPicker.contains(event.target) && event.target !== emojiToggle && !emojiToggle.contains(event.target)) {
                emojiPicker.classList.add('d-none');
                emojiPicker.hidden = true;
            }
        });
    }

    if (thread) {
        thread.scrollTop = thread.scrollHeight;
    }
});
