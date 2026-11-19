// Test fixture only (RUB-562, spec 46). A deck script that tries to read the parent
// document. The browser refuses it; the error name goes into the speaker notes, which
// the bridge's describe returns to the parent, so the parent can check it.
(function () {
  var outcome;

  try {
    void window.parent.document;
    outcome = 'allowed';
  } catch (error) {
    outcome = error.name;
  }

  document.querySelector('p.notes').textContent = 'parent document: ' + outcome;
})();
