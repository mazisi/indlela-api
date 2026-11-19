<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateEmailTemplateRequest;
use App\Mail\EmailTemplateType;
use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Settings › Email templates (`*_settings_email_templates`), ported from
 * workforce-api's Email notifications without its notification routing:
 * every email the system sends, with the wording this workspace uses. The
 * type catalogue is code-owned (App\Mail\EmailTemplateType): no create or
 * destroy. Saving stores the workspace's own copy; reset restores the default.
 */
class EmailTemplateController extends Controller
{
    /** Sample values for the preview and test email, by placeholder. */
    private const PREVIEW_SAMPLES = [
        'name' => 'Nomsa',
        'app_name' => 'Indlela',
        'ttl' => '60 minutes',
        'url' => 'https://app.indlela.co.za/…',
        'code' => '482913',
        'added_by' => 'Sipho Dlamini',
        'workspace' => 'Acme Digital',
        'role' => 'Analyst',
        'clients' => 'Karoo Coffee, Durban Dental',
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('view_settings_email_templates');
        $stored = EmailTemplate::all()->keyBy('type');

        return response()->json([
            'data' => array_map(fn (EmailTemplateType $type) => $this->present($type, $stored->get($type->value)), EmailTemplateType::cases()),
            'mail' => ['from_name' => config('mail.from.name'), 'from_address' => config('mail.from.address')],
            'can' => ['update' => $request->user()->can('update_settings_email_templates')],
        ]);
    }

    public function show(string $type): JsonResponse
    {
        Gate::authorize('view_settings_email_templates');
        $enum = $this->resolveType($type);

        return response()->json(['data' => $this->present($enum, EmailTemplate::forType($enum, $this->current->id()))]);
    }

    public function update(UpdateEmailTemplateRequest $request, string $type): JsonResponse
    {
        $enum = $this->resolveType($type);
        $data = $request->validated();

        $template = EmailTemplate::updateOrCreate(['type' => $enum->value], [
            'subject' => $data['subject'],
            // Sanitize the rich-text body before it can ever reach an inbox.
            'body' => clean($data['body']),
        ]);

        return response()->json(['message' => 'Email template updated.', 'data' => $this->present($enum, $template)]);
    }

    /** The (unsaved, when sent) subject and body rendered with sample data. */
    public function preview(Request $request, string $type): JsonResponse
    {
        Gate::authorize('view_settings_email_templates');
        $enum = $this->resolveType($type);
        $draft = $request->validate(['subject' => ['nullable', 'string', 'max:500'], 'body' => ['nullable', 'string', 'max:20000']]);
        $stored = EmailTemplate::forType($enum, $this->current->id());
        $template = new EmailTemplate([
            'type' => $enum->value,
            'subject' => $draft['subject'] ?? $stored?->subject ?? $enum->defaultSubject(),
            'body' => isset($draft['body']) ? clean($draft['body']) : ($stored?->body ?? $enum->defaultBody()),
        ]);

        return response()->json(['data' => $template->render(self::samples($enum))]);
    }

    /** A test email with sample data to the signed-in user. */
    public function test(Request $request, string $type): JsonResponse
    {
        Gate::authorize('update_settings_email_templates');
        $enum = $this->resolveType($type);
        $user = $request->user();
        $url = $enum->buttonText() ? rtrim((string) config('app.frontend_url'), '/').'/dashboard' : null;

        try {
            Mail::to($user->email, $user->full_name)->send(new TemplatedMail($enum, self::samples($enum), $url, $this->current->id(), '[TEST] '));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The test email could not be sent. Check the mail server settings.'], 422);
        }

        return response()->json(['message' => "Test email sent to {$user->email}."]);
    }

    /** Discard the workspace's wording and restore the default. */
    public function reset(string $type): JsonResponse
    {
        Gate::authorize('update_settings_email_templates');
        $enum = $this->resolveType($type);
        $template = EmailTemplate::updateOrCreate(['type' => $enum->value], [
            'subject' => $enum->defaultSubject(), 'body' => $enum->defaultBody(),
        ]);

        return response()->json(['message' => 'Email template reset to default.', 'data' => $this->present($enum, $template)]);
    }

    /** @return array<string, string> */
    public static function samples(EmailTemplateType $type): array
    {
        $out = [];
        foreach ($type->variables() as $key => $description) {
            $out[$key] = self::PREVIEW_SAMPLES[$key]
                ?? (preg_match('/\(e\.g\.\s*(.+)\)\s*$/', $description, $m) ? $m[1] : '['.$key.']');
        }

        return $out;
    }

    private function resolveType(string $type): EmailTemplateType
    {
        return EmailTemplateType::tryFrom($type) ?? abort(404, 'Unknown email template.');
    }

    /** @return array<string, mixed> */
    private function present(EmailTemplateType $type, ?EmailTemplate $template): array
    {
        return [
            'type' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            // A list, not a map: the app's camelCase transform would rename the keys.
            'variables' => collect($type->variables())->map(fn (string $d, string $k) => ['key' => $k, 'description' => $d])->values(),
            'button_text' => $type->buttonText(),
            'subject' => $template?->subject ?? $type->defaultSubject(),
            'body' => $template?->body ?? $type->defaultBody(),
            'stores_body' => $type->storesBody(),
            'direct_recipient' => $type->directRecipient(),
            'is_customised' => $template !== null && ($template->subject !== $type->defaultSubject() || $template->body !== $type->defaultBody()),
            'updated_at' => $template?->updated_at,
        ];
    }
}
