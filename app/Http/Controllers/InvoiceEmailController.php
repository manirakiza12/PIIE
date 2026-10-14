<?php

namespace App\Http\Controllers;

use App\Models\StudentFeeManager;
use App\Support\Billing\InvoiceEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceEmailController extends Controller
{
    public function send(Request $request, int $id)
    {
        $invoice = StudentFeeManager::where('school_id', $request->user()->school_id)->findOrFail($id);
        $sent = InvoiceEmail::deliver(InvoiceEmail::record($invoice), true);
        return back()->with($sent ? 'message' : 'error', $sent
            ? 'Invoice emailed to the student.'
            : 'Invoice email was not sent. Check the student email and SMTP settings. Pending delivery will be retried automatically.');
    }

    public function preferences(Request $request)
    {
        $request->validate(['automatic' => 'required|boolean']);
        DB::table('invoice_email_preferences')->updateOrInsert(['school_id' => $request->user()->school_id], ['automatic' => $request->boolean('automatic')]);
        return back()->with('message', 'Invoice email preference saved. This applies to future invoices.');
    }
}
