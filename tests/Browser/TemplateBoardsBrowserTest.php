<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Specs 22-24 Unit C: the shipped template bundles work in a real browser.
 * The harness serves each template's real `index.html` + `app.js` (inlined)
 * with `window.fetch` stubbed at the Worker contract boundary: the stub keeps
 * an in-memory row store seeded from the template's own seed rows and answers
 * exactly the Worker shapes (`{docs, cursor}` lists, doc envelopes, 409s with
 * `current`, 204 deletes, `{token, expires_at, role}` sessions). Every
 * request is recorded on `window.__calls` for transport assertions. What the
 * stub does NOT do is the server's job: roles, versions and quotas are
 * asserted in the Feature suites.
 */

function templateBoardHtml(string $key, string $mode = 'ok'): string
{
    $files = resource_path("templates/{$key}/files");
    $html = (string) file_get_contents($files.'/index.html');
    $js = (string) file_get_contents($files.'/app.js');
    $manifest = json_decode((string) file_get_contents(resource_path("templates/{$key}/template.json")), true);

    $rows = [];
    foreach ($manifest['db']['seed'] ?? [] as $row) {
        $rows[] = [
            'collection' => $row['collection'],
            'doc_id' => $row['doc_id'],
            'body' => $row['body'],
            'version' => 1,
            'updated_at' => '2026-11-14T00:00:00Z',
            'updated_by' => 'artfct',
        ];
    }

    $stub = 'window.__calls=[];window.__db='.json_encode($rows).';window.__mode='.json_encode($mode).';'
        .'window.fetch=function(url,opts){opts=opts||{};var u=String(url);'
        .'function rec(m){var b=null;try{b=opts.body?JSON.parse(opts.body):null;}catch(e){}window.__calls.push({method:m,url:u,body:b});}'
        .'function resp(d,s){return Promise.resolve(new Response(JSON.stringify(d),{status:s||200,headers:{"Content-Type":"application/json"}}));}'
        .'if(u.indexOf("/__artfct/db/session")!==-1){rec("GET");'
        .'if(window.__mode==="expired")return resp({error:{code:"forbidden"}},401);'
        .'return resp({token:"test-token",expires_at:"2999-01-01T00:00:00Z",role:"owner"});}'
        .'var m=u.match(/\\/__artfct\\/db\\/([A-Za-z0-9._-]+)(?:\\/([A-Za-z0-9._-]+))?(\\?.*)?$/);'
        .'if(!m)return Promise.resolve(new Response("not found",{status:404}));'
        .'var col=m[1],id=m[2]||null,q=m[3]||"";'
        .'function rows(){return window.__db.filter(function(r){return r.collection===col;}).sort(function(a,b){return a.doc_id<b.doc_id?-1:1;});}'
        .'function one(){return rows().filter(function(r){return r.doc_id===id;})[0];}'
        .'function ver(){var v=q.match(/if_version=(\\d+)/);return v?parseInt(v[1],10):null;}'
        .'if((opts.method||"GET")==="GET"&&!id){rec("GET");return resp({docs:rows(),cursor:null});}'
        .'if((opts.method||"GET")==="GET"){rec("GET");var d=one();return d?resp(d):resp({error:{code:"not_found"}},404);}'
        .'if(window.__mode==="expired")return resp({error:{code:"forbidden"}},403);'
        .'if(opts.method==="PUT"||opts.method==="PATCH"){rec(opts.method);var cur=one()||null;var iv=ver();'
        .'if(cur&&iv!==null&&cur.version!==iv)return resp({error:{code:"version_conflict"},current:cur},409);'
        .'var nb=opts.body?JSON.parse(opts.body):{};'
        .'if(opts.method==="PATCH"&&cur){for(var k in nb)cur.body[k]=nb[k];}else{cur={collection:col,doc_id:id,body:nb,version:(cur?cur.version:0)+1,updated_at:"t",updated_by:"system"};window.__db.push(cur);}'
        .'if(!cur.version)cur.version=1;'
        .'return resp(cur);}'
        .'if(opts.method==="DELETE"){rec("DELETE");var del=one();var ivd=ver();'
        .'if(del&&ivd!==null&&del.version!==ivd)return resp({error:{code:"version_conflict"},current:del},409);'
        .'window.__db=window.__db.filter(function(r){return r!==del;});'
        .'return new Promise(function(r){r(new Response(null,{status:204}));});}'
        .'return Promise.resolve(new Response("not found",{status:404}));};';

    $bundle = '<script>'.$stub.$js.'</script>';

    return str_replace('<script src="app.js"></script>', $bundle, $html);
}

function openTemplateBoard(string $key, string $mode = 'ok')
{
    $page = visit('/');

    $page->script('document.open();document.write('.json_encode(templateBoardHtml($key, $mode)).');document.close();');
    sleep(2);

    return $page;
}

function boardCalls(): array
{
    return [];
}

test('kanban_board_moves_cards_and_records_the_contract', function () {
    $page = openTemplateBoard('kanban');

    $page->assertSee('This is a card')
        ->assertSee('Agents move cards too')
        ->assertNoJavaScriptErrors();

    // Move the first card one column right via its own control.
    $page->script('document.querySelector(\'[data-column="todo"] [data-action="right"]\').click();');
    sleep(1);
    $page->assertSee('2 cards');

    $calls = $page->script('JSON.stringify(window.__calls)');
    $calls = json_decode((string) $calls, true);

    $patch = array_values(array_filter($calls, fn ($c) => $c['method'] === 'PATCH'));
    expect($patch)->not->toBeEmpty()
        ->and($patch[0]['url'])->toContain('/__artfct/db/cards/')
        ->and($patch[0]['url'])->toContain('if_version=')
        ->and($patch[0]['body']['column'])->toBe('doing');

    // Add a card through the form; it renders in its column.
    $page->script('var f=document.querySelector(\'[data-column="todo"] [data-add]\');'
        .'f.querySelector("input").value="Card from the test suite";f.requestSubmit();');
    sleep(1);
    $page->assertSee('Card from the test suite');
});

test('kanban_board_shows_reload_state_after_session_expiry', function () {
    $page = openTemplateBoard('kanban', 'expired');

    sleep(1);
    $page->assertSee('This board needs to be reloaded to keep saving.')
        ->assertNoJavaScriptErrors();
});

test('kanban_board_keyboard_moves_a_card', function () {
    $page = openTemplateBoard('kanban');

    $page->assertSee('This is a card');
    $page->script('var c=document.querySelector(\'[data-column="todo"] .card\');c.focus();'
        .'c.dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowRight",ctrlKey:true,bubbles:true}));');
    sleep(1);
    $page->assertSee('2 cards');

    $calls = json_decode((string) $page->script('JSON.stringify(window.__calls)'), true);
    $patch = array_values(array_filter($calls, fn ($c) => $c['method'] === 'PATCH'));

    expect($patch)->not->toBeEmpty()
        ->and($patch[0]['body']['column'])->toBe('doing');
});

test('kanban_board_stacks_without_horizontal_scroll_on_mobile', function () {
    $page = openTemplateBoard('kanban');

    $page->resize(375, 812);
    sleep(1);
    $page->assertSee('This is a card');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

test('wiki_index_page_and_graph_views_render', function () {
    $page = openTemplateBoard('team-wiki');

    $page->assertSee('Wiki overview')
        ->assertSee('Index conventions')
        ->assertNoJavaScriptErrors();

    // Clicking a page shows its rendered body.
    $page->script('document.querySelector(\'.page-list a[href="#/page/overview"]\').click();');
    sleep(1);
    $page->assertSee('shared memory');

    // The knowledge graph renders one node per page with a caption.
    $page->script('document.querySelector(\'[data-view="graph"]\').click();');
    sleep(1);
    $page->assertSee('orphan');
    expect($page->script('document.querySelectorAll(".graph-wrap .node").length'))->toBe(3);
});

test('announcement_board_posts_newest_first_and_filters_by_tag', function () {
    $page = openTemplateBoard('announcements');

    $page->assertSee('Welcome to the announcement board')
        ->assertNoJavaScriptErrors();

    // Posting puts the launch first (newest-first order).
    $page->script('document.querySelector("#f-title").value="Shipped v2";'
        .'document.querySelector("#f-body").value="It is live.";'
        .'document.querySelector("#f-tag").value="launch";'
        .'document.querySelector("#composer").requestSubmit();');
    sleep(1);
    $page->assertSee('Shipped v2');
    expect($page->script('document.querySelector(".posts > li h3").textContent'))->toContain('Shipped v2');

    // Tag chips narrow the list to the matching tag.
    $page->script('document.querySelector(\'[data-tag="launch"]\').click();');
    $page->assertSee('Shipped v2');
    expect($page->script('document.querySelectorAll(".posts > li").length'))->toBe(1);
});
