<?php

test('contradicting tools article renders without javascript errors', function () {
    visit(route('blog.show', ['slug' => 'stop-ai-tools-contradicting-each-other']))
        ->assertSee('Stopping Claude and Cursor from contradicting each other')
        ->assertSee('The team is aligned. The tools are not.')
        ->assertSee('Why Claude and Cursor contradict each other')
        ->assertSee('Frequently asked questions')
        ->assertNoJavaScriptErrors();
});
