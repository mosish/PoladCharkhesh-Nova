// Polad Charkhesh Nova — Admin behaviour (Phase 3 step 9). Plain JavaScript, no inline handlers (CSP).
// - Unsaved-change guard (A3 note 9): leaving a page with edited, unsubmitted forms asks first. A page
//   such as the Product editor has many forms; saving one reloads the page, so if other forms hold
//   unsaved edits the save asks before they are discarded (never silently).
// - Confirmation before destructive or public actions (Admin UX standards): forms with data-confirm.
// Loaded as a module (deferred), so the document is parsed when this runs.
const dirty = new Set();
const digits = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

// Capture phase on the document: runs before any form's own listeners, so a cancelled save stops here.
document.addEventListener('submit', event => {
  const form = event.target;
  const others = [...dirty].filter(f => f !== form);
  if (others.length > 0) {
    const ok = window.confirm(`در ${digits(others.length)} بخش دیگر این صفحه تغییرات ذخیره‌نشده دارید. این ذخیره صفحه را دوباره بارگذاری می‌کند و آن تغییرات از بین می‌رود. ادامه می‌دهید؟`);
    if (!ok) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }
}, true);

for (const form of document.querySelectorAll('form[data-confirm]')) {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  });
}

const watch = (element, form) => {
  element.addEventListener('input', () => dirty.add(form));
  element.addEventListener('change', () => dirty.add(form));
};
for (const form of document.querySelectorAll('form[data-guard]')) {
  watch(form, form);
}
// Inputs placed outside their form (table rows) belong to it through the form attribute.
for (const input of document.querySelectorAll('[form]')) {
  const form = document.getElementById(input.getAttribute('form'));
  if (form && form.hasAttribute('data-guard')) {
    watch(input, form);
  }
}

// Bubble phase on the document: runs only if no confirmation cancelled the submission. The page is
// about to be replaced, and anything still unsaved was confirmed above, so leaving is not asked again.
document.addEventListener('submit', event => {
  if (!event.defaultPrevented) {
    dirty.clear();
  }
});

window.addEventListener('beforeunload', event => {
  if (dirty.size > 0) {
    event.preventDefault();
    event.returnValue = '';
  }
});
