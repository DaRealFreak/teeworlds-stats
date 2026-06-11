<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'name'    => $this->faker->firstName(),
            'version' => '0.7.5',
            'ip'      => $this->faker->ipv4(),
            'port'    => $this->faker->numberBetween(1, 65535),
        ];
    }
}
