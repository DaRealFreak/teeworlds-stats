<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServerAddress>
 */
class ServerAddressFactory extends Factory
{
    protected $model = ServerAddress::class;

    public function definition(): array
    {
        return [
            'server_id'    => Server::factory(),
            'ip'           => $this->faker->ipv4(),
            'port'         => $this->faker->numberBetween(1, 65535),
            'protocol'     => 6,
            'is_canonical' => true,
        ];
    }
}
