<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerMods extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    public function player()
    {
        return $this->belongsTo(Tee::class);
    }
}
