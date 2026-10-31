<?php

namespace App\Services\Search;

use RuntimeException;

/** Thrown when search_artifacts is scoped to a collection that does not exist. */
final class CollectionNotFoundException extends RuntimeException {}
