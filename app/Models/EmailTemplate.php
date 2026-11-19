<?php

namespace App\Models;

use App\Mail\EmailTemplateType;
use App\Traits\Auditable;
use App\Traits\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's wording (subject/body) for a code-owned EmailTemplateType.
 * The type catalogue, its variables and default copy live in code; a
 * workspace without a row gets the defaults.
 */
class EmailTemplate extends Model
{
    use Auditable, BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'type', 'subject', 'body'];

    /** The stored content for a type in a workspace, if it has been edited or seeded. */
    public static function forType(EmailTemplateType $type, ?int $workspaceId): ?self
    {
        if ($workspaceId === null) {
            return null;
        }

        return static::inWorkspace($workspaceId)->where('type', $type->value)->first();
    }

    /** The code-owned type this row backs (null if the key is unknown). */
    public function templateType(): ?EmailTemplateType
    {
        return EmailTemplateType::tryFrom($this->type);
    }

    /**
     * Substitute {{variables}} into the subject and body. Values are escaped for
     * the HTML body so a variable (e.g. a user's name) can't inject markup; the
     * subject is plain text. Unknown placeholders are left untouched.
     *
     * @param  array<string, string>  $data
     * @return array{subject: string, body: string}
     */
    public function render(array $data): array
    {
        $subject = $this->subject;
        $body = $this->body;

        foreach ($data as $key => $value) {
            $placeholder = '{{'.$key.'}}';
            $subject = str_replace($placeholder, (string) $value, $subject);
            $body = str_replace($placeholder, e((string) $value), $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /** Names the entry in the History drawer. */
    protected function transformAttributesForAudit(array $attributes): array
    {
        return $attributes + ['_label' => EmailTemplateType::tryFrom((string) ($attributes['type'] ?? ''))?->label()];
    }
}
