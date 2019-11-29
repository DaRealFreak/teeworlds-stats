<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ModRule
 *
 * @mixin \Eloquent
 * @property-read \App\Models\Mod $mod
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string $decider
 * @property string $rule
 * @property int $mod_id
 * @property int $priority
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ModRule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ModRule whereDecider($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ModRule whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ModRule whereModId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ModRule wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ModRule whereRule($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ModRule whereUpdatedAt($value)
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
