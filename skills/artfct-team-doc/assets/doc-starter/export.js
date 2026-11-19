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

  function describe() {
    var sheet = sheetElement();
    var kind = pageKind();
    var size = PAGE_PT[kind];
    var sheetStyle = window.getComputedStyle(sheet);
    var marginPt = parseFloat(sheetStyle.paddingTop) * PT_PER_PX;
    var printableHeight = size[1] - 2 * marginPt;
    var sheetRect = sheet.getBoundingClientRect();
    var contentHeight = sheet.scrollHeight * PT_PER_PX;
    var isFixed = sheet.classList.contains('sheet--fixed');
    var pageCount = isFixed ? 1 : Math.max(1, Math.ceil(contentHeight / printableHeight));
    var breaks = [];

    for (var b = 1; b < pageCount; b++) {
      breaks.push(b * printableHeight);
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
        y: (rect.top - sheetRect.top) * PT_PER_PX,
        w: rect.width * PT_PER_PX,
        h: rect.height * PT_PER_PX,
        font_family: style.fontFamily,
        font_size_pt: parseFloat(style.fontSize) * PT_PER_PX,
        color: style.color
      });
    }

    var images = [];
    var imageNodes = sheet.querySelectorAll('img');

    for (var i = 0; i < imageNodes.length; i++) {
      var src = imageNodes[i].getAttribute('src') || '';

      if (src === '' || /^(?:[a-z][a-z0-9+.-]*:|\/)/i.test(src)) {
        continue;
      }

      var imageRect = imageNodes[i].getBoundingClientRect();
      images.push({
        src: src,
        x: (imageRect.left - sheetRect.left) * PT_PER_PX,
        y: (imageRect.top - sheetRect.top) * PT_PER_PX,
        w: imageRect.width * PT_PER_PX,
        h: imageRect.height * PT_PER_PX
      });
    }

    var pages = [];

    for (var p = 0; p < pageCount; p++) {
      var top = p * printableHeight;
      var bottom = top + printableHeight;
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

    return { pages: pages };
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
