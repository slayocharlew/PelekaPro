<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#ff6c37">
        <meta name="color-scheme" content="light">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">

        <title>Provide delivery details · PelekaPro</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="delivery-request-page">
        <header class="delivery-request-header">
            <div class="delivery-request-container delivery-request-header__inner">
                @include('tracking.partials.brand')
                <span>Requested by {{ $businessName }}</span>
            </div>
        </header>

        <main
            class="delivery-request-main"
            data-customer-delivery-request
            data-session-delete-url="{{ route('customer.delivery-request.session.destroy', absolute: false) }}"
            data-session-page-url="{{ route('customer.delivery-request.page', absolute: false) }}"
            data-session-expires-at="{{ $expiresAt }}"
        >
            <div class="delivery-request-container">
                <div class="delivery-request-heading">
                    <h1>Where should we deliver your items?</h1>
                    <p>Provide your contact, items, written address and an exact map location.</p>
                </div>

                @if ($errors->any())
                    <div class="delivery-request-alert" role="alert">
                        <strong>Please correct the highlighted information.</strong>
                    </div>
                @endif

                <form method="POST" action="{{ route('customer.delivery-request.session.store') }}" data-customer-delivery-request-form>
                    @csrf

                    <section class="delivery-request-card">
                        <h2>Your contact</h2>
                        <div class="delivery-request-grid">
                            <div class="portal-field">
                                <label for="customer_name">Name <span aria-hidden="true">*</span></label>
                                <input id="customer_name" name="customer_name" type="text" maxlength="255" autocomplete="name" value="{{ old('customer_name') }}" required>
                                @error('customer_name') <p class="portal-field__error">{{ $message }}</p> @enderror
                            </div>
                            <div class="portal-field">
                                <label for="customer_phone">Phone <span aria-hidden="true">*</span></label>
                                <input id="customer_phone" name="customer_phone" type="tel" maxlength="30" autocomplete="tel" value="{{ old('customer_phone') }}" required>
                                @error('customer_phone') <p class="portal-field__error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </section>

                    <section class="delivery-request-card">
                        <div class="delivery-request-card__header">
                            <h2>Your items</h2>
                            <button class="portal-button portal-button--secondary portal-button--small" type="button" data-add-request-item>Add item</button>
                        </div>
                        @php
                            $requestItems = old('items', [['item_name' => '', 'quantity' => 1, 'description' => '']]);
                        @endphp
                        <div class="delivery-request-items" data-request-items>
                            @foreach ($requestItems as $index => $item)
                                <fieldset class="delivery-request-item" data-request-item>
                                    <legend>Item <span data-request-item-number>{{ $loop->iteration }}</span></legend>
                                    <button type="button" class="delivery-request-item__remove" data-remove-request-item aria-label="Remove item {{ $loop->iteration }}">Remove</button>
                                    <div class="delivery-request-grid">
                                        <div class="portal-field"><label for="items_{{ $index }}_name">Item name <span aria-hidden="true">*</span></label><input id="items_{{ $index }}_name" name="items[{{ $index }}][item_name]" type="text" maxlength="255" value="{{ $item['item_name'] ?? '' }}" required>@error("items.$index.item_name") <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                                        <div class="portal-field"><label for="items_{{ $index }}_quantity">Quantity <span aria-hidden="true">*</span></label><input id="items_{{ $index }}_quantity" name="items[{{ $index }}][quantity]" type="number" min="1" max="999" value="{{ $item['quantity'] ?? 1 }}" required>@error("items.$index.quantity") <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                                        <div class="portal-field delivery-request-grid__wide"><label for="items_{{ $index }}_description">Description (optional)</label><textarea id="items_{{ $index }}_description" name="items[{{ $index }}][description]" rows="2" maxlength="2000">{{ $item['description'] ?? '' }}</textarea>@error("items.$index.description") <p class="portal-field__error">{{ $message }}</p> @enderror</div>
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>
                        <template data-request-item-template>
                            <fieldset class="delivery-request-item" data-request-item>
                                <legend>Item <span data-request-item-number></span></legend>
                                <button type="button" class="delivery-request-item__remove" data-remove-request-item aria-label="Remove item">Remove</button>
                                <div class="delivery-request-grid">
                                    <div class="portal-field"><label for="request_items___INDEX___name">Item name <span aria-hidden="true">*</span></label><input id="request_items___INDEX___name" name="items[__INDEX__][item_name]" type="text" maxlength="255" required></div>
                                    <div class="portal-field"><label for="request_items___INDEX___quantity">Quantity <span aria-hidden="true">*</span></label><input id="request_items___INDEX___quantity" name="items[__INDEX__][quantity]" type="number" min="1" max="999" value="1" required></div>
                                    <div class="portal-field delivery-request-grid__wide"><label for="request_items___INDEX___description">Description (optional)</label><textarea id="request_items___INDEX___description" name="items[__INDEX__][description]" rows="2" maxlength="2000"></textarea></div>
                                </div>
                            </fieldset>
                        </template>
                    </section>

                    <section class="delivery-request-card">
                        <h2>Delivery destination</h2>
                        <div class="portal-field">
                            <label for="dropoff_address">Written address <span aria-hidden="true">*</span></label>
                            <textarea id="dropoff_address" name="dropoff_address" rows="3" maxlength="255" autocomplete="street-address" required>{{ old('dropoff_address') }}</textarea>
                            @error('dropoff_address') <p class="portal-field__error">{{ $message }}</p> @enderror
                        </div>

                        <div class="delivery-request-map-controls">
                            <button class="portal-button portal-button--secondary" type="button" data-use-current-location>Use my current location</button>
                            <p id="delivery-request-location-status" data-location-status role="status" aria-live="polite">Choose your current location or tap the map to place the delivery pin.</p>
                        </div>
                        <div class="delivery-request-map" data-delivery-request-map role="application" tabindex="0" aria-label="Select delivery location on map" aria-describedby="delivery-request-location-status"></div>
                        <input name="dropoff_latitude" type="hidden" value="{{ old('dropoff_latitude') }}" data-request-latitude>
                        <input name="dropoff_longitude" type="hidden" value="{{ old('dropoff_longitude') }}" data-request-longitude>
                        @error('dropoff_latitude') <p class="portal-field__error">{{ $message }}</p> @enderror
                        @error('dropoff_longitude') <p class="portal-field__error">{{ $message }}</p> @enderror

                        <div class="portal-field">
                            <label for="special_instruction">Delivery instructions (optional)</label>
                            <textarea id="special_instruction" name="special_instruction" rows="3" maxlength="2000">{{ old('special_instruction') }}</textarea>
                            @error('special_instruction') <p class="portal-field__error">{{ $message }}</p> @enderror
                        </div>
                    </section>

                    <div class="delivery-request-actions">
                        <button class="portal-button portal-button--primary" type="submit" data-request-submit>Submit delivery details</button>
                        <button class="portal-button portal-button--quiet" type="button" data-close-delivery-request>Close form</button>
                    </div>
                </form>
            </div>
        </main>
    </body>
</html>
