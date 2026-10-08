/**
 * AnswerEng panel actions. The config page is one big <form>, so each action
 * builds its own POST form outside it. The delete button only enables when
 * the typed key matches and the box is ticked; the server re-validates both.
 */
define([], function () {
    'use strict';

    return function (config, root) {
        function scopeOf(el) {
            return el.closest('[data-answereng-identity]');
        }

        function refresh(event) {
            var scope = scopeOf(event.target);
            var button = scope && scope.querySelector('[data-confirm-delete]');
            if (!button) {
                return;
            }
            button.disabled = !(scope.querySelector('[data-confirm-key]').value === scope.getAttribute('data-store-key')
                && scope.querySelector('[data-confirm-ack]').checked);
        }

        root.addEventListener('input', refresh);
        root.addEventListener('change', refresh);
        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-answereng-action]');
            if (!button || button.disabled) {
                return;
            }
            event.preventDefault();
            var confirmText = button.getAttribute('data-confirm-text');
            if (confirmText && !window.confirm(confirmText)) {
                return;
            }
            var fields = JSON.parse(button.getAttribute('data-fields') || '{}');
            if (button.hasAttribute('data-confirm-delete')) {
                var scope = scopeOf(button);
                fields.confirm_key = scope.querySelector('[data-confirm-key]').value;
                fields.acknowledge = scope.querySelector('[data-confirm-ack]').checked ? '1' : '0';
            }
            fields.form_key = window.FORM_KEY;
            var form = document.createElement('form');
            form.method = 'post';
            form.action = button.getAttribute('data-answereng-action');
            Object.keys(fields).forEach(function (name) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = fields[name];
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        });
    };
});
