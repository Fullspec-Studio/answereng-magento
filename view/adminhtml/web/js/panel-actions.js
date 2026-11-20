/**
 * AnswerEng panel actions. The config page is one big <form>, so each action
 * builds its own POST form outside it. The delete button only enables when
 * the typed key matches and the box is ticked; the server re-validates both.
 * The activity log shows its first rows and toggles the rest. Copy draft puts a
 * fix's text on the clipboard; without the Clipboard API it selects the draft.
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

        function selectDraft(button) {
            var fix = button.closest('[data-answereng-fix]');
            var draft = fix && fix.querySelector('pre');
            if (!draft || !window.getSelection) {
                return;
            }
            var range = document.createRange();
            range.selectNodeContents(draft);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }

        function copied(button) {
            if (!button.hasAttribute('data-label-default')) {
                button.setAttribute('data-label-default', button.textContent.trim());
            }
            button.textContent = button.getAttribute('data-label-copied');
            window.clearTimeout(button.aeCopyTimer);
            button.aeCopyTimer = window.setTimeout(function () {
                button.textContent = button.getAttribute('data-label-default');
            }, 2000);
        }

        function copyDraft(button) {
            var text = button.getAttribute('data-answereng-copy');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    copied(button);
                }, function () {
                    selectDraft(button);
                });
                return;
            }
            selectDraft(button);
        }

        root.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target.hasAttribute && event.target.hasAttribute('data-confirm-key')) {
                event.preventDefault();
            }
        });
        root.addEventListener('input', refresh);
        root.addEventListener('change', refresh);
        root.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-answereng-toggle-log]');
            if (toggle) {
                event.preventDefault();
                var open = toggle.getAttribute('aria-expanded') !== 'true';
                toggle.closest('[data-answereng-log]').querySelectorAll('.ae-log-more').forEach(function (row) {
                    row.hidden = !open;
                });
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.textContent = toggle.getAttribute(open ? 'data-label-less' : 'data-label-more');
                return;
            }
            var copy = event.target.closest('[data-answereng-copy]');
            if (copy) {
                event.preventDefault();
                copyDraft(copy);
                return;
            }
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
