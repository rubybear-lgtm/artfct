(function () {
    'use strict';

    var ORDER = ['todo', 'doing', 'done'];
    var PAGE_SIZE = 100;
    var DB = '/__artfct/db';

    var token = null;
    var writesStopped = false;
    var canWrite = true;
    var cards = [];
    var nextCursor = null;
    var pageCursor = null;
    var cursorHistory = [];
    var total = null;
    var filter = '';
    var seq = 0;

    var board = document.getElementById('board');
    var banner = document.getElementById('session-banner');
    var reloadBtn = document.getElementById('reload-btn');
    var toastEl = document.getElementById('toast');
    var searchInput = document.getElementById('search');
    var pageLine = document.getElementById('page-line');
    var prevBtn = document.getElementById('prev-page');
    var nextBtn = document.getElementById('next-page');
    var modePill = document.getElementById('mode-pill');

    var toastTimer = null;
    function toast(msg) {
        toastEl.textContent = msg;
        toastEl.hidden = false;
        if (toastTimer) window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toastEl.hidden = true;
        }, 4000);
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            }[c];
        });
    }

    function headers() {
        return {
            Authorization: 'Bearer ' + token,
            'Content-Type': 'application/json',
            'X-Artfct-Db': '1',
        };
    }

    function authRefused(status) {
        return status === 401 || status === 403;
    }

    function stopWrites() {
        if (writesStopped) return;
        writesStopped = true;
        canWrite = false;
        banner.hidden = false;
        render();
    }

    function withVersion(path, version) {
        if (version === null || version === undefined) return path;
        return (
            path +
            (path.indexOf('?') >= 0 ? '&' : '?') +
            'if_version=' +
            encodeURIComponent(version)
        );
    }

    function api(method, path, body, version) {
        return window
            .fetch(DB + withVersion(path, version), {
                method: method,
                headers: headers(),
                body: body === undefined ? undefined : JSON.stringify(body),
            })
            .then(function (res) {
                if (authRefused(res.status)) {
                    stopWrites();
                    throw new Error('auth');
                }
                return res;
            });
    }

    function loadSession() {
        return window
            .fetch(DB + '/session', { headers: { 'X-Artfct-Db': '1' } })
            .then(function (res) {
                if (authRefused(res.status)) {
                    stopWrites();
                    throw new Error('auth');
                }
                if (!res.ok) throw new Error('session ' + res.status);
                return res.json();
            })
            .then(function (data) {
                token = data.token;
                var role = data.role || 'member';
                canWrite =
                    (role === 'owner' ||
                        role === 'admin' ||
                        role === 'member') &&
                    !writesStopped;
                applyMode(data.sharing || 'team');
            });
    }

    function applyMode(sharing) {
        if (sharing === 'private') {
            modePill.innerHTML =
                '<strong>Personal board</strong> · only you and your agents can see it';
        } else {
            modePill.innerHTML =
                '<strong>Shared with the team</strong> · anyone with edit access can move cards';
        }
        modePill.setAttribute('data-sharing', sharing);
    }

    function currentSharing() {
        return modePill.getAttribute('data-sharing') || 'team';
    }

    function emptyCopy() {
        if (currentSharing() === 'private')
            return 'No cards here yet — add one below to track your own work.';
        return 'No cards here yet — add one below, or ask an agent to fill the board (see SKILL.md).';
    }

    function loadPage(cursor) {
        var path = '/cards?limit=' + PAGE_SIZE;
        if (cursor) path += '&cursor=' + encodeURIComponent(cursor);
        return api('GET', path)
            .then(function (res) {
                if (!res.ok) throw new Error('list ' + res.status);
                return res.json();
            })
            .then(function (data) {
                var docs = data.docs || data.documents || data.rows || [];
                cards = docs.map(function (d) {
                    var b = d.data || d.body || d;
                    return {
                        id: d.id || d.doc_id,
                        column: b.column,
                        title: b.title,
                        body: b.body || '',
                        owner: b.owner || '',
                        position:
                            typeof b.position === 'number' ? b.position : 0,
                        version: d.version,
                    };
                });
                nextCursor = data.next_cursor || data.cursor || null;
                pageCursor = cursor || null;
                render();
            })
            .catch(function (err) {
                if (err && err.message === 'auth') return;
                toast('Could not load cards. Reload to try again.');
            });
    }

    function visibleCards() {
        var q = filter.trim().toLowerCase();
        var sorted = cards.slice().sort(function (a, b) {
            return a.position - b.position;
        });
        if (!q) return sorted;
        return sorted.filter(function (c) {
            return (
                (c.title + ' ' + c.body + ' ' + c.owner)
                    .toLowerCase()
                    .indexOf(q) >= 0
            );
        });
    }

    function render() {
        var shown = visibleCards();
        ORDER.forEach(function (col) {
            var section = board.querySelector('[data-column="' + col + '"]');
            var list = section.querySelector('[data-cards]');
            var mine = shown.filter(function (c) {
                return c.column === col;
            });
            section.querySelector('[data-count]').textContent =
                mine.length + (mine.length === 1 ? ' card' : ' cards');
            if (mine.length === 0) {
                list.innerHTML =
                    '<li><p class="empty">' + esc(emptyCopy()) + '</p></li>';
                return;
            }
            list.innerHTML = mine
                .map(function (c) {
                    var i = ORDER.indexOf(c.column);
                    var leftDisabled = i === 0 || !canWrite ? ' disabled' : '';
                    var rightDisabled =
                        i === ORDER.length - 1 || !canWrite ? ' disabled' : '';
                    var delDisabled = !canWrite ? ' disabled' : '';
                    return (
                        '<li><article class="card" tabindex="0" draggable="true" data-id="' +
                        esc(c.id) +
                        '">' +
                        '<h3>' +
                        esc(c.title) +
                        '</h3>' +
                        (c.body ? '<p>' + esc(c.body) + '</p>' : '') +
                        '<div class="card-meta"><span class="owner">' +
                        esc(c.owner) +
                        '</span>' +
                        '<span class="card-actions">' +
                        '<button class="icon-btn" data-action="left" aria-label="Move left"' +
                        leftDisabled +
                        '>\u2190</button>' +
                        '<button class="icon-btn" data-action="right" aria-label="Move right"' +
                        rightDisabled +
                        '>\u2192</button>' +
                        '<button class="icon-btn" data-action="delete" aria-label="Delete card"' +
                        delDisabled +
                        '>\u00d7</button>' +
                        '</span></div></article></li>'
                    );
                })
                .join('');
        });
        var countText = filter
            ? 'showing ' + shown.length + ' of ' + cards.length + ' loaded'
            : 'showing ' +
              cards.length +
              (total !== null ? ' of ' + total : ' cards');
        pageLine.textContent = countText;
        prevBtn.disabled = cursorHistory.length === 0;
        nextBtn.disabled = !nextCursor;
        board
            .querySelectorAll('[data-add] input, [data-add] button')
            .forEach(function (el) {
                el.disabled = !canWrite;
            });
    }

    function refocus(id) {
        var el = board.querySelector('[data-id="' + id + '"]');
        if (el) el.focus();
    }

    function findCard(id) {
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].id === id) return cards[i];
        }
        return null;
    }

    function maxPosition(col, exceptId) {
        var max = 0;
        cards.forEach(function (c) {
            if (c.column === col && c.id !== exceptId && c.position > max)
                max = c.position;
        });
        return max;
    }

    function reread(id) {
        return api('GET', '/cards/' + encodeURIComponent(id))
            .then(function (res) {
                if (!res.ok) throw new Error('get ' + res.status);
                return res.json();
            })
            .then(function (d) {
                var b = d.data || d.body || d;
                for (var i = 0; i < cards.length; i++) {
                    if (cards[i].id === id) {
                        cards[i] = {
                            id: id,
                            column: b.column,
                            title: b.title,
                            body: b.body || '',
                            owner: b.owner || '',
                            position:
                                typeof b.position === 'number' ? b.position : 0,
                            version: d.version,
                        };
                    }
                }
                render();
            });
    }

    function conflict(id) {
        reread(id).catch(function () {
            loadPage();
        });
        toast('That card changed underneath you — try again.');
    }

    function guardWrite() {
        if (writesStopped || !canWrite) {
            if (!banner.hidden) return false;
            toast('This board is read-only for you.');
            return false;
        }
        return true;
    }

    function addCard(col, title) {
        if (!guardWrite()) return;
        seq += 1;
        var id = 'c-' + Date.now().toString(36) + '-' + seq;
        var body = {
            column: col,
            title: title,
            body: 'Added from the board.',
            owner: 'you',
            position: maxPosition(col, null) + 1,
        };
        api('PUT', '/cards/' + encodeURIComponent(id), body)
            .then(function (res) {
                if (res.status === 409) {
                    loadPage();
                    toast('That card changed underneath you — try again.');
                    return;
                }
                if (!res.ok) throw new Error('set ' + res.status);
                return loadPage();
            })
            .catch(function (err) {
                if (err && err.message === 'auth') return;
                toast('Could not add the card. Try again.');
            });
    }

    function moveCard(id, dir) {
        if (!guardWrite()) return;
        var card = findCard(id);
        if (!card) return;
        var i = ORDER.indexOf(card.column) + dir;
        if (i < 0 || i >= ORDER.length) return;
        var target = ORDER[i];
        var body = { column: target, position: maxPosition(target, id) + 1 };
        api('PATCH', '/cards/' + encodeURIComponent(id), body, card.version)
            .then(function (res) {
                if (res.status === 409) {
                    conflict(id);
                    return;
                }
                if (!res.ok) throw new Error('move ' + res.status);
                return loadPage().then(function () {
                    refocus(id);
                });
            })
            .catch(function (err) {
                if (err && err.message === 'auth') return;
                toast('Could not move the card. Try again.');
            });
    }

    function deleteCard(id) {
        if (!guardWrite()) return;
        var card = findCard(id);
        if (!card) return;
        if (!window.confirm('Delete "' + card.title + '"?')) return;
        api(
            'DELETE',
            '/cards/' + encodeURIComponent(id),
            undefined,
            card.version,
        )
            .then(function (res) {
                if (res.status === 409) {
                    conflict(id);
                    return;
                }
                if (!res.ok) throw new Error('delete ' + res.status);
                return loadPage();
            })
            .catch(function (err) {
                if (err && err.message === 'auth') return;
                toast('Could not delete the card. Try again.');
            });
    }

    function openEditor(article, card) {
        if (!canWrite || writesStopped) return;
        if (article.querySelector('.edit-form')) return;
        var form = document.createElement('form');
        form.className = 'edit-form';
        form.innerHTML =
            '<input type="text" name="title" maxlength="200" aria-label="Card title">' +
            '<textarea name="body" rows="2" aria-label="Card body"></textarea>' +
            '<div class="edit-row">' +
            '<button class="btn" type="submit">Save</button>' +
            '<button class="btn-secondary" type="button" data-cancel>Cancel</button>' +
            '</div>';
        form.elements.title.value = card.title;
        form.elements.body.value = card.body;
        article.appendChild(form);
        form.elements.title.focus();
        form.elements.title.select();
        form.querySelector('[data-cancel]').addEventListener(
            'click',
            function () {
                form.remove();
                refocus(card.id);
            },
        );
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var title = form.elements.title.value.trim();
            if (!title) {
                form.elements.title.focus();
                return;
            }
            api(
                'PATCH',
                '/cards/' + encodeURIComponent(card.id),
                {
                    title: title,
                    body: form.elements.body.value,
                },
                card.version,
            )
                .then(function (res) {
                    if (res.status === 409) {
                        conflict(card.id);
                        return;
                    }
                    if (!res.ok) throw new Error('edit ' + res.status);
                    return loadPage().then(function () {
                        refocus(card.id);
                    });
                })
                .catch(function (err) {
                    if (err && err.message === 'auth') return;
                    toast('Could not save the card. Try again.');
                });
        });
    }

    function dropCard(id, targetCol, beforeId) {
        if (!guardWrite()) return;
        var card = findCard(id);
        if (!card) return;
        var pos;
        if (beforeId && beforeId !== id) {
            var before = findCard(beforeId);
            if (before && before.column === targetCol) {
                var prev = 0;
                cards.forEach(function (c) {
                    if (
                        c.column === targetCol &&
                        c.id !== id &&
                        c.position < before.position &&
                        c.position > prev
                    )
                        prev = c.position;
                });
                pos = (prev + before.position) / 2;
                if (!(pos > prev)) pos = before.position - 0.5;
            } else {
                pos = maxPosition(targetCol, id) + 1;
            }
        } else {
            pos = maxPosition(targetCol, id) + 1;
        }
        api(
            'PATCH',
            '/cards/' + encodeURIComponent(id),
            { column: targetCol, position: pos },
            card.version,
        )
            .then(function (res) {
                if (res.status === 409) {
                    conflict(id);
                    return;
                }
                if (!res.ok) throw new Error('drop ' + res.status);
                return loadPage().then(function () {
                    refocus(id);
                });
            })
            .catch(function (err) {
                if (err && err.message === 'auth') return;
                toast('Could not move the card. Try again.');
            });
    }

    board.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn || btn.disabled) return;
        var article = e.target.closest('[data-id]');
        if (!article) return;
        var id = article.getAttribute('data-id');
        var action = btn.getAttribute('data-action');
        if (action === 'left') moveCard(id, -1);
        else if (action === 'right') moveCard(id, 1);
        else if (action === 'delete') deleteCard(id);
    });

    board.addEventListener('keydown', function (e) {
        var article = e.target.closest('[data-id]');
        if (!article) return;
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')
            return;
        var id = article.getAttribute('data-id');
        if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            deleteCard(id);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var card = findCard(id);
            if (card) openEditor(article, card);
        } else if (e.ctrlKey && e.key === 'ArrowLeft') {
            e.preventDefault();
            moveCard(id, -1);
        } else if (e.ctrlKey && e.key === 'ArrowRight') {
            e.preventDefault();
            moveCard(id, 1);
        }
    });

    var dragId = null;

    function clearDropMarks() {
        board.querySelectorAll('.drop-before').forEach(function (el) {
            el.classList.remove('drop-before');
        });
    }

    board.addEventListener('dragstart', function (e) {
        var article = e.target.closest('[data-id]');
        if (!article) return;
        dragId = article.getAttribute('data-id');
        e.dataTransfer.setData('text/plain', dragId);
        e.dataTransfer.effectAllowed = 'move';
        article.classList.add('dragging');
    });
    board.addEventListener('dragend', function () {
        dragId = null;
        clearDropMarks();
        board.querySelectorAll('.dragging').forEach(function (el) {
            el.classList.remove('dragging');
        });
    });

    board.addEventListener('dragover', function (e) {
        var section = e.target.closest('[data-column]');
        if (!section || !dragId) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        clearDropMarks();
        var over = e.target.closest('[data-id]');
        if (over && over.getAttribute('data-id') !== dragId) {
            over.classList.add('drop-before');
        }
    });

    board.addEventListener('drop', function (e) {
        var section = e.target.closest('[data-column]');
        if (!section) return;
        e.preventDefault();
        var id = dragId || e.dataTransfer.getData('text/plain');
        if (!id) return;
        var over = e.target.closest('[data-id]');
        var beforeId = over ? over.getAttribute('data-id') : null;
        if (beforeId === id) return;
        clearDropMarks();
        dropCard(id, section.getAttribute('data-column'), beforeId);
    });

    board.querySelectorAll('[data-add]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = form.elements.title;
            var title = input.value.trim();
            if (!title) return;
            var section = form.closest('[data-column]');
            input.value = '';
            addCard(section.getAttribute('data-column'), title);
        });
    });

    searchInput.addEventListener('input', function () {
        filter = searchInput.value;
        render();
    });

    prevBtn.addEventListener('click', function () {
        if (cursorHistory.length === 0) return;
        loadPage(cursorHistory.pop());
    });

    nextBtn.addEventListener('click', function () {
        if (!nextCursor) return;
        cursorHistory.push(pageCursor);
        loadPage(nextCursor);
    });

    reloadBtn.addEventListener('click', function () {
        window.location.reload();
    });

    loadSession()
        .then(function () {
            return loadPage();
        })
        .catch(function () {
            render();
        });
})();
