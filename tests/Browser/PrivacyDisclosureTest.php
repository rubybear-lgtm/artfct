<?php

/**
 * Spec 17: operator views of a team can be withheld from its audit log under a
 * suppression case, so the published privacy text must say so (and say what
 * staff can and cannot see) before the feature is used.
 */
test('privacy_page_discloses_staff_access_and_the_withholding_exception', function () {
    visit(route('privacy'))
        ->assertSee('What our staff can see')
        ->assertSee('do not show your files')
        ->assertSee('artfct staff')
        ->assertSee('we may withhold the record of staff looking at your team from your audit log')
        ->assertSee('changes staff make to your team always stay visible to you')
        ->assertNoJavaScriptErrors();
});
