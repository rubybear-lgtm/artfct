/*
 * Artfct deck starter chrome (deck@1, spec 31). Scales the stage, navigates the
 * slides, builds the navigation controls, and answers the export bridge. It
 * makes no network request, never reads the parent page DOM, and answers the
 * bridge only when the deck is framed by its parent.
 *
 * Full screen: the F key and a button in the controls toggle the browser's full
 * screen for the deck. The button is built only when the browser offers it, which
 * a framed deck gets only if its parent allows it.
 *
 * Navigation API for script on the same page:
 *   window.artfctDeck.goTo(index)   0-based; returns false when out of range
 *   window.artfctDeck.slideCount    number of slides
 * The active slide carries the data-deck-active attribute.
 *
 * Bridge replies go to the origin the browser reports for the incoming message,
 * never to '*'. Only event.source === window.parent is trusted.
 */
(function () {
  'use strict';

  var CHANNEL = 'artfct-export';
  var PROTOCOL = 1;
  var STARTER = 'deck@1';
  var STAGE_WIDTH = 1920;
  var STAGE_HEIGHT = 1080;

  var root = document.documentElement;
  var stage = document.querySelector('.deck-stage');
  var slides = Array.prototype.slice.call(stage.querySelectorAll('.slide'));
  var noScale = stage.hasAttribute('noscale');
  var current = 0;
  var handshaken = false;
  var counter = null;
  var previousButton = null;
  var nextButton = null;
  var fullscreenButton = null;

  root.classList.add('deck-js');

  if (noScale) {
    root.classList.add('deck-noscale');
  }

  function isOrdinaryIndex(index) {
    return typeof index === 'number' && index % 1 === 0 && index >= 0 && index < slides.length;
  }

  function setHash(index) {
    try {
      history.replaceState(null, '', '#' + (index + 1));
    } catch {
      // Some sandboxed frames refuse history writes; navigation still works.
    }
  }

  function goTo(index) {
    if (!isOrdinaryIndex(index)) {
      return false;
    }

    current = index;

    slides.forEach(function (slide, position) {
      if (position === index) {
        slide.setAttribute('data-deck-active', '');
      } else {
        slide.removeAttribute('data-deck-active');
      }
    });

    if (counter) {
      counter.textContent = (index + 1) + ' / ' + slides.length;
    }

    if (previousButton) {
      previousButton.disabled = index === 0;
    }

    if (nextButton) {
      nextButton.disabled = index === slides.length - 1;
    }

    setHash(index);

    return true;
  }

  function step(delta) {
    goTo(Math.max(0, Math.min(slides.length - 1, current + delta)));
  }

  function fit() {
    if (noScale) {
      stage.style.transform = '';

      return;
    }

    var scale = Math.min(window.innerWidth / STAGE_WIDTH, window.innerHeight / STAGE_HEIGHT);
    var left = (window.innerWidth - STAGE_WIDTH * scale) / 2;
    var top = (window.innerHeight - STAGE_HEIGHT * scale) / 2;

    stage.style.transform = 'translate(' + left + 'px, ' + top + 'px) scale(' + scale + ')';
  }

  function isEditable(target) {
    if (!target || !target.tagName) {
      return false;
    }

    return target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName);
  }

  function canFullscreen() {
    return Boolean(document.fullscreenEnabled && root.requestFullscreen);
  }

  function ignoreRefusal(result) {
    // A browser may refuse (no user gesture, policy). The deck keeps working either way.
    if (result && typeof result.catch === 'function') {
      result.catch(function () {});
    }
  }

  function toggleFullscreen() {
    if (!canFullscreen()) {
      return;
    }

    if (document.fullscreenElement) {
      ignoreRefusal(document.exitFullscreen());
    } else {
      ignoreRefusal(root.requestFullscreen());
    }
  }

  function syncFullscreenButton() {
    if (!fullscreenButton) {
      return;
    }

    var active = Boolean(document.fullscreenElement);

    fullscreenButton.setAttribute('aria-label', active ? 'Exit full screen' : 'Enter full screen');
    fullscreenButton.setAttribute('aria-pressed', active ? 'true' : 'false');
  }

  function buildControls() {
    var nav = document.createElement('nav');
    nav.className = 'deck-controls';
    nav.setAttribute('aria-label', 'Slide controls');

    previousButton = document.createElement('button');
    previousButton.type = 'button';
    previousButton.setAttribute('aria-label', 'Previous slide');
    previousButton.textContent = '←';
    previousButton.addEventListener('click', function () {
      step(-1);
    });

    counter = document.createElement('span');
    counter.className = 'deck-counter';
    counter.setAttribute('aria-live', 'polite');

    nextButton = document.createElement('button');
    nextButton.type = 'button';
    nextButton.setAttribute('aria-label', 'Next slide');
    nextButton.textContent = '→';
    nextButton.addEventListener('click', function () {
      step(1);
    });

    nav.appendChild(previousButton);
    nav.appendChild(counter);
    nav.appendChild(nextButton);

    if (canFullscreen()) {
      fullscreenButton = document.createElement('button');
      fullscreenButton.type = 'button';
      fullscreenButton.className = 'deck-fullscreen';
      fullscreenButton.textContent = '\u26F6';
      fullscreenButton.addEventListener('click', toggleFullscreen);
      nav.appendChild(fullscreenButton);
      syncFullscreenButton();
      document.addEventListener('fullscreenchange', syncFullscreenButton);
    }

    document.body.appendChild(nav);
  }

  function startIndex() {
    var match = /^#(\d+)$/.exec(window.location.hash);

    if (match && isOrdinaryIndex(Number(match[1]) - 1)) {
      return Number(match[1]) - 1;
    }

    return 0;
  }

  document.addEventListener('keydown', function (event) {
    if (event.altKey || event.ctrlKey || event.metaKey) {
      return;
    }

    // Typing in a field inside a slide is never a shortcut.
    if (isEditable(event.target)) {
      return;
    }

    // A focused button already turns Space into a click; do not move twice.
    if (event.key === ' ' && event.target && event.target.tagName === 'BUTTON') {
      return;
    }

    if (event.key === 'ArrowRight' || event.key === ' ' || event.key === 'PageDown') {
      event.preventDefault();
      step(1);
    } else if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
      event.preventDefault();
      step(-1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      goTo(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      goTo(slides.length - 1);
    } else if ((event.key === 'f' || event.key === 'F') && canFullscreen()) {
      event.preventDefault();

      // Holding the key repeats keydown; one press is one toggle.
      if (!event.repeat) {
        toggleFullscreen();
      }
    }
  });

  window.artfctDeck = {
    goTo: goTo,
    slideCount: slides.length
  };

  // Bridge: answers window.parent only. Inert when the deck is not framed.
  if (window.parent !== window) {
    registerBridge();
  }

  function registerBridge() {
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

      if (message.v !== PROTOCOL || typeof message.id !== 'string') {
        return;
      }

      if (message.cmd === 'ready') {
        handshaken = true;
        reply(event.source, event.origin, message.id, {
          ok: true,
          result: {
            starter: STARTER,
            protocol: PROTOCOL,
            commands: ['ready', 'describe', 'goTo'],
            slideCount: slides.length
          }
        });

        return;
      }

      if (!handshaken) {
        return;
      }

      if (message.cmd === 'describe') {
        reply(event.source, event.origin, message.id, { ok: true, result: describe() });

        return;
      }

      if (message.cmd === 'goTo') {
        if (goTo(message.index)) {
          reply(event.source, event.origin, message.id, {
            ok: true,
            result: { index: current, slideCount: slides.length }
          });
        } else {
          reply(event.source, event.origin, message.id, {
            ok: false,
            error: { code: 'out_of_range' }
          });
        }

        return;
      }

      reply(event.source, event.origin, message.id, {
        ok: false,
        error: { code: 'unknown_command' }
      });
    });
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

  // Allowlist: a src is kept only when it is a plain relative path under the
  // bundle (no scheme, host, backslash, absolute path or `..` segment).
  function isRelativeImageSrc(src) {
    return /^[A-Za-z0-9_][A-Za-z0-9_./-]*$/.test(src) &&
      src.indexOf('\\') === -1 &&
      src.split('/').indexOf('..') === -1;
  }

  function describe() {
    var stageRect = stage.getBoundingClientRect();
    var scale = stageRect.width / STAGE_WIDTH || 1;
    var droppedImages = 0;

    var slideReports = slides.map(function (slide, index) {
      var runs = [];
      var images = [];
      var walker = document.createTreeWalker(slide, NodeFilter.SHOW_TEXT);
      var node;

      while ((node = walker.nextNode())) {
        var text = node.nodeValue.replace(/\s+/g, ' ').trim();

        if (text === '' || !node.parentElement || node.parentElement.closest('.notes')) {
          continue;
        }

        var range = document.createRange();
        range.selectNodeContents(node);
        var rect = range.getBoundingClientRect();
        var style = window.getComputedStyle(node.parentElement);

        runs.push({
          text: text,
          x: (rect.left - stageRect.left) / scale,
          y: (rect.top - stageRect.top) / scale,
          w: rect.width / scale,
          h: rect.height / scale,
          font_family: style.fontFamily,
          font_size_px: parseFloat(style.fontSize),
          color: style.color
        });
      }

      slide.querySelectorAll('img').forEach(function (image) {
        var src = (image.getAttribute('src') || '').trim();

        if (!isRelativeImageSrc(src)) {
          droppedImages += 1;

          return;
        }

        var imageRect = image.getBoundingClientRect();

        images.push({
          src: src,
          x: (imageRect.left - stageRect.left) / scale,
          y: (imageRect.top - stageRect.top) / scale,
          w: imageRect.width / scale,
          h: imageRect.height / scale
        });
      });

      var notesNode = slide.querySelector('p.notes');

      return {
        index: index,
        notes: notesNode ? notesNode.textContent.replace(/\s+/g, ' ').trim() : '',
        runs: runs,
        images: images
      };
    });

    return { slides: slideReports, droppedImages: droppedImages };
  }

  buildControls();
  fit();
  window.addEventListener('resize', fit);
  goTo(startIndex());
  stage.setAttribute('data-deck-ready', '');
})();
