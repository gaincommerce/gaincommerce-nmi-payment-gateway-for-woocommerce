(function () {
    'use strict';

    if (typeof apnmiReview === 'undefined') {
        return;
    }

    function send(action) {
        var body = new URLSearchParams();
        body.append('action', apnmiReview.action);
        body.append('nonce', apnmiReview.nonce);
        body.append('do', action);

        return fetch(apnmiReview.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).catch(function () { /* non-critical */ });
    }

    function hideAll(action) {
        // Dismissals (not snooze / setup-card close) also hide every other review prompt on the page.
        var all = document.querySelectorAll('[data-apnmi-review]');
        Array.prototype.forEach.call(all, function (el) { el.style.display = 'none'; });
    }

    document.addEventListener('click', function (e) {
        var target = e.target.closest ? e.target.closest('[data-apnmi-do], [data-apnmi-show]') : null;
        if (!target) {
            return;
        }

        var container = target.closest('[data-apnmi-review]');

        // Two-step satisfaction check: just swap panels, nothing is stored.
        var show = target.getAttribute('data-apnmi-show');
        if (show && container) {
            Array.prototype.forEach.call(container.querySelectorAll('[data-apnmi-step]'), function (step) {
                step.hidden = step.getAttribute('data-apnmi-step') !== show;
            });
            return;
        }

        var action = target.getAttribute('data-apnmi-do');
        if (!action) {
            return;
        }

        // Links (Leave a Review) keep their default behaviour: open in a new tab.
        send(action);

        if (action === 'later' || action === 'dismiss_setup') {
            if (container) { container.style.display = 'none'; }
        } else {
            hideAll(action);
        }
    });
})();
