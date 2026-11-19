<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare workspace (no roles). Tests and seeders that need a usable one go
 * through WorkspaceProvisioner, which adds the default roles and an Owner.
 *
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->company()];
    }
}
