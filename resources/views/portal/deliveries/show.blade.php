@extends('layouts.portal')

@section('title', $delivery->delivery_number)

@section('content')
    <div class="portal-page-heading">
        <div>
            <a class="portal-back-link" href="{{ route('portal.deliveries.index') }}">← Back to deliveries</a>
            <p class="portal-eyebrow">Delivery detail</p>
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
                        <p class="portal-eyebrow">Customer</p>
                        <h2>Recipient and location</h2>
                    </div>
                </div>
                <dl class="portal-detail-list">
                    <div>
                        <dt>Customer</dt>
                        <dd>{{ $delivery->customer?->name ?? 'Not available' }}</dd>
                    </div>
                    <div>
                        <dt>Customer phone</dt>
                        <dd>{{ $delivery->customer?->phone ?? 'Not available' }}</dd>
                    </div>
                    <div>
                        <dt>Drop-off contact</dt>
                        <dd>{{ $delivery->dropoff_name ?: 'Not provided' }} @if ($delivery->dropoff_phone) · {{ $delivery->dropoff_phone }} @endif</dd>
                    </div>
                    <div class="portal-detail-list__wide">
                        <dt>Drop-off address</dt>
                        <dd>
                            {{ $delivery->dropoff_address ?: collect([
                                $delivery->customerAddress?->street,
                                $delivery->customerAddress?->ward,
                                $delivery->customerAddress?->district,
                                $delivery->customerAddress?->region,
                            ])->filter()->implode(', ') ?: 'Not provided' }}
                        </dd>
                    </div>
                    <div>
                        <dt>Drop-off coordinates</dt>
                        <dd>
                            @if ($delivery->dropoff_latitude !== null && $delivery->dropoff_longitude !== null)
                                {{ $delivery->dropoff_latitude }}, {{ $delivery->dropoff_longitude }}
                            @else
                                Not confirmed
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Branch</dt>
                        <dd>{{ $delivery->branch?->name ?? 'No branch selected' }}</dd>
                    </div>
                    <div>
                        <dt>Pickup contact</dt>
                        <dd>{{ $delivery->pickup_name ?: 'Not provided' }} @if ($delivery->pickup_phone) · {{ $delivery->pickup_phone }} @endif</dd>
                    </div>
                    <div class="portal-detail-list__wide">
                        <dt>Pickup address</dt>
                        <dd>{{ $delivery->pickup_address ?: 'Not provided' }}</dd>
                    </div>
                    @if ($delivery->special_instruction)
                        <div class="portal-detail-list__wide">
                            <dt>Special instruction</dt>
                            <dd>{{ $delivery->special_instruction }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            <section class="portal-card">
                <div class="portal-card__header">
                    <div>
                        <p class="portal-eyebrow">Contents</p>
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
                        <p class="portal-eyebrow">Timeline</p>
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
                        <p class="portal-eyebrow">Driver</p>
                        <h2>Assignment</h2>
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
                    <p class="portal-callout">Assignment is locked because this delivery has started.</p>
                @endif
            </section>

            <section class="portal-card">
                <div class="portal-card__header">
                    <div>
                        <p class="portal-eyebrow">Payment</p>
                        <h2>Payment summary</h2>
                    </div>
                </div>
                <dl class="portal-summary-list">
                    <div><dt>Method</dt><dd>{{ str($delivery->payment_method)->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>Expected</dt><dd>TZS {{ number_format((float) ($delivery->payment?->expected_amount ?? $delivery->amount_to_collect), 2) }}</dd></div>
                    <div><dt>Collected</dt><dd>TZS {{ number_format((float) ($delivery->payment?->collected_amount ?? 0), 2) }}</dd></div>
                    <div><dt>State</dt><dd>{{ str($delivery->payment?->payment_status ?? 'pending')->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>Delivery fee</dt><dd>TZS {{ number_format((float) $delivery->delivery_fee, 2) }}</dd></div>
                </dl>
            </section>

            <section class="portal-card portal-tracking-link">
                <div class="portal-card__header">
                    <div>
                        <p class="portal-eyebrow">Customer access</p>
                        <h2>Secure tracking link</h2>
                    </div>
                </div>
                <p>Share this private link only with the customer for this delivery.</p>
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

            <section class="portal-card">
                <div class="portal-card__header">
                    <div>
                        <p class="portal-eyebrow">Record</p>
                        <h2>Timestamps</h2>
                    </div>
                </div>
                <dl class="portal-summary-list">
                    <div><dt>Created</dt><dd>{{ $delivery->created_at?->format('d M Y, H:i') }}</dd></div>
                    <div><dt>Updated</dt><dd>{{ $delivery->updated_at?->format('d M Y, H:i') }}</dd></div>
                    @if ($delivery->started_at)<div><dt>Started</dt><dd>{{ $delivery->started_at->format('d M Y, H:i') }}</dd></div>@endif
                    @if ($delivery->delivered_at)<div><dt>Delivered</dt><dd>{{ $delivery->delivered_at->format('d M Y, H:i') }}</dd></div>@endif
                    @if ($delivery->failed_at)<div><dt>Failed</dt><dd>{{ $delivery->failed_at->format('d M Y, H:i') }}</dd></div>@endif
                    @if ($delivery->cancelled_at)<div><dt>Cancelled</dt><dd>{{ $delivery->cancelled_at->format('d M Y, H:i') }}</dd></div>@endif
                </dl>
            </section>
        </aside>
    </div>

    @if ($canCancel)
        <dialog id="cancel-delivery-dialog" class="portal-dialog">
            <form method="POST" action="{{ route('portal.deliveries.cancel', $delivery) }}" data-submitting-form>
                @csrf
                <div class="portal-dialog__heading">
                    <p class="portal-eyebrow">Confirm action</p>
                    <h2>Cancel this delivery?</h2>
                    <p>Cancellation closes any active tracking session and removes the temporary live-location state.</p>
                </div>
                <div class="portal-field">
                    <label for="note">Internal status note (optional)</label>
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
