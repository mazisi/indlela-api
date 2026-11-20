<?php

namespace App\Mail;

use App\Models\Report;
use App\Services\Reports\ReportPdf;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A report emailed to a client: white-label. The wording is the workspace's
 * `client_report` template; the sender name is the workspace (the address
 * stays MAIL_FROM_ADDRESS), Reply-To the person who generated it (else the
 * workspace's first Owner), the PDF is attached and the button opens the
 * share page. Its own layout (emails/client-report) carries the workspace's
 * logo and colour and no Indlela branding: no app name, header or footer.
 *
 * {{summary}}, {{note}} and {{message}} are HTML built here (each line
 * escaped), filled in after the template's plain variables.
 */
class ClientReportMail extends TemplatedMail
{
    /**
     * @param  array{email: string, name: string}|null  $replyToAddress
     * @param  array{bytes: string, mime: string}|null  $logo
     */
    public function __construct(
        public Report $report,
        public string $pdf,
        public string $fileName,
        public string $shareUrl,
        public ?array $replyToAddress = null,
        public ?string $personalMessage = null,
        public ?array $logo = null,
    ) {
        $data = $report->data ?? [];
        parent::__construct(EmailTemplateType::ClientReport, [
            'workspace' => (string) ($data['branding']['name'] ?? ''),
            'client' => (string) ($data['client']['name'] ?? ''),
            'site' => (string) ($data['site']['url'] ?? ''),
            'month' => (string) ($data['period']['label'] ?? ''),
            'title' => (string) $report->title,
        ], $shareUrl, $report->workspace_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->senderName()),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress['email'], $this->replyToAddress['name'])] : [],
            subject: $this->rendered()['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-report',
            with: [
                'body' => $this->rendered()['body'],
                'url' => $this->url,
                'buttonText' => $this->type->buttonText(),
                'brand' => $this->senderName(),
                'color' => ReportPdf::safeColor($this->report->data['branding']['brand_color'] ?? null),
                'logo' => $this->logo,
                'clientName' => (string) ($this->report->data['client']['name'] ?? ''),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }

    /** @return array{subject: string, body: string} */
    public function rendered(): array
    {
        if ($this->renderedCache !== null) {
            return $this->renderedCache;
        }

        $rendered = $this->renderTemplate($this->type, $this->vars + ['url' => (string) $this->url], $this->workspaceId);
        $summary = $this->report->summary ?? [];
        $note = trim((string) $this->report->note);
        $message = trim((string) $this->personalMessage);

        $html = [
            '{{summary}}' => $summary === [] ? '' : '<ul>'.implode('', array_map(fn (string $l) => '<li>'.e($l).'</li>', $summary)).'</ul>',
            '{{note}}' => $note === '' ? '' : '<p>'.nl2br(e($note)).'</p>',
            '{{message}}' => $message === '' ? '' : '<p>'.nl2br(e($message)).'</p>',
        ];
        $text = [
            '{{summary}}' => implode('; ', $summary),
            '{{note}}' => $note,
            '{{message}}' => $message,
        ];

        return $this->renderedCache = [
            'subject' => trim(preg_replace('/\s+/', ' ', strtr($rendered['subject'], $text))),
            'body' => strtr($rendered['body'], $html),
        ];
    }

    private function senderName(): string
    {
        return (string) ($this->report->data['branding']['name'] ?? '');
    }
}
