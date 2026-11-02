@php($creating = ! $company->exists)
<div class="row g-3">
    <div class="col-12"><h2 class="h6 fw-bold text-secondary mb-0">Basic details</h2></div>
    <div class="col-12 col-md-6">
        <label for="name" class="form-label">Company name <span class="text-danger">*</span></label>
        <input id="name" name="name" value="{{ old('name', $company->name) }}" class="form-control @error('name') is-invalid @enderror" required maxlength="255">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-6">
        <label for="legal_name" class="form-label">Legal name</label>
        <input id="legal_name" name="legal_name" value="{{ old('legal_name', $company->legal_name) }}" class="form-control" maxlength="255">
    </div>
    <div class="col-12 col-md-6">
        <label for="mailing_name" class="form-label">Mailing name <small class="text-secondary">(printed on documents)</small></label>
        <input id="mailing_name" name="mailing_name" value="{{ old('mailing_name', $company->mailing_name) }}" class="form-control" maxlength="255">
    </div>
    <div class="col-12 col-md-6">
        <label for="email" class="form-label">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email', $company->email) }}" class="form-control @error('email') is-invalid @enderror">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-6">
        <label for="phone" class="form-label">Phone</label>
        <input id="phone" type="tel" name="phone" value="{{ old('phone', $company->phone) }}" class="form-control" maxlength="20">
    </div>
    <div class="col-12 col-md-6">
        <label for="website" class="form-label">Website</label>
        <input id="website" type="url" name="website" value="{{ old('website', $company->website) }}" class="form-control @error('website') is-invalid @enderror" placeholder="https://">
        @error('website')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 mt-4"><h2 class="h6 fw-bold text-secondary mb-0">Address</h2></div>
    <div class="col-12">
        <label for="address" class="form-label">Address</label>
        <textarea id="address" name="address" rows="2" class="form-control">{{ old('address', $company->address) }}</textarea>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="city" class="form-label">City</label>
        <input id="city" name="city" value="{{ old('city', $company->city) }}" class="form-control">
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="state" class="form-label">State</label>
        <select id="state" name="state" class="form-select @error('state') is-invalid @enderror">
            <option value="">Select state</option>
            @foreach (config('india.states') as $code => $state)
                <option value="{{ $state }}" @selected(old('state', $company->state) === $state)>{{ $state }} ({{ str_pad((string) $code, 2, '0', STR_PAD_LEFT) }})</option>
            @endforeach
        </select>
        @error('state')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="pincode" class="form-label">PIN code</label>
        <input id="pincode" name="pincode" inputmode="numeric" value="{{ old('pincode', $company->pincode) }}" class="form-control @error('pincode') is-invalid @enderror" maxlength="6">
        @error('pincode')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="country" class="form-label">Country</label>
        <input id="country" name="country" value="{{ old('country', $company->country ?? 'India') }}" class="form-control">
    </div>

    <div class="col-12 mt-4"><h2 class="h6 fw-bold text-secondary mb-0">Statutory details</h2></div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="gstin" class="form-label">GSTIN</label>
        <input id="gstin" name="gstin" value="{{ old('gstin', $company->gstin) }}" class="form-control text-uppercase @error('gstin') is-invalid @enderror" maxlength="15">
        @error('gstin')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="pan" class="form-label">PAN</label>
        <input id="pan" name="pan" value="{{ old('pan', $company->pan) }}" class="form-control text-uppercase @error('pan') is-invalid @enderror" maxlength="10">
        @error('pan')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="tan" class="form-label">TAN</label>
        <input id="tan" name="tan" value="{{ old('tan', $company->tan) }}" class="form-control text-uppercase @error('tan') is-invalid @enderror" maxlength="10">
        @error('tan')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label for="cin" class="form-label">CIN</label>
        <input id="cin" name="cin" value="{{ old('cin', $company->cin) }}" class="form-control text-uppercase @error('cin') is-invalid @enderror" maxlength="21">
        @error('cin')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12 mt-4"><h2 class="h6 fw-bold text-secondary mb-0">Books</h2></div>
    @if ($creating)
        @php($thisYear = now()->month >= 4 ? now()->year : now()->year - 1)
        <div class="col-12 col-sm-6 col-lg-4">
            <label for="financial_year" class="form-label">Financial year <span class="text-danger">*</span></label>
            <select id="financial_year" name="financial_year" class="form-select @error('financial_year') is-invalid @enderror" required>
                @foreach (range($thisYear + 1, $thisYear - 5) as $y)
                    @php($label = $y.'-'.substr((string) ($y + 1), -2))
                    <option value="{{ $label }}" @selected(old('financial_year', $thisYear.'-'.substr((string) ($thisYear + 1), -2)) === $label)>{{ $label }} (1 Apr {{ $y }} – 31 Mar {{ $y + 1 }})</option>
                @endforeach
            </select>
            @error('financial_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <label for="books_begin_date" class="form-label">Books beginning from</label>
            <input id="books_begin_date" type="date" name="books_begin_date" value="{{ old('books_begin_date') }}" class="form-control @error('books_begin_date') is-invalid @enderror">
            <div class="form-text">Leave blank to start from the first day of the financial year.</div>
            @error('books_begin_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @else
        <div class="col-12 col-sm-6 col-lg-4">
            <label class="form-label">Books beginning from</label>
            <input class="form-control" value="{{ $company->books_begin_date?->format('d M Y') }}" disabled>
        </div>
    @endif
    <div class="col-12 col-sm-6 col-lg-4">
        <label for="currency" class="form-label">Base currency</label>
        <select id="currency" name="currency" class="form-select" required>
            <option value="INR">INR — Indian Rupee (₹)</option>
        </select>
    </div>
</div>
