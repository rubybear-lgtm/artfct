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

  function capitalise(value) {
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Not set';
  }

  function fill(data) {
    setField('kind', capitalise(data.kind));
    setField('owner', data.owner || 'Not set');
    setField('status', capitalise(data.status));

    // The snapshot may already link to the newer post; keep that link unless
    // the data says there is no replacement.
    var replacedBy = block.querySelector('[data-field="replaced_by"]');

    if (data.replaced_by === null || data.replaced_by === undefined) {
      setField('replaced_by', 'None');
    } else if (replacedBy && !replacedBy.querySelector('a')) {
      setField('replaced_by', data.replaced_by);
    }
  }

  function readJson(response) {
    return response.ok ? response.json() : null;
  }

  fetch('/__artfct/db/session', {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Artfct-Db': '1'
    }
  })
    .then(readJson)
    .then(function (session) {
      if (!session || !session.token) {
        return null;
      }

      return fetch('/__artfct/db/post/details', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + session.token,
          'X-Artfct-Db': '1'
        }
      }).then(readJson);
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
