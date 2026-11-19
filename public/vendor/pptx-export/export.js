/*
 * PowerPoint export runner (RUB-562, spec 46). Runs in the app origin and only
 * receives data. It embeds the deck at its isolated artifact origin in a sandboxed
 * frame, asks the deck starter's bridge for one PNG per slide, and posts the PNGs
 * to the export route, which writes the .pptx. Artifact script never runs here.
 *
 * Usage: window.artfctPptxExport.run({ frameUrl, exportUrl, onProgress })
 * resolves to { filename, bytes, captures } after the download starts, and
 * rejects with an Error whose `code` is one of: capture_unsupported,
 * capture_failed, capture_unavailable, timeout, export_failed.
 */
(function () {
  'use strict';

  var CHANNEL = 'artfct-export';
  var PROTOCOL = 1;
  var REPLY_TIMEOUT_MS = 20000;
  var sequence = 0;

  function failure(code, message) {
    var error = new Error(message || code);

    error.code = code;

    return error;
  }

  function nextId() {
    sequence += 1;

    return 'pptx-' + sequence;
  }

  function waitForLoad(frame) {
    return new Promise(function (resolve) {
      frame.addEventListener('load', function () {
        resolve();
      }, { once: true });
    });
  }

  // One bridge request. The reply must come from the frame's window and the
  // frame's origin, and carry this request's id. Anything else is ignored.
  function ask(state, cmd, extra) {
    var id = nextId();

    return new Promise(function (resolve, reject) {
      var timer = null;

      function finish() {
        window.clearTimeout(timer);
        window.removeEventListener('message', onMessage);
      }

      function onMessage(event) {
        if (event.source !== state.frame.contentWindow || event.origin !== state.origin) {
          return;
        }

        var data = event.data;

        if (!data || data.channel !== CHANNEL || data.v !== PROTOCOL || data.id !== id) {
          return;
        }

        finish();

        if (data.ok === true) {
          resolve(data.result);
        } else {
          reject(failure((data.error && data.error.code) || 'capture_failed'));
        }
      }

      timer = window.setTimeout(function () {
        finish();
        reject(failure('timeout', 'The deck did not answer in time.'));
      }, REPLY_TIMEOUT_MS);

      window.addEventListener('message', onMessage);

      var message = { channel: CHANNEL, v: PROTOCOL, id: id, cmd: cmd };

      Object.keys(extra || {}).forEach(function (key) {
        message[key] = extra[key];
      });

      state.frame.contentWindow.postMessage(message, state.origin);
    });
  }

  function xsrfToken() {
    var match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
  }

  function filenameFrom(disposition) {
    var match = disposition && disposition.match(/filename="([^"]+)"/);

    return match ? match[1] : 'deck.pptx';
  }

  function postCaptures(exportUrl, captures) {
    return fetch(exportUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrfToken()
      },
      body: JSON.stringify({ captures: captures })
    }).then(function (response) {
      if (!response.ok) {
        return response.json().catch(function () {
          return {};
        }).then(function (body) {
          throw failure(body.code || 'export_failed', body.message);
        });
      }

      return response.blob().then(function (blob) {
        return { blob: blob, filename: filenameFrom(response.headers.get('Content-Disposition')) };
      });
    });
  }

  function download(file) {
    var url = URL.createObjectURL(file.blob);
    var link = document.createElement('a');

    link.href = url;
    link.download = file.filename;
    document.body.appendChild(link);
    link.click();
    link.remove();

    window.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 1000);
  }

  function run(options) {
    var origin = new URL(options.frameUrl, window.location.href).origin;
    var frame = document.createElement('iframe');
    var state = { frame: frame, origin: origin };

    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
    frame.setAttribute('title', 'Slide export');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1920px;height:1080px;border:0;';
    frame.src = options.frameUrl;
    document.body.appendChild(frame);

    var loaded = waitForLoad(frame);

    return loaded.then(function () {
      return ask(state, 'ready');
    }).then(function (ready) {
      if (!ready || !Array.isArray(ready.commands) || ready.commands.indexOf('capture') === -1) {
        throw failure('capture_unsupported', 'This deck was published before PowerPoint export; republish it from the current deck starter.');
      }

      var captures = [];
      var chain = Promise.resolve();

      for (var index = 0; index < ready.slideCount; index++) {
        chain = chain.then(captureSlide(state, index, captures, ready.slideCount, options.onProgress));
      }

      return chain.then(function () {
        return captures;
      });
    }).then(function (captures) {
      return postCaptures(options.exportUrl, captures).then(function (file) {
        download(file);

        return { filename: file.filename, bytes: file.blob.size, captures: captures.length };
      });
    }).finally(function () {
      frame.remove();
    });
  }

  function captureSlide(state, index, captures, total, onProgress) {
    return function () {
      return ask(state, 'capture', { index: index }).then(function (result) {
        captures.push(result.png_base64);

        if (typeof onProgress === 'function') {
          onProgress(captures.length, total);
        }
      });
    };
  }

  window.artfctPptxExport = { run: run };
})();
