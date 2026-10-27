<?php

/*
 * Runs inside a Swage checkout: it uses Swage's models, factories and test helpers. See the README's Development
 * section.
 */

use App\Deployments\DeployScript;
use App\Enums\PhpVersion;
use App\Enums\ResourceStatus;
use App\Jobs\DeploySite;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Models\Daemon;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\User;
use App\Ssh\RemoteShell;
use App\Ssh\ScriptResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Swage\Nightwatch\NightwatchServiceProvider;
use Swage\Nightwatch\NightwatchSite;

beforeEach(function () {
    $this->app->register(NightwatchServiceProvider::class, force: true);
    $this->artisan('migrate')->assertSuccessful();

    Queue::fake([InstallDaemon::class, RemoveDaemon::class]);
});

function nightwatchSettings(Site $site)
{
    return Livewire::actingAs($site->server->user)->test('nightwatch.site-settings', ['site' => $site]);
}

/**
 * Open the site's Nightwatch settings with laravel/nightwatch found in its live release.
 */
function nightwatchSettingsWithPackage(Site $site)
{
    fakeShell()->respondTo('vendor/laravel/nightwatch', new ScriptResult(0, "installed\n"));

    return nightwatchSettings($site)->call('check')->assertSet('installed', true);
}

test('adding nightwatch writes its token and agent address into the environment and starts an agent', function () {
    $site = Site::factory()->systemUser('example')->create(['domain' => 'example.com', 'php_version' => PhpVersion::Php85]);

    $settings = nightwatchSettingsWithPackage($site);
    $shell = app(RemoteShell::class);

    $settings
        ->set('token', 'nw-Secret_Token/123')
        ->set('request_sample_rate', '0.25')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('token', '');

    $nightwatch = NightwatchSite::for($site);

    expect($nightwatch)
        ->token->toBe('nw-Secret_Token/123')
        ->port->toBe(2407)
        ->request_sample_rate->toBe(0.25)
        ->and($nightwatch->daemon)
        ->command->toBe('php8.5 /home/example/example.com/current/artisan nightwatch:agent')
        ->user->toBe('example')
        ->site_id->toBe($site->id);

    expect(collect($shell->ran)->pluck('script')->first(fn (string $script): bool => str_contains($script, 'NIGHTWATCH_TOKEN')))
        ->toContain(base64_encode('NIGHTWATCH_TOKEN=nw-Secret_Token/123'))
        ->toContain(base64_encode('NIGHTWATCH_INGEST_URI=127.0.0.1:2407'))
        ->toContain(base64_encode('NIGHTWATCH_REQUEST_SAMPLE_RATE=0.25'))
        ->not->toContain('nw-Secret_Token/123');

    Queue::assertPushed(InstallDaemon::class, fn (InstallDaemon $job) => $job->daemon->is($nightwatch->daemon));
});

test('each site on a server gets an agent on a port of its own', function () {
    $site = Site::factory()->create();
    $neighbour = Site::factory()->for($site->server)->create();
    $elsewhere = Site::factory()->create();

    foreach ([$site, $neighbour, $elsewhere] as $each) {
        nightwatchSettingsWithPackage($each)->set('token', 'token')->call('save')->assertHasNoErrors();
    }

    expect(NightwatchSite::for($site)->port)->toBe(2407)
        ->and(NightwatchSite::for($neighbour)->port)->toBe(2408)
        ->and(NightwatchSite::for($elsewhere)->port)->toBe(2407);
});

test('saving again keeps the token, port and agent, which updating the environment restarts', function () {
    $site = Site::factory()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'saved-token')->call('save');
    $daemon = NightwatchSite::for($site)->daemon;
    $daemon->update(['status' => ResourceStatus::Installed]);

    nightwatchSettingsWithPackage($site)
        ->assertSet('token', '')
        ->set('request_sample_rate', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(NightwatchSite::for($site))
        ->token->toBe('saved-token')
        ->port->toBe(2407)
        ->request_sample_rate->toBeNull()
        ->daemon_id->toBe($daemon->id)
        ->and(app(RemoteShell::class)->lastScript())
        ->toContain("supervisorctl restart '{$daemon->programName()}:*'")
        ->toContain('swage_env_unset NIGHTWATCH_REQUEST_SAMPLE_RATE');

    Queue::assertPushed(InstallDaemon::class, 1);
});

test('the agent is started again when its daemon was deleted', function () {
    $site = Site::factory()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'saved-token')->call('save');
    NightwatchSite::for($site)->daemon->delete();

    nightwatchSettingsWithPackage($site)
        ->assertSee('Agent missing')
        ->call('save')
        ->assertHasNoErrors();

    expect(NightwatchSite::for($site)->daemon)->not->toBeNull();
    Queue::assertPushed(InstallDaemon::class, 2);
});

test('nightwatch settings are validated', function (string $token, string $rate, string $error) {
    $site = Site::factory()->create();

    nightwatchSettingsWithPackage($site)
        ->set('token', $token)
        ->set('request_sample_rate', $rate)
        ->call('save')
        ->assertHasErrors([$error]);

    expect(NightwatchSite::for($site))->toBeNull()
        ->and(Daemon::count())->toBe(0);
})->with([
    'missing token' => ['', '', 'token'],
    'space in the token' => ['tok en', '', 'token'],
    'rate above one' => ['token', '1.5', 'request_sample_rate'],
    'rate that is not a number' => ['token', 'half', 'request_sample_rate'],
]);

test('removing nightwatch deletes its variables and stops the agent', function () {
    $site = Site::factory()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'saved-token')->call('save');
    $daemon = NightwatchSite::for($site)->daemon;

    nightwatchSettings($site)->call('remove')->assertHasNoErrors();

    expect(NightwatchSite::for($site))->toBeNull()
        ->and($daemon->fresh()->status)->toBe(ResourceStatus::Removing)
        ->and(collect(app(RemoteShell::class)->ran)->pluck('script')->last(fn (string $script): bool => str_contains($script, 'swage_env_unset NIGHTWATCH')))
        ->toContain('swage_env_unset NIGHTWATCH_TOKEN')
        ->toContain('swage_env_unset NIGHTWATCH_INGEST_URI');

    Queue::assertPushed(RemoveDaemon::class, fn (RemoveDaemon $job) => $job->daemon->is($daemon));
});

test('nothing is saved or started when the environment cannot be updated', function () {
    $site = Site::factory()->create();
    $settings = nightwatchSettingsWithPackage($site);
    fakeShell()->respondTo('NIGHTWATCH', new ScriptResult(1, '.env does not exist. Install the site first.'));

    $settings->set('token', 'token')->call('save')->assertSet('error', '.env does not exist. Install the site first.');

    expect(NightwatchSite::for($site))->toBeNull()
        ->and(Daemon::count())->toBe(0);
});

test('nightwatch cannot be added until the package is installed', function () {
    $shell = fakeShell()->respondTo('vendor/laravel/nightwatch', new ScriptResult(0, "missing\n"));
    $site = Site::factory()->systemUser('example')->create(['domain' => 'example.com']);

    nightwatchSettings($site)
        ->call('check')
        ->assertSet('installed', false)
        ->assertSee('composer require laravel/nightwatch')
        ->set('token', 'token')
        ->call('save')
        ->assertSet('error', 'Install laravel/nightwatch in the application and deploy it before adding Nightwatch.');

    expect(NightwatchSite::for($site))->toBeNull()
        ->and($shell->lastScript())->toContain("test -f '/home/example/example.com/current/vendor/laravel/nightwatch/composer.json'");
});

test('the package check is remembered until the site is deployed again', function () {
    $site = Site::factory()->withRepository()->create();
    $shell = fakeShell()->respondTo('vendor/laravel/nightwatch', new ScriptResult(0, "missing\n"));

    nightwatchSettings($site)->call('check')->assertSet('installed', false);
    nightwatchSettings($site)->call('check')->assertSet('installed', false);

    expect(collect($shell->ran)->filter(fn (array $run) => str_contains($run['script'], 'vendor/laravel/nightwatch')))->toHaveCount(1);

    DeploySite::dispatchSync(Deployment::factory()->for($site)->create());
    $shell->respondTo('vendor/laravel/nightwatch', new ScriptResult(0, "installed\n"));

    nightwatchSettings($site)->call('check')->assertSet('installed', true);
});

test('an unreachable server is not remembered as missing the package', function () {
    $site = Site::factory()->create();
    $shell = fakeShell()->respondTo('vendor/laravel/nightwatch', new ScriptResult(255, 'Connection refused'));

    nightwatchSettings($site)->call('check')->assertSet('installed', null)->assertSee('Unable to check for laravel/nightwatch');
    $shell->respondTo('vendor/laravel/nightwatch', new ScriptResult(0, "installed\n"));

    nightwatchSettings($site)->call('recheck')->assertSet('installed', true);
});

test('the panel is on the site\'s plugins tab and never sends the saved token to the browser', function () {
    $site = Site::factory()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'super-secret-token')->call('save');
    NightwatchSite::for($site)->daemon->update(['status' => ResourceStatus::Installed]);

    $this->actingAs($site->server->user)
        ->get(route('servers.sites.plugins', [$site->server, $site]))
        ->assertOk()
        ->assertSee('Nightwatch')
        ->assertSee('Reporting')
        ->assertDontSee('super-secret-token');
});

test('saved tokens are redacted from task output', function () {
    $site = Site::factory()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'super-secret-token')->call('save');

    expect($site->server->redactSecrets('token=super-secret-token'))->toBe('token=********');
});

test('other users cannot see or change a site\'s nightwatch settings', function () {
    $site = Site::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('nightwatch.site-settings', ['site' => $site])
        ->assertForbidden();
});

test('the settings are deleted with the site', function () {
    $site = Site::factory()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'token')->call('save');

    $site->delete();

    expect(NightwatchSite::count())->toBe(0);
});

test('deployments are tagged with their commit and marked in nightwatch', function () {
    $site = Site::factory()->withRepository()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'token')->call('save');

    $script = DeployScript::render($site);

    expect($script)
        ->toContain('export NIGHTWATCH_DEPLOY="$SWAGE_COMMIT_HASH"')
        ->toContain('artisan" nightwatch:deploy "$SWAGE_COMMIT_HASH" --ref="$SWAGE_COMMIT_HASH"')
        ->and(strpos($script, 'export NIGHTWATCH_DEPLOY'))->toBeLessThan(strpos($script, 'php artisan optimize'))
        ->and(strpos($script, 'nightwatch:deploy'))->toBeGreaterThan(strpos($script, 'Activating release'));
});

test('deployments of sites without nightwatch are left alone', function () {
    $site = Site::factory()->withRepository()->create();

    expect(DeployScript::render($site))->not->toContain('NIGHTWATCH');
});

test('the deploy steps are valid bash', function () {
    if (! Process::run('command -v bash')->successful()) {
        $this->markTestSkipped('bash is not available to check script syntax.');
    }

    $site = Site::factory()->withRepository()->create();
    nightwatchSettingsWithPackage($site)->set('token', 'token')->call('save');

    $result = Process::input(DeployScript::render($site))->run('bash -n');

    expect($result->successful())->toBeTrue($result->errorOutput());
});

test('a site\'s own manager configures nightwatch, and its viewers cannot', function () {
    $site = Site::factory()->create();
    $manager = User::factory()->create();
    $viewer = User::factory()->create();
    $site->members()->attach($manager->id, ['role' => 'manager']);
    $site->members()->attach($viewer->id, ['role' => 'viewer']);
    fakeShell()->respondTo('vendor/laravel/nightwatch', new ScriptResult(0, "installed\n"));

    Livewire::actingAs($viewer)->test('nightwatch.site-settings', ['site' => $site])
        ->call('check')->set('token', 'token')->call('save');
    expect(NightwatchSite::for($site))->toBeNull();

    Livewire::actingAs($manager)->test('nightwatch.site-settings', ['site' => $site])
        ->call('check')->set('token', 'token')->call('save')->assertHasNoErrors();
    expect(NightwatchSite::for($site))->not->toBeNull();
});

test('a new agent only accepts connections from its own site\'s user and root', function () {
    $site = Site::factory()->systemUser('example')->create();

    nightwatchSettingsWithPackage($site)->set('token', 'token')->call('save')->assertHasNoErrors();

    $shell = app(RemoteShell::class);

    expect(NightwatchSite::for($site)->restricted)->toBeTrue()
        ->and($shell->lastScript())
        ->toContain("echo 'example' > /etc/swage/nightwatch-agents/2407")
        ->toContain('-m owner --uid-owner "$user" -j RETURN')
        ->toContain('-j REJECT --reject-with tcp-reset')
        ->toContain('systemctl enable --quiet swage-nightwatch-firewall')
        ->toContain('/usr/local/sbin/swage-nightwatch-firewall');

    nightwatchSettings($site)->set('token', 'other')->call('save');

    expect(collect($shell->ran)->filter(fn (array $run): bool => str_contains($run['script'], 'nightwatch-agents/'))->count())->toBe(1);
});

test('removing nightwatch removes its agent\'s firewall rule', function () {
    $site = Site::factory()->systemUser('example')->create();
    nightwatchSettingsWithPackage($site)->set('token', 'token')->call('save');

    nightwatchSettings($site)->call('remove')->assertHasNoErrors();

    app(RemoteShell::class)->assertRan('rm -f /etc/swage/nightwatch-agents/2407');
});

test('the agent restriction script is valid bash', function () {
    if (! Process::run('command -v bash')->successful()) {
        $this->markTestSkipped('bash is not available to check script syntax.');
    }

    foreach (['example', null] as $user) {
        expect(Process::input(view('nightwatch::scripts.restrict-agent', ['port' => 2407, 'user' => $user])->render())->run('bash -n')->successful())->toBeTrue();
    }
});
