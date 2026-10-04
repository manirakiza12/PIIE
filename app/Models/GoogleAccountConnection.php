<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's own Google account connection.
 *
 * @property int $id
 * @property int|null $school_id
 * @property int $user_id
 * @property string|null $access_token_ciphertext
 * @property string|null $refresh_token_ciphertext
 * @property int|null $access_token_expires_at
 * @property string|null $scope
 * @property string|null $token_type
 * @property string|null $google_email
 * @property string|null $google_name
 * @property string|null $google_subject_id
 * @property string $calendar_id
 * @property string $status
 * @property string|null $status_detail
 * @property \Illuminate\Support\Carbon|null $connected_at
 * @property \Illuminate\Support\Carbon|null $last_refreshed_at
 */
class GoogleAccountConnection extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';

    /** Google said the grant is gone. Only a fresh consent can fix it. */
    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'google_account_connections';

    /**
     * `encrypted` on both tokens, and `encrypted:array` on the timestamps would
     * be wrong for them.
     *
     * Why encrypted rather than hashed: hashing is one-way, and the refresh token
     * must be sent BACK to Google to mint an access token. A hash cannot be
     * replayed. So this is confidentiality, not integrity, and it relies on
     * `APP_KEY` — the same key that already protects session cookies.
     *
     * Why `null` is allowed on access_token but not required on refresh_token:
     * a revoked connection has neither, and the row is kept so the lecturer can
     * be told "reconnect" rather than silently appearing never to have connected.
     */
    protected $casts = [
        'access_token_ciphertext' => 'encrypted',
        'refresh_token_ciphertext' => 'encrypted',
        'access_token_expires_at' => 'integer',
        'connected_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
    ];

    protected $fillable = [
        'school_id',
        'user_id',
        'access_token_ciphertext',
        'refresh_token_ciphertext',
        'access_token_expires_at',
        'scope',
        'token_type',
        'google_email',
        'google_name',
        'google_subject_id',
        'calendar_id',
        'status',
        'status_detail',
        'connected_at',
        'last_refreshed_at',
    ];

    /**
     * Secrets never belong in a log line, an exception dump or a `toArray()`
     * that gets serialised into something readable. Model-level `$hidden` is the
     * last line of defence for the common leak — `dd()`, a debug bar, a resource
     * or an exception's model context — rather than relying on every call site to
     * remember to pick fields.
     */
    protected $hidden = [
        'access_token_ciphertext',
        'refresh_token_ciphertext',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_OK
            && filled($this->refresh_token_ciphertext);
    }

    public function needsReauthorization(): bool
    {
        return in_array($this->status, [self::STATUS_NEEDS_REAUTH, self::STATUS_REVOKED], true);
    }
}