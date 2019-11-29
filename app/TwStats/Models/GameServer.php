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

    /**
     * some servers return me the same players in multiple packets
     * so we check if a player with identical attributes is already indexed
     *
     * @param Player $player
     * @param bool $caseInsensitive
     * @return bool
     */
    public function doesPlayerAlreadyExist(Player $player, $caseInsensitive = True)
    {
        /** @var Player $indexedPlayer */
        foreach ($this->getAttribute('players') as $indexedPlayer) {
            if ($caseInsensitive) {
                if (array_map('mb_strtolower', $player->getAttributes()) === array_map('mb_strtolower', $indexedPlayer->getAttributes())) {
                    return True;
                }
            } else {
                if ($player->getAttributes() === $indexedPlayer->getAttributes()) {
                    return True;
                }
            }
        }
        return False;
    }
}
