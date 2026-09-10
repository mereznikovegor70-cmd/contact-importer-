'use strict';
document.querySelectorAll('[data-back]').forEach(button => button.addEventListener('click', () => history.back()));
document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {
  if (form.dataset.confirm && !confirm(form.dataset.confirm)) { event.preventDefault(); return; }
  if (form.hasAttribute('data-busy')) {
    const button = form.querySelector('button[type="submit"], button:not([type])');
    if (button) { button.disabled = true; button.textContent = button.dataset.busyLabel || 'Сохраняем…'; }
  }
}));
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const input = document.getElementById(button.dataset.copy);
  try { await navigator.clipboard.writeText(input.value); button.textContent = 'Скопировано'; }
  catch { input.focus(); input.select(); button.textContent = 'Нажмите Ctrl+C'; }
}));
document.querySelectorAll('[data-file-input]').forEach(input => {
  const zone = input.closest('.dropzone');
  const show = () => { zone.querySelector('[data-filename]').textContent = input.files[0]?.name || ''; };
  input.addEventListener('change', show);
  zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('dragging'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragging'));
  zone.addEventListener('drop', event => { event.preventDefault(); zone.classList.remove('dragging'); if(event.dataTransfer.files.length) { input.files = event.dataTransfer.files; show(); } });
});
document.querySelectorAll('.mapping-grid fieldset').forEach(field => {
  const update = () => { field.querySelector('[data-selection-count]').textContent = field.querySelectorAll('input:checked').length; };
  field.addEventListener('change', update); update();
});
