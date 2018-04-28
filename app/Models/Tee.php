<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tee extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the clan record associated with the tee.
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class, 'clan_id');
    }
}
