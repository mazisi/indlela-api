<?php

namespace App\Mail;

use App\Mail\Concerns\RendersEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One Mailable for every EmailTemplateType (ported from workforce-api).
 * Subject/body come from the workspace's editable template (with the code
 * fallback); the call-to-action button stays in code so editing the copy can
 * never break the link. From: MAIL_FROM_*. Client-facing white-label mail
 * (ClientReportMail) extends this with the workspace as the sender.
 */
class TemplatedMail extends Mailable
{
    use Queueable, RendersEmailTemplate, SerializesModels;

    /** @var array{subject: string, body: string}|null */
    protected ?array $renderedCache = null;

    /**
     * @param  array<string, string>  $vars  values for $type->variables(); app_name is added
     * @param  int|null  $workspaceId  whose wording to use (null = the code defaults)
     */
    public function __construct(
        public EmailTemplateType $type,
        public array $vars,
        public ?string $url = null,
        public ?int $workspaceId = null,
        public string $subjectPrefix = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectPrefix.$this->rendered()['subject']);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.template',
            with: [
                'body' => $this->rendered()['body'],
                'url' => $this->url,
                'buttonText' => $this->type->buttonText(),
            ],
        );
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function rendered(): array
    {
        return $this->renderedCache ??= $this->renderTemplate(
            $this->type,
            ['app_name' => (string) config('app.name')] + $this->vars + ($this->url ? ['url' => $this->url] : []),
            $this->workspaceId,
        );
    }
}
