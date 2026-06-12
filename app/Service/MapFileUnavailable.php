<?php

namespace App\Service;

/**
 * Thrown when no MapSource can produce verified bytes for a map (e.g. a non-DDNet map in Phase A,
 * or every source failed). Callers translate this into a 404 / "not available" UI state.
 */
final class MapFileUnavailable extends \RuntimeException
{
}
