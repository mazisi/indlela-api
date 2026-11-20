<?php

namespace App\Services\RankTracking;

/**
 * The DataForSEO SERP API calls rank tracking makes. DataForSeoSerpApi talks
 * to DataForSEO over HTTP (tests fake it with Http::fake). Every method
 * throws App\Exceptions\DataForSeoException when the request as a whole
 * fails; per-task outcomes come back as data.
 */
interface SerpApi
{
    /** Whether a login and password are set. */
    public function configured(): bool;

    /**
     * Queue up to 100 tasks (Standard queue) in one task_post call.
     *
     * @param  list<array<string, mixed>>  $tasks  task_post bodies (keyword, location, device, tag, …)
     * @return list<array{id: ?string, status_code: int, status_message: string, tag: ?string, cost: float}>
     */
    public function postTasks(array $tasks): array;

    /**
     * Finished Google organic tasks not collected yet.
     *
     * @return list<array{id: string, tag: ?string}>
     */
    public function tasksReady(): array;

    /**
     * One task's advanced result.
     *
     * @return array{status_code: int, status_message: string, cost: float, result: ?array<string, mixed>}
     */
    public function taskGet(string $id): array;

    /**
     * A Live (immediate, dearer) check.
     *
     * @param  array<string, mixed>  $task
     * @return array{id: ?string, status_code: int, status_message: string, cost: float, result: ?array<string, mixed>}
     */
    public function live(array $task): array;

    /**
     * Every Google location: [{location_code, location_name, location_code_parent, country_iso_code, location_type}].
     *
     * @return list<array<string, mixed>>
     */
    public function locations(): array;

    /** The account's balance in USD. */
    public function balance(): ?float;
}
