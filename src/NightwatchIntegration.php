<?php

namespace Swage\Nightwatch;

use App\Models\Server;
use App\Models\Site;
use App\Sites\HooksIntoDeployments;
use App\Sites\SiteIntegration;
use Illuminate\Support\Facades\Cache;

class NightwatchIntegration implements HooksIntoDeployments, SiteIntegration
{
    public function id(): string
    {
        return 'nightwatch';
    }

    public function settingsComponent(): string
    {
        return 'nightwatch.site-settings';
    }

    /**
     * The environment tokens of the server's sites.
     *
     * @return list<string|null>
     */
    public function secrets(Server $server): array
    {
        return NightwatchSite::query()->whereIn('site_id', $server->sites()->select('id'))->get()->pluck('token')->all();
    }

    /**
     * A deployment can add or remove packages, so the site is checked for laravel/nightwatch again.
     */
    public function deployed(Site $site): void
    {
        Cache::forget(SetNightwatch::packageCacheKey($site));
    }

    /**
     * Tag what the new release records with its commit. Exported rather than written to the .env, so the release that is
     * still live keeps its own until the new one takes over, and the config cache the release builds picks it up.
     */
    public function afterCreatingRelease(Site $site): string
    {
        if (NightwatchSite::for($site) === null) {
            return '';
        }

        return 'export NIGHTWATCH_DEPLOY="$SWAGE_COMMIT_HASH"';
    }

    /**
     * Mark the deployment in Nightwatch once the release is live. A failure never fails the deployment.
     */
    public function afterActivatingRelease(Site $site): string
    {
        if (NightwatchSite::for($site) === null) {
            return '';
        }

        return <<<'BASH'
            echo "Sending the deployment to Nightwatch"
            php "$SWAGE_RELEASE_DIRECTORY/artisan" nightwatch:deploy "$SWAGE_COMMIT_HASH" --ref="$SWAGE_COMMIT_HASH" --name="$(git -C "$SWAGE_RELEASE_DIRECTORY" log -1 --format=%s)" \
                || echo "Warning: the deployment could not be sent to Nightwatch."
            BASH;
    }
}
