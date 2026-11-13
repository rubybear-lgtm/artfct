#!/usr/bin/env node
/**
 * Copies the Artfct skills from this repo into the plugin repository
 * (artfct-dev/artfct-plugin). This repo owns the skills; the plugin repo only
 * holds copies, so nothing there is edited by hand.
 *
 *   node scripts/plugin/sync-skills.mjs <plugin-repo-dir>          copy the skills
 *   node scripts/plugin/sync-skills.mjs <plugin-repo-dir> --check  exit 1 if they differ
 *
 * `artfct-init` is left out on purpose: it runs Node scripts, and the plugin
 * must work with no Node on the computer.
 */
import {
    cpSync,
    existsSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    rmSync,
    statSync,
} from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const PLUGIN_SKILLS = [
    'artfct',
    'artfct-publish',
    'artfct-search',
    'artfct-collaborate',
    'artfct-data',
];

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** @returns {string[]} sorted file paths relative to `dir` */
function listFiles(dir) {
    if (!existsSync(dir)) {
        return [];
    }

    return readdirSync(dir, { recursive: true })
        .map(String)
        .filter((path) => statSync(join(dir, path)).isFile())
        .sort();
}

/**
 * @param {string} sourceSkillsDir
 * @param {string} targetSkillsDir
 * @returns {string[]} one line per difference; empty when the copies match
 */
export function skillDifferences(sourceSkillsDir, targetSkillsDir) {
    const differences = [];

    for (const skill of PLUGIN_SKILLS) {
        const source = join(sourceSkillsDir, skill);
        const target = join(targetSkillsDir, skill);
        const sourceFiles = listFiles(source);
        const targetFiles = listFiles(target);

        for (const file of sourceFiles) {
            if (!targetFiles.includes(file)) {
                differences.push(`missing: ${skill}/${file}`);
            } else if (
                !readFileSync(join(source, file)).equals(
                    readFileSync(join(target, file)),
                )
            ) {
                differences.push(`changed: ${skill}/${file}`);
            }
        }

        for (const file of targetFiles) {
            if (!sourceFiles.includes(file)) {
                differences.push(`stale: ${skill}/${file}`);
            }
        }
    }

    for (const entry of existsSync(targetSkillsDir)
        ? readdirSync(targetSkillsDir)
        : []) {
        if (!PLUGIN_SKILLS.includes(entry)) {
            differences.push(`stale: ${entry}/`);
        }
    }

    return differences;
}

/** Replaces the plugin's skills with this repo's copies. */
export function syncSkills(sourceSkillsDir, targetSkillsDir) {
    rmSync(targetSkillsDir, { recursive: true, force: true });
    mkdirSync(targetSkillsDir, { recursive: true });

    for (const skill of PLUGIN_SKILLS) {
        cpSync(join(sourceSkillsDir, skill), join(targetSkillsDir, skill), {
            recursive: true,
        });
    }
}

function main(argv) {
    const [pluginRepo, flag] = argv;

    if (!pluginRepo || (flag !== undefined && flag !== '--check')) {
        console.error(
            'Usage: node scripts/plugin/sync-skills.mjs <plugin-repo-dir> [--check]',
        );
        process.exit(2);
    }

    const sourceSkillsDir = join(repoRoot, 'skills');
    const targetSkillsDir = join(
        resolve(pluginRepo),
        'plugins',
        'artfct',
        'skills',
    );

    if (
        !existsSync(
            join(resolve(pluginRepo), '.claude-plugin', 'marketplace.json'),
        )
    ) {
        console.error(
            `${pluginRepo} is not the plugin repository (no .claude-plugin/marketplace.json).`,
        );
        process.exit(2);
    }

    const differences = skillDifferences(sourceSkillsDir, targetSkillsDir);

    if (flag === '--check') {
        if (differences.length > 0) {
            console.error(
                `The plugin skills differ from ${relative(process.cwd(), sourceSkillsDir) || 'skills'}:`,
            );
            differences.forEach((line) => console.error(`  ${line}`));
            process.exit(1);
        }

        console.log('The plugin skills match.');

        return;
    }

    syncSkills(sourceSkillsDir, targetSkillsDir);
    console.log(
        differences.length === 0
            ? 'Already in sync.'
            : `Synced ${differences.length} difference(s).`,
    );
}

if (
    process.argv[1] &&
    resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
    main(process.argv.slice(2));
}
