<p align="center">
    <img src="https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/public/favicon.svg" width="72" height="72" alt="Swage">
</p>

<h1 align="center">Nightwatch for Swage</h1>

<p align="center">
    Report your sites to Laravel Nightwatch, with an agent Swage runs for each site.
    <br>
    <a href="https://swagedeploy.app"><strong>swagedeploy.app</strong></a> · <a href="https://swagedeploy.app/docs/plugins">Documentation</a>
</p>

---

Connects sites to [Laravel Nightwatch](https://nightwatch.laravel.com). Each site's **Plugins** tab gets a **Nightwatch** panel where you enter the environment's token. Swage writes it into the site's `.env` as `NIGHTWATCH_TOKEN`, gives the site a Nightwatch agent of its own and keeps it running under Supervisor.

## Requirements

- Swage `v1.0.0` or later
- The `laravel/nightwatch` package in each application you connect

## Installation

1. **Install the plugin** from the **Available** list on Swage's **Plugins** page, where it is listed as Nightwatch. Swage installs the newest version, rebuilds itself with it and shows its progress.

   From the Swage host instead:

   ```bash
   sudo -u swage php /home/swage/app/current/artisan swage:plugin:install swage/nightwatch
   ```

2. **Require the package in the application** and deploy the site:

   ```bash
   composer require laravel/nightwatch
   ```

   The panel checks the site's live release for `laravel/nightwatch` and stays disabled until it is there. The answer is remembered until the site is next deployed; **Check again** asks the server straight away.

3. **Connect the site** on its **Plugins** tab: paste the token from the environment's settings in Nightwatch, and optionally a request sample rate, such as `0.1` to record one request in ten.

## How it runs

The application sends what it records to an agent on the same server, which forwards it to Nightwatch. Since the token decides which Nightwatch application an agent reports to, every site gets an agent of its own:

- Swage picks a local port for the site, starting at Nightwatch's default of `2407` and counting up for each site on the same server, and sets it as `NIGHTWATCH_INGEST_URI`.
- The agent runs as a daemon, `php artisan nightwatch:agent` as the site's user, so it shows on the server's daemons alongside queue workers. It reads its token and port from the site's config.
- Deployments restart it with the other daemons, so it always runs the live release. Changing the settings rebuilds the config cache and restarts it too.

If the agent's daemon is deleted, the panel says so; saving starts a new one. **Remove** deletes the variables from the `.env` and stops the agent.

Tokens are stored encrypted, never sent back to the browser, and redacted from task output. Leave the token blank when saving to keep the one already saved.

## Deployments

Every deployment of a connected site is tagged with its commit, and marked in Nightwatch once it is live:

- Before the release is built, the deploy script exports `NIGHTWATCH_DEPLOY` as the commit hash, so the config cache the release builds tags everything it records with it. The release that is still live keeps its own until the new one takes over. Applications that do not cache their config, which the default deploy script does with `php artisan optimize`, are not tagged.
- Once the release is live, `php artisan nightwatch:deploy` sends the deployment, named after the commit's message, to Nightwatch. If that fails, the deployment still succeeds with a warning in its output.

These steps are added where the deploy script uses the `$CREATE_RELEASE()` and `$ACTIVATE_RELEASE()` macros.

## Members

A site's managers, including people added to just that site, can change its settings here; its viewers can only look.

## Updating

Swage's **Plugins** page shows when a newer version is available. Update from there, or on the Swage host:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:plugin:update swage/nightwatch
```

## Development

The plugin implements Swage's site integration contract (`App\Sites\SiteIntegration` and `HooksIntoDeployments`), version 1, so it runs inside a Swage install rather than on its own.

`tests/NightwatchPluginTest.php` is a Pest test that runs inside a Swage checkout: it uses Swage's models, factories and test helpers. To run it:

1. Add this plugin's `src` directory to the Swage checkout's `autoload-dev` as `Swage\\Nightwatch\\`.
2. Run `composer dump-autoload`.
3. Copy the test into Swage's `tests/Feature`.
4. Run `php artisan test --filter=NightwatchPluginTest`.

## License

Copyright (c) 2026 Node Dog Consulting. Released under the [MIT License](LICENSE).
