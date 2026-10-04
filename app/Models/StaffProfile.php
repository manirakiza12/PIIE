<?php

namespace App\Models;

use App\Models\Concerns\StaffRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional (0..1) professional profile of a staff user. Holds only what users
 * cannot: personal extras, the NIN (encrypted + keyed hash), the emergency
 * contact and the teaching profile. Historical staff simply have none.
 *
 * The NIN is written and read ONLY through App\Support\Staff\StaffNin; both NIN
 * columns are hidden from every array/JSON serialization.
 */
class StaffProfile extends Model
{
    use StaffRecord;

    protected $fillable = [
        'user_id', 'school_id',
        'title', 'middle_name', 'nationality', 'marital_status', 'religion',
        'alternative_phone', 'city', 'country', 'date_joined',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
        'emergency_contact_alternative_phone', 'emergency_contact_email', 'emergency_contact_address',
        'academic_title', 'specialisation', 'years_teaching_experience', 'research_interests',
        'created_by', 'updated_by',
    ];

    protected $hidden = ['nin_encrypted', 'nin_hash'];

    protected $casts = [
        'date_joined' => 'date',
        'years_teaching_experience' => 'integer',
    ];

    public function hasNin(): bool
    {
        return !empty($this->nin_hash);
    }
}
