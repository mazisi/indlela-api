<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Support\Permissions;
use PHPUnit\Framework\TestCase;

class PermissionsTest extends TestCase
{
    public function test_names_are_action_then_page(): void
    {
        $this->assertContains('update_clients_sites', Permissions::all());
        $this->assertContains('view_dashboard', Permissions::all());
        $this->assertContains('approve_reports', Permissions::all());
        $this->assertNotContains('create_dashboard', Permissions::all(), 'the dashboard is view only');
        $this->assertSame('clients_sites', Permissions::resourceOf('update_clients_sites'));
        $this->assertSame('search_console', Permissions::resourceOf('view_search_console'));
    }

    public function test_the_catalogue_has_the_agreed_resources(): void
    {
        $this->assertSame(
            ['dashboard', 'clients_clients', 'clients_sites', 'search_console', 'site_audit', 'rank_tracking', 'reports',
                'settings_workspace', 'settings_users', 'settings_roles', 'settings_login_history', 'settings_email_templates', 'platform_usage'],
            array_keys(Permissions::resources()),
        );
    }

    public function test_owner_only_pages_are_not_grantable(): void
    {
        $this->assertContains('view_settings_users', Permissions::all());
        $this->assertNotContains('view_settings_users', Permissions::grantable());
        $this->assertNotContains('update_settings_roles', Permissions::grantable());
        $this->assertNotContains('update_settings_workspace', Permissions::grantable());
        $this->assertContains('update_settings_email_templates', Permissions::grantable());
    }

    public function test_platform_pages_are_neither_grantable_nor_owner_only(): void
    {
        $this->assertContains('view_platform_usage', Permissions::all());
        $this->assertSame(['view_platform_usage'], Permissions::platformOnly());
        $this->assertNotContains('view_platform_usage', Permissions::grantable());
        $this->assertNotContains('view_platform_usage', Permissions::adminOnly());
        $this->assertNotContains('view_platform_usage', Permissions::forGroupLevels(['platform' => 'approve']));
    }

    public function test_every_action_brings_view_with_it(): void
    {
        $this->assertEqualsCanonicalizing(
            ['update_clients_sites', 'view_clients_sites', 'view_reports'],
            Permissions::withView(['update_clients_sites', 'view_reports']),
        );
    }

    public function test_levels_expand_to_page_actions(): void
    {
        $names = Permissions::forGroupLevels(['clients' => 'edit', 'reports' => 'view', 'settings' => 'approve']);

        $this->assertContains('delete_clients_sites', $names);
        $this->assertContains('view_reports', $names);
        $this->assertNotContains('update_reports', $names);
        $this->assertContains('update_settings_email_templates', $names);
        $this->assertNotContains('view_settings_users', $names, 'never through levels');
        $this->assertNotContains('view_site_audit', $names);
    }

    public function test_site_addresses_are_normalised(): void
    {
        $this->assertSame(['url' => 'https://www.example.co.za/blog', 'domain' => 'example.co.za'], Site::normalise(' WWW.Example.co.za/blog/?utm=x#top '));
        $this->assertSame(['url' => 'http://shop.example.com:8080', 'domain' => 'shop.example.com'], Site::normalise('http://Shop.Example.com:8080/'));
        $this->assertNull(Site::normalise('not a site'));
        $this->assertNull(Site::normalise('ftp://example.com'));
        $this->assertNull(Site::normalise('localhost'));
    }
}
