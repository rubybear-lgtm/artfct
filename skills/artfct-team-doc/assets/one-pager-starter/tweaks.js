(function () {
  var DECLARATION_PATH = 'tweaks.json';
  var SESSION_PATH = '/__artfct/db/session';
  var FIELDS_PATH = '/__artfct/db/tweaks/fields';
  var JSON_HEADERS = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Artfct-Db': '1'
  };
  var TYPES = ['text', 'choice', 'color', 'toggle', 'range', 'image'];
  var NAME = /^[A-Za-z][A-Za-z0-9_]{0,63}$/;
  var HEX = /^#[0-9a-fA-F]{6}$/;
  var IMAGE_PATH = /^[A-Za-z0-9][A-Za-z0-9._/-]*\.(png|jpe?g|gif|webp|svg)$/i;
  var NOT_IN_BUNDLE = 'That file is not in this bundle.';

  var fields = [];
  var values = {};
  var controls = {};
  var placeholders = new Map();
  var panel = null;

  function messageOf(error) {
    return error && error.message ? error.message : 'Something went wrong.';
  }

  function findField(name) {
    for (var i = 0; i < fields.length; i++) {
      if (fields[i].name === name) {
        return fields[i];
      }
    }

    return null;
  }

  // Synchronous shape check. An image path is also checked against the bundle
  // on save, because that check needs a request.
  function shapeError(field, value) {
    switch (field.type) {
      case 'text':
        if (typeof value !== 'string') {
          return 'Use text.';
        }

        if (field.max !== undefined && Array.from(value).length > field.max) {
          return 'Use at most ' + field.max + ' characters.';
        }

        return null;
      case 'choice':
        return field.choices.indexOf(value) === -1 ? 'Choose one of the listed choices.' : null;
      case 'color':
        return typeof value === 'string' && HEX.test(value) ? null : 'Use a hex colour such as #701a24.';
      case 'toggle':
        return typeof value === 'boolean' ? null : 'Use on or off.';
      case 'range':
        return typeof value === 'number' && isFinite(value) && value >= field.min && value <= field.max
          ? null
          : 'Use a number from ' + field.min + ' to ' + field.max + '.';
      case 'image':
        if (value === '') {
          return null;
        }

        return typeof value === 'string' && IMAGE_PATH.test(value) && value.indexOf('..') === -1
          ? null
          : 'Use a file in this bundle, such as logo.png, or leave it empty.';
      default:
        return 'Unknown type.';
    }
  }

  function declarationError(list) {
    if (!Array.isArray(list) || list.length === 0) {
      return 'tweaks.json must be a non-empty array of fields.';
    }

    var seen = {};

    for (var i = 0; i < list.length; i++) {
      var field = list[i];

      if (!field || typeof field !== 'object' || typeof field.name !== 'string' || !NAME.test(field.name)) {
        return 'tweaks.json field ' + i + ' needs a name of letters, digits and underscores.';
      }

      if (seen[field.name]) {
        return 'tweaks.json names [' + field.name + '] twice.';
      }

      seen[field.name] = true;

      if (typeof field.label !== 'string' || TYPES.indexOf(field.type) === -1) {
        return 'tweaks.json field [' + field.name + '] needs a label and one of the types ' + TYPES.join(', ') + '.';
      }

      if (field.type === 'choice' && (!Array.isArray(field.choices) || field.choices.length === 0)) {
        return 'tweaks.json field [' + field.name + '] needs a non-empty choices list.';
      }

      if (field.type === 'range' && (typeof field.min !== 'number' || typeof field.max !== 'number' || field.min > field.max)) {
        return 'tweaks.json field [' + field.name + '] needs numeric min and max, with min at most max.';
      }

      if (shapeError(field, field.default) !== null) {
        return 'tweaks.json default for [' + field.name + '] is not valid for its type.';
      }
    }

    return null;
  }

  // Resolves to an error string, or null when the value may be saved.
  function bundleFileError(path) {
    return fetch(path, { credentials: 'same-origin' }).then(
      function (response) {
        return response.ok ? null : NOT_IN_BUNDLE;
      },
      function () {
        return NOT_IN_BUNDLE;
      }
    );
  }

  function checkValue(field, value) {
    var error = shapeError(field, value);

    if (error !== null || field.type !== 'image' || value === '') {
      return Promise.resolve(error);
    }

    return bundleFileError(value);
  }

  function showPageError(message) {
    var element = document.createElement('p');
    element.className = 'tweak-error';
    element.setAttribute('role', 'alert');
    element.setAttribute('data-tweak-error', '');
    element.textContent = message;
    document.body.appendChild(element);
  }

  function readStored() {
    return fetch(FIELDS_PATH, { method: 'GET', credentials: 'same-origin', headers: JSON_HEADERS })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(
        function (stored) {
          return stored && stored.data && typeof stored.data === 'object' ? stored.data : {};
        },
        function () {
          return {};
        }
      );
  }

  function readSession() {
    return fetch(SESSION_PATH, { method: 'GET', credentials: 'same-origin', headers: JSON_HEADERS })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .catch(function () {
        return null;
      });
  }

  function showValue(field, value, element) {
    if (field.type === 'image') {
      if (value === '') {
        element.textContent = placeholders.get(element);

        return;
      }

      bundleFileError(value).then(function (error) {
        if (error !== null) {
          return;
        }

        var image = document.createElement('img');
        image.alt = field.label;
        image.src = value;
        element.textContent = '';
        element.appendChild(image);
      });

      return;
    }

    element.textContent = field.type === 'toggle' ? (value ? 'Yes' : 'No') : String(value);
  }

  function fillPage() {
    fields.forEach(function (field) {
      var value = shapeError(field, values[field.name]) === null ? values[field.name] : field.default;
      var elements = document.querySelectorAll('[data-tweak="' + field.name + '"]');

      Array.prototype.forEach.call(elements, function (element) {
        showValue(field, value, element);
      });
    });
  }

  function showControlError(name, error) {
    var control = controls[name];

    if (!control) {
      return;
    }

    control.error.textContent = error || '';
    control.input.setAttribute('aria-invalid', error ? 'true' : 'false');
  }

  function setStatus(message) {
    if (panel) {
      panel.status.textContent = message;
    }
  }

  function createInput(field, value) {
    var input;

    if (field.type === 'choice') {
      input = document.createElement('select');
      field.choices.forEach(function (choice) {
        var option = document.createElement('option');
        option.value = choice;
        option.textContent = choice;
        option.selected = choice === value;
        input.appendChild(option);
      });

      return input;
    }

    input = document.createElement('input');

    if (field.type === 'toggle') {
      input.type = 'checkbox';
      input.checked = value === true;
    } else if (field.type === 'range') {
      input.type = 'range';
      input.min = String(field.min);
      input.max = String(field.max);
      input.step = String(field.step || 1);
      input.value = String(value);
    } else {
      input.type = 'text';
      input.value = value === undefined || value === null ? '' : String(value);
    }

    return input;
  }

  // A control the reader has not touched keeps the stored value, so a stored
  // value the control cannot show (an out-of-range number, a choice that is not
  // listed) is still checked on save instead of being replaced by the control.
  function markDirty(name) {
    return function () {
      if (controls[name]) {
        controls[name].dirty = true;
      }
    };
  }

  function readControl(field) {
    var control = controls[field.name];

    if (field.type === 'toggle') {
      return control.input.checked;
    }

    if (field.type === 'range') {
      return Number(control.input.value);
    }

    return control.input.value;
  }

  function buildPanel() {
    var section = document.createElement('section');
    section.className = 'tweak-panel';
    section.setAttribute('data-tweak-panel', '');
    section.setAttribute('aria-label', 'Edit fields');

    var heading = document.createElement('h2');
    heading.textContent = 'Edit fields';

    var form = document.createElement('form');
    form.noValidate = true;

    fields.forEach(function (field) {
      var wrapper = document.createElement('div');
      wrapper.className = 'tweak-field';

      var label = document.createElement('label');
      var input = createInput(field, values[field.name]);
      input.id = 'tweak-' + field.name;
      label.htmlFor = input.id;
      label.textContent = field.label;

      var error = document.createElement('p');
      error.className = 'tweak-field-error';
      error.setAttribute('data-tweak-error-for', field.name);
      error.setAttribute('aria-live', 'polite');

      wrapper.appendChild(label);
      wrapper.appendChild(input);
      wrapper.appendChild(error);
      form.appendChild(wrapper);

      controls[field.name] = { input: input, error: error, dirty: false };
      input.addEventListener('input', markDirty(field.name));
      input.addEventListener('change', markDirty(field.name));
    });

    var saveButton = document.createElement('button');
    saveButton.type = 'submit';
    saveButton.textContent = 'Save';
    form.appendChild(saveButton);

    var status = document.createElement('p');
    status.className = 'tweak-status';
    status.setAttribute('role', 'status');
    form.appendChild(status);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      save();
    });

    section.appendChild(heading);
    section.appendChild(form);
    document.body.appendChild(section);

    panel = { section: section, status: status };
  }

  function save() {
    var body = {};

    fields.forEach(function (field) {
      body[field.name] = controls[field.name].dirty ? readControl(field) : values[field.name];
    });

    setStatus('Saving…');

    Promise.all(
      fields.map(function (field) {
        return checkValue(field, body[field.name]).then(function (error) {
          showControlError(field.name, error);

          return error;
        });
      })
    )
      .then(function (errors) {
        if (errors.some(function (error) {
 return error !== null; 
})) {
          setStatus('Fix the highlighted fields before saving.');

          return null;
        }

        return readSession().then(function (session) {
          if (!session || session.role !== 'writer' || !session.token) {
            throw new Error('This session cannot save changes. Reload the page and try again.');
          }

          return fetch(FIELDS_PATH, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: Object.assign({}, JSON_HEADERS, { Authorization: 'Bearer ' + session.token }),
            body: JSON.stringify(body)
          }).then(function (response) {
            if (!response.ok) {
              throw new Error('Save failed (' + response.status + ').');
            }

            values = body;
            fillPage();
            setStatus('Saved.');
          });
        });
      })
      .catch(function (error) {
        setStatus(messageOf(error));
      });
  }

  function start() {
    fetch(DECLARATION_PATH, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('tweaks.json is missing from this bundle, so the tweak fields cannot load.');
        }

        return response.text();
      })
      .then(function (text) {
        var list;

        try {
          list = JSON.parse(text);
        } catch {
          throw new Error('tweaks.json is not valid JSON, so the tweak fields cannot load.');
        }

        var problem = declarationError(list);

        if (problem !== null) {
          throw new Error(problem);
        }

        fields = list;

        return Promise.all([readStored(), readSession()]);
      })
      .then(function (results) {
        var stored = results[0] || {};
        var session = results[1];

        fields.forEach(function (field) {
          values[field.name] = Object.prototype.hasOwnProperty.call(stored, field.name)
            ? stored[field.name]
            : field.default;
        });

        document.querySelectorAll('[data-tweak]').forEach(function (element) {
          placeholders.set(element, element.textContent);
        });

        fillPage();

        if (session && session.role === 'writer') {
          buildPanel();
          fields.forEach(function (field) {
            showControlError(field.name, shapeError(field, values[field.name]));
          });
        }
      })
      .catch(function (error) {
        showPageError(messageOf(error));
      });
  }

  window.artfctTweaks = {
    check: function (name, value) {
      var field = findField(name);

      return field ? shapeError(field, value) : 'Unknown field.';
    }
  };

  start();
})();
