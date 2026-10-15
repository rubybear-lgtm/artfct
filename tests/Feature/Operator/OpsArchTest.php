<?php

/*
 * The operator console's content boundary, enforced as a build failure
 * (spec 17): nothing under the `/ops` namespaces may depend on the services
 * that read artifact content or mint a link to it, nor on the models that
 * hold indexed text, comments or collections.
 *
 * One expectation per namespace: Pest's multi-namespace group form reports a
 * pass when any one of the namespaces is clean, which would hide a violation.
 */

arch('ops_namespace_does_not_depend_on_content_services_or_models', function () {
    $forbidden = [
        // Reads and lists artifacts held by the Worker.
        'App\Contracts\ArtifactContentSource',
        'App\Contracts\ArtifactDirectory',
        'App\Contracts\PublicArtifactSource',
        // Viewer, preview, link minting, version and export services.
        'App\Services\Artifacts',
        'App\Http\Controllers\ArtifactViewerController',
        'App\Http\Controllers\ArtifactVersionController',
        'App\Http\Controllers\ConsoleController',
        'App\Http\Controllers\Teams\ArtifactPreviewController',
        // Indexed text, search and everything derived from content.
        'App\Services\Indexing',
        'App\Services\Search',
        'App\Services\Collections',
        'App\Services\Sharing\ArtifactVisibility',
        'App\Models\ArtifactChunk',
        'App\Models\ArtifactIndexEntry',
        'App\Models\ArtifactComment',
        'App\Models\ArtifactTypeAssignment',
        'App\Models\Collection',
        'App\Models\CollectionArtifact',
        'App\Models\SearchResultServed',
    ];

    foreach ([
        'App\Http\Controllers\Ops',
        'App\Services\Operator',
        'App\Services\Readiness',
    ] as $namespace) {
        expect($namespace)->not->toUse($forbidden);
    }
});
