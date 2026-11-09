<div class="eoff-form">
    <form method="POST" class="d-block" action="{{ route('admin.salary_structures.store') }}">
        @csrf
        <div class="form-row">
            <div class="fpb-7"><label class="eForm-label">{{ get_phrase('Staff Member') }} *</label>
                <select class="form-control eForm-control" name="user_id" required>
                    <option value="">{{ get_phrase('Select Staff') }}</option>
                    @foreach($staff as $s)
                        <option value="{{ $s->id }}" @selected((string) old('user_id') === (string) $s->id)>{{ $s->name }} ({{ $s->code }})</option>
                    @endforeach
                </select>
            </div>
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Basic Salary') }} *</label>
                    <input type="number" class="form-control eForm-control" name="basic" value="{{ old('basic') }}" min="0" step="0.01" required></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Housing Allowance') }}</label>
                    <input type="number" class="form-control eForm-control" name="housing" value="{{ old('housing', 0) }}" min="0" step="0.01"></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Transport Allowance') }}</label>
                    <input type="number" class="form-control eForm-control" name="transport" value="{{ old('transport', 0) }}" min="0" step="0.01"></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Medical Allowance') }}</label>
                    <input type="number" class="form-control eForm-control" name="medical" value="{{ old('medical', 0) }}" min="0" step="0.01"></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Other Earnings') }}</label>
                    <input type="number" class="form-control eForm-control" name="other_earnings" value="{{ old('other_earnings', 0) }}" min="0" step="0.01"></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Payment Method') }}</label>
                    <select class="form-control eForm-control" name="payment_method">
                        <option value="bank" @selected(old('payment_method', 'bank') === 'bank')>{{ get_phrase('Bank transfer') }}</option>
                        <option value="cash" @selected(old('payment_method') === 'cash')>{{ get_phrase('Cash') }}</option>
                        <option value="mobile_money" @selected(old('payment_method') === 'mobile_money')>{{ get_phrase('Mobile money') }}</option>
                    </select></div>
            </div>
            <div class="fpb-7 mt-2"><label class="eForm-label">{{ get_phrase('Bank / Mobile Money Account Number') }}</label>
                <input type="text" class="form-control eForm-control" name="bank_account" value="{{ old('bank_account') }}" maxlength="100"></div>
            <div class="fpb-7 mt-2"><label class="eForm-label">{{ get_phrase('Effective From') }}</label>
                <input type="date" class="form-control eForm-control" name="effective_from" value="{{ old('effective_from', date('Y-m-01')) }}" required></div>
            <div class="fpb-7 pt-3">
                <button class="btn-form" type="submit">{{ get_phrase('Save Salary Structure') }}</button>
            </div>
        </div>
    </form>
</div>
