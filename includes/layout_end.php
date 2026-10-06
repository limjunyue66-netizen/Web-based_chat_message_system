<?php
declare(strict_types=1);
$layout = $layout ?? 'app';
?>
<?php if ($layout === 'guest'): ?>
    </main>
<?php else: ?>
        </div>
    </div>
    <div id="call-overlay" class="call-overlay d-none" role="dialog" aria-modal="true" aria-labelledby="call-name">
        <div class="call-stage">
            <video id="remote-video" autoplay playsinline></video>
            <video id="local-video" class="d-none" autoplay playsinline muted></video>
            <audio id="remote-audio" autoplay></audio>
            <div class="call-center" id="call-center">
                <img id="call-avatar" alt="">
                <h2 id="call-name">Call</h2>
                <p id="call-status" aria-live="polite">Calling...</p>
                <p id="call-timer" class="call-timer d-none">0:00</p>
            </div>
            <div class="call-controls">
                <button type="button" id="call-mute" class="call-btn" aria-pressed="false" title="Mute my microphone"><i class="fa-solid fa-microphone"></i></button>
                <button type="button" id="call-deaf" class="call-btn" aria-pressed="false" title="Mute their microphone"><i class="fa-solid fa-microphone-lines-slash"></i></button>
                <button type="button" id="call-camera" class="call-btn d-none" aria-pressed="false" title="Turn camera off"><i class="fa-solid fa-video"></i></button>
                <button type="button" id="call-accept" class="call-btn call-accept d-none" title="Accept call"><i class="fa-solid fa-phone"></i></button>
                <button type="button" id="call-end" class="call-btn call-end" title="End call"><i class="fa-solid fa-phone-slash"></i></button>
            </div>
        </div>
    </div>
    <div class="modal-backdrop-custom d-none" id="report-modal">
        <form class="sheet" id="report-form">
            <h2>Report</h2>
            <p class="text-secondary">Tell an administrator what happened. Reports are reviewed in the admin panel.</p>
            <input type="hidden" name="target_type" id="report-type">
            <input type="hidden" name="target_id" id="report-id">
            <label class="form-label" for="report-reason">Reason</label>
            <textarea class="form-control" id="report-reason" name="reason" minlength="10" maxlength="500" required placeholder="Describe the problem"></textarea>
            <div class="sheet-actions">
                <button type="button" class="btn btn-light" id="report-cancel">Cancel</button>
                <button type="submit" class="btn btn-accent">Submit report</button>
            </div>
        </form>
    </div>
    <div class="modal-backdrop-custom d-none" id="forward-modal">
        <form class="sheet forward-sheet" id="forward-form">
            <h2>Forward message</h2>
            <p class="text-secondary">Choose friends or groups to share this message with.</p>
            <div id="forward-list" class="forward-list"></div>
            <div class="sheet-actions">
                <button type="button" class="btn btn-light" id="forward-cancel">Cancel</button>
                <button type="submit" class="btn btn-accent" id="forward-send">Forward</button>
            </div>
        </form>
    </div>
    <div id="msg-context-menu" class="msg-context-menu d-none" role="menu" hidden>
        <div class="msg-context-reacts" id="msg-context-reacts"></div>
        <div class="msg-context-items" id="msg-context-items"></div>
    </div>
    <div class="modal-backdrop-custom d-none" id="delete-confirm-modal">
        <div class="sheet" role="dialog" aria-labelledby="delete-confirm-title">
            <h2 id="delete-confirm-title">Delete messages?</h2>
            <p class="text-secondary" id="delete-confirm-copy">Selected messages will be removed from this chat on your side only.</p>
            <div class="sheet-actions">
                <button type="button" class="btn btn-light" id="delete-confirm-cancel">Cancel</button>
                <button type="button" class="btn btn-accent" id="delete-confirm-ok">Delete for me</button>
            </div>
        </div>
    </div>
    <div class="modal-backdrop-custom d-none" id="call-pick-modal">
        <div class="sheet" role="dialog" aria-labelledby="call-pick-title">
            <h2 id="call-pick-title">Call a member</h2>
            <p class="text-secondary" id="call-pick-copy">Choose who to call from this group.</p>
            <div id="call-pick-list" class="forward-list"></div>
            <div class="sheet-actions">
                <button type="button" class="btn btn-light" id="call-pick-cancel">Cancel</button>
            </div>
        </div>
    </div>
<?php endif; ?>
<div id="toast-stack" class="toast-stack" aria-live="polite"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
<?php if (($layout ?? 'app') !== 'guest' && !empty($me)): ?>
    <script src="<?= e(asset_url('assets/js/calls.js')) ?>"></script>
<?php endif; ?>
<?php if (!empty($pageScript)): ?>
    <script src="<?= e(asset_url('assets/js/' . $pageScript)) ?>"></script>
<?php endif; ?>
</body>
</html>
