@extends('layouts.portal')

@section('title', $delivery->delivery_number)

@section('content')
    <div class="portal-page-heading">
        <div>
            <a class="portal-back-link" href="{{ route('portal.deliveries.index') }}">← Back to deliveries</a>
            <div class="portal-heading-line">
                <h1>{{ $delivery->delivery_number }}</h1>
                @include('portal.partials.status-badge', ['status' => $delivery->status])
            </div>
            <p>Tracking code {{ $delivery->tracking_code }} · Created <time datetime="{{ $delivery->created_at?->toISOString() }}">{{ $delivery->created_at?->format('d M Y, H:i') }}</time></p>
        </div>
        <div class="portal-page-heading__actions">
            @if ($canEdit)
                <a class="portal-button portal-button--secondary" href="{{ route('portal.deliveries.edit', $delivery) }}">Edit delivery</a>
            @endif
            @if ($canCancel)
                <button class="portal-button portal-button--danger" type="button" data-dialog-open="cancel-delivery-dialog">Cancel delivery</button>
            @endif
        </div>
    </div>

    <div class="portal-detail-grid">
        <div class="portal-detail-main">
            <section class="portal-card">
                <div class="portal-card__header">
                    <div>
                        <h2>Customer and route</h2>
                    </div>
                </div>
                <dl class="portal-detail-list">
                    <div>
                        <dt>Recipient</dt>
                        <dd>{{ $delivery->dropoff_name ?: ($delivery->customer?->name ?? 'Not available') }}</dd>
                    </div>
                    <div>
                        <dt>Phone</dt>
                        <dd>{{ $delivery->dropoff_phone ?: ($delivery->customer?->phone ?? 'Not available') }}</dd>
                    </div>
                    <div class="portal-detail-list__wide">
                        <dt>Delivery address</dt>
                        <dd>
                            {{ $delivery->dropoff_address ?: collect([
                                $delivery->customerAddress?->street,
                                $delivery->customerAddress?->ward,
                                $delivery->customerAddress?->district,
                                $delivery->customerAddress?->region,
                            ])->filter()->implode(', ') ?: 'Not provided' }}
                        </dd>
                    </div>
                    <div class="portal-detail-list__wide">
                        <dt>Pickup</dt>
                        <dd>
                            {{ $delivery->branch?->name ?? ($delivery->pickup_name ?: 'Not provided') }}
                            @if ($delivery->pickup_address) · {{ $delivery->pickup_address }} @endif
                        </dd>
                    </div>
                    @if ($delivery->special_instruction)
                        <div class="portal-detail-list__wide">
                            <dt>Instructions</dt>
                            <dd>{{ $delivery->special_instruction }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            <section class="portal-card">
                <div class="portal-card__header">
                    <div>
                        <h2>Delivery items</h2>
                    </div>
                    <span>{{ $delivery->items->count() }} {{ str('item')->plural($delivery->items->count()) }}</span>
                </div>
                <div class="portal-items-summary">
                    @forelse ($delivery->items as $item)
                        <article>
                            <div>
                                <strong>{{ $item->item_name }}</strong>
                                @if ($item->description)<p>{{ $item->description }}</p>@endif
                            </div>
                            <span>Qty {{ $item->quantity }}</span>
                            <strong>TZS {{ number_format((float) $item->amount, 2) }}</strong>
                        </article>
                    @empty
                        <p class="portal-muted">No delivery items were recorded.</p>
                    @endforelse
                </div>
            </section>

            <section class="portal-card">
                <div class="portal-card__header">
                    <div>
                        <h2>Status history</h2>
                    </div>
                </div>
                <ol class="portal-timeline">
                    @forelse ($delivery->statusLogs->sortByDesc('created_at') as $log)
                        <li>
                            <span class="portal-timeline__marker" aria-hidden="true"></span>
                            <div>
                                <div class="portal-timeline__heading">
                                    <strong>{{ str($log->to_status)->replace('_', ' ')->title() }}</strong>
                                    <time datetime="{{ $log->created_at?->toISOString() }}">{{ $log->created_at?->format('d M Y, H:i') }}</time>
                                </div>
                                @if ($log->note)<p>{{ $log->note }}</p>@endif
                                <small>Changed by {{ $log->changedBy?->name ?? 'System' }}</small>
                            </div>
                        </li>
                    @empty
                        <li><p class="portal-muted">No status changes have been recorded.</p></li>
                    @endforelse
                </ol>
            </section>
        </div>

        <aside class="portal-detail-sidebar">
            <section id="driver-assignment" class="portal-card portal-sticky-card">
                <div class="portal-card__header">
                    <div>
                        <h2>Driver</h2>
                    </div>
                </div>

                @if ($delivery->assignedDriver)
                    <div class="portal-assignee">
                        <span class="portal-assignee__avatar" aria-hidden="true">{{ str($delivery->assignedDriver->name)->substr(0, 1)->upper() }}</span>
                        <div>
                            <strong>{{ $delivery->assignedDriver->name }}</strong>
                            <span>{{ $delivery->assignedDriver->phone }}</span>
                        </div>
                    </div>
                @else
                    <div class="portal-empty portal-empty--small">
                        <h3>No driver assigned</h3>
                        <p>Choose an active, available driver from this business.</p>
                    </div>
                @endif

                @if ($canAssign)
                    <form class="portal-inline-form" method="POST" action="{{ route('portal.deliveries.assign', $delivery) }}" data-submitting-form>
                        @csrf
                        <div class="portal-field">
                            <label for="driver_id">{{ $delivery->assignedDriver ? 'Change driver' : 'Assign driver' }}</label>
                            <select id="driver_id" name="driver_id" required>
                                <option value="">Select available driver</option>
                                @foreach ($availableDrivers as $driver)
                                    <option value="{{ $driver->id }}" @selected((string) old('driver_id') === (string) $driver->id)>
                                        {{ $driver->name }} · {{ $driver->phone }}
                                    </option>
                                @endforeach
                            </select>
                            @error('driver_id') <p class="portal-field__error">{{ $message }}</p> @enderror
                        </div>
                        <button class="portal-button portal-button--primary portal-button--wide" type="submit" data-submit-label="Assigning…">
                            {{ $delivery->assignedDriver ? 'Update assignment' : 'Assign driver' }}
                        </button>
                    </form>

                    @if ($delivery->assignedDriver)
                        <form method="POST" action="{{ route('portal.deliveries.unassign', $delivery) }}" data-confirm="Remove this driver from the delivery?" data-submitting-form>
                            @csrf
                            @method('DELETE')
                            <button class="portal-button portal-button--quiet portal-button--wide" type="submit" data-submit-label="Removing…">Unassign driver</button>
                        </form>
                    @endif
                @elseif ($delivery->started_at)
                    <p class="portal-callout">The driver cannot be changed after the delivery starts.</p>
                @endif
            </section>

            <section class="portal-card">
                <div class="portal-card__header">
                    <div>
                        <h2>Payment</h2>
                    </div>
                </div>
                <dl class="portal-summary-list">
                    <div><dt>Method</dt><dd>{{ str($delivery->payment_method)->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>Amount to collect</dt><dd>TZS {{ number_format((float) ($delivery->payment?->expected_amount ?? $delivery->amount_to_collect), 2) }}</dd></div>
                    <div><dt>Collected</dt><dd>TZS {{ number_format((float) ($delivery->payment?->collected_amount ?? 0), 2) }}</dd></div>
                    <div><dt>Payment status</dt><dd>{{ str($delivery->payment?->payment_status ?? 'pending')->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>Delivery fee</dt><dd>TZS {{ number_format((float) $delivery->delivery_fee, 2) }}</dd></div>
                </dl>
            </section>

            <section class="portal-card portal-tracking-link">
                <div class="portal-card__header">
                    <div>
                        <h2>Customer tracking</h2>
                    </div>
                </div>
                <p>Copy or share this delivery's tracking link with the customer.</p>
                <div class="portal-copy-field">
                    <input id="tracking-link" type="text" value="{{ $trackingUrl }}" readonly aria-label="Customer tracking link">
                    <button class="portal-button portal-button--secondary portal-button--small" type="button" data-copy-target="tracking-link">Copy</button>
                </div>
                <button
                    class="portal-button portal-button--primary portal-button--wide"
                    type="button"
                    data-share-url="{{ $trackingUrl }}"
                    data-share-title="Track delivery {{ $delivery->tracking_code }}"
                >
                    Share tracking link
                </button>
                <p class="portal-copy-status" data-copy-status role="status" aria-live="polite"></p>
            </section>

        </aside>
    </div>

    @if ($canCancel)
        <dialog id="cancel-delivery-dialog" class="portal-dialog">
            <form method="POST" action="{{ route('portal.deliveries.cancel', $delivery) }}" data-submitting-form>
                @csrf
                <div class="portal-dialog__heading">
                    <h2>Cancel this delivery?</h2>
                    <p>The delivery will stop and the driver will no longer be able to continue it.</p>
                </div>
                <div class="portal-field">
                    <label for="note">Reason (optional)</label>
                    <textarea id="note" name="note" rows="3" maxlength="1000"></textarea>
                    @error('note') <p class="portal-field__error">{{ $message }}</p> @enderror
                </div>
                <div class="portal-dialog__actions">
                    <button class="portal-button portal-button--quiet" type="button" data-dialog-close>Keep delivery</button>
                    <button class="portal-button portal-button--danger" type="submit" data-submit-label="Cancelling…">Cancel delivery</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection
