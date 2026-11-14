(function () {
  "use strict";

  var TAGS = ["news", "decision", "launch", "event", "note"];
  var PAGE_SIZE = 100;
  var BASE = "/__artfct/db";

  var activeTag = "all";
  var pages = []; // each page: { rows, nextCursor, total }
  var pageIndex = 0;
  var session = { token: null, canWrite: false, isOwner: false, sharing: "team", expired: false };
  var versions = {}; // id -> if_version for the rows on screen

  var list = document.getElementById("posts");
  var chips = document.getElementById("chips");
  var counts = document.getElementById("counts");
  var pager = document.getElementById("pager");
  var pageLabel = document.getElementById("page-label");
  var prevBtn = document.getElementById("prev-page");
  var nextBtn = document.getElementById("next-page");
  var modeBadge = document.getElementById("mode-badge");
  var readonlyBanner = document.getElementById("readonly-banner");
  var expiredBanner = document.getElementById("expired-banner");
  var composerSection = document.getElementById("composer-section");
  var composer = document.getElementById("composer");
  var formError = document.getElementById("form-error");

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function fmt(at) {
    try {
      var d = new Date(at);
      if (isNaN(d.getTime())) return "";
      return d.toLocaleString(undefined, {
        month: "short", day: "numeric", year: "numeric",
        hour: "numeric", minute: "2-digit"
      });
    } catch (e) { return ""; }
  }
  // Minimal safe markdown subset: escape everything, then paragraphs,
  // **bold**, *italic*, `code`, and bracketed web links.
  function md(src) {
    var parts = String(src || "").split(/\n{2,}/);
    return parts.map(function (block) {
      var t = esc(block).replace(/\n/g, "<br>");
      t = t.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
      t = t.replace(/(^|\W)\*([^*\n]+)\*/g, "$1<em>$2</em>");
      t = t.replace(/`([^`\n]+)`/g, "<code>$1</code>");
      t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" rel="noopener">$1</a>');
      return "<p>" + t + "</p>";
    }).join("");
  }

  function headers(extra) {
    var h = { "Content-Type": "application/json", "X-Artfct-Db": "1" };
    if (session.token) h["Authorization"] = "Bearer " + session.token;
    if (extra) for (var k in extra) h[k] = extra[k];
    return h;
  }

  function authFailed(status) { return status === 401 || status === 403; }

  function expire() {
    if (session.expired) return;
    session.expired = true;
    session.canWrite = false;
    expiredBanner.hidden = false;
    composerSection.hidden = true;
    render();
  }

  function showError(msg) {
    formError.textContent = msg;
    formError.hidden = false;
  }

  function clearError() {
    formError.textContent = "";
    formError.hidden = true;
  }

  function dbList(cursor) {
    var url = BASE + "/list?collection=posts&order=published_at&direction=desc&limit=" + PAGE_SIZE;
    if (cursor) url += "&cursor=" + encodeURIComponent(cursor);
    if (activeTag !== "all") url += "&tag=" + encodeURIComponent(activeTag);
    return fetch(url, { headers: headers() }).then(function (res) {
      if (authFailed(res.status)) { expire(); return null; }
      if (!res.ok) throw new Error("list failed: " + res.status);
      return res.json();
    });
  }

  function loadPage(cursor) {
    return dbList(cursor).then(function (data) {
      if (!data) return;
      var rows = data.rows || data.posts || [];
      rows.forEach(function (r) { if (r.id && r.version !== undefined) versions[r.id] = r.version; });
      pages[pageIndex] = { rows: rows, nextCursor: data.next_cursor || data.nextCursor || null, total: data.total };
      render();
    }).catch(function () {
      counts.textContent = "Could not load announcements. Reload the page to try again.";
    });
  }

  function currentPage() { return pages[pageIndex] || { rows: [], nextCursor: null, total: 0 }; }

  function totalCount() {
    var t = currentPage().total;
    if (typeof t === "number") return t;
    var n = 0;
    pages.forEach(function (p) { if (p) n += p.rows.length; });
    return n;
  }

  function renderChips() {
    var all = ["all"].concat(TAGS);
    chips.innerHTML = all.map(function (t) {
      var pressed = t === activeTag ? "true" : "false";
      var label = t === "all" ? "All" : t;
      return '<button class="chip" type="button" data-tag="' + esc(t) + '" aria-pressed="' + pressed + '">' + esc(label) + "</button>";
    }).join("");
  }

  function emptyCopy() {
    if (activeTag !== "all") return "Nothing under this tag yet.";
    if (session.sharing === "private") return "No entries yet. Use the form below to start your personal log — drafts, changelog notes, experiments.";
    return "Nothing announced yet. Agents on this team check this board before starting work — post the first decision, launch, or date change below.";
  }

  function postHtml(p) {
    var tag = esc(p.tag || "note");
    var title = esc(p.title || "(untitled)");
    var by = p.updated_by ? esc(p.updated_by) : "";
    var when = esc(fmt(p.published_at || p.publishedAt || p.at || ""));
    var meta = by ? when + " · " + by : when;
    var html = "<li data-id=\"" + esc(p.id || "") + "\"><span class=\"tag\">" + tag + "</span><h3>" + title + "</h3>" +
      '<div class="body">' + md(p.body || "") + "</div>" +
      "<time>" + meta + "</time>";
    if (session.canWrite && !session.expired) {
      html += '<div class="post-actions"><button class="btn btn-quiet" type="button" data-act="edit">Edit</button>';
      if (session.isOwner) html += '<button class="btn btn-quiet" type="button" data-act="delete">Delete</button>';
      html += "</div>";
    }
    return html + "</li>";
  }

  function render() {
    renderChips();
    var page = currentPage();
    var rows = page.rows;
    var total = totalCount();
    counts.textContent = "Showing " + rows.length + " of " + total + " announcements.";
    if (rows.length === 0) {
      list.innerHTML = '<li class="empty">' + esc(emptyCopy()) + "</li>";
    } else {
      list.innerHTML = rows.map(postHtml).join("");
    }
    var showPager = pageIndex > 0 || !!page.nextCursor;
    pager.hidden = !showPager;
    if (showPager) {
      pageLabel.textContent = "Page " + (pageIndex + 1);
      prevBtn.disabled = pageIndex === 0;
      nextBtn.disabled = !page.nextCursor;
    }
    if (session.sharing === "private") {
      modeBadge.innerHTML = "<strong>Personal log</strong> · only you can see this";
    } else {
      modeBadge.innerHTML = "<strong>Shared with the team</strong> · anyone with edit access can post";
    }
    readonlyBanner.hidden = session.canWrite || session.expired;
    composerSection.hidden = !session.canWrite || session.expired;
  }

  function refresh() {
    pages = [];
    pageIndex = 0;
    return loadPage(null);
  }

  function dbWrite(method, body) {
    return fetch(BASE + "/" + method, {
      method: "POST",
      headers: headers(),
      body: JSON.stringify(body)
    }).then(function (res) {
      if (authFailed(res.status)) { expire(); return null; }
      return res.json().then(function (data) {
        return { status: res.status, ok: res.ok, data: data };
      }).catch(function () {
        return { status: res.status, ok: res.ok, data: null };
      });
    });
  }

  chips.addEventListener("click", function (e) {
    var btn = e.target.closest ? e.target.closest("[data-tag]") : null;
    if (!btn) return;
    activeTag = btn.getAttribute("data-tag");
    refresh();
  });

  prevBtn.addEventListener("click", function () {
    if (pageIndex === 0) return;
    pageIndex -= 1;
    render();
  });

  nextBtn.addEventListener("click", function () {
    var page = currentPage();
    if (!page.nextCursor) return;
    pageIndex += 1;
    if (pages[pageIndex]) { render(); return; }
    loadPage(page.nextCursor);
  });

  composer.addEventListener("submit", function (e) {
    e.preventDefault();
    if (session.expired) return;
    clearError();
    var titleEl = document.getElementById("f-title");
    var bodyEl = document.getElementById("f-body");
    var tagEl = document.getElementById("f-tag");
    var title = titleEl.value.trim();
    if (!title) { showError("Give the announcement a one-line title."); return; }
    if (TAGS.indexOf(tagEl.value) === -1) { showError("Pick one of the five tags."); return; }
    var now = new Date().toISOString();
    dbWrite("set", {
      collection: "posts",
      data: { title: title, body: bodyEl.value.trim(), tag: tagEl.value, published_at: now }
    }).then(function (res) {
      if (!res) return;
      if (res.status === 409) { showError("Someone just posted — reloaded the latest, check it is not a duplicate, then try again."); refresh(); return; }
      if (!res.ok) { showError("Could not post. Reload and try again."); return; }
      titleEl.value = "";
      bodyEl.value = "";
      activeTag = "all";
      refresh();
    }).catch(function () {
      showError("Could not post. Reload and try again.");
    });
  });

  list.addEventListener("click", function (e) {
    var btn = e.target.closest ? e.target.closest("[data-act]") : null;
    if (!btn || session.expired) return;
    var li = btn.closest("li[data-id]");
    if (!li) return;
    var id = li.getAttribute("data-id");
    var page = currentPage();
    var post = null;
    page.rows.forEach(function (r) { if (String(r.id) === id) post = r; });
    if (!post) return;
    var act = btn.getAttribute("data-act");

    if (act === "delete") {
      if (!session.isOwner) return;
      var label = post.title || "(untitled)";
      if (!window.confirm('Delete "' + label + '"? The board keeps a trustworthy log, so deletes are for mistakes only.')) return;
      var payload = { collection: "posts", id: post.id };
      if (versions[post.id] !== undefined) payload.if_version = versions[post.id];
      dbWrite("delete", payload).then(function (res) {
        if (!res) return;
        if (res.status === 409) { refresh(); showError("That post changed underneath you — reloaded the latest instead of deleting."); return; }
        if (!res.ok) { showError("Could not delete. Reload and try again."); return; }
        refresh();
      });
      return;
    }

    if (act === "edit") {
      if (li.querySelector(".edit-form")) return;
      var wrap = document.createElement("div");
      wrap.className = "edit-form";
      var area = document.createElement("textarea");
      area.value = post.body || "";
      area.setAttribute("aria-label", "Edit announcement body");
      var save = document.createElement("button");
      save.className = "btn";
      save.type = "button";
      save.textContent = "Save";
      var cancel = document.createElement("button");
      cancel.className = "btn btn-quiet";
      cancel.type = "button";
      cancel.textContent = "Cancel";
      cancel.style.marginLeft = "8px";
      wrap.appendChild(area);
      wrap.appendChild(save);
      wrap.appendChild(cancel);
      li.appendChild(wrap);
      cancel.addEventListener("click", function () { li.removeChild(wrap); });
      save.addEventListener("click", function () {
        var payload = {
          collection: "posts",
          id: post.id,
          data: { title: post.title, body: area.value, tag: post.tag, published_at: post.published_at || post.publishedAt }
        };
        if (versions[post.id] !== undefined) payload.if_version = versions[post.id];
        dbWrite("update", payload).then(function (res) {
          if (!res) return;
          if (res.status === 409) {
            refresh();
            showError("That post changed underneath you — reloaded the latest, copy your edit, and try again.");
            return;
          }
          if (!res.ok) { showError("Could not save. Reload and try again."); return; }
          refresh();
        });
      });
    }
  });

  document.getElementById("reload-btn").addEventListener("click", function () {
    window.location.reload();
  });

  function detectSharing() {
    try {
      var q = new URLSearchParams(window.location.search);
      var s = q.get("sharing") || q.get("mode");
      if (s === "private" || s === "personal") return "private";
    } catch (e) { /* ignore */ }
    return "team";
  }

  function initSession() {
    session.sharing = detectSharing();
    return fetch(BASE + "/session", { headers: headers() }).then(function (res) {
      if (!res.ok) {
        session.canWrite = false;
        render();
        return;
      }
      return res.json().then(function (data) {
        data = data || {};
        if (typeof data.token === "string") session.token = data.token;
        var role = String(data.role || data.access || "");
        session.canWrite = data.can_write === true || data.canWrite === true ||
          role === "edit" || role === "owner" || role === "write";
        session.isOwner = data.is_owner === true || data.isOwner === true || role === "owner";
        if (data.sharing === "private" || data.sharing === "team") session.sharing = data.sharing;
        render();
      }).catch(function () { render(); });
    }).catch(function () { render(); });
  }

  render();
  refresh();
  initSession();
})();
