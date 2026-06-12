<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a map's .map bytes: `file` inline (what the in-browser viewer fetches) and `download`
 * as an attachment (the Download button). Both lazily acquire + cache via MapFileStore and 404
 * when the file cannot be produced. Not response-cached — binary and potentially large.
 */
class MapFileController extends Controller
{
    public function __construct(private readonly MapFileStore $store)
    {
    }

    public function file(string $map_name): StreamedResponse
    {
        return $this->serve($map_name, attachment: false);
    }

    public function download(string $map_name): StreamedResponse
    {
        return $this->serve($map_name, attachment: true);
    }

    private function serve(string $map_name, bool $attachment): StreamedResponse
    {
        /** @var Map $map */
        $map = Map::where('name', urldecode($map_name))->firstOrFail();

        try {
            $relative = $this->store->ensure($map);
        } catch (MapFileUnavailable) {
            abort(404, 'Map file not available');
        }

        $disk = Storage::disk($this->store->disk);
        $filename = $map->getAttribute('name') . '.map';
        $headers = ['Content-Type' => 'application/octet-stream'];

        return $attachment
            ? $disk->download($relative, $filename, $headers)
            : $disk->response($relative, $filename, $headers, 'inline');
    }
}
