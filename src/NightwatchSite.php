<?php

namespace Swage\Nightwatch;

use App\Models\Daemon;
use App\Models\Site;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site that reports to Nightwatch through an agent of its own.
 *
 * @property int $site_id
 * @property string $token
 * @property int $port
 * @property float|null $request_sample_rate
 * @property int|null $daemon_id
 */
#[Fillable(['site_id', 'token', 'port', 'request_sample_rate', 'daemon_id'])]
#[Hidden(['token'])]
class NightwatchSite extends Model
{
    protected $table = 'nightwatch_sites';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'port' => 'integer',
            'request_sample_rate' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The Supervisor program running the site's agent.
     *
     * @return BelongsTo<Daemon, $this>
     */
    public function daemon(): BelongsTo
    {
        return $this->belongsTo(Daemon::class);
    }

    public static function for(Site $site): ?self
    {
        return static::query()->firstWhere('site_id', $site->id);
    }

    /**
     * The local address the site's agent listens on and the application sends to.
     */
    public function ingestUri(): string
    {
        return "127.0.0.1:{$this->port}";
    }
}
