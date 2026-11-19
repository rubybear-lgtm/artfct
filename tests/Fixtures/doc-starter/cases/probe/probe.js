// Test fixture only: a hostile bundle script. It tries to read the parent
// (app page) document, then reports the outcome to the parent fixture so the
// browser test can assert it. The report goes to the parent window.
(function () {
  var outcome;

  try {
    outcome = window.parent.document ? 'read' : 'empty';
  } catch (error) {
    outcome = error.name;
  }

  window.parent.postMessage({ channel: 'pdf-export-probe', outcome: outcome }, '*');
})();
