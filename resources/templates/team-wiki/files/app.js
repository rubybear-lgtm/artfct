/* Team wiki app.
 *
 * Renderer decision: hand-rolled paragraph subset (escape + blank-line
 * paragraphs + [[slug]] links), ported from the validated mock. No vendored
 * markdown library, so zero bundle cost against MAX_BUNDLE_BYTES and no new
 * origins. Bodies in seed use plain paragraphs only; anything fancier must
 * extend renderBody below, not the seed.
 *
 * DB client: same core as the kanban template. Session token from
 * GET /__artfct/db/session; every request sends Authorization plus
 * X-Artfct-Db: 1. Page writes are full-document PUTs carrying if_version; a
 * 409 re-reads and shows "changed underneath you" instead of overwriting.
 * Auth refusal shows the reload banner and stops writes. Endpoint shapes
 * follow plan 22 unit A3; if the merged spec-20 table diverges, it wins.
 */
(function () {
  "use strict";

  var PAGE_LIMIT = 100;

  var wiki = document.getElementById("wiki");
  var banner = document.getElementById("banner");
  var modeBadge = document.getElementById("mode-badge");

  var token = null;
  var canWrite = true;
  var expired = false;
  var sharing = "team";

  var pages = [];
  var pagesTotal = 0;
  var pagesCursor = null;
  var activity = [];
  var activityTotal = 0;
  var versions = {};

  var view = "index";
  var filter = "";
  var kindFilter = "all";

  function headers() {
    var h = { "Content-Type": "application/json", "X-Artfct-Db": "1" };
    if (token) h.Authorization = "Bearer " + token;
    return h;
  }

  function authRefused(status) {
    return status === 401 || status === 403;
  }

  function showBanner(html) {
    banner.innerHTML = html;
    banner.hidden = false;
    var btn = document.getElementById("banner-reload");
    if (btn) {
      btn.addEventListener("click", function () {
        window.location.reload();
      });
    }
  }
  function onExpired() {
    if (expired) return;
    expired = true;
    canWrite = false;
    showBanner(
      "This wiki needs to be reloaded to keep saving." +
      '<button type="button" id="banner-reload">Reload</button>'
    );
    route();
  }

  function onReadOnly(reason) {
    canWrite = false;
    syncMode();
    showBanner("Read-only — " + reason + ". The full wiki stays readable.");
  }

  function syncMode() {
    var label = sharing === "private" ? "Personal wiki" : "Team wiki";
    var sub = sharing === "private" ? "only you can read and maintain it" : "anyone with edit access can maintain pages";
    var ro = canWrite ? "" : " · read-only";
    modeBadge.innerHTML = "<strong>" + esc(label) + ro + "</strong> · " + esc(sub);
  }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }


  function pageBySlug(slug) {
    for (var i = 0; i < pages.length; i++) {
      if (pages[i].slug === slug) return pages[i];
    }
    return null;
  }

  function linked(text) {
    return esc(text).replace(/\[\[([A-Za-z0-9._-]+)\]\]/g, function (m, slug) {
      if (pageBySlug(slug)) {
        return '<a href="#/page/' + esc(slug) + '">' + esc(pageBySlug(slug).title) + "</a>";
      }
      return '<span class="dangling" title="missing page: ' + esc(slug) + '">' + esc(slug) + "</span>";
    });
  }

  function renderBody(bodyMd) {
    return String(bodyMd).split(/\n{2,}/).map(function (para) {
      return "<p>" + linked(para) + "</p>";
    }).join("");
  }

  function summaryOf(p) {
    return String(p.body_md || "").split("\n")[0];
  }

  function linksOf(p) {
    var out = [];
    var re = /\[\[([A-Za-z0-9._-]+)\]\]/g;
    var m;
    while ((m = re.exec(String(p.body_md || ""))) !== null) {
      if (pageBySlug(m[1]) && out.indexOf(m[1]) === -1) out.push(m[1]);
    }
    return out;
  }

  function incoming(slug) {
    var count = 0;
    pages.forEach(function (p) {
      if (p.slug !== slug && String(p.body_md || "").indexOf("[[" + slug + "]]") !== -1) count++;
    });
    return count;
  }

  function sourceCount(p) {
    var n = (p.sources || []).length;
    if (n === 0) return "no sources yet";
    return n + (n === 1 ? " source" : " sources");
  }

  function rowsEnvelope(json) {
    if (!json) return { rows: [], total: 0, cursor: null };
    var rows = json.rows || json.items || json.data || [];
    return {
      rows: rows,
      total: typeof json.total === "number" ? json.total : rows.length,
      cursor: json.cursor || json.next_cursor || null
    };
  }

  function loadCollection(name, cursor) {
    var url = "/__artfct/db/" + name + "?limit=" + PAGE_LIMIT;
    if (cursor) url += "&cursor=" + encodeURIComponent(cursor);
    return fetch(url, { headers: headers() }).then(function (res) {
      if (authRefused(res.status)) {
        onReadOnly("this role cannot read the wiki database");
        return { rows: [], total: 0, cursor: null };
      }
      if (!res.ok) throw new Error("db " + name + " list failed: " + res.status);
      return res.json().then(rowsEnvelope);
    });
  }

  function refreshAll() {
    return loadCollection("pages", null).then(function (env) {
      pages = env.rows;
      pagesTotal = env.total;
      pagesCursor = env.cursor;
      versions = {};
      pages.forEach(function (p) {
        if (p.version !== undefined) versions[p.slug] = p.version;
      });
      return loadCollection("activity", null);
    }).then(function (env) {
      activity = env.rows.slice().sort(function (a, b) {
        return String(a.ts) < String(b.ts) ? 1 : -1;
      });
      activityTotal = env.total;
      route();
    }).catch(function (err) {
      wiki.innerHTML = '<p class="empty">The wiki could not be loaded. ' + esc(err.message) + "</p>";
    });
  }

  function renderIndex() {
    var q = filter.trim().toLowerCase();
    var shown = pages.filter(function (p) {
      return !q ||
        String(p.title).toLowerCase().indexOf(q) !== -1 ||
        summaryOf(p).toLowerCase().indexOf(q) !== -1;
    });
    var orphans = shown.filter(function (p) { return incoming(p.slug) === 0; });
    var rest = shown.filter(function (p) { return incoming(p.slug) > 0; });
    function item(p) {
      var tag = incoming(p.slug) === 0 ? '<span class="orphan-tag">orphan</span>' : "";
      return '<li><a href="#/page/' + esc(p.slug) + '"><h3>' + esc(p.title) + tag +
        ' <span class="link">→</span></h3><p>' + esc(summaryOf(p)) + "</p>" +
        '<span class="sources">' + esc(sourceCount(p)) + " · updated " + esc(p.updated_at) + "</span></a></li>";
    }
    var html = '<input class="filter" type="search" id="wiki-filter" ' +
      'placeholder="Filter pages…" aria-label="Filter pages" value="' + esc(filter) + '">';
    if (pages.length === 0) {
      html += '<p class="empty">No pages yet. Ingest a source to start the wiki.</p>';
    } else if (shown.length === 0) {
      html += '<p class="empty">No pages match. Try ingesting a source.</p>';
    } else {
      html += '<ul class="page-list">' + rest.map(item).join("") + "</ul>";
      if (orphans.length > 0) {
        html += "<h3>Orphans — nothing links here yet</h3>";
        html += '<ul class="page-list">' + orphans.map(item).join("") + "</ul>";
      }
      html += '<p class="sources">Showing ' + shown.length + " of " + pagesTotal + " pages.</p>";
      if (pagesCursor) {
        html += '<div class="pager"><button class="btn-ghost" type="button" id="wiki-more">More</button></div>';
      }
    }
    wiki.innerHTML = html;
    var input = document.getElementById("wiki-filter");
    input.addEventListener("input", function () {
      filter = input.value;
      renderIndexKeepFocus();
    });
    var more = document.getElementById("wiki-more");
    if (more) {
      more.addEventListener("click", function () {
        more.disabled = true;
        loadCollection("pages", pagesCursor).then(function (env) {
          env.rows.forEach(function (p) {
            pages.push(p);
            if (p.version !== undefined) versions[p.slug] = p.version;
          });
          pagesCursor = env.cursor;
          renderIndex();
        });
      });
    }
  }

  function renderIndexKeepFocus() {
    var el = document.getElementById("wiki-filter");
    var pos = el.selectionStart;
    renderIndex();
    var input = document.getElementById("wiki-filter");
    input.focus();
    input.setSelectionRange(pos, pos);
  }

  function renderPage(slug) {
    var p = pageBySlug(slug);
    if (!p) {
      wiki.innerHTML = '<p class="empty">No such page. <a class="back" href="#/">Back to index</a></p>';
      return;
    }
    var src = (p.sources || []).length === 0
      ? "No sources yet — ingest one to cite it here."
      : "<ul>" + (p.sources || []).map(function (s) {
        return "<li>" + esc(s.label) + " — " + esc(s.ref) + "</li>";
      }).join("") + "</ul>";
    var html = '<a class="back" href="#/">← Index</a><article class="doc"><h2>' + esc(p.title) + "</h2>" +
      renderBody(p.body_md) +
      '<div class="doc-meta"><strong>Sources</strong>' + src +
      "<br>Updated " + esc(p.updated_at) + " by " + esc(p.updated_by) + "</div>";
    if (canWrite && !expired) {
      html += '<div class="doc-actions"><button class="btn-ghost" type="button" id="page-edit">Edit</button></div>' +
        '<div class="doc-edit" id="page-editor" hidden></div>';
    } else if (!canWrite) {
      html += '<div class="doc-actions"><span class="sources">Read-only — this role cannot edit pages.</span></div>';
    }
    html += "</article>";
    wiki.innerHTML = html;
    var editBtn = document.getElementById("page-edit");
    if (editBtn) {
      editBtn.addEventListener("click", function () {
        renderEditor(p);
      });
    }
  }

  function renderEditor(p) {
    var editor = document.getElementById("page-editor");
    editor.hidden = false;
    editor.innerHTML =
      '<label class="sources" for="page-body">Body (markdown paragraphs, links as [[slug]])</label>' +
      '<textarea id="page-body">' + esc(p.body_md) + "</textarea>" +
      '<div class="doc-actions"><button class="btn" type="button" id="page-save">Save</button>' +
      '<button class="btn-ghost" type="button" id="page-cancel">Cancel</button>' +
      '<span class="sources" id="page-status"></span></div>';
    document.getElementById("page-cancel").addEventListener("click", function () {
      renderPage(p.slug);
    });
    document.getElementById("page-save").addEventListener("click", function () {
      savePage(p);
    });
  }

  function savePage(p) {
    var status = document.getElementById("page-status");
    var next = {
      slug: p.slug,
      title: p.title,
      body_md: document.getElementById("page-body").value,
      sources: p.sources || [],
      visibility: p.visibility || "shared",
      updated_at: p.updated_at,
      updated_by: p.updated_by
    };
    var body = { data: next };
    if (versions[p.slug] !== undefined) body.if_version = versions[p.slug];
    status.textContent = "Saving…";
    fetch("/__artfct/db/pages/" + encodeURIComponent(p.slug), {
      method: "PUT",
      headers: headers(),
      body: JSON.stringify(body)
    }).then(function (res) {
      if (res.status === 409) {
        status.textContent = "Changed underneath you — reloaded, try again.";
        return refreshAll();
      }
      if (authRefused(res.status)) {
        onExpired();
        return null;
      }
      if (res.status === 403) {
        status.textContent = "Not saved — this role needs edit access.";
        return null;
      }
      if (!res.ok) throw new Error("save failed: " + res.status);
      return res.json();
    }).then(function (saved) {
      if (!saved) return;
      var row = saved.row || saved.data || saved;
      for (var i = 0; i < pages.length; i++) {
        if (pages[i].slug === p.slug) pages[i] = row.data || row;
      }
      if (row.version !== undefined) versions[p.slug] = row.version;
      renderPage(p.slug);
    }).catch(function (err) {
      status.textContent = err.message;
    });
  }

  function renderLog() {
    var kinds = ["all", "ingest", "query", "lint"];
    var html = '<div class="kind-filter" role="group" aria-label="Filter by kind">' +
      kinds.map(function (k) {
        return '<button class="btn-ghost" type="button" data-kind="' + k + '" aria-pressed="' +
          (kindFilter === k ? "true" : "false") + '">' + esc(k) + "</button>";
      }).join("") + "</div>";
    var items = activity.filter(function (a) {
      return kindFilter === "all" || a.kind === kindFilter;
    }).map(function (a) {
      return '<li><span class="kind">' + esc(a.kind) + "</span>" + esc(a.summary) +
        "<time>" + esc(a.ts) + " · " + esc(a.updated_by) + "</time></li>";
    }).join("");
    if (!items) {
      html += '<p class="empty">No activity yet.</p>';
    } else {
      html += '<ul class="log-list">' + items + "</ul>";
      html += '<p class="sources">Showing ' + activity.filter(function (a) {
        return kindFilter === "all" || a.kind === kindFilter;
      }).length + " of " + activityTotal + " entries.</p>";
    }
    wiki.innerHTML = html;
    wiki.querySelectorAll("[data-kind]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        kindFilter = btn.getAttribute("data-kind");
        renderLog();
      });
    });
  }

  function renderGraph() {
    var n = pages.length;
    if (n === 0) {
      wiki.innerHTML = '<p class="empty">No pages yet — the graph appears after the first ingest.</p>';
      return;
    }
    var W = 680, H = 440, cx = W / 2, cy = H / 2 - 10;
    var R = Math.min(W, H) / 2 - 70;
    var pos = {};
    pages.forEach(function (p, i) {
      var a = -Math.PI / 2 + (i * 2 * Math.PI) / Math.max(n, 1);
      pos[p.slug] = { x: cx + R * Math.cos(a), y: cy + R * Math.sin(a) };
    });
    var edgeCount = 0;
    var edges = pages.map(function (p) {
      return linksOf(p).map(function (t) {
        edgeCount++;
        var a = pos[p.slug], b = pos[t];
        return '<line class="edge" x1="' + a.x.toFixed(1) + '" y1="' + a.y.toFixed(1) +
          '" x2="' + b.x.toFixed(1) + '" y2="' + b.y.toFixed(1) + '"/>';
      }).join("");
    }).join("");
    var orphans = 0;
    var nodes = pages.map(function (p) {
      var isOrphan = incoming(p.slug) === 0;
      if (isOrphan) orphans++;
      var r = 12 + incoming(p.slug) * 4;
      var c = pos[p.slug];
      return '<a class="node' + (isOrphan ? " orphan" : "") + '" href="#/page/' + esc(p.slug) + '">' +
        '<circle cx="' + c.x.toFixed(1) + '" cy="' + c.y.toFixed(1) + '" r="' + r + '">' +
        "<title>" + esc(p.title) + "</title></circle>" +
        '<text x="' + c.x.toFixed(1) + '" y="' + (c.y + r + 16).toFixed(1) + '">' + esc(p.title) + "</text></a>";
    }).join("");
    wiki.innerHTML = '<div class="graph-wrap"><svg viewBox="0 0 ' + W + " " + H +
      '" role="img" aria-label="Knowledge graph: ' + n + " pages, " + edgeCount + ' links">' +
      edges + nodes + "</svg></div>" +
      '<p class="sources">' + n + " pages · " + edgeCount + " links · " + orphans +
      " orphan" + (orphans === 1 ? "" : "s") + ' (dashed). Node size grows with incoming links.</p>';
  }

  function route() {
    var hash = window.location.hash;
    var m = hash.match(/^#\/page\/([A-Za-z0-9._-]+)$/);
    window.scrollTo(0, 0);
    if (m) { view = "page"; syncTabs(); renderPage(m[1]); return; }
    if (hash === "#/log") { view = "log"; syncTabs(); renderLog(); return; }
    if (hash === "#/graph") { view = "graph"; syncTabs(); renderGraph(); return; }
    view = "index"; syncTabs(); renderIndex();
  }

  function syncTabs() {
    document.querySelectorAll("[data-view]").forEach(function (btn) {
      btn.setAttribute("aria-pressed", btn.getAttribute("data-view") === view ? "true" : "false");
    });
  }

  document.querySelectorAll("[data-view]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var v = btn.getAttribute("data-view");
      window.location.hash = v === "index" ? "#/" : "#/" + v;
    });
  });
  window.addEventListener("hashchange", route);

  fetch("/__artfct/db/session").then(function (res) {
    if (authRefused(res.status)) {
      onReadOnly("this role cannot write to the wiki database");
      return null;
    }
    if (!res.ok) throw new Error("session failed: " + res.status);
    return res.json();
  }).then(function (sess) {
    if (!sess) return refreshAll();
    token = sess.token || sess.access_token || null;
    if (sess.role === "viewer" || sess.can_write === false) canWrite = false;
    if (sess.sharing === "private" || sess.sharing === "team") sharing = sess.sharing;
    syncMode();
    return refreshAll();
  }).catch(function (err) {
    wiki.innerHTML = '<p class="empty">The wiki could not be loaded. ' + esc(err.message) + "</p>";
  });
})();
