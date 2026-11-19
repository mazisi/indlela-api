<?php

namespace Database\Seeders;

use App\Mail\EmailTemplateType;
use App\Models\EmailTemplate;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Gives every workspace one editable row per code-owned email type, with the
 * default wording. Optional: a workspace without rows gets the defaults
 * anyway. Non-destructive (firstOrCreate never overwrites edited content) and
 * quiet (no change history for rows nobody edited).
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::withoutEvents(function () {
            foreach (Workspace::all() as $workspace) {
                foreach (EmailTemplateType::cases() as $type) {
                    EmailTemplate::inWorkspace($workspace)->firstOrCreate(
                        ['workspace_id' => $workspace->id, 'type' => $type->value],
                        ['subject' => $type->defaultSubject(), 'body' => $type->defaultBody()],
                    );
                }
            }
        });
    }
}
