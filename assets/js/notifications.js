/* Notification forms only need the mark-read button; Open is a normal link. */
(function () {
    var forms = document.querySelectorAll('.person-card form');
    Array.prototype.forEach.call(forms, function (form) {
        form.addEventListener('submit', function () {
            window.setTimeout(function () {
                var button = form.querySelector('button');
                if (button) {
                    button.disabled = true;
                }
            }, 0);
        });
    });
})();
