# Customer delivery requests

The business generates an expiring link at `/portal/delivery-requests/create`.
`GET /request-delivery/{token}` establishes the encrypted request cookie and
redirects to the token-free `/delivery-request` form. Existing expiry, revocation,
CSRF protection, rate limits and business isolation remain in place.

## Customer submission

The customer provides only:

- Name (`customer_name`)
- Phone (`customer_phone`)
- Written delivery address (`dropoff_address`)
- Delivery location selected by GPS or map pin (`dropoff_latitude`, `dropoff_longitude`)

All are required. The map captures coordinates; the customer does not type them.
`POST /delivery-request/session` saves these fields on the request. It does not
create a customer, an official delivery, items or a payment. Items, email,
instructions and pricing sent by an altered client are not saved. Successful
submission consumes customer access to the request.

## Owner/admin completion

The request review page displays the submitted contact, address and location as
read-only text. The owner/admin supplies items, quantities, descriptions, prices,
pickup/branch information, payment method, collection amount, delivery fee and
any business instructions. An empty request starts with one blank item row;
additional items can be added. Existing item and pricing validation still applies.

Conversion copies customer details from the database request locked inside the
transaction, never from the conversion form. An existing customer may only be
reused when active, in the same business and matching the submitted phone. Reuse
does not overwrite that customer's stored profile; the delivery recipient and
destination retain the submitted details.

The normal delivery edit page also keeps these details read-only for deliveries
created from requests. The shared update service ignores overrides to recipient
name, phone, address, coordinates, customer ID and customer-address ID. This applies
to both portal and API updates, including when the source request is soft-deleted.
Manual delivery creation/editing keeps its existing behavior.

If details are incorrect, revoke the unconverted request and create a new request
link for the customer. Converted requests remain consumed; use the existing
cancellation workflow if an incorrect official delivery must be replaced. There
is no owner correction or request-reopening endpoint.

After creation the owner assigns a driver through the existing delivery page.
The official delivery has a separate tracking link. A request token never becomes
a tracking token.

No migrations are changed or data removed. Previously submitted request items
remain stored and can be reviewed as draft items; the owner confirms the official
items and prices.
