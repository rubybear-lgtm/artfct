<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactDirectory;
use RuntimeException;
use ZipArchive;

/**
 * Packs an organization's permanent artifacts into one zip: `artifacts.json`
 * (the Worker's export metadata, provenance included) plus every referenced
 * file under `blobs/{sha256}`. A blob whose bytes do not hash to its name is
 * refused rather than written, so the archive is byte-identical to what the
 * Worker stored or the export fails.
 */
final class OrganizationExportArchive
{
    public function __construct(private readonly ArtifactDirectory $artifacts) {}

    /**
     * Build the zip in a temporary file and return its path. The caller owns
     * the file and must delete it.
     */
    public function build(string $orgSlug): string
    {
        $export = $this->artifacts->exportArtifacts($orgSlug);
        $path = tempnam(sys_get_temp_dir(), 'artfct-export-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary export file.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('Could not open the export archive.');
        }

        try {
            $zip->addFromString('artifacts.json', json_encode(
                ['artifacts' => $export['artifacts']],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            foreach (array_keys($export['blobs']) as $sha256) {
                $sha256 = (string) $sha256;
                $bytes = $this->artifacts->fetchBlob($orgSlug, $sha256);

                if ($bytes === null || ! hash_equals($sha256, hash('sha256', $bytes))) {
                    throw new RuntimeException("Export blob {$sha256} is missing or does not match its hash.");
                }

                $zip->addFromString("blobs/{$sha256}", $bytes);
            }

            $zip->close();
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return $path;
    }
}
