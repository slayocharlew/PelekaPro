@php
    $editing = isset($delivery);
    $selectedBusinessId = old('business_id', $editing ? $delivery->business_id : auth('web')->user()->business_id);
    $selectedCustomerId = old('customer_id', $editing ? $delivery->customer_id : null);
    $selectedAddressId = old('customer_address_id', $editing ? $delivery->customer_address_id : null);
    $itemRows = old('items', $editing
        ? $delivery->items->map(fn ($item) => [
            'item_name' => $item->item_name,
            'quantity' => $item->quantity,
            'amount' => $item->amount,
            'description' => $item->description,
        ])->values()->all()
        : [['item_name' => '', 'quantity' => 1, 'amount' => 0, 'description' => '']]);
@endphp

@if ($errors->any())
    <div class="portal-alert portal-alert--error" role="alert">
        <strong>Please correct the highlighted fields.</strong>
        <p>{{ $errors->count() }} {{ str('problem')->plural($errors->count()) }} prevented this delivery from being saved.</p>
    </div>
@endif

<section class="portal-card portal-form-section">
    <div class="portal-card__header">
        <div>
            <p class="portal-eyebrow">Customer</p>
            <h2>Customer and branch</h2>
        </div>
    </div>

    <div class="portal-form-grid">
        @if (auth('web')->user()->isSuperAdmin())
            <div class="portal-field">
                <label for="business_id">Business <span aria-hidden="true">*</span></label>
                <select id="business_id" name="business_id" required data-business-select>
                    <option value="">Select a business</option>
                    @foreach ($businesses as $business)
                        <option value="{{ $business->id }}" @selected((string) $selectedBusinessId === (string) $business->id)>
                            {{ $business->name }}
                        </option>
                    @endforeach
                </select>
                @error('business_id') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="portal-field">
            <label for="branch_id">Branch</label>
            <select id="branch_id" name="branch_id" data-business-option-list>
                <option value="">No branch</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" data-business-id="{{ $branch->business_id }}" @selected((string) old('branch_id', $editing ? $delivery->branch_id : '') === (string) $branch->id)>
                        {{ $branch->name }}
                    </option>
                @endforeach
            </select>
            @error('branch_id') <p class="portal-field__error">{{ $message }}</p> @enderror
        </div>

        <div class="portal-field">
            <label for="customer_id">Customer <span aria-hidden="true">*</span></label>
            <select id="customer_id" name="customer_id" required data-customer-select data-business-option-list>
                <option value="">Select a customer</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" data-business-id="{{ $customer->business_id }}" @selected((string) $selectedCustomerId === (string) $customer->id)>
                        {{ $customer->name }} · {{ $customer->phone }}
                    </option>
                @endforeach
            </select>
            @error('customer_id') <p class="portal-field__error">{{ $message }}</p> @enderror
        </div>

        <div class="portal-field">
            <label for="customer_address_id">Saved customer address</label>
            <select id="customer_address_id" name="customer_address_id" data-address-select>
                <option value="">Enter location below</option>
                @foreach ($addresses as $address)
                    <option
                        value="{{ $address->id }}"
                        data-business-id="{{ $address->business_id }}"
                        data-customer-id="{{ $address->customer_id }}"
                        @selected((string) $selectedAddressId === (string) $address->id)
                    >
                        {{ $address->label ?: 'Saved address' }} · {{ collect([$address->street, $address->ward, $address->district, $address->region])->filter()->implode(', ') }}
                    </option>
                @endforeach
            </select>
            @error('customer_address_id') <p class="portal-field__error">{{ $message }}</p> @enderror
        </div>
    </div>
</section>

<section class="portal-card portal-form-section">
    <div class="portal-card__header">
        <div>
            <p class="portal-eyebrow">Route details</p>
            <h2>Pickup and drop-off</h2>
        </div>
        <p>Coordinates confirm a precise destination when available.</p>
    </div>

    <div class="portal-form-columns">
        <fieldset class="portal-fieldset">
            <legend>Pickup</legend>
            <div class="portal-field">
                <label for="pickup_name">Contact name</label>
                <input id="pickup_name" name="pickup_name" type="text" maxlength="255" value="{{ old('pickup_name', $editing ? $delivery->pickup_name : '') }}">
                @error('pickup_name') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
            <div class="portal-field">
                <label for="pickup_phone">Contact phone</label>
                <input id="pickup_phone" name="pickup_phone" type="tel" maxlength="255" value="{{ old('pickup_phone', $editing ? $delivery->pickup_phone : '') }}">
                @error('pickup_phone') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
            <div class="portal-field">
                <label for="pickup_address">Address</label>
                <textarea id="pickup_address" name="pickup_address" rows="3">{{ old('pickup_address', $editing ? $delivery->pickup_address : '') }}</textarea>
                @error('pickup_address') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
            <div class="portal-coordinate-grid">
                <div class="portal-field">
                    <label for="pickup_latitude">Latitude</label>
                    <input id="pickup_latitude" name="pickup_latitude" type="number" step="0.0000001" min="-90" max="90" value="{{ old('pickup_latitude', $editing ? $delivery->pickup_latitude : '') }}">
                    @error('pickup_latitude') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="pickup_longitude">Longitude</label>
                    <input id="pickup_longitude" name="pickup_longitude" type="number" step="0.0000001" min="-180" max="180" value="{{ old('pickup_longitude', $editing ? $delivery->pickup_longitude : '') }}">
                    @error('pickup_longitude') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="portal-fieldset">
            <legend>Drop-off</legend>
            <div class="portal-field">
                <label for="dropoff_name">Recipient name</label>
                <input id="dropoff_name" name="dropoff_name" type="text" maxlength="255" value="{{ old('dropoff_name', $editing ? $delivery->dropoff_name : '') }}">
                @error('dropoff_name') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
            <div class="portal-field">
                <label for="dropoff_phone">Recipient phone</label>
                <input id="dropoff_phone" name="dropoff_phone" type="tel" maxlength="255" value="{{ old('dropoff_phone', $editing ? $delivery->dropoff_phone : '') }}">
                @error('dropoff_phone') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
            <div class="portal-field">
                <label for="dropoff_address">Address</label>
                <textarea id="dropoff_address" name="dropoff_address" rows="3">{{ old('dropoff_address', $editing ? $delivery->dropoff_address : '') }}</textarea>
                @error('dropoff_address') <p class="portal-field__error">{{ $message }}</p> @enderror
            </div>
            <div class="portal-coordinate-grid">
                <div class="portal-field">
                    <label for="dropoff_latitude">Latitude</label>
                    <input id="dropoff_latitude" name="dropoff_latitude" type="number" step="0.0000001" min="-90" max="90" value="{{ old('dropoff_latitude', $editing ? $delivery->dropoff_latitude : '') }}">
                    @error('dropoff_latitude') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-field">
                    <label for="dropoff_longitude">Longitude</label>
                    <input id="dropoff_longitude" name="dropoff_longitude" type="number" step="0.0000001" min="-180" max="180" value="{{ old('dropoff_longitude', $editing ? $delivery->dropoff_longitude : '') }}">
                    @error('dropoff_longitude') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>
    </div>
</section>

<section class="portal-card portal-form-section">
    <div class="portal-card__header">
        <div>
            <p class="portal-eyebrow">Contents</p>
            <h2>Delivery items</h2>
        </div>
        <button class="portal-button portal-button--secondary portal-button--small" type="button" data-add-delivery-item>Add item</button>
    </div>

    <div class="portal-items" data-delivery-items>
        @foreach ($itemRows as $index => $item)
            <fieldset class="portal-item" data-delivery-item>
                <legend>Item <span data-item-number>{{ $loop->iteration }}</span></legend>
                <button class="portal-item__remove" type="button" data-remove-delivery-item aria-label="Remove item {{ $loop->iteration }}">Remove</button>
                <div class="portal-form-grid">
                    <div class="portal-field">
                        <label for="items_{{ $index }}_name">Item name <span aria-hidden="true">*</span></label>
                        <input id="items_{{ $index }}_name" name="items[{{ $index }}][item_name]" type="text" maxlength="255" value="{{ $item['item_name'] ?? '' }}" required>
                        @error("items.$index.item_name") <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="portal-field">
                        <label for="items_{{ $index }}_quantity">Quantity</label>
                        <input id="items_{{ $index }}_quantity" name="items[{{ $index }}][quantity]" type="number" min="1" value="{{ $item['quantity'] ?? 1 }}">
                        @error("items.$index.quantity") <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="portal-field">
                        <label for="items_{{ $index }}_amount">Item amount (TZS)</label>
                        <input id="items_{{ $index }}_amount" name="items[{{ $index }}][amount]" type="number" min="0" step="0.01" value="{{ $item['amount'] ?? 0 }}">
                        @error("items.$index.amount") <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                    <div class="portal-field portal-field--wide">
                        <label for="items_{{ $index }}_description">Description</label>
                        <textarea id="items_{{ $index }}_description" name="items[{{ $index }}][description]" rows="2">{{ $item['description'] ?? '' }}</textarea>
                        @error("items.$index.description") <p class="portal-field__error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        @endforeach
    </div>

    <template data-delivery-item-template>
        <fieldset class="portal-item" data-delivery-item>
            <legend>Item <span data-item-number></span></legend>
            <button class="portal-item__remove" type="button" data-remove-delivery-item aria-label="Remove item">Remove</button>
            <div class="portal-form-grid">
                <div class="portal-field">
                    <label for="items___INDEX___name">Item name <span aria-hidden="true">*</span></label>
                    <input id="items___INDEX___name" name="items[__INDEX__][item_name]" type="text" maxlength="255" required>
                </div>
                <div class="portal-field">
                    <label for="items___INDEX___quantity">Quantity</label>
                    <input id="items___INDEX___quantity" name="items[__INDEX__][quantity]" type="number" min="1" value="1">
                </div>
                <div class="portal-field">
                    <label for="items___INDEX___amount">Item amount (TZS)</label>
                    <input id="items___INDEX___amount" name="items[__INDEX__][amount]" type="number" min="0" step="0.01" value="0">
                </div>
                <div class="portal-field portal-field--wide">
                    <label for="items___INDEX___description">Description</label>
                    <textarea id="items___INDEX___description" name="items[__INDEX__][description]" rows="2"></textarea>
                </div>
            </div>
        </fieldset>
    </template>
</section>

<section class="portal-card portal-form-section">
    <div class="portal-card__header">
        <div>
            <p class="portal-eyebrow">Payment</p>
            <h2>Collection details</h2>
        </div>
        <p>The selected method remains authoritative for the driver workflow.</p>
    </div>

    <div class="portal-form-grid">
        <div class="portal-field">
            <label for="payment_method">Payment method</label>
            <select id="payment_method" name="payment_method">
                @foreach ($paymentMethods as $method)
                    <option value="{{ $method }}" @selected(old('payment_method', $editing ? $delivery->payment_method : 'cash_on_delivery') === $method)>
                        {{ str($method)->replace('_', ' ')->title() }}
                    </option>
                @endforeach
            </select>
            @error('payment_method') <p class="portal-field__error">{{ $message }}</p> @enderror
        </div>
        <div class="portal-field">
            <label for="amount_to_collect">Amount to collect (TZS)</label>
            <input id="amount_to_collect" name="amount_to_collect" type="number" min="0" step="0.01" value="{{ old('amount_to_collect', $editing ? $delivery->amount_to_collect : 0) }}">
            @error('amount_to_collect') <p class="portal-field__error">{{ $message }}</p> @enderror
        </div>
        <div class="portal-field">
            <label for="delivery_fee">Delivery fee (TZS)</label>
            <input id="delivery_fee" name="delivery_fee" type="number" min="0" step="0.01" value="{{ old('delivery_fee', $editing ? $delivery->delivery_fee : 0) }}">
            @error('delivery_fee') <p class="portal-field__error">{{ $message }}</p> @enderror
        </div>
        <div class="portal-field portal-field--wide">
            <label for="special_instruction">Special instruction</label>
            <textarea id="special_instruction" name="special_instruction" rows="3">{{ old('special_instruction', $editing ? $delivery->special_instruction : '') }}</textarea>
            @error('special_instruction') <p class="portal-field__error">{{ $message }}</p> @enderror
        </div>
    </div>
</section>

<div class="portal-form-actions">
    <a class="portal-button portal-button--quiet" href="{{ $editing ? route('portal.deliveries.show', $delivery) : route('portal.deliveries.index') }}">Cancel</a>
    <button class="portal-button portal-button--primary" type="submit" data-submit-label="Saving…">{{ $submitLabel }}</button>
</div>
