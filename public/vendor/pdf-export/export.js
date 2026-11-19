/*
 * Artfct PDF export runner (RUB-561, spec 45). Runs in the APP origin.
 *
 * It frames the artifact at its isolated artifact origin, asks the starter's
 * export bridge (artfct-export, v1) for structured page data, and writes a PDF
 * from that data with the vendored pdf-lib writer. Nothing from the artifact
 * runs here: the frame is only a data source.
 *
 * Replies are accepted only when event.source is the frame this code created
 * and event.origin is the artifact origin. Requests go to the artifact origin
 * as targetOrigin, never '*'. The only network request is the bundled font
 * files, fetched from this script's own folder.
 */
(function (global) {
  'use strict';

  var CHANNEL = 'artfct-export';
  var PROTOCOL = 1;
  var TIMEOUT_MS = 5000;
  var FRAME_WIDTH = 1920;
  var FRAME_HEIGHT = 1080;
  var PT_PER_PX = 0.75;
  var DECK_PAGE_PT = [FRAME_WIDTH * PT_PER_PX, FRAME_HEIGHT * PT_PER_PX];
  var FOOTER_SIZE_PT = 8;
  var FOOTER_BASELINE_PT = 20;
  var LINE_HEIGHT = 1.3;
  var BASE_URL = (function () {
    var script = document.currentScript;
    var src = script && script.src ? script.src : '';

    return src.replace(/export\.js(\?.*)?$/, '');
  })();
  var FONT_FILES = {
    sans: 'fonts/manrope-latin-400-normal.woff',
    serif: 'fonts/newsreader-latin-400-normal.woff'
  };
  var requestCounter = 0;

  function fail(code, message) {
    var error = new Error(message || code);
    error.code = code;

    return error;
  }

  function nextId(cmd) {
    requestCounter += 1;

    return 'pdf-' + cmd + '-' + requestCounter;
  }

  // One request to the bridge. Resolves with the reply body or rejects with
  // code "no_bridge" when the frame does not answer in time.
  function askFrame(frame, artifactOrigin, cmd) {
    return new Promise(function (resolve, reject) {
      var id = nextId(cmd);
      var timer = null;

      function onMessage(event) {
        if (event.source !== frame.contentWindow) {
          return;
        }

        if (event.origin !== artifactOrigin) {
          return;
        }

        var data = event.data;

        if (!data || typeof data !== 'object' || data.channel !== CHANNEL || data.id !== id) {
          return;
        }

        global.clearTimeout(timer);
        global.removeEventListener('message', onMessage);
        resolve(data);
      }

      global.addEventListener('message', onMessage);
      timer = global.setTimeout(function () {
        global.removeEventListener('message', onMessage);
        reject(fail('no_bridge', 'The artifact did not answer the export bridge.'));
      }, TIMEOUT_MS);

      frame.contentWindow.postMessage({ channel: CHANNEL, v: PROTOCOL, id: id, cmd: cmd }, artifactOrigin);
    });
  }

  function loadFrame(frameUrl) {
    return new Promise(function (resolve) {
      var frame = document.createElement('iframe');

      frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
      frame.setAttribute('referrerpolicy', 'no-referrer');
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('tabindex', '-1');
      frame.setAttribute('data-testid', 'pdf-export-frame');
      frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + FRAME_WIDTH + 'px;height:' + FRAME_HEIGHT + 'px;border:0;visibility:hidden;';
      frame.addEventListener('load', function () {
        resolve(frame);
      });
      frame.src = frameUrl;
      document.body.appendChild(frame);
    });
  }

  function loadWriter() {
    if (!global.PDFLib || !global.fontkit) {
      return Promise.reject(fail('writer_missing', 'The PDF writer is not loaded.'));
    }

    return Promise.resolve(global.PDFLib);
  }

  function fetchFontBytes(key) {
    return fetch(BASE_URL + FONT_FILES[key]).then(function (response) {
      if (!response.ok) {
        throw fail('font_missing', 'Font file ' + FONT_FILES[key] + ' is missing.');
      }

      return response.arrayBuffer();
    });
  }

  // Maps the starter's computed font stack to a bundled family. Georgia and
  // serif stacks become Newsreader; everything else becomes Manrope.
  function fontKeyFor(fontFamily) {
    var lower = String(fontFamily || '').toLowerCase();

    if (/georgia|times|serif/.test(lower) && !/sans/.test(lower)) {
      return 'serif';
    }

    return 'sans';
  }

  function parseColor(value) {
    var match = /rgba?\(\s*(\d+)[ ,]+(\d+)[ ,]+(\d+)/.exec(String(value || ''));

    if (!match) {
      return [0.15, 0.15, 0.14];
    }

    return [Number(match[1]) / 255, Number(match[2]) / 255, Number(match[3]) / 255];
  }

  // Normalises both bridge shapes to pages in points: doc@1 returns pages in
  // points; deck@1 returns one page per slide in stage pixels.
  function toPages(result, starter) {
    if (starter === 'deck@1') {
      return result.slides.map(function (slide) {
        return {
          widthPt: DECK_PAGE_PT[0],
          heightPt: DECK_PAGE_PT[1],
          runs: slide.runs.map(function (run) {
            return {
              text: run.text,
              x: run.x * PT_PER_PX,
              y: run.y * PT_PER_PX,
              w: run.w * PT_PER_PX,
              fontFamily: run.font_family,
              sizePt: run.font_size_px * PT_PER_PX,
              color: run.color
            };
          })
        };
      });
    }

    return result.pages.map(function (page) {
      return {
        widthPt: page.width_pt,
        heightPt: page.height_pt,
        runs: page.runs.map(function (run) {
          return {
            text: run.text,
            x: run.x,
            y: run.y,
            w: run.w,
            fontFamily: run.font_family,
            sizePt: run.font_size_pt,
            color: run.color
          };
        })
      };
    });
  }

  // Greedy word wrap of one run into lines no wider than maxWidth.
  function wrapText(font, text, sizePt, maxWidth) {
    var words = text.split(' ');
    var lines = [];
    var current = '';

    words.forEach(function (word) {
      var candidate = current === '' ? word : current + ' ' + word;

      if (current !== '' && font.widthOfTextAtSize(candidate, sizePt) > maxWidth) {
        lines.push(current);
        current = word;
      } else {
        current = candidate;
      }
    });

    if (current !== '') {
      lines.push(current);
    }

    return lines;
  }

  // Replaces any character the bundled subset cannot encode with '?'.
  function encodable(font, text, counter) {
    var out = '';

    Array.from(text).forEach(function (character) {
      try {
        font.encodeText(character);
        out += character;
      } catch (error) {
        counter.replaced += 1;
        out += '?';
      }
    });

    return out;
  }

  async function writePdf(PDFLib, pages, footerUrl) {
    var pdfDoc = await PDFLib.PDFDocument.create();
    var counter = { replaced: 0 };

    pdfDoc.registerFontkit(global.fontkit);

    var fonts = {
      sans: await pdfDoc.embedFont(await fetchFontBytes('sans'), { subset: true }),
      serif: await pdfDoc.embedFont(await fetchFontBytes('serif'), { subset: true })
    };

    pages.forEach(function (page, index) {
      var pdfPage = pdfDoc.addPage([page.widthPt, page.heightPt]);

      page.runs.forEach(function (run) {
        if (run.y >= page.heightPt || run.text === '') {
          return;
        }

        var font = fonts[fontKeyFor(run.fontFamily)];
        var color = parseColor(run.color);
        var lines = run.w > 0 ? wrapText(font, run.text, run.sizePt, run.w) : [run.text];

        lines.forEach(function (line, lineIndex) {
          var top = run.y + lineIndex * run.sizePt * LINE_HEIGHT;

          if (top >= page.heightPt) {
            return;
          }

          pdfPage.drawText(encodable(font, line, counter), {
            x: run.x,
            y: page.heightPt - top - run.sizePt * 0.9,
            size: run.sizePt,
            font: font,
            color: PDFLib.rgb(color[0], color[1], color[2])
          });
        });
      });

      pdfPage.drawText(encodable(fonts.sans, footerUrl + '   ' + (index + 1) + ' / ' + pages.length, counter), {
        x: 36,
        y: FOOTER_BASELINE_PT,
        size: FOOTER_SIZE_PT,
        font: fonts.sans,
        color: PDFLib.rgb(0.4, 0.4, 0.38)
      });
    });

    var bytes = await pdfDoc.save();

    return { bytes: bytes, pageCount: pages.length, replacedCharacters: counter.replaced };
  }

  function download(bytes, fileName) {
    var blob = new Blob([bytes], { type: 'application/pdf' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');

    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    global.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 1000);
  }

  /**
   * options: { frameUrl, artifactOrigin, footerUrl, fileName }.
   * Resolves { bytes, pageCount, replacedCharacters, starter }. Rejects with an
   * Error whose code is no_bridge, not_starter, writer_missing or font_missing.
   */
  async function exportArtifact(options) {
    var PDFLib = await loadWriter();
    var frame = await loadFrame(options.frameUrl);
    var result;

    try {
      var ready = await askFrame(frame, options.artifactOrigin, 'ready');

      if (!ready.ok || (ready.result.starter !== 'doc@1' && ready.result.starter !== 'deck@1')) {
        throw fail('not_starter', 'This artifact was not published from an official starter.');
      }

      var described = await askFrame(frame, options.artifactOrigin, 'describe');

      if (!described.ok) {
        throw fail('no_sheet', 'The starter has no page to export.');
      }

      var pages = toPages(described.result, ready.result.starter);
      result = await writePdf(PDFLib, pages, options.footerUrl);
      result.starter = ready.result.starter;
    } finally {
      frame.remove();
    }

    return result;
  }

  global.ArtfctPdfExport = {
    exportArtifact: exportArtifact,
    download: download
  };
})(window);
