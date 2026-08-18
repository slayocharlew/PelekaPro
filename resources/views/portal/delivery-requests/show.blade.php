@extends('layouts.portal')

@section('title', 'Customer request #'.$deliveryRequest->id)

@section('content')
    @php
        $status = $deliveryRequest->effectiveStatus();
        $reviewItems = old('items', $deliveryRequest->items->map(fn ($item) => [
            'item_name' => $item->item_name,
            'quantity' => $item->quantity,
            'amount' => 0,
            'description' => $item->description,
        ])->values()->all());
        $customerResolution = old('customer_resolution', $matchingCustomers->isEmpty() ? 'new' : '');
        $selectedBranchId = old(
            'branch_id',
            auth('web')->user()->belongsToBusiness($deliveryRequest->business_id)
                ? auth('web')->user()->branch_id
                : null
        );
    @endphp

    <div class="portal-page-heading">
        <div>
            <a class="portal-back-link" href="{{ route('portal.delivery-requests.index') }}">← Back to requests</a>
            <div class="portal-heading-line">
                <h1>Customer request #{{ $deliveryRequest->id }}</h1>
                @include('portal.partials.status-badge', ['status' => $status])
            </div>
            <p>{{ $deliveryRequest->business->name }} · Created {{ $deliveryRequest->created_at?->format('d M Y, H:i') }}</p>
        </div>
    </div>

    @if ($requestUrl)
        <section class="portal-card portal-tracking-link">
            <div class="portal-card__header">
                <div>
                    <h2>Customer form link</h2>
                    <p>Copy this link now. Only its hash is stored, so the exact link cannot be displayed again.</p>
                </div>
            </div>
            <div class="portal-copy-field">
                <input id="delivery-request-link" type="text" value="{{ $requestUrl }}" readonly aria-label="Customer delivery request link">
                <button class="portal-button portal-button--secondary portal-button--small" type="button" data-copy-target="delivery-request-link">Copy</button>
            </div>
            <button
                class="portal-button portal-button--primary"
                type="button"
                data-share-url="{{ $requestUrl }}"
                data-share-title="Complete your PelekaPro delivery request"
            >Share link</button>
            <p class="portal-copy-status" data-copy-status role="status" aria-live="polite"></p>
        </section>
    @endif

    @if ($status === 'pending' || $status === 'expired')
        <section class="portal-card portal-form-section">
            <div class="portal-card__header">
                <div>
                    <h2>{{ $status === 'expired' ? 'Request link expired' : 'Waiting for customer' }}</h2>
                    <p>
                        @if ($status === 'expired')
                            Generate a replacement link if the customer still needs to provide the details.
                        @else
                            This link expires <time datetime="{{ $deliveryRequest->expires_at->toISOString() }}">{{ $deliveryRequest->expires_at->format('d M Y, H:i') }}</time>.
                        @endif
                    </p>
                </div>
            </div>
            <div class="portal-form-actions">
                <form method="POST" action="{{ route('portal.delivery-requests.regenerate', $deliveryRequest) }}" data-confirm="Generate a new link? The previous link will stop working." data-submitting-form>
                    @csrf
                    <button class="portal-button portal-button--primary" type="submit" data-submit-label="Generating…">{{ $requestUrl ? 'Replace link' : 'Generate new link' }}</button>
                </form>
                <form method="POST" action="{{ route('portal.delivery-requests.revoke', $deliveryRequest) }}" data-confirm="Revoke this customer request?" data-submitting-form>
                    @csrf
                    <button class="portal-button portal-button--danger" type="submit" data-submit-label="Revoking…">Revoke request</button>
                </form>
            </div>
        </section>
    @elseif ($status === 'submitted')
        @if ($errors->any())
            <div class="portal-alert portal-alert--error" role="alert">
                <strong>Please correct the highlighted fields.</strong>
            </div>
        @endif

        <form class="portal-form portal-form--delivery" method="POST" action="{{ route('portal.delivery-requests.convert', $deliveryRequest) }}" data-delivery-form data-submitting-form>
            @csrf

            <section class="portal-card portal-form-section">
                <div class="portal-card__header">
                    <div>
                        <h2>Review customer</h2>
                        <p>The submitted request remains unchanged; corrections below apply to the official delivery.</p>
                    </div>
                </div>
                <div class="portal-form-grid">
                    @if ($matchingCustomers->isNotEmpty())
                        <div class="portal-field portal-field--wide">
                            <label for="customer_resolution">Existing customer match <span aria-hidden="true">*</span></label>
                            <select id="customer_resolution" name="customer_resolution" required data-customer-resolution>
                                <option value="">Choose how to continue</option>
                                <option value="existing" @selected($customerResolution === 'existing')>Reuse an existing customer</option>
                                <option value="new" @selected($customerResolution === 'new')>Create a separate customer</option>
                            </select>
                            @error('customer_resolution') <p class="portal-field__error">{{ $message }}</p> @enderror
                        </div>
                        <div class="portal-field portal-field--wide" data-existing-customer-field>
                            <label for="customer_id">Matching customer</label>
                            <select id="customer_id" name="customer_id">
                                <option value="">Select matching customer</option>
                                @foreach ($matchingCustomers as $customer)
                                    <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }} · {{ $customer->phone }} @if ($customer->email) · {{ $customer->email }} @endif</option>
                                @endforeach
                            </select>
                            @error('customer_id') <p class="portal-field__error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <input type="hidden" name="customer_resolution" value="new">
                    @endif

                    <div class="portal-field">
                        <label for="customer_name">Customer name <span aria-hidden="true">*</span></label>
                        <input id="customer_name" name="customer_name" type="text" maxlength="255" value="{{ old('customer_name', $deliveryRequest->customer_name) }}" required>
                        @error('customer_name') <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="portal-field">
                        <label for="customer_phone">Customer phone <span aria-hidden="true">*</span></label>
                        <input id="customer_phone" name="customer_phone" type="tel" maxlength="30" value="{{ old('customer_phone', $deliveryRequest->customer_phone) }}" required>
                        @error('customer_phone') <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="portal-field">
                        <label for="customer_email">Customer email (optional)</label>
                        <input id="customer_email" name="customer_email" type="email" maxlength="255" value="{{ old('customer_email', $deliveryRequest->customer_email) }}">
                        @error('customer_email') <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="portal-field">
                        <label for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" data-branch-pickup-select>
                            <option value="">No branch</option>
                            @foreach ($branches as $branch)
                                <option
                                    value="{{ $branch->id }}"
                                    data-pickup-name="{{ $branch->name }}"
                                    data-pickup-phone="{{ $branch->phone ?? $branch->business?->phone }}"
                                    data-pickup-address="{{ $branch->pickupAddress() }}"
                                    data-pickup-latitude="{{ $branch->latitude }}"
                                    data-pickup-longitude="{{ $branch->longitude }}"
                                    @selected((string) $selectedBranchId === (string) $branch->id)
                                >{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id') <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section class="portal-card portal-form-section">
                <div class="portal-card__header"><div><h2>Pickup and destination</h2></div></div>
                <div class="portal-form-columns">
                    <fieldset class="portal-fieldset">
                        <legend>Pickup</legend>
                        <div class="portal-field"><label for="pickup_name">Contact name</label><input id="pickup_name" name="pickup_name" type="text" maxlength="255" value="{{ old('pickup_name') }}" data-pickup-name-field>@error('pickup_name') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                        <div class="portal-field"><label for="pickup_phone">Contact phone</label><input id="pickup_phone" name="pickup_phone" type="tel" maxlength="255" value="{{ old('pickup_phone') }}" data-pickup-phone-field>@error('pickup_phone') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                        <div class="portal-field"><label for="pickup_address">Address</label><textarea id="pickup_address" name="pickup_address" rows="3" data-pickup-address-field>{{ old('pickup_address') }}</textarea>@error('pickup_address') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                        <input id="pickup_latitude" name="pickup_latitude" type="hidden" value="{{ old('pickup_latitude') }}" data-pickup-latitude-field>
                        <input id="pickup_longitude" name="pickup_longitude" type="hidden" value="{{ old('pickup_longitude') }}" data-pickup-longitude-field>
                        <p class="portal-field__hint" data-branch-pickup-status role="status" aria-live="polite">Select a branch to load its saved pickup location.</p>
                        @error('pickup_latitude') <p class="portal-field__error">{{ $message }}</p> @enderror
                        @error('pickup_longitude') <p class="portal-field__error">{{ $message }}</p> @enderror
                    </fieldset>
                    <fieldset class="portal-fieldset">
                        <legend>Customer destination</legend>
                        <div class="portal-field"><label for="dropoff_address">Address <span aria-hidden="true">*</span></label><textarea id="dropoff_address" name="dropoff_address" rows="3" maxlength="255" required>{{ old('dropoff_address', $deliveryRequest->dropoff_address) }}</textarea>@error('dropoff_address') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                        <div class="portal-coordinate-grid">
                            <div class="portal-field"><label for="dropoff_latitude">Latitude <span aria-hidden="true">*</span></label><input id="dropoff_latitude" name="dropoff_latitude" type="number" step="0.0000001" min="-90" max="90" value="{{ old('dropoff_latitude', $deliveryRequest->dropoff_latitude) }}" required>@error('dropoff_latitude') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                            <div class="portal-field"><label for="dropoff_longitude">Longitude <span aria-hidden="true">*</span></label><input id="dropoff_longitude" name="dropoff_longitude" type="number" step="0.0000001" min="-180" max="180" value="{{ old('dropoff_longitude', $deliveryRequest->dropoff_longitude) }}" required>@error('dropoff_longitude') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                        </div>
                    </fieldset>
                </div>
            </section>

            <section class="portal-card portal-form-section">
                <div class="portal-card__header">
                    <div><h2>Delivery items and prices</h2></div>
                    <button class="portal-button portal-button--secondary portal-button--small" type="button" data-add-delivery-item>Add item</button>
                </div>
                <div class="portal-items" data-delivery-items>
                    @foreach ($reviewItems as $index => $item)
                        <fieldset class="portal-item" data-delivery-item>
                            <legend>Item <span data-item-number>{{ $loop->iteration }}</span></legend>
                            <button class="portal-item__remove" type="button" data-remove-delivery-item aria-label="Remove item {{ $loop->iteration }}">Remove</button>
                            <div class="portal-form-grid">
                                <div class="portal-field"><label for="items_{{ $index }}_name">Item name <span aria-hidden="true">*</span></label><input id="items_{{ $index }}_name" name="items[{{ $index }}][item_name]" type="text" maxlength="255" value="{{ $item['item_name'] ?? '' }}" required>@error("items.$index.item_name") <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                                <div class="portal-field"><label for="items_{{ $index }}_quantity">Quantity</label><input id="items_{{ $index }}_quantity" name="items[{{ $index }}][quantity]" type="number" min="1" max="999" value="{{ $item['quantity'] ?? 1 }}" required>@error("items.$index.quantity") <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                                <div class="portal-field"><label for="items_{{ $index }}_amount">Item amount (TZS) <span aria-hidden="true">*</span></label><input id="items_{{ $index }}_amount" name="items[{{ $index }}][amount]" type="number" min="0" step="0.01" value="{{ $item['amount'] ?? 0 }}" required>@error("items.$index.amount") <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                                <div class="portal-field portal-field--wide"><label for="items_{{ $index }}_description">Description</label><textarea id="items_{{ $index }}_description" name="items[{{ $index }}][description]" rows="2">{{ $item['description'] ?? '' }}</textarea>@error("items.$index.description") <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                            </div>
                        </fieldset>
                    @endforeach
                </div>
                <template data-delivery-item-template>
                    <fieldset class="portal-item" data-delivery-item>
                        <legend>Item <span data-item-number></span></legend>
                        <button class="portal-item__remove" type="button" data-remove-delivery-item aria-label="Remove item">Remove</button>
                        <div class="portal-form-grid">
                            <div class="portal-field"><label for="items___INDEX___name">Item name <span aria-hidden="true">*</span></label><input id="items___INDEX___name" name="items[__INDEX__][item_name]" type="text" maxlength="255" required></div>
                            <div class="portal-field"><label for="items___INDEX___quantity">Quantity</label><input id="items___INDEX___quantity" name="items[__INDEX__][quantity]" type="number" min="1" max="999" value="1" required></div>
                            <div class="portal-field"><label for="items___INDEX___amount">Item amount (TZS) <span aria-hidden="true">*</span></label><input id="items___INDEX___amount" name="items[__INDEX__][amount]" type="number" min="0" step="0.01" value="0" required></div>
                            <div class="portal-field portal-field--wide"><label for="items___INDEX___description">Description</label><textarea id="items___INDEX___description" name="items[__INDEX__][description]" rows="2"></textarea></div>
                        </div>
                    </fieldset>
                </template>
            </section>

            <section class="portal-card portal-form-section">
                <div class="portal-card__header"><div><h2>Business-controlled payment</h2></div></div>
                <div class="portal-form-grid">
                    <div class="portal-field"><label for="payment_method">Payment method</label><select id="payment_method" name="payment_method" required>@foreach ($paymentMethods as $method)<option value="{{ $method }}" @selected(old('payment_method', 'cash_on_delivery') === $method)>{{ str($method)->replace('_', ' ')->title() }}</option>@endforeach</select>@error('payment_method') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                    <div class="portal-field"><label for="amount_to_collect">Amount to collect (TZS)</label><input id="amount_to_collect" name="amount_to_collect" type="number" min="0" step="0.01" value="{{ old('amount_to_collect', 0) }}" required>@error('amount_to_collect') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                    <div class="portal-field"><label for="delivery_fee">Delivery fee (TZS)</label><input id="delivery_fee" name="delivery_fee" type="number" min="0" step="0.01" value="{{ old('delivery_fee', 0) }}" required>@error('delivery_fee') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                    <div class="portal-field portal-field--wide"><label for="special_instruction">Special instruction</label><textarea id="special_instruction" name="special_instruction" rows="3">{{ old('special_instruction', $deliveryRequest->special_instruction) }}</textarea>@error('special_instruction') <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                </div>
            </section>

            <div class="portal-form-actions">
                <button class="portal-button portal-button--primary" type="submit" data-submit-label="Creating…">Create official delivery</button>
            </div>
        </form>

        <form method="POST" action="{{ route('portal.delivery-requests.revoke', $deliveryRequest) }}" data-confirm="Revoke this submitted request without creating a delivery?" data-submitting-form>
            @csrf
            <button class="portal-button portal-button--danger" type="submit" data-submit-label="Revoking…">Revoke request</button>
        </form>
    @elseif ($status === 'converted')
        <section class="portal-card portal-form-section">
            <div class="portal-card__header"><div><h2>Official delivery created</h2></div></div>
            <p>This request has been consumed and its customer link no longer works.</p>
            @if ($deliveryRequest->convertedDelivery)
                <a class="portal-button portal-button--primary" href="{{ route('portal.deliveries.show', $deliveryRequest->convertedDelivery) }}">Open {{ $deliveryRequest->convertedDelivery->delivery_number }}</a>
            @endif
        </section>
    @else
        <section class="portal-card portal-form-section">
            <div class="portal-card__header"><div><h2>Request revoked</h2></div></div>
            <p>The customer link is no longer valid. Create a new request if customer details are still needed.</p>
        </section>
    @endif
@endsection
