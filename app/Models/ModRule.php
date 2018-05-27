<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ModRule
 *
 * @mixin \Eloquent
 * @property-read \App\Models\Mod $mod
 */
class ModRule extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function mod()
    {
        return $this->belongsTo(Mod::class, 'mod_id');
    }

    /**
     * @return Collection
     */
    public function mods()
    {
        if ($this->getAttribute('decider') !== 'mod') {
            return new Collection();
        }
        return Mod::where('name', 'like', $this->getAttribute('rule'))
            ->where('id', '!=', $this->getAttribute('mod_id'))
            ->get();
    }

    /**
     * @return Collection
     */
    public function servers()
    {
        if ($this->getAttribute('decider') !== 'server') {
            return new Collection();
        }
        return Server::where('name', 'like', $this->getAttribute('rule'))->get();
    }
}
