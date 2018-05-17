<?php

namespace App\TwStats\Models;

/**
 * App\TwStats\Models\GameServer
 *
 * @mixin \Eloquent
 */
class GameServer extends Server
{
    protected $additionalAttributes = [
        'port' => 8303,
        'players' => []
    ];

    protected $additionalFillables = [
        'players', 'num_servers'
    ];

    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge(parent::getAttributes(), $this->additionalAttributes);
        $this->fillable = array_merge(parent::getFillable(), $this->additionalFillables);
        parent::__construct($attributes);
    }
}
