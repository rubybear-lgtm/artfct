#!/usr/bin/env python3
"""Build the FTS5 search index over the mined glean.com corpus.

The corpus text itself lives outside this repository (it is third-party
marketing copy); only this reproducible indexer is versioned.

    python3 build-corpus-index.py            # build ~/.pi/agent/artfct-content-corpus/glean.db
    python3 build-corpus-index.py --query "token efficiency"
"""
import argparse
import json
import os
import re
import sqlite3
import sys

CORPUS_DIR = os.path.expanduser('~/.pi/agent/artfct-content-corpus')
CORPUS = os.path.join(CORPUS_DIR, 'glean-corpus.jsonl')
DB = os.path.join(CORPUS_DIR, 'glean.db')

MIN_CHUNK = 40
MAX_CHUNK = 2400

SCHEMA = """
DROP TABLE IF EXISTS chunks;
DROP TABLE IF EXISTS pages;
CREATE TABLE pages (
  url TEXT PRIMARY KEY, section TEXT, title TEXT, description TEXT,
  author TEXT, date TEXT, words INTEGER, headings TEXT
);
CREATE VIRTUAL TABLE chunks USING fts5(
  text,
  title UNINDEXED, section UNINDEXED, author UNINDEXED,
  url UNINDEXED, date UNINDEXED, words UNINDEXED, ord UNINDEXED, kind UNINDEXED,
  tokenize='porter unicode61'
);
"""


def split_chunks(prose):
    """Paragraph-level passages, so a hit is a usable quote rather than a page."""
    out = []
    for block in re.split(r'\n\s*\n', prose or ''):
        block = ' '.join(block.split())
        if not block:
            continue
        if len(block) <= MAX_CHUNK:
            out.append(block)
            continue
        # Long block: break on sentence boundaries so no chunk is unusable.
        buf = ''
        for sent in re.split(r'(?<=[.!?])\s+', block):
            if len(buf) + len(sent) + 1 > MAX_CHUNK and buf:
                out.append(buf.strip())
                buf = ''
            buf += sent + ' '
        if buf.strip():
            out.append(buf.strip())
    return out


def build():
    if not os.path.exists(CORPUS):
        sys.exit('corpus not found: %s' % CORPUS)

    con = sqlite3.connect(DB)
    con.executescript(SCHEMA)

    pages, chunks = 0, 0
    for line in open(CORPUS):
        r = json.loads(line)
        prose = r.get('prose') or ''
        if len(prose) < 200:
            continue
        url = r.get('url', '')
        title = (r.get('title') or r.get('h1') or '')[:300]
        section = r.get('section', '')
        author = r.get('author') or ''
        date = r.get('last_updated_iso') or ''
        words = r.get('words', 0)

        con.execute(
            'INSERT OR REPLACE INTO pages VALUES (?,?,?,?,?,?,?,?)',
            (url, section, title, (r.get('description') or '')[:600], author, date,
             words, json.dumps([h[1] for h in (r.get('headings') or [])][:60])),
        )
        pages += 1

        ordn = 0
        seen = set()

        def emit(text, kind):
            """One passage per distinct string per page: the extractor keeps the H1
            in the body too, so headings and their paragraphs collide otherwise."""
            nonlocal ordn
            text = ' '.join((text or '').split())
            key = text.lower()
            if len(text) < MIN_CHUNK or key in seen:
                return
            seen.add(key)
            con.execute(
                'INSERT INTO chunks VALUES (?,?,?,?,?,?,?,?,?)',
                (text, title, section, author, url, date, words, ordn, kind),
            )
            ordn += 1

        for h in (r.get('headings') or []):
            if len(' '.join((h[1] or '').split())) >= 8:
                emit(h[1], 'heading')
        for para in split_chunks(prose):
            emit(para, 'para')

    con.execute('CREATE INDEX pages_section ON pages(section)')
    con.commit()
    con.execute('INSERT INTO chunks(chunks) VALUES(\'optimize\')')
    con.commit()
    chunks = con.execute('SELECT count(*) FROM chunks').fetchone()[0]
    con.close()
    print('indexed %d pages, %d passages -> %s (%.1f MB)'
          % (pages, chunks, DB, os.path.getsize(DB) / 1e6))


def query_rows(q, limit=8, section=None):
    """Structured results, for the extension. One passage per page."""
    con = sqlite3.connect(DB)
    sql = ('SELECT snippet(chunks, 0, "<<", ">>", " … ", 18) AS snip, title, url, '
           'section, author, date, bm25(chunks) AS rank FROM chunks '
           'WHERE chunks MATCH ?')
    args = [q]
    if section:
        sql += ' AND section = ?'
        args.append(section)
    sql += ' ORDER BY rank LIMIT ?'
    args.append(limit * 8)
    # One passage per page: a page's heading ladder otherwise fills the result
    # set with near-identical lines and hides the other articles.
    seen, rows = set(), []
    for snip, title, url, sec, author, date, rank in con.execute(sql, args):
        if url in seen:
            continue
        seen.add(url)
        rows.append({'section': sec, 'title': title, 'url': url,
                     'author': author, 'date': date, 'snippet': snip})
        if len(rows) >= limit:
            break
    con.close()
    return rows


def query(q, limit=8, section=None):
    for row in query_rows(q, limit, section):
        print('\n[%s] %s' % (row['section'], row['title']))
        print('  %s  (%s)' % (row['url'], row['date'] or 'undated'))
        print('  %s' % row['snippet'])


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--query')
    ap.add_argument('--limit', type=int, default=8)
    ap.add_argument('--section')
    ap.add_argument('--json', action='store_true')
    a = ap.parse_args()
    if a.query:
        if a.json:
            print(json.dumps(query_rows(a.query, a.limit, a.section)))
        else:
            query(a.query, a.limit, a.section)
    else:
        build()
