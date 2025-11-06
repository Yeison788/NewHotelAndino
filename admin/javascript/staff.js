function toggleCustomRole(select) {
  const wrapper = select.closest('[data-role-wrapper]');
  if (!wrapper) return;
  const customInput = wrapper.querySelector('[data-custom-role]');
  if (!customInput) return;
  const show = select.value === '__custom';
  customInput.classList.toggle('d-none', !show);
  customInput.required = show;
  if (!show) {
    customInput.value = '';
  }
}

function toggleAdminEmail(select) {
  const form = select.closest('form') || document;
  const adminField = form.querySelector('[data-admin-email]');
  if (!adminField) return;
  const input = adminField.querySelector('input');
  const isAdmin = select.value === 'administrativo';
  adminField.classList.toggle('d-none', !isAdmin);
  if (input) {
    input.required = isAdmin && adminField.closest('.modal') !== null;
    if (!isAdmin) {
      input.value = '';
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-role-select]').forEach((select) => {
    toggleCustomRole(select);
    select.addEventListener('change', () => toggleCustomRole(select));
  });

  document.querySelectorAll('[data-category-select]').forEach((select) => {
    toggleAdminEmail(select);
    select.addEventListener('change', () => toggleAdminEmail(select));
  });
});
