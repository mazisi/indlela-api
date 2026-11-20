<?php

namespace App\Support;

use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\GoogleConnection;
use App\Models\MapGrid;
use App\Models\RankKeyword;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * What Settings › Change history shows from audit_logs (ported from
 * workforce): for each audited table, what its records are called, the page
 * they belong to (its permission resource: a user sees the changes on the
 * pages they may open), the fields worth showing with their labels, and
 * what can be undone. Fields not listed are bookkeeping and stay hidden.
 * A new Auditable model gets a line here.
 */
final class ChangeHistory
{
    private const ACTIVE = ['is_active' => 'Active'];

    /**
     * table => [model, record type, permission resource, [column => label], undo]
     * undo: null (nothing on this table can be undone: the reason says why), or
     * ['locked' => fields a change of which can't be undone, 'restore' => a
     * deleted record can be brought back].
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: string, 3: array<string, string>, 4: array{locked: list<string>, restore: bool}|string}>
     */
    public const TABLES = [
        'clients' => [Client::class, 'Client', 'clients_clients', [
            'name' => 'Name', 'contact_name' => 'Contact name', 'contact_email' => 'Contact email', 'notes' => 'Notes', ...self::ACTIVE,
        ], ['locked' => [], 'restore' => true]],
        'sites' => [Site::class, 'Site', 'clients_sites', [
            'name' => 'Name', 'url' => 'URL', 'domain' => 'Domain', 'client' => 'Client', ...self::ACTIVE,
            'gsc_property' => 'Search Console property', 'audit_schedule' => 'Audit schedule', 'audit_page_limit' => 'Pages per audit',
        ], ['locked' => ['client', 'client_id'], 'restore' => true]],
        'rank_keywords' => [RankKeyword::class, 'Keyword', 'rank_tracking', [
            'keyword' => 'Keyword', 'location_name' => 'Location', 'language_code' => 'Language', 'device' => 'Device',
            'frequency' => 'Frequency', 'weekly_day' => 'Weekly check day', 'tags' => 'Tags', ...self::ACTIVE,
        ], ['locked' => [], 'restore' => true]],
        'map_grids' => [MapGrid::class, 'Local grid', 'local_grid', [
            'keyword' => 'Keyword', 'business_title' => 'Business', 'business_address' => 'Business address',
            'business_place_id' => 'Google place id', 'business_cid' => 'Google CID',
            'center_lat' => 'Centre latitude', 'center_lng' => 'Centre longitude', 'size' => 'Grid size', 'spacing_km' => 'Spacing (km)',
            'device' => 'Device', 'schedule' => 'Schedule', ...self::ACTIVE,
        ], ['locked' => ['business_place_id', 'business_cid'], 'restore' => true]],
        'reports' => [Report::class, 'Report', 'reports', [
            'title' => 'Title', 'period_start' => 'From', 'period_end' => 'To', 'compare' => 'Compared with', 'sections' => 'Sections',
            'note' => 'Note', 'trigger' => 'Made by', 'sent_to' => 'Sent to', 'sent_at' => 'Sent', 'shared' => 'Shared', 'share_expires_at' => 'Link expires',
        ], 'Reports are changed from their own menu: share, revoke or send them again there.'],
        'report_schedules' => [ReportSchedule::class, 'Monthly report', 'reports', [
            'is_active' => 'On', 'day_of_month' => 'Day of month', 'send_hour' => 'Time', 'recipients' => 'Recipients',
            'sections' => 'Sections', 'compare' => 'Compared with', 'note' => 'Note',
        ], ['locked' => [], 'restore' => false]],
        'email_templates' => [EmailTemplate::class, 'Email template', 'settings_email_templates', [
            'type' => 'Email', 'subject' => 'Subject', 'body' => 'Message',
        ], ['locked' => ['type'], 'restore' => false]],
        'workspaces' => [Workspace::class, 'Workspace', 'settings_workspace', [
            'name' => 'Name', 'logo_path' => 'Logo', 'brand_color' => 'Brand colour', 'rank_keyword_limit' => 'Keyword limit',
        ], ['locked' => ['logo_path', 'slug', 'rank_keyword_limit'], 'restore' => false]],
        // Plus one field per page whose permissions changed (see fieldLabels()).
        'roles' => [Role::class, 'Role', 'settings_roles', [
            'name' => 'Role name', 'description' => 'Description', 'all_clients' => 'Sees every client',
        ], ['locked' => [], 'restore' => false]],
        'users' => [User::class, 'User', 'settings_users', [
            'full_name' => 'First name', 'surname' => 'Surname', 'email' => 'Email', 'mobile' => 'Mobile', 'status' => 'Status',
            'role' => 'Role', 'clients' => 'Clients',
        ], 'Change people\'s accounts on Users and access.'],
        'google_connections' => [GoogleConnection::class, 'Google account', 'search_console', [
            'google_email' => 'Google account', 'status' => 'Status', 'last_error' => 'Problem',
        ], 'Connect or disconnect Google on Search Console.'],
    ];

    public static function has(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    /** @return class-string<Model>|null */
    public static function model(string $table): ?string
    {
        return self::TABLES[$table][0] ?? null;
    }

    public static function recordType(string $table): ?string
    {
        return self::TABLES[$table][1] ?? null;
    }

    public static function resource(string $table): ?string
    {
        return self::TABLES[$table][2] ?? null;
    }

    /** @return array{locked: list<string>, restore: bool}|string the undo rules, or why nothing on the table can be undone */
    public static function undo(string $table): array|string
    {
        return self::TABLES[$table][4] ?? 'This change can\'t be undone.';
    }

    /** @return array<string, string> page resource => page name, for the pages that have audited tables */
    public static function pages(): array
    {
        $resources = Permissions::resources();

        return collect(self::TABLES)
            ->mapWithKeys(fn (array $t) => [$t[2] => $resources[$t[2]]['label']])
            ->sort()
            ->all();
    }

    /** @return list<string> the tables on these pages */
    public static function tablesOn(array $resources): array
    {
        return array_keys(array_filter(self::TABLES, fn (array $t) => in_array($t[2], $resources, true)));
    }

    /** @return list<string> resources of the pages this user may open */
    public static function pagesFor(User $user): array
    {
        return array_values(array_filter(array_keys(self::pages()), fn (string $r) => $user->can("view_{$r}")));
    }

    /** @return array<string, string> column => label for a table's changes */
    public static function fieldLabels(string $table): array
    {
        $labels = self::TABLES[$table][3] ?? [];
        if ($table === 'roles') {
            foreach (Permissions::resources() as $key => $resource) {
                $labels[$key] = Permissions::GROUPS[$resource['group']][0].' › '.$resource['label'];
            }
        }

        return $labels;
    }
}
