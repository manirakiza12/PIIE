<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A public website enquiry submitted through the Contact page form.
 *
 * NOT a CMS model. It deliberately shares no relationship with `WebsiteItem`,
 * `WebsiteSection`, `WebsitePage` or `WebsiteSetting`, because none of those may
 * ever contain visitor-supplied text — they are rendered on the public site, and a
 * stranger's message is not institutional content.
 *
 * Read ONLY by the Super Admin inbox, behind `auth` + `superAdmin`. There is no
 * public read route of any kind.
 *
 * @property int         $id
 * @property int|null    $school_id
 * @property string      $name
 * @property string      $email
 * @property string|null $phone
 * @property string      $subject
 * @property string      $message
 * @property string      $status
 * @property \Illuminate\Support\Carbon|null $handled_at
 */
class WebsiteEnquiry extends Model
{
    use HasFactory;

    protected $table = 'website_enquiries';

    protected $fillable = [
        'school_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'honeypot',
        'ip_address',
        'user_agent',
        'handled_by',
        'handled_at',
    ];

    /**
     * `message` and `user_agent` are cast to string.
     *
     * `user_agent` matters: an Eloquent model with a `text` column returns it as
     * `null` rather than `''` when the column is NULL, and `?->` on a string would
     * then be a type error. Casting keeps the inbox view's escaping predictable.
     */
    protected $casts = [
        'handled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Inbox status values the application itself understands. */
    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_SPAM = 'spam';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_READ,
        self::STATUS_ANSWERED,
        self::STATUS_SPAM,
    ];

    public function isUnread(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    /**
     * Whether this row should be treated as spam rather than an enquiry.
     *
     * The honeypot is the signal. It is a field a real person cannot see, so a
     * non-empty value means an automated client filled in every input it found.
     */
    public function looksLikeSpam(): bool
    {
        return trim((string) $this->honeypot) !== '';
    }
}
