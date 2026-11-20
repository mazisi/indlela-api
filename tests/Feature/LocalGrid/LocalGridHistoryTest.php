<?php

namespace Tests\Feature\LocalGrid;

use App\Models\AuditLog;
use App\Models\MapGrid;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthTestHelpers;
use Tests\Support\LocalGridHelpers;
use Tests\TestCase;

/** Grids in Settings › Change history: labelled, scoped to the user's clients, undone. */
class LocalGridHistoryTest extends TestCase
{
    use AuthTestHelpers, LocalGridHelpers, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->manager = $this->makeUser(Role::MANAGER);
    }

    private function entry(string $action): AuditLog
    {
        return AuditLog::query()->where('table_name', 'map_grids')->where('action_type', $action)->latest('id')->firstOrFail();
    }

    public function test_grid_changes_are_listed_scoped_and_undone(): void
    {
        $mine = $this->makeClient();
        $theirs = $this->makeClient();
        $grid = $this->makeGrid($this->rankSite($mine, 'https://mine.co.za'));
        $other = $this->makeGrid($this->rankSite($theirs, 'https://theirs.co.za'), ['keyword' => 'not yours']);
        $as = $this->actingAs($this->manager, 'sanctum');

        $as->putJson("/api/local-grids/{$grid->id}", ['schedule' => 'weekly', 'size' => 5])->assertOk();
        $as->getJson('/api/settings/change-history?page_key=local_grid')->assertOk()
            ->assertJsonPath('data.0.record_type', 'Local grid')
            ->assertJsonPath('data.0.record_label', 'coffee shop sandton');

        // An analyst sees only their clients' grids.
        $analyst = $this->makeUser(Role::ANALYST, clients: [$mine]);
        $role = Role::inWorkspace($this->workspace()->id)->where('name', Role::ANALYST)->firstOrFail();
        $this->inWorkspace($this->workspace(), fn () => $role->givePermissionTo('view_settings_change_history'));
        $labels = collect($this->actingAs($analyst, 'sanctum')->getJson('/api/settings/change-history?page_key=local_grid')->assertOk()->json('data'))->pluck('record_label');
        $this->assertContains('coffee shop sandton', $labels);
        $this->assertNotContains('not yours', $labels);

        // Undo the edit.
        $as = $this->actingAs($this->manager, 'sanctum');
        $as->postJson('/api/settings/change-history/'.$this->entry('UPDATE')->id.'/undo')->assertOk();
        $this->assertSame(['none', 3], [$grid->fresh()->schedule, $grid->fresh()->size]);

        // Undo a deletion: needs room under the grid limit.
        $as->deleteJson("/api/local-grids/{$grid->id}")->assertNoContent();
        $deleted = $this->entry('DELETE');
        config(['indlela.local_grid.max_grids_per_workspace' => 1]);
        $as->postJson("/api/settings/change-history/{$deleted->id}/undo")->assertUnprocessable()->assertJsonValidationErrors('undo');
        $this->assertSoftDeleted($grid);

        $as->deleteJson("/api/local-grids/{$other->id}")->assertNoContent();
        $as->postJson("/api/settings/change-history/{$deleted->id}/undo")->assertOk();
        $this->assertFalse(MapGrid::withTrashed()->find($grid->id)->trashed());
    }
}
