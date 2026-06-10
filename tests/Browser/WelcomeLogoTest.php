<?php

it('keeps the ascii logo centered and contained on mobile', function () {
    $page = visit('/')->on()->mobile();

    $page
        ->assertNoJavaScriptErrors()
        ->assertScript(<<<'JS'
            (() => {
                const logo = document.querySelector('.ascii-hero');

                if (! logo) {
                    return false;
                }

                const rect = logo.getBoundingClientRect();
                const parent = logo.parentElement.getBoundingClientRect();
                const lines = logo.textContent.split('\n');
                const centeredDelta = Math.abs(
                    (rect.left + rect.width / 2) - (parent.left + parent.width / 2),
                );

                return lines.length === 7
                    && lines.every((line) => Array.from(line).length === 50)
                    && rect.left >= 0
                    && rect.right <= window.innerWidth
                    && centeredDelta <= 1;
            })()
            JS, true);
});
