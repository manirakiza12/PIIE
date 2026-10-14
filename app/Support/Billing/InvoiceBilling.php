<?php

namespace App\Support\Billing;

use App\Models\Admission;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class InvoiceBilling
{
    public static function details(array $invoice): array
    {
        $student = User::where('school_id', $invoice['school_id'])->where('role_id', 7)->findOrFail($invoice['student_id']);
        $info = $student->user_information;
        for ($i = 0; $i < 2 && is_string($info); ++$i) $info = json_decode($info, true);
        $info = is_array($info) ? $info : (is_object($info) ? (array) $info : []);
        $address = trim((string) ($info['address'] ?? $student->getAttribute('address') ?? ''));
        $phone = trim((string) ($info['phone'] ?? $student->getAttribute('phone') ?? ''));
        if ($address === '') $address = trim((string) $student->getAttribute('address'));
        if ($phone === '') $phone = trim((string) $student->getAttribute('phone'));
        if (($address === '' || $phone === '') && Schema::hasTable('admissions')) {
            $application = Admission::where('school_id', $student->school_id)->where('email', $student->email)
                ->where('status', 'enrolled')->latest('id')->first();
            if ($application) {
                if ($address === '') $address = trim(implode(', ', array_filter([$application->physical_address, $application->city])));
                if ($phone === '') $phone = trim((string) $application->phone);
            }
        }
        return ['name' => $student->name, 'email' => $student->email, 'phone' => $phone,
            'address' => $address, 'registration_number' => $student->code];
    }
}
