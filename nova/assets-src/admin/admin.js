// Polad Charkhesh Nova — Admin behaviour (Phase 3 step 9). Plain JavaScript, no inline handlers (CSP).
// - Confirmation before destructive or public actions (Admin UX standards): forms with data-confirm.
// - Unsaved-change guard (A3 note 9): leaving a page with edited, unsubmitted forms asks first.
// Loaded as a module (deferred), so the document is parsed when this runs.
for (const form of document.querySelectorAll('form[data-confirm]')) {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  });
}

const dirty = new Set();
const watch = (element, form) => {
  element.addEventListener('input', () => dirty.add(form));
  element.addEventListener('change', () => dirty.add(form));
};
for (const form of document.querySelectorAll('form[data-guard]')) {
  watch(form, form);
  form.addEventListener('submit', () => dirty.clear());
}
// Inputs placed outside their form (table rows) belong to it through the form attribute.
for (const input of document.querySelectorAll('[form]')) {
  const form = document.getElementById(input.getAttribute('form'));
  if (form && form.hasAttribute('data-guard')) {
    watch(input, form);
  }
}

window.addEventListener('beforeunload', event => {
  if (dirty.size > 0) {
    event.preventDefault();
    event.returnValue = '';
  }
});
