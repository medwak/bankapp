// Progressive enhancement only - every page works without JavaScript.
(function () {
  'use strict';

  // Confirmation-of-payee: show the masked beneficiary name as soon as a valid account number is typed.
  var to = document.getElementById('to');
  var out = document.getElementById('payee');
  if (to && out) {
    var timer = null;
    var lookup = function () {
      var v = to.value.replace(/\s+/g, '');
      if (!/^\d{10}$/.test(v)) { out.textContent = ''; return; }
      fetch('transfer-lookup.php?acct=' + encodeURIComponent(v), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { out.textContent = j.name ? 'Account holder: ' + j.name : (j.error || ''); })
        .catch(function () { out.textContent = ''; });
    };
    to.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(lookup, 350); });
    lookup();
  }

  // Stop accidental double-submits on money-moving forms.
  document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit], button:not([type])');
      if (b) { setTimeout(function () { b.disabled = true; }, 0); }
    });
  });
})();
