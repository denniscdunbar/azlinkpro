/* Copy a shortcode only when the user clicks, and give explicit status feedback. */
(function () {
  'use strict';
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.azp-dashboard-copy');
    if (!button) return;
    var shortcode = button.getAttribute('data-shortcode') || '';
    var status = document.getElementById('azp-dashboard-copy-status');
    if (!shortcode) return;
    function feedback(message) { if (status) status.textContent = message; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(shortcode).then(function () {
        feedback('Shortcode copied: ' + shortcode);
      }).catch(function () {
        feedback('Could not copy automatically. Select and copy the shortcode shown in the table.');
      });
    } else {
      feedback('Select and copy the shortcode shown in the table.');
    }
  });
})();
