<?php

namespace App\Mail\Concerns;

use App\Mail\EmailTemplateType;
use App\Models\EmailTemplate;

/**
 * Renders a Mailable's subject/body from the workspace's editable
 * EmailTemplate, falling back to the code-owned defaults when it has none,
 * so a missing template can never break outgoing mail.
 */
trait RendersEmailTemplate
{
    /**
     * @param  array<string, string>  $vars
     * @return array{subject: string, body: string}
     */
    protected function renderTemplate(EmailTemplateType $type, array $vars, ?int $workspaceId): array
    {
        $template = EmailTemplate::forType($type, $workspaceId) ?? new EmailTemplate([
            'type' => $type->value,
            'subject' => $type->defaultSubject(),
            'body' => $type->defaultBody(),
        ]);

        return $template->render($vars);
    }
}
