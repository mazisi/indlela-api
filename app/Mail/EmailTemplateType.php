<?php

namespace App\Mail;

/**
 * The code-owned catalogue of transactional email types (ported from
 * workforce-api, trimmed to Indlela's). This enum IS the wiring a Mailable
 * binds to: its cases, variables and default copy are fixed in code. The
 * editable content (subject/body) lives in email_templates, per workspace,
 * keyed by value(); workspaces edit content, never the type.
 *
 * To add a templated email: add a case here (with its variables + defaults)
 * and send it with TemplatedMail.
 */
enum EmailTemplateType: string
{
    case PasswordReset = 'password_reset';
    case LoginPin = 'login_pin';
    case UserInvited = 'user_invited';
    case AddedToWorkspace = 'added_to_workspace';
    case WorkspaceCreated = 'workspace_created';
    case ClientReport = 'client_report';

    public function label(): string
    {
        return match ($this) {
            self::PasswordReset => 'Password reset',
            self::LoginPin => 'Sign-in one-time PIN',
            self::UserInvited => 'Invited (new account)',
            self::AddedToWorkspace => 'Added to the workspace (existing account)',
            self::WorkspaceCreated => 'Welcome after sign-up',
            self::ClientReport => 'Client report (white-label)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PasswordReset => 'Sent when a user asks for a password reset from the sign-in page, or an Owner sends one.',
            self::LoginPin => 'Sent after a correct password to users whose one-time PIN goes by email.',
            self::UserInvited => 'Sent when someone without an account is added to the workspace, with a link to choose their password.',
            self::AddedToWorkspace => 'Sent when someone who already has an account is added to the workspace.',
            self::WorkspaceCreated => 'Sent to the new Owner when they sign up and create a workspace.',
            self::ClientReport => 'Sends an SEO report to a client, from your workspace\'s name, with the PDF attached and a button to the online report. Monthly when a schedule is set.',
        };
    }

    /**
     * The variables the code injects at send time: key => human description.
     * These are the ONLY placeholders that resolve; anything else is left as-is.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::PasswordReset => [
                'name' => "The user's first name",
                'app_name' => 'The application name',
                'ttl' => 'How long the link stays valid (e.g. 60 minutes)',
                'url' => 'The reset link (also rendered as the button)',
            ],
            self::LoginPin => [
                'name' => "The user's first name",
                'app_name' => 'The application name',
                'code' => 'The 6-digit PIN',
                'ttl' => 'How long the PIN stays valid (e.g. 5 minutes)',
            ],
            self::UserInvited => [
                'name' => "The user's first name",
                'added_by' => 'Who added them',
                'workspace' => 'The workspace (e.g. Acme Digital)',
                'role' => 'The role they were given',
                'clients' => 'The clients they can work on',
                'ttl' => 'How long the link stays valid (e.g. 72 hours)',
                'app_name' => 'The application name',
                'url' => 'The set-password link (also rendered as the button)',
            ],
            self::AddedToWorkspace => [
                'name' => "The user's first name",
                'added_by' => 'Who added them',
                'workspace' => 'The workspace (e.g. Acme Digital)',
                'role' => 'The role they were given',
                'clients' => 'The clients they can work on',
                'app_name' => 'The application name',
                'url' => 'The sign-in link (also rendered as the button)',
            ],
            self::WorkspaceCreated => [
                'name' => "The user's first name",
                'workspace' => 'The workspace they created',
                'app_name' => 'The application name',
                'url' => 'The sign-in link (also rendered as the button)',
            ],
            // White-label: no app_name; the client sees only the workspace.
            self::ClientReport => [
                'workspace' => 'Your workspace (e.g. Acme Digital)',
                'client' => 'The client (e.g. Karoo Coffee)',
                'site' => 'The website (e.g. karoocoffee.co.za)',
                'month' => 'The report period (e.g. October 2026)',
                'title' => 'The report title',
                'summary' => 'The report summary as bullet points (e.g. Search traffic up 14% (5,190 clicks))',
                'note' => 'The note on the report, if any',
                'message' => 'The personal message typed when sending, if any',
                'url' => 'The online report link (also rendered as the button)',
            ],
        };
    }

    /** Label of the fixed call-to-action button, or null for no button. */
    public function buttonText(): ?string
    {
        return match ($this) {
            self::PasswordReset => 'Choose a new password',
            self::UserInvited => 'Choose your password',
            self::AddedToWorkspace, self::WorkspaceCreated => 'Sign in',
            self::ClientReport => 'View the report online',
            self::LoginPin => null,
        };
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::PasswordReset => 'Reset your {{app_name}} password',
            self::LoginPin => 'Your {{app_name}} sign-in PIN: {{code}}',
            self::UserInvited => 'You have been given {{app_name}} access to {{workspace}}',
            self::AddedToWorkspace => 'You have been added to {{workspace}} on {{app_name}}',
            self::WorkspaceCreated => 'Welcome to {{app_name}}: {{workspace}} is ready',
            self::ClientReport => 'Your {{month}} SEO report from {{workspace}}',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::PasswordReset => <<<'HTML'
                <p>Hi {{name}},</p>
                <p>We received a request to reset your password. Click the button below to choose a new one.</p>
                <p>The link works once and expires in {{ttl}}. If you did not ask for this, you can ignore this email — your password has not changed.</p>
                HTML,
            self::LoginPin => <<<'HTML'
                <p>Hi {{name}},</p>
                <p>Your one-time PIN to sign in to {{app_name}} is:</p>
                <p style="font-size:28px;font-weight:700;letter-spacing:8px;">{{code}}</p>
                <p>It expires in {{ttl}}. If you did not try to sign in, change your password straight away.</p>
                HTML,
            self::UserInvited => <<<'HTML'
                <p>Hi {{name}},</p>
                <p>{{added_by}} has given you {{app_name}} access to <strong>{{workspace}}</strong> as <strong>{{role}}</strong> ({{clients}}).</p>
                <p>Click the button below to choose your password. The link works once and expires in {{ttl}}; after that, use "Forgot password" on the sign-in page.</p>
                HTML,
            self::AddedToWorkspace => <<<'HTML'
                <p>Hi {{name}},</p>
                <p>{{added_by}} has added you to <strong>{{workspace}}</strong> on {{app_name}} as <strong>{{role}}</strong> ({{clients}}).</p>
                <p>Sign in with your existing password and choose {{workspace}} in the workspace switcher.</p>
                HTML,
            self::WorkspaceCreated => <<<'HTML'
                <p>Hi {{name}},</p>
                <p>Your workspace <strong>{{workspace}}</strong> is ready, and you are its Owner.</p>
                <p>Next: add your clients and their sites, then invite your team from Settings › Users.</p>
                HTML,
            self::ClientReport => <<<'HTML'
                <p>Hello,</p>
                {{message}}
                <p>Here is the {{month}} SEO report for <strong>{{client}}</strong> ({{site}}). In short:</p>
                {{summary}}
                {{note}}
                <p>Open the full report online with the button below. The PDF is attached.</p>
                <p>Kind regards,<br>{{workspace}}</p>
                HTML,
        };
    }

    /** Who it goes to, for the list. */
    public function directRecipient(): string
    {
        return match ($this) {
            self::WorkspaceCreated => 'The new Owner',
            self::ClientReport => 'The report recipients',
            default => 'The user',
        };
    }

    /**
     * Whether a log may keep the message. A sign-in code or a reset or
     * invitation link is a key: never stored or resent.
     */
    public function storesBody(): bool
    {
        return ! in_array($this, [self::PasswordReset, self::LoginPin, self::UserInvited], true);
    }
}
