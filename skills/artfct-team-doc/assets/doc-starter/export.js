/*
 * Artfct doc starter export bridge (doc@1, spec 30). Chrome only: it reads the
 * page's layout and answers the export runner. It makes no network request,
 * never reads the parent page DOM, and does nothing when the page is not framed.
 *
 * Replies go to the origin the browser reports for the incoming message, never
 * to '*'. Only event.source === window.parent is trusted.
 */
(function () {
  'use strict';

  var CHANNEL = 'artfct-export';
  var PROTOCOL = 1;
  var STARTER = 'doc@1';
  var ID_MAX = 128;
  var PT_PER_PX = 0.75;
  var PAGE_PT = { letter: [612, 792], a4: [595.28, 841.89] };
  var EPSILON_PT = 1;
  var handshaken = false;

  if (window.parent === window) {
    return;
  }

  function isHttpOrigin(origin) {
    return typeof origin === 'string' && /^https?:\/\/[^/]+$/.test(origin);
  }

  function reply(source, origin, id, body) {
    var message = { channel: CHANNEL, v: PROTOCOL, id: id };

    for (var key in body) {
      if (Object.prototype.hasOwnProperty.call(body, key)) {
        message[key] = body[key];
      }
    }

    source.postMessage(message, origin);
  }

  function pageKind() {
    return document.documentElement.classList.contains('sheet-a4') ? 'a4' : 'letter';
  }

  function sheetElement() {
    return document.querySelector('.sheet');
  }

  // Allowlist: a src is kept only when it is a plain relative path under the
  // bundle (no scheme, host, backslash, absolute path or `..` segment).
  function isRelativeImageSrc(src) {
    return /^[A-Za-z0-9_][A-Za-z0-9_./-]*$/.test(src) &&
      src.indexOf('\\') === -1 &&
      src.split('/').indexOf('..') === -1;
  }

  // Page starts in content points (0 is the printable top). A page starts at 0,
  // at every .page-start element (a forced break), and every printableHeight
  // after the last start. A start at or past the content end is not a page.
  function pageStarts(printableHeight, contentHeight, forcedTops) {
    var starts = [0];
    var start = 0;

    if (printableHeight <= 0) {
      return starts;
    }

    while (true) {
      var limit = start + printableHeight;
      var next = limit;
      var nextForced = null;

      for (var f = 0; f < forcedTops.length; f++) {
        if (forcedTops[f] > start + EPSILON_PT) {
          nextForced = forcedTops[f];
          break;
        }
      }

      if (nextForced !== null && nextForced <= limit) {
        next = nextForced;
      }

      if (next >= contentHeight - EPSILON_PT) {
        return starts;
      }

      starts.push(next);
      start = next;
    }
  }

  function describe() {
    var sheet = sheetElement();
    var kind = pageKind();
    var size = PAGE_PT[kind];
    var sheetStyle = window.getComputedStyle(sheet);
    // Print puts the margin outside the sheet's content box, so the printable
    // area is the content box, not the padded border box that scrollHeight measures.
    var marginPt = parseFloat(sheetStyle.paddingTop) * PT_PER_PX;
    var printableHeight = size[1] - 2 * marginPt;
    var sheetRect = sheet.getBoundingClientRect();
    var contentHeight = sheet.scrollHeight * PT_PER_PX - 2 * marginPt;
    var isFixed = sheet.classList.contains('sheet--fixed');
    var forcedTops = [];
    var forcedNodes = sheet.querySelectorAll('.page-start');

    for (var g = 0; g < forcedNodes.length; g++) {
      forcedTops.push((forcedNodes[g].getBoundingClientRect().top - sheetRect.top) * PT_PER_PX - marginPt);
    }

    forcedTops.sort(function (a, b) {
      return a - b;
    });

    var starts = isFixed ? [0] : pageStarts(printableHeight, contentHeight, forcedTops);
    var breaks = starts.slice(1);

    // Content coordinates: y is measured from the printable top, not the sheet top.
    function contentTop(rect) {
      return (rect.top - sheetRect.top) * PT_PER_PX - marginPt;
    }

    var runs = [];
    var walker = document.createTreeWalker(sheet, NodeFilter.SHOW_TEXT);
    var node;

    while ((node = walker.nextNode())) {
      var text = node.nodeValue.replace(/\s+/g, ' ').trim();

      if (text === '' || !node.parentElement) {
        continue;
      }

      var range = document.createRange();
      range.selectNodeContents(node);
      var rect = range.getBoundingClientRect();
      var style = window.getComputedStyle(node.parentElement);
      runs.push({
        text: text,
        x: (rect.left - sheetRect.left) * PT_PER_PX,
        y: contentTop(rect),
        w: rect.width * PT_PER_PX,
        h: rect.height * PT_PER_PX,
        font_family: style.fontFamily,
        font_size_pt: parseFloat(style.fontSize) * PT_PER_PX,
        color: style.color
      });
    }

    var images = [];
    var droppedImages = 0;
    var imageNodes = sheet.querySelectorAll('img');

    for (var i = 0; i < imageNodes.length; i++) {
      var src = (imageNodes[i].getAttribute('src') || '').trim();

      if (!isRelativeImageSrc(src)) {
        droppedImages += 1;
        continue;
      }

      var imageRect = imageNodes[i].getBoundingClientRect();
      images.push({
        src: src,
        x: (imageRect.left - sheetRect.left) * PT_PER_PX,
        y: contentTop(imageRect),
        w: imageRect.width * PT_PER_PX,
        h: imageRect.height * PT_PER_PX
      });
    }

    var pages = [];

    for (var p = 0; p < starts.length; p++) {
      var top = starts[p];
      // A fixed sheet clips at the printable edge; a flowing page ends at the next start.
      var bottom = p + 1 < starts.length ? starts[p + 1] : (isFixed ? top + printableHeight : Infinity);
      pages.push({
        index: p,
        width_pt: size[0],
        height_pt: size[1],
        runs: runs.filter(function (run) {
          return run.y >= top && run.y < bottom;
        }).map(function (run) {
          return Object.assign({}, run, { y: run.y - top });
        }),
        images: images.filter(function (image) {
          return image.y >= top && image.y < bottom;
        }).map(function (image) {
          return Object.assign({}, image, { y: image.y - top });
        }),
        breaks: breaks
      });
    }

    return { pages: pages, droppedImages: droppedImages };
  }

  window.addEventListener('message', function (event) {
    if (event.source !== window.parent) {
      return;
    }

    if (!isHttpOrigin(event.origin)) {
      return;
    }

    var message = event.data;

    if (!message || typeof message !== 'object' || message.channel !== CHANNEL) {
      return;
    }

    if (message.v !== PROTOCOL || typeof message.id !== 'string' || message.id.length > ID_MAX) {
      return;
    }

    if (message.cmd === 'ready') {
      handshaken = true;
      reply(event.source, event.origin, message.id, {
        ok: true,
        result: { starter: STARTER, protocol: PROTOCOL, commands: ['ready', 'describe'] }
      });

      return;
    }

    if (!handshaken) {
      return;
    }

    if (message.cmd === 'describe') {
      if (sheetElement() === null) {
        reply(event.source, event.origin, message.id, { ok: false, error: { code: 'no_sheet' } });

        return;
      }

      reply(event.source, event.origin, message.id, { ok: true, result: describe() });

      return;
    }

    reply(event.source, event.origin, message.id, {
      ok: false,
      error: { code: 'unknown_command' }
    });
  });
})();
