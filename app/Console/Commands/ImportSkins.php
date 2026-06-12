<?php

namespace App\Console\Commands;

use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Pre-downloads the 0.6 skins our players use from the DDNet skin database into public/skins/06 so
 * they become "local" (served by us, no per-render external fetch). This is the optional first tier
 * of the client's local > fetch > default order: whatever this imports renders locally, anything it
 * can't find still falls back to a live DDNet-DB fetch and finally the default tee at render time.
 * Re-runnable — already-present skins are skipped.
 */
class ImportSkins extends Command
{
    protected $signature = 'skins:import
        {--min-players=1 : only import skins used by at least this many players}
        {--limit=0 : cap how many skins to fetch (0 = no cap)}';

    protected $description = 'Download the player 0.6 skins available in the DDNet skin DB into public/skins/06';

    private const DDNET_SKIN_DB = 'https://skins.ddnet.org/skin/';

    public function handle(): int
    {
        $dir = public_path('skins/06');
        $local = array_map(static fn (string $f): string => basename($f, '.png'), glob($dir . '/*.png') ?: []);

        $skins = Player::query()
            ->whereNotNull('skin')->where('skin', '!=', '')
            ->selectRaw('skin, COUNT(*) as players')
            ->groupBy('skin')
            ->having('players', '>=', (int) $this->option('min-players'))
            ->orderByDesc('players')
            ->pluck('players', 'skin')
            ->reject(static fn (int $players, string $name): bool => in_array($name, $local, true))
            // a skin name that can't be a safe filename can never be local; it stays fetch-only
            ->reject(static fn (int $players, string $name): bool => (bool) preg_match('#[/\\\\\x00]#', $name));

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $skins = $skins->take($limit);
        }

        $this->info("Checking {$skins->count()} distinct skins against the DDNet skin DB…");
        $bar = $this->output->createProgressBar($skins->count());
        $imported = 0;
        $missing = 0;

        foreach ($skins as $name => $players) {
            $bar->advance();

            try {
                $response = Http::timeout(10)->get(self::DDNET_SKIN_DB . rawurlencode($name) . '.png');
            } catch (\Throwable) {
                $missing++;
                continue;
            }

            if (!$response->successful()) {
                $missing++;
                continue;
            }

            $body = $response->body();
            $size = @getimagesizefromstring($body);
            // accept standard (256x128) and HD (2:1) sheets; reject anything else (incl. 404 HTML)
            if (!$size || ($size['mime'] ?? '') !== 'image/png' || $size[1] === 0 || $size[0] !== $size[1] * 2) {
                $missing++;
                continue;
            }

            file_put_contents($dir . '/' . $name . '.png', $body);
            $imported++;
        }

        $bar->finish();
        $this->newLine();
        $this->info("Imported {$imported} skins to public/skins/06; {$missing} not in the DB (these render via live fetch or the default fallback).");

        return self::SUCCESS;
    }
}
