<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(['workspace_id' => $workspace->id]);
    }
}
