<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactDirectory;
use App\Enums\ArtifactType;
use RuntimeException;
use ZipArchive;

/**
 * Builds the engineering handoff pack for a mockup artifact (RUB-564, spec 41):
 * a zip of every bundle file, with the bundle's `HANDOFF.md` promoted to
 * `README.md`. The README's first line links the artifact version it was built
 * from. Nothing is stored; the zip is built per download in a temp file the
 * caller owns and must delete.
 */
final class HandoffPackBuilder
{
    public const HANDOFF_FILE = 'HANDOFF.md';

    public const README_FILE = 'README.md';

    public function __construct(private readonly ArtifactDirectory $directory) {}

    /**
     * @param  array{html: string, entrypoint: ?string, version?: ?int, files: ?list<array{path: string, sha256: string, content_type: string, size_bytes: int}>}  $entry
     * @return string path of the temporary zip; the caller owns the file
     *
     * @throws HandoffPackException when the artifact has no handoff pack
     */
    public function build(string $orgSlug, string $artifactId, array $entry, ?string $artifactType): string
    {
        if ($artifactType !== ArtifactType::Mockup->value) {
            throw new HandoffPackException(422, 'handoff_not_mockup', ["Artifact [{$artifactId}] is not a mockup, so it has no handoff pack."]);
        }

        $files = $entry['files'] ?? null;

        if ($files === null || $entry['entrypoint'] === null) {
            throw new HandoffPackException(503, 'handoff_unavailable', ['The Worker did not list the files of this artifact, so its handoff pack cannot be built yet.']);
        }

        $paths = array_column($files, 'path');

        if (! in_array(self::HANDOFF_FILE, $paths, true)) {
            throw new HandoffPackException(422, 'handoff_missing', ["Artifact [{$artifactId}] has no ".self::HANDOFF_FILE.' in its bundle.']);
        }

        if (in_array(self::README_FILE, $paths, true)) {
            throw new HandoffPackException(422, 'handoff_readme_conflict', ["Artifact [{$artifactId}] already has a ".self::README_FILE.' in its bundle, so the handoff README cannot be written.']);
        }

        $handoff = $this->readFile($orgSlug, $artifactId, $files, $entry, self::HANDOFF_FILE);

        $version = $entry['version'] ?? null;

        if (! is_int($version) || $version < 1) {
            $readme = 'Artifact view: '.route('artifacts.show', ['artifactId' => $artifactId])."\n\n".$handoff;
        } else {
            $viewUrl = route('artifacts.version', ['artifactId' => $artifactId, 'version' => $version]);
            $readme = "Artifact view: {$viewUrl} (version {$version})\n\n".$handoff;
        }

        $path = tempnam(sys_get_temp_dir(), 'artfct-handoff-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary handoff file.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Could not open the handoff archive.');
        }

        try {
            foreach ($files as $file) {
                if ($file['path'] === self::HANDOFF_FILE) {
                    continue;
                }

                $zip->addFromString($file['path'], $this->readFile($orgSlug, $artifactId, $files, $entry, $file['path']));
            }

            $zip->addFromString(self::README_FILE, $readme);
            $zip->close();
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return $path;
    }

    /**
     * The bytes of one listed file. The entrypoint comes from the content read,
     * as the packer does; every other file is a blob. A file whose bytes do not
     * match its listed size is refused and nothing is written.
     *
     * @param  list<array{path: string, sha256: string, content_type: string, size_bytes: int}>  $files
     * @param  array{html: string, entrypoint: ?string}  $entry
     */
    private function readFile(string $orgSlug, string $artifactId, array $files, array $entry, string $path): string
    {
        $listed = collect($files)->firstWhere('path', $path);

        if ($listed === null) {
            throw new HandoffPackException(422, 'handoff_unreadable', ["Artifact [{$artifactId}] file [{$path}] is not in the bundle."]);
        }

        $bytes = $path === $entry['entrypoint']
            ? $entry['html']
            : $this->directory->fetchBlob($orgSlug, $listed['sha256']);

        if ($bytes === null || strlen($bytes) !== $listed['size_bytes']) {
            throw new HandoffPackException(422, 'handoff_unreadable', ["Artifact [{$artifactId}] file [{$path}] could not be read from the Worker, or does not match its listed size."]);
        }

        return $bytes;
    }
}
