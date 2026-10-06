document.addEventListener('DOMContentLoaded', function () {
    var name = document.querySelector('#name');
    if (name && name.focus) {
        name.focus();
    }
});
