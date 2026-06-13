<?php

namespace Tests\Feature;

use App\Service\MapArchiveStatus;
use Tests\TestCase;

class MapArchiverTest extends TestCase
{
    public function test_status_labels_and_badge_classes(): void
    {
        $this->assertSame('Downloaded', MapArchiveStatus::Downloaded->label());
        $this->assertSame('Queued', MapArchiveStatus::Queued->label());
        $this->assertSame('Retrying', MapArchiveStatus::Retrying->label());
        $this->assertSame('Unavailable', MapArchiveStatus::Unavailable->label());

        $this->assertSame('bg-success', MapArchiveStatus::Downloaded->badgeClass());
        $this->assertSame('bg-info', MapArchiveStatus::Queued->badgeClass());
        $this->assertSame('bg-warning', MapArchiveStatus::Retrying->badgeClass());
        $this->assertSame('bg-secondary', MapArchiveStatus::Unavailable->badgeClass());
    }
}
