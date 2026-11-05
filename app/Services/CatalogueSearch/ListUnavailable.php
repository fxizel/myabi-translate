<?php

namespace App\Services\CatalogueSearch;

/** No complete ordinary list exists; never present an empty/partial result as a fallback. */
final class ListUnavailable extends \RuntimeException {}
