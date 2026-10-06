document.addEventListener('DOMContentLoaded', function () {
    var avatar = document.getElementById('avatar');
    var nameEl = document.getElementById('avatar-name');
    if (avatar && nameEl) {
        avatar.addEventListener('change', function (event) {
            var file = event.target.files && event.target.files[0];
            nameEl.textContent = file ? file.name : '';
        });
    }
});
