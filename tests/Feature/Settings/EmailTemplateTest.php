<?php

namespace Tests\Feature\Settings;

use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthTestHelpers;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use AuthTestHelpers, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuth();
        $this->owner = $this->ownerOf($this->workspace());
    }

    public function test_the_list_has_every_type_with_the_default_wording(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->getJson('/api/email-templates')->assertOk();

        $this->assertSame(array_map(fn ($t) => $t->value, EmailTemplateType::cases()), array_column($response->json('data'), 'type'));
        $invite = collect($response->json('data'))->firstWhere('type', 'user_invited');
        $this->assertSame('You have been given {{app_name}} access to {{workspace}}', $invite['subject']);
        $this->assertFalse($invite['is_customised']);
        $this->assertContains(['key' => 'workspace', 'description' => 'The workspace (e.g. Acme Digital)'], $invite['variables']);
    }

    public function test_edits_are_sanitised_and_apply_to_this_workspace_only(): void
    {
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/email-templates/user_invited', [
            'subject' => 'Welcome to {{workspace}}',
            'body' => '<p>Hi {{name}}</p><script>alert(1)</script>',
        ])->assertOk()->assertJsonPath('data.is_customised', true);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/email-templates/user_invited')
            ->assertJsonPath('data.subject', 'Welcome to {{workspace}}')
            ->assertJsonPath('data.body', '<p>Hi {{name}}</p>');

        // Another workspace still gets the default.
        $other = $this->makeWorkspace('Other');
        $this->actingAs($this->ownerOf($other), 'sanctum')->getJson('/api/email-templates/user_invited')
            ->assertJsonPath('data.is_customised', false);

        $mine = new TemplatedMail(EmailTemplateType::UserInvited, ['workspace' => 'Acme'], null, $this->workspace()->id);
        $theirs = new TemplatedMail(EmailTemplateType::UserInvited, ['workspace' => 'Other'], null, $other->id);
        $this->assertSame('Welcome to Acme', $mine->rendered()['subject']);
        $this->assertSame('You have been given Indlela access to Other', $theirs->rendered()['subject']);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/email-templates/user_invited/reset')->assertOk()
            ->assertJsonPath('data.is_customised', false);
    }

    public function test_preview_and_test_email(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/email-templates/login_pin/preview', ['subject' => 'PIN {{code}}'])
            ->assertOk()->assertJsonPath('data.subject', 'PIN 482913');

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/email-templates/login_pin/test')->assertOk();
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo($this->owner->email) && $m->subjectPrefix === '[TEST] ');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/email-templates/nope')->assertNotFound();
    }

    public function test_managers_may_edit_clients_may_not_see(): void
    {
        $this->actingAs($this->makeUser(Role::MANAGER), 'sanctum')
            ->putJson('/api/email-templates/password_reset', ['subject' => 'Reset', 'body' => '<p>x</p>'])->assertOk();

        $this->actingAs($this->makeUser(Role::CLIENT), 'sanctum')->getJson('/api/email-templates')->assertForbidden();
        $this->actingAs($this->makeUser(Role::ANALYST), 'sanctum')
            ->putJson('/api/email-templates/password_reset', ['subject' => 'Reset', 'body' => '<p>x</p>'])->assertForbidden();
    }
}
