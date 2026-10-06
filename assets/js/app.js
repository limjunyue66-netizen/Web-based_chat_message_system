window.WC = window.WC || {};

WC.escape = function (value) {
    var text = value === undefined || value === null ? '' : String(value);
    return text.replace(/[&<>"']/g, function (char) {
        return ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[char];
    });
};

WC.buildUrl = function (path, query) {
    // Stay on the current page origin so localhost / 127.0.0.1 cookies match.
    var prefix = '';
    if (WC_BOOT && WC_BOOT.basePath) {
        prefix = String(WC_BOOT.basePath).replace(/\/$/, '');
    } else {
        try {
            prefix = new URL((WC_BOOT && WC_BOOT.baseUrl) || window.location.href, window.location.origin).pathname.replace(/\/$/, '');
        } catch (error) {
            prefix = '';
        }
    }
    var url = new URL(window.location.origin + prefix + '/' + String(path).replace(/^\//, ''));
    if (query) {
        Object.keys(query).forEach(function (key) {
            var value = query[key];
            if (value !== undefined && value !== null && value !== '') {
                url.searchParams.set(key, value);
            }
        });
    }
    return url.toString();
};

WC.api = function (path, options) {
    var settings = options || {};
    var method = settings.method || 'GET';
    var headers = {};
    if (settings.headers) {
        Object.keys(settings.headers).forEach(function (key) {
            headers[key] = settings.headers[key];
        });
    }
    if (method !== 'GET') {
        headers['X-CSRF-Token'] = WC_BOOT.csrf;
    }
    var request = { method: method, credentials: 'same-origin', headers: headers, cache: 'no-store' };
    if (settings.json) {
        headers['Content-Type'] = 'application/json';
        if (!settings.json.csrf_token) {
            settings.json.csrf_token = WC_BOOT.csrf;
        }
        request.body = JSON.stringify(settings.json);
    } else if (settings.form) {
        if (window.FormData && settings.form instanceof FormData) {
            var hasCsrf = typeof settings.form.has === 'function' ? settings.form.has('csrf_token') : false;
            if (!hasCsrf) {
                settings.form.append('csrf_token', WC_BOOT.csrf);
            }
        }
        request.body = settings.form;
    }

    var query = {};
    if (settings.query) {
        Object.keys(settings.query).forEach(function (key) {
            query[key] = settings.query[key];
        });
    }
    if (method === 'GET') {
        query._ = String(Date.now());
    }

    return fetch(WC.buildUrl(path, query), request).then(function (response) {
        return response.json().then(function (payload) {
            return { response: response, payload: payload };
        }).catch(function () {
            return { response: response, payload: { success: false, message: 'Unexpected server response.' } };
        });
    }).then(function (result) {
        var response = result.response;
        var payload = result.payload || { success: false, message: 'Unexpected server response.' };
        if (response.status === 401) {
            window.location.href = WC.buildUrl('login.php');
            throw new Error(payload.message || 'Unauthorized');
        }
        if (!response.ok || !payload.success) {
            var failure = new Error(payload.message || 'Request failed.');
            failure.status = response.status;
            throw failure;
        }
        return payload;
    });
};

WC.toast = function (message) {
    var stack = document.getElementById('toast-stack');
    if (!stack) {
        return;
    }
    var item = document.createElement('div');
    item.className = 'toast-item';
    item.textContent = message;
    stack.appendChild(item);
    setTimeout(function () {
        if (item.parentNode) {
            item.parentNode.removeChild(item);
        }
    }, 4200);
};

WC.openReport = function (type, id) {
    var modal = document.getElementById('report-modal');
    if (!modal) {
        return;
    }
    document.getElementById('report-type').value = type;
    document.getElementById('report-id').value = String(id);
    document.getElementById('report-reason').value = '';
    modal.classList.remove('d-none');
};

document.addEventListener('submit', function (event) {
    var submitter = event.submitter || document.activeElement;
    var message = '';
    if (submitter && submitter.getAttribute) {
        message = submitter.getAttribute('data-confirm') || '';
    }
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var reportForm = document.getElementById('report-form');
    var reportModal = document.getElementById('report-modal');
    var reportCancel = document.getElementById('report-cancel');
    if (reportCancel) {
        reportCancel.addEventListener('click', function () {
            if (reportModal) {
                reportModal.classList.add('d-none');
            }
        });
    }
    if (reportForm) {
        reportForm.addEventListener('submit', function (event) {
            event.preventDefault();
            WC.api('api/reports.php', {
                method: 'POST',
                json: {
                    target_type: document.getElementById('report-type').value,
                    target_id: Number(document.getElementById('report-id').value),
                    reason: document.getElementById('report-reason').value
                }
            }).then(function () {
                if (reportModal) {
                    reportModal.classList.add('d-none');
                }
                WC.toast('Report submitted.');
            }).catch(function (error) {
                WC.toast(error.message);
            });
        });
    }

    if (!window.WC_BOOT || !WC_BOOT.me) {
        return;
    }

    var badge = document.getElementById('nav-badge');
    function refreshBadge() {
        WC.api('api/notifications.php', { query: { action: 'count' } }).then(function (result) {
            if (!badge) {
                return;
            }
            var unread = Number((result.data && result.data.unread) || 0);
            badge.textContent = String(unread);
            if (unread < 1) {
                badge.classList.add('d-none');
            } else {
                badge.classList.remove('d-none');
            }
        }).catch(function () {});
    }
    refreshBadge();
    setInterval(refreshBadge, 5000);
    setInterval(function () {
        WC.api('api/presence.php', { query: { action: 'ping' } }).catch(function () {});
    }, 20000);
});
