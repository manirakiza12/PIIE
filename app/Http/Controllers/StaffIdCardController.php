<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Staff\StaffIdCard;
use Barryvdh\DomPDF\Facade\Pdf;

class StaffIdCardController extends Controller
{
    public function show()
    {
        $staff = auth()->user();
        abort_unless(StaffIdCard::isActive($staff), 403);

        return view('staff.id_card', StaffIdCard::data($staff));
    }

    public function pdf()
    {
        $staff = auth()->user();
        abort_unless(StaffIdCard::isActive($staff), 403);
        $number = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($staff->code ?: $staff->id));

        return Pdf::loadView('staff.id_card_pdf', StaffIdCard::data($staff, true))
            ->setPaper('a4')->download('Staff_ID_Card_'.$number.'.pdf');
    }

    public function verify($staff)
    {
        $member = User::findOrFail($staff);
        abort_unless(StaffIdCard::isStaff($member), 404);

        return view('id_card.verify', StaffIdCard::data($member) + ['isActive' => StaffIdCard::isActive($member)]);
    }
}
