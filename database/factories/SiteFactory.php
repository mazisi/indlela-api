<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        $domain = fake()->unique()->domainWord().'.co.za';

        return [
            'client_id' => Client::factory(),
            'name' => $domain,
            'url' => "https://www.{$domain}",
            'domain' => $domain,
            'is_active' => true,
        ];
    }
}
