<?php

namespace Tests\Feature;

use App\Models\MapAcquisitionAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MapAcquisitionPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_rows_older_than_thirty_days(): void
    {
        $old = MapAcquisitionAttempt::create(['source' => 'udp', 'outcome' => 'failure', 'reason' => 'timeout']);
        $fresh = MapAcquisitionAttempt::create(['source' => 'udp', 'outcome' => 'failure', 'reason' => 'timeout']);
        DB::table('map_acquisition_attempts')->where('id', $old->id)->update(['created_at' => now()->subDays(31)]);
        DB::table('map_acquisition_attempts')->where('id', $fresh->id)->update(['created_at' => now()->subDays(29)]);

        $this->artisan('model:prune', ['--model' => [MapAcquisitionAttempt::class]])->assertExitCode(0);

        $this->assertDatabaseMissing('map_acquisition_attempts', ['id' => $old->id]);
        $this->assertDatabaseHas('map_acquisition_attempts', ['id' => $fresh->id]);
    }
}
