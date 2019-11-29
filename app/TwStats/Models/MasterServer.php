<?php

namespace App\TwStats\Models;

/**
 * App\TwStats\Models\MasterServer
 *
 * @mixin \Eloquent
 */
class MasterServer extends Server
{
    protected $additionalAttributes = [
        'port' => 8300,
        'servers' => []
    ];

    protected $additionalFillables = [
        'servers', 'num_servers'
    ];

    public function __construct(array $attributes = [])
    {
        $this->attributes = array_merge(parent::getAttributes(), $this->additionalAttributes);
        $this->fillable = array_merge(parent::getFillable(), $this->additionalFillables);
        parent::__construct($attributes);
    }
}
