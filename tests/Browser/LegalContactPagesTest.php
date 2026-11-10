<?php

/**
 * RUB-505: directory submissions need a real privacy contact and a public
 * support URL. These visit the rendered pages (Inertia + Vite), so the
 * assertion sees the contact text a reviewer sees — not a stubbed prop.
 */
test('privacy page shows the privacy contact and not the launch placeholder', function () {
    visit(route('privacy'))
        ->assertDontSee('to be set before public launch')
        ->assertSee('privacy@artfct.dev')
        ->assertAttribute('a[href="mailto:privacy@artfct.dev"]', 'href', 'mailto:privacy@artfct.dev')
        ->assertNoJavaScriptErrors();
});

test('terms page shows the privacy contact and not the launch placeholder', function () {
    visit(route('terms'))
        ->assertDontSee('to be set before public launch')
        ->assertSee('privacy@artfct.dev')
        ->assertAttribute('a[href="mailto:privacy@artfct.dev"]', 'href', 'mailto:privacy@artfct.dev')
        ->assertNoJavaScriptErrors();
});

test('support page lists the support contact and links to docs', function () {
    visit(route('support'))
        ->assertDontSee('to be set before public launch')
        ->assertSee('support@artfct.dev')
        ->assertAttribute('a[href="mailto:support@artfct.dev"]', 'href', 'mailto:support@artfct.dev')
        ->assertSeeLink('Docs')
        ->assertNoJavaScriptErrors();
});
