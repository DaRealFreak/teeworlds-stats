<?php

use App\Service\LegacyMapCacheRenamer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Rename existing hash-only cache files to the new <name>_<hash>.map scheme so the archive is
        // browsable on disk. Runs once per environment; a no-op when there are no legacy files (e.g.
        // a fresh test database, where no Map rows match the on-disk keys).
        app(LegacyMapCacheRenamer::class)->run();
    }

    public function down(): void
    {
        // The cache is regenerable; renaming back has no value.
    }
};
