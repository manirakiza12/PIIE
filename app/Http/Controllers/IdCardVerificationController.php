<?php

namespace App\Http\Controllers;

use App\Models\Programme;
use App\Models\School;
use App\Models\StudentProfile;
use App\Models\User;

/**
 * The public landing page a scanned ID-card QR code opens. Deliberately
 * unauthenticated (a security guard or visitor scanning a physical card has
 * no PIIE login) but only reachable via a Laravel signed URL — the `signed`
 * middleware on this route rejects any request whose signature doesn't
 * match or has expired before this method ever runs, so the only way in is
 * a URL this app itself generated (see IdCard::verifyUrl()).
 *
 * Shows the minimum needed to confirm "this is a real, currently active
 * student ID" — name, photo, class/programme, school, status. Deliberately
 * does NOT show phone or other personal detail the card itself carries:
 * those are fine printed on physical plastic a student
 * carries, but not fine served to anyone who scans a code, unauthenticated,
 * from anywhere.
 */
class IdCardVerificationController extends Controller
{
    public function show($studentId)
    {
        $student = User::where('id', $studentId)->where('role_id', 7)->first();

        if (!$student) {
            abort(404);
        }

        $school = School::find($student->school_id);
        $studentDetails = (new CommonController)->get_student_details_by_id($student->id);
        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $programme = $studentProfile?->programme_id ? Programme::find($studentProfile->programme_id) : null;
        $isActive = $student->account_status !== 'disable';

        return view('id_card.verify', compact('student', 'school', 'studentDetails', 'programme', 'isActive'));
    }
}
