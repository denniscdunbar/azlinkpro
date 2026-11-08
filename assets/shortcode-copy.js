/* Shared one-click shortcode copy: delegated for both WP listing columns and editor boxes. */
(function () {
  'use strict';
  function fallbackCopy(text) {
    var field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.setAttribute('aria-hidden', 'true');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.focus();
    field.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (error) { ok = false; }
    field.remove();
    return ok;
  }
  function announce(button, message, success) {
    var parent = button.closest('.azp-copy-control');
    var status = parent ? parent.querySelector('.azp-copy-status') : null;
    if (status) { status.textContent = message; }
    button.setAttribute('data-copy-result', success ? 'success' : 'error');
    button.title = success ? 'Copied!' : 'Copy shortcode';
  }
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.azp-copy-button');
    if (!button || button.disabled) { return; }
    var shortcode = button.getAttribute('data-shortcode');
    if (!shortcode) { announce(button, 'Nothing to copy.', false); return; }
    function success() { announce(button, 'Copied!', true); }
    function failure() {
      if (fallbackCopy(shortcode)) { success(); return; }
      var parent = button.closest('.azp-copy-control');
      var input = parent ? parent.querySelector('.azp-copy-value') : null;
      if (input) { input.focus(); input.select(); }
      announce(button, 'Copy unavailable; select the shortcode and copy manually.', false);
    }
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(shortcode).then(success, failure);
    } else { failure(); }
  });
})();
