<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ServerAddress
 *
 * @property int $id
 * @property int $server_id
 * @property string $ip
 * @property int $port
 * @property int $protocol
 * @property bool $is_canonical
 */
class ServerAddress extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'protocol'     => 'integer',
        'is_canonical' => 'boolean',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
