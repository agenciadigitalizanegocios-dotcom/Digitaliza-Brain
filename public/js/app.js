// Cérebro do Robô — JavaScript puro, sem build.
(function () {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function autosize(el) {
        el.style.height = 'auto';
        el.style.height = Math.max(el.scrollHeight + 2, 72) + 'px';
    }

    function openDialog(dialog) {
        if (!dialog) return;
        dialog.showModal();
        const first = $('input:not([type=hidden]), textarea, select', dialog);
        if (first) setTimeout(() => first.focus(), 30);
        $$('[data-autosize]', dialog).forEach(autosize);
    }

    function setImagePreview(wrap, url) {
        if (!wrap) return;
        const img = $('[data-preview]', wrap);
        const remove = $('[data-remove-wrap]', wrap);
        const box = $('[data-remove]', wrap);
        if (box) box.checked = false;
        if (url) { img.src = url; img.hidden = false; if (remove) remove.hidden = false; }
        else { img.removeAttribute('src'); img.hidden = true; if (remove) remove.hidden = true; }
    }

    // Máscara de dinheiro no padrão brasileiro: 1.234,56 (o servidor é quem decide o valor).
    function maskMoney(el) {
        const digits = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        if (!digits) { el.value = ''; return; }
        const cents = digits.padStart(3, '0');
        const int = cents.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        el.value = int + ',' + cents.slice(-2);
    }

    document.addEventListener('DOMContentLoaded', () => {
        $$('[data-money]').forEach((el) => el.addEventListener('input', () => maskMoney(el)));
        $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form && el.form.submit()));

        // Prévia da imagem escolhida.
        $$('[data-image-input]').forEach((input) => input.addEventListener('change', () => {
            const wrap = input.closest('[data-image-field]');
            const file = input.files && input.files[0];
            if (file) setImagePreview(wrap, URL.createObjectURL(file));
        }));

        // Modal de campo (editor de estrutura).
        const fieldModal = $('#field-modal');
        if (fieldModal) {
            const ff = $('form', fieldModal);
            const setF = (name, v) => {
                const el = $('[data-f="' + name + '"]', ff);
                if (!el) return;
                if (el.type === 'checkbox') el.checked = !!v; else el.value = v === null || v === undefined ? '' : v;
            };
            const openField = (f, action, method, locked) => {
                ff.action = action;
                $('[name=_method]', ff).value = method;
                $('[data-title]', fieldModal).textContent = method === 'PUT' ? 'Editar campo' : 'Novo campo';
                $('[data-locked-note]', fieldModal).hidden = !locked;
                setF('label', f.label || '');
                setF('type', f.type || 'text');
                $('[data-f="type"]', ff).disabled = false;
                setF('required', f.required);
                setF('send_to_ai', f.send_to_ai === undefined ? true : f.send_to_ai);
                setF('public', f.public === undefined ? true : f.public);
                setF('placeholder', f.placeholder);
                setF('help', f.help);
                setF('min', f.min);
                setF('max', f.max);
                setF('default', f.default);
                const opts = f.options ? Object.keys(f.options).map((k) => k + ': ' + f.options[k]).join('\n') : '';
                setF('options', opts);
                setF('archived_options', (f.archived_options || []).join(', '));
                openDialog(fieldModal);
            };
            $$('[data-new-field]').forEach((b) => b.addEventListener('click', () => openField({}, ff.dataset.storeAction, 'POST', false)));
            $$('[data-edit-field]').forEach((b) => b.addEventListener('click', () => {
                openField(JSON.parse(b.dataset.editField || '{}'), b.dataset.action, 'PUT', b.dataset.locked === '1');
            }));
        }

        // Textareas crescem com o texto.
        $$('[data-autosize]').forEach((el) => {
            autosize(el);
            el.addEventListener('input', () => autosize(el));
        });

        // Fechar modais.
        $$('dialog').forEach((dialog) => {
            $$('[data-close]', dialog).forEach((b) => b.addEventListener('click', () => dialog.close()));
            dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
        });

        // Botões que abrem um modal qualquer (ex.: novo cliente).
        $$('[data-open-dialog]').forEach((b) => b.addEventListener('click', () => openDialog($(b.dataset.openDialog))));
        $$('dialog[data-reopen="1"]').forEach(openDialog);

        // Modal de adicionar/editar item.
        const modal = $('#item-modal');
        if (modal) {
            const form = $('form', modal);
            const title = $('[data-title]', modal);

            const setMode = (mode, action) => {
                form.action = action;
                $('[name=_method]', form).value = mode === 'edit' ? 'PUT' : 'POST';
                $('[name=_action]', form).value = action;
                $('[name=_mode]', form).value = mode;
                title.textContent = mode === 'edit' ? form.dataset.editTitle : form.dataset.newTitle;
            };

            const clearErrors = () => {
                $$('.field-error', form).forEach((e) => e.remove());
                $$('.has-error', form).forEach((e) => e.classList.remove('has-error'));
            };

            const fill = (values) => {
                $$('[data-field]', form).forEach((el) => {
                    const key = el.dataset.field;
                    const has = values && Object.prototype.hasOwnProperty.call(values, key);
                    if (el.type === 'file') {
                        el.value = '';
                        setImagePreview(el.closest('[data-image-field]'), has ? values[key] : null);
                    } else if (el.type === 'checkbox') {
                        el.checked = has ? !!values[key] : el.dataset.default === '1';
                    } else {
                        el.value = has && values[key] !== null ? values[key] : '';
                    }
                });
            };

            $$('[data-open-modal]').forEach((b) => b.addEventListener('click', () => {
                clearErrors();
                fill({});
                setMode('new', form.dataset.storeAction);
                openDialog(modal);
            }));

            $$('[data-edit]').forEach((b) => b.addEventListener('click', () => {
                const data = JSON.parse(b.dataset.edit || '{}');
                clearErrors();
                fill(data.fields || {});
                setMode('edit', b.dataset.action);
                openDialog(modal);
            }));

            // Voltou com erro de validação: reabre o modal com o que foi digitado.
            if (modal.dataset.reopen) {
                try {
                    const r = JSON.parse(modal.dataset.reopen);
                    setMode(r.mode === 'edit' ? 'edit' : 'new', r.action);
                    openDialog(modal);
                } catch (e) { /* ignora */ }
            }
        }

        // Confirmação antes de excluir, restaurar etc.
        $$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => {
            if (!window.confirm(f.dataset.confirm)) e.preventDefault();
        }));

        // Copiar texto (prompt, token).
        $$('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
            const target = $(b.dataset.copy);
            if (!target) return;
            const text = target.innerText;
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                const range = document.createRange();
                range.selectNodeContents(target);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                document.execCommand('copy');
                sel.removeAllRanges();
            }
            const old = b.textContent;
            b.textContent = 'Copiado ✓';
            setTimeout(() => { b.textContent = old; }, 1600);
        }));

        // Avisa se sair da página com alteração não salva.
        $$('form[data-dirty-check]').forEach((f) => {
            let dirty = false;
            const badge = $('.unsaved', f);
            f.addEventListener('input', () => {
                dirty = true;
                if (badge) badge.hidden = false;
            });
            f.addEventListener('submit', () => { dirty = false; });
            window.addEventListener('beforeunload', (e) => {
                if (dirty) { e.preventDefault(); e.returnValue = ''; }
            });
        });

        // Mensagem de sucesso some sozinha.
        $$('[data-autohide]').forEach((el) => setTimeout(() => el.classList.add('hide'), 4500));
    });
})();
