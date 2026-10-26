<?php

namespace Swage\Nightwatch\Livewire;

use App\Exceptions\ScriptFailedException;
use App\Models\Site;
use App\Ssh\RemoteShell;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Swage\Nightwatch\NightwatchSite;
use Swage\Nightwatch\SetNightwatch;
use Throwable;

/**
 * The Nightwatch panel on a site's settings page.
 */
class SiteSettings extends Component
{
    public Site $site;

    /**
     * A new token. The saved one never leaves the server, so a blank field keeps it.
     */
    public string $token = '';

    /**
     * The share of requests to record, from 0 to 1. Blank records every request.
     */
    public string $request_sample_rate = '';

    public ?string $error = null;

    /**
     * Whether laravel/nightwatch is in the site's live release; null until checked, or when the server could not be
     * reached.
     */
    #[Locked]
    public ?bool $installed = null;

    #[Locked]
    public bool $checked = false;

    public function mount(): void
    {
        $this->authorize('view', $this->site);

        $this->request_sample_rate = (string) (NightwatchSite::for($this->site)->request_sample_rate ?? '');
    }

    public function render(): View
    {
        return view('nightwatch::site-settings', ['nightwatch' => NightwatchSite::for($this->site)?->load('daemon')]);
    }

    /**
     * Check the site's live release for the laravel/nightwatch package.
     *
     * The answer is remembered until the site is next deployed, so opening the settings does not reach the server
     * every time. Only a definite answer is remembered, never a server that could not be reached.
     */
    public function check(RemoteShell $shell): void
    {
        $this->installed = Cache::get(SetNightwatch::packageCacheKey($this->site));

        if ($this->installed === null) {
            try {
                $result = $shell->run($this->site->server, 'test -f '.escapeshellarg(SetNightwatch::packageManifestPath($this->site)).' && echo installed || echo missing', timeout: 20);

                $this->installed = $result->successful() ? trim($result->output) === 'installed' : null;
            } catch (Throwable) {
                $this->installed = null;
            }

            if ($this->installed !== null) {
                Cache::put(SetNightwatch::packageCacheKey($this->site), $this->installed, now()->addDay());
            }
        }

        $this->checked = true;
    }

    /**
     * Check the site for laravel/nightwatch again, ignoring the remembered answer.
     */
    public function recheck(RemoteShell $shell): void
    {
        Cache::forget(SetNightwatch::packageCacheKey($this->site));

        $this->check($shell);
    }

    /**
     * Write the token into the site's .env and start its agent.
     */
    public function save(SetNightwatch $setNightwatch): void
    {
        $this->authorize('update', $this->site);

        if ($this->installed !== true) {
            $this->error = __('Install laravel/nightwatch in the application and deploy it before adding Nightwatch.');

            return;
        }

        $savedToken = NightwatchSite::for($this->site)?->token;

        $this->token = trim($this->token);
        $this->request_sample_rate = trim($this->request_sample_rate);

        $validated = $this->validate([
            'token' => [$savedToken ? 'nullable' : 'required', 'string', 'max:255', 'regex:'.SetNightwatch::TOKEN_PATTERN],
            'request_sample_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ], [
            'token.regex' => __('The token cannot contain spaces.'),
        ]);

        $this->error = null;

        try {
            $setNightwatch->enable(
                $this->site,
                $validated['token'] ?: $savedToken,
                filled($validated['request_sample_rate']) ? (float) $validated['request_sample_rate'] : null,
            );
        } catch (ScriptFailedException $exception) {
            $this->error = trim($exception->task->output ?? '');

            return;
        }

        $this->token = '';

        Flux::toast(variant: 'success', text: __('Nightwatch is configured. Its agent is starting.'));
    }

    /**
     * Let only the site's user reach an agent started before agents were restricted.
     */
    public function restrict(SetNightwatch $setNightwatch): void
    {
        $this->authorize('update', $this->site);

        $nightwatch = NightwatchSite::for($this->site) ?? abort(404);

        $this->error = null;

        try {
            $setNightwatch->restrict($nightwatch->setRelation('site', $this->site));
        } catch (ScriptFailedException $exception) {
            $this->error = trim($exception->task->output ?? '');

            return;
        }

        Flux::toast(variant: 'success', text: __('Only this site can reach its Nightwatch agent now.'));
    }

    /**
     * Remove Nightwatch's settings from the .env and stop the agent.
     */
    public function remove(SetNightwatch $setNightwatch): void
    {
        $this->authorize('update', $this->site);

        $this->error = null;

        try {
            $setNightwatch->disable($this->site);
        } catch (ScriptFailedException $exception) {
            $this->error = trim($exception->task->output ?? '');

            return;
        }

        $this->token = '';
        $this->request_sample_rate = '';

        Flux::toast(variant: 'success', text: __('Nightwatch removed.'));
    }
}
