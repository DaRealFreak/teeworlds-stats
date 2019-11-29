<?php

namespace App\TwStats\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\TwStats\Models\Player
 *
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string $last_seen
 * @property string $name
 * @property string $country
 * @method static \Illuminate\Database\Eloquent\Builder|\App\TwStats\Models\Player whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\TwStats\Models\Player whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\TwStats\Models\Player whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\TwStats\Models\Player whereLastSeen($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\TwStats\Models\Player whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\TwStats\Models\Player whereUpdatedAt($value)
 */
class Player extends Model
{
    protected $fillable = [
        'name', 'clan', 'country', 'score', 'ingame'
    ];

}
