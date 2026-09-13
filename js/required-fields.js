(function () {
  'use strict';

  function createMark() {
    const mark = document.createElement('span');
    mark.className = 'required-mark';
    mark.setAttribute('aria-hidden', 'true');
    mark.textContent = '*';
    return mark;
  }

  function findDirectTextNode(element) {
    return Array.from(element.childNodes).find(node =>
      node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== ''
    );
  }

  function addMark(element) {
    if (element.querySelector(':scope > .required-mark, :scope > .required-label-text > .required-mark')) {
      return;
    }

    const textNode = findDirectTextNode(element);
    if (textNode) {
      const caption = document.createElement('span');
      caption.className = 'required-label-text';
      caption.textContent = textNode.textContent.trim();
      caption.appendChild(createMark());
      textNode.replaceWith(caption);
      return;
    }

    element.appendChild(createMark());
  }

  function removeMark(element) {
    const mark = element.querySelector(':scope > .required-mark, :scope > .required-label-text > .required-mark');
    if (mark) mark.remove();
  }

  function syncRequiredMarks(root) {
    root.querySelectorAll('label, [data-required-label]').forEach(element => {
      const explicitlyRequired = element.hasAttribute('data-required-label');
      const requiredControl = element.matches('label')
        ? element.querySelector('input[required], select[required], textarea[required]')
        : null;
      if (explicitlyRequired || requiredControl) {
        addMark(element);
      } else {
        removeMark(element);
      }
    });
  }

  function initialize(form) {
    syncRequiredMarks(form);
    const observer = new MutationObserver(() => syncRequiredMarks(form));
    observer.observe(form, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['required']
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-required-fields]').forEach(initialize);
  });
})();
