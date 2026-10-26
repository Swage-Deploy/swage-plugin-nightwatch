<?php

namespace Swage\Nightwatch;

use App\Actions\Servers\RunServerTask;
use App\Actions\Sites\UpdateSiteEnvironment;
use App\Enums\ResourceStatus;
use App\Exceptions\ScriptFailedException;
use App\Jobs\InstallDaemon;
use App\Jobs\RemoveDaemon;
use App\Models\Site;

class SetNightwatch
{
    /**
     * The port Nightwatch's agent listens on by default, handed to the first site on each server.
     */
    public const int FIRST_PORT = 2407;

    /**
     * Environment tokens, which hold no whitespace.
     */
    public const string TOKEN_PATTERN = '/^\S+$/D';

    public function __construct(protected UpdateSiteEnvironment $updateEnvironment, protected RunServerTask $runTask) {}

    /**
     * Point the site at Nightwatch and start its agent, or update the settings of a site that already reports.
     *
     * Every site gets an agent of its own on its own port, since the token decides which Nightwatch application the
     * agent reports to. The application must require laravel/nightwatch itself.
     *
     * @throws ScriptFailedException
     */
    public function enable(Site $site, string $token, ?float $requestSampleRate): NightwatchSite
    {
        $nightwatch = NightwatchSite::for($site) ?? new NightwatchSite([
            'site_id' => $site->id,
            'port' => $this->nextAvailablePort($site),
        ]);

        $this->updateEnvironment->handle($site, [
            'NIGHTWATCH_TOKEN' => $token,
            'NIGHTWATCH_INGEST_URI' => $nightwatch->ingestUri(),
            'NIGHTWATCH_REQUEST_SAMPLE_RATE' => $requestSampleRate === null ? null : (string) $requestSampleRate,
        ], "Configuring Nightwatch for {$site->domain}");

        $nightwatch->fill(['token' => $token, 'request_sample_rate' => $requestSampleRate])->save();

        // Updating the environment restarts an agent that is already running, so only a missing one is installed.
        if ($nightwatch->daemon === null) {
            $daemon = $site->server->daemons()->create([
                'site_id' => $site->id,
                'command' => self::agentCommand($site),
                'directory' => $site->currentPath(),
                'user' => $site->systemUser(),
                'processes' => 1,
                'stop_wait_seconds' => 15,
                'status' => ResourceStatus::Pending,
            ]);

            $nightwatch->update(['daemon_id' => $daemon->id]);

            InstallDaemon::dispatch($daemon);
        }

        if (! $nightwatch->restricted) {
            $this->restrict($nightwatch->setRelation('site', $site));
        }

        return $nightwatch;
    }

    /**
     * Let only the site's user, and root, connect to its agent. The agent listens on 127.0.0.1, where every other site
     * on the server could otherwise send it made-up events under the site's token.
     *
     * @throws ScriptFailedException
     */
    public function restrict(NightwatchSite $nightwatch): void
    {
        $site = $nightwatch->site;

        $this->runTask->run($site->server, "Restricting the Nightwatch agent of {$site->domain} to its site", view('nightwatch::scripts.restrict-agent', [
            'port' => $nightwatch->port,
            'user' => $site->systemUser(),
        ])->render(), timeout: 60);

        $nightwatch->update(['restricted' => true]);
    }

    /**
     * Remove Nightwatch's settings from the site's .env and stop its agent.
     *
     * @throws ScriptFailedException
     */
    public function disable(Site $site): void
    {
        $nightwatch = NightwatchSite::for($site);

        $this->updateEnvironment->handle($site, [
            'NIGHTWATCH_TOKEN' => null,
            'NIGHTWATCH_INGEST_URI' => null,
            'NIGHTWATCH_REQUEST_SAMPLE_RATE' => null,
        ], "Removing Nightwatch from {$site->domain}");

        if ($daemon = $nightwatch?->daemon) {
            $daemon->update(['status' => ResourceStatus::Removing]);

            RemoveDaemon::dispatch($daemon);
        }

        if ($nightwatch?->restricted) {
            $this->runTask->run($site->server, "Removing the firewall rule of the Nightwatch agent of {$site->domain}", view('nightwatch::scripts.restrict-agent', [
                'port' => $nightwatch->port,
                'user' => null,
            ])->render(), timeout: 60);
        }

        $nightwatch?->delete();
    }

    /**
     * The Supervisor command that runs the site's agent, which reads its token and port from the site's config.
     */
    public static function agentCommand(Site $site): string
    {
        return sprintf('%s %s/artisan nightwatch:agent', $site->php_version->binary(), $site->currentPath());
    }

    /**
     * The file that exists in the site's live release once laravel/nightwatch is installed.
     */
    public static function packageManifestPath(Site $site): string
    {
        return $site->currentPath().'/vendor/laravel/nightwatch/composer.json';
    }

    /**
     * The cache key for whether laravel/nightwatch is in the site's live release, which only a deployment can change.
     */
    public static function packageCacheKey(Site $site): string
    {
        return "nightwatch-installed:{$site->id}";
    }

    protected function nextAvailablePort(Site $site): int
    {
        $highest = NightwatchSite::query()->whereIn('site_id', $site->server->sites()->select('id'))->max('port');

        return $highest ? $highest + 1 : self::FIRST_PORT;
    }
}
