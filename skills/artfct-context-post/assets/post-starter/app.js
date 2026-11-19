(function () {
  var block = document.querySelector('[data-post-details]');

  if (!block || typeof window.fetch !== 'function') {
    return;
  }

  function setField(name, value) {
    var target = block.querySelector('[data-field="' + name + '"]');

    if (target) {
      target.textContent = value;
    }
  }

  function fill(data) {
    setField('kind', data.kind || 'Not set');
    setField('owner', data.owner || 'Not set');
    setField('status', data.status || 'Not set');
    setField('replaced_by', data.replaced_by || 'None');
  }

  fetch('/__artfct/db/post/details', {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Artfct-Db': '1'
    }
  })
    .then(function (response) {
      return response.ok ? response.json() : null;
    })
    .then(function (response) {
      if (response && response.data) {
        fill(response.data);
      }
    })
    .catch(function () {
      // The snapshot in the page stays visible when the data cannot be read.
    });
})();
