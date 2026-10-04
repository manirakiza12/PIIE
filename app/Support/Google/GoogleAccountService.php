<?php

namespace App\Support\Google;

use App\Models\GoogleAccountConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A user's own Google connection: connect, disconnect, refresh, and report status.
 *
 * Owns the session, the database and the user model. The wire protocol lives in
 * {@see GoogleOAuthClient}. Keeping the two apart is what lets both halves be
 * tested without the other.
 */
class GoogleAccountService
{
    public function __construct(private readonly GoogleOAuthClient $client) {}

    // -- CSRF state ----------------------------------------------------------

    /**
     * A fresh, unpredictable CSRF value for one authorization attempt.
     *
     * 40 characters of application randomness. This value is the only thing
     * preventing an attacker from attaching their own Google account to someone
     * else's PIIE account, so it has to be unguessable by an outsider rather than
     * merely unique per attempt.
     */
    public function newState(): string
    {
        return Str::random(40);
    }

    // -- Connect -------------------------------------------------------------

    /**
     * Complete the flow: exchange the code, identify the account, store it.
     *
     * Idempotent by design. Google shows consent on every attempt (see `PROMPT`),
     * so a lecturer who reconnects re-authorises the same account and must NOT end
     * up with two rows. `updateOrCreate` on `user_id` is what makes a
     * double-submitted callback safe rather than a duplicate-connection bug.
     */
    public function connect(User $user, string $code, string $redirectUri): GoogleAccountConnection
    {
        $tokens = $this->client->exchangeCode($code, $redirectUri);
        $identity = $this->client->identify($tokens['access_token']);

        return DB::transaction(function () use ($user, $tokens, $identity) {
            /** @var GoogleAccountConnection $connection */
            $connection = GoogleAccountConnection::updateOrCreate(
                ['user_id' => (int) $user->id],
                [
                    'school_id' => $user->school_id,
                    'access_token_ciphertext' => $tokens['access_token'],
                    // A first grant always returns a refresh token. A RE-grant may
                    // not, and overwriting a good one with null would silently
                    // break a working connection.
                    'refresh_token_ciphertext' => $tokens['refresh_token'],
                    'access_token_expires_at' => $tokens['expires_in'] === null
                        ? null
                        : time() + $tokens['expires_in'],
                    'scope' => $tokens['scope'],
                    'token_type' => $tokens['token_type'],
                    'google_email' => $identity['email'],
                    'google_name' => $identity['name'],
                    'google_subject_id' => $identity['sub'],
                    'calendar_id' => 'primary',
                    'status' => GoogleAccountConnection::STATUS_OK,
                    'status_detail' => null,
                    'connected_at' => now(),
                    'last_refreshed_at' => now(),
                ]
            );

            // Only overwrite a stored refresh token when Google actually issued a
            // new one, which is the first-grant case and a rotation.
            if ($tokens['refresh_token'] === null && filled($connection->getOriginal('refresh_token_ciphertext'))) {
                $connection->refresh_token_ciphertext = $connection->getOriginal('refresh_token_ciphertext');
                $connection->save();
            }

            return $connection;
        });
    }

    // -- Disconnect ----------------------------------------------------------

    /**
     * Remove this user's own connection.
     *
     * The row is deleted rather than flagged. A disconnected account leaves
     * nothing worth keeping: the tokens are gone from PIIE the moment they are
     * deleted, and a lingering row would keep a lecturer looking "connected" on a
     * dashboard they believe they have disconnected from.
     *
     * Scoped to `$user` on the delete, so a crafted id cannot remove someone
     * else's connection.
     */
    public function disconnect(User $user): void
    {
        GoogleAccountConnection::query()
            ->where('user_id', (int) $user->id)
            ->delete();
    }

    // -- Read ----------------------------------------------------------------

    /**
     * The signed-in user's own connection, or null.
     *
     * WHY AN ABSENT TABLE IS TOLERATED
     * -------------------------------
     * Deploying code before running its migration is an ordinary way to release,
     * and the Live Classes index renders this on every lecturer and administrator
     * page load. Without the guard, an installation that has deployed this
     * feature but not yet migrated gets a 500 on its teaching pages: a broken
     * dashboard over a missing optional integration.
     *
     * Returning null is the correct answer in that state. The feature is simply
     * not installed yet, and "not connected" is what a lecturer should see.
     *
     * It is NOT silent: a warning is logged. An operator who finds the feature
     * missing from the UI has a way to find out why, rather than a page that
     * quietly lies about a connection that does not exist.
     *
     * The schema question is asked every time rather than memoised. An earlier
     * version cached "missing" in a static, which was wrong twice over: it leaked
     * between tests in one PHPUnit process, and in production it would persist
     * for the lifetime of a queue worker, so a worker that started before
     * `php artisan migrate` ran would report every lecturer as unconnected
     * forever. One `hasTable` query per page load is the cheaper mistake.
     */
    public function forUser(User $user): ?GoogleAccountConnection
    {
        if (! Schema::hasTable('google_account_connections')) {
            Log::warning('Google account connections are unavailable: the google_account_connections table does not exist. Run php artisan migrate.');

            return null;
        }

        return GoogleAccountConnection::query()
            ->where('user_id', (int) $user->id)
            ->first();
    }

    public function isConnected(User $user): bool
    {
        return $this->forUser($user)?->isUsable() ?? false;
    }

    // -- Tokens --------------------------------------------------------------

    /**
     * A usable access token for this user, refreshing if stale.
     *
     * Returns null when there is no usable connection. "Not configured" is a
     * normal state here, not an error, and the caller decides what to do about it
     * rather than being handed an exception it must catch merely to find out.
     *
     * A refused refresh marks the connection `needs_reauth` and returns null.
     * Persisting that is the point: the alternative is retrying a dead grant on
     * every scheduling attempt and reporting the same opaque failure to the
     * lecturer indefinitely.
     *
     * @return array{token:string, connection:GoogleAccountConnection}|null
     */
    public function accessTokenFor(User $user): ?array
    {
        $connection = $this->forUser($user);

        if (! $connection || ! $connection->isUsable()) {
            return null;
        }

        $refreshToken = $connection->refresh_token_ciphertext;

        // Refresh-token rotation. If the stored value is unreadable (app key
        // changed, row restored from a different environment), treat it as gone
        // rather than letting an undecryptable value travel to Google.
        if (! is_string($refreshToken) || $refreshToken === '') {
            $this->markNeedsReauth($connection, 'The stored Google credential could not be read.');

            return null;
        }

        try {
            $fresh = $this->client->freshAccessToken(
                (string) $connection->access_token_ciphertext,
                $refreshToken,
                $connection->access_token_expires_at,
            );
        } catch (RuntimeException $e) {
            $this->markNeedsReauth($connection, $e->getMessage());

            return null;
        }

        // Persist only what actually changed. A refresh that returned the same
        // values should not dirty the row on every read.
        $attributes = [];

        if (($fresh['access_token'] ?? null) !== null && $fresh['access_token'] !== $connection->access_token_ciphertext) {
            $attributes['access_token_ciphertext'] = $fresh['access_token'];
            $attributes['access_token_expires_at'] = $fresh['expires_in'] === null
                ? null
                : time() + $fresh['expires_in'];
            $attributes['last_refreshed_at'] = now();
        }

        if (($fresh['refresh_token'] ?? null) !== null && $fresh['refresh_token'] !== $refreshToken) {
            $attributes['refresh_token_ciphertext'] = $fresh['refresh_token'];
        }

        if ($attributes !== []) {
            $connection->forceFill($attributes)->save();
        }

        return ['token' => $fresh['access_token'], 'connection' => $connection->fresh()];
    }

    /**
     * Which calendar to write events into.
     */
    public function calendarIdFor(GoogleAccountConnection $connection): string
    {
        return filled($connection->calendar_id) ? $connection->calendar_id : 'primary';
    }

    private function markNeedsReauth(GoogleAccountConnection $connection, string $detail): void
    {
        $connection->forceFill([
            'status' => GoogleAccountConnection::STATUS_NEEDS_REAUTH,
            // Truncated: this message is shown to the lecturer, and an unbounded
            // provider response in a text column is a poor place to put it.
            'status_detail' => Str::limit($detail, 500),
        ])->save();
    }
}