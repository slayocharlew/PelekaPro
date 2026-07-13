# Authorization Foundation

This project now has route middleware aliases ready for the first API routes:

- `role`: restricts authenticated users by role name.
- `business.scope`: enforces that non-super-admin users only access their own `business_id`.
- `driver.delivery`: enforces that a driver can access only deliveries assigned to their user ID.
- `customer.tracking`: enforces public customer tracking through `public_tracking_token`.

Future controllers must enforce these rules:

- `business_owner` and `business_admin` can only access records scoped to their own business.
- `driver` can only access deliveries where `deliveries.assigned_driver_id` matches the authenticated user.
- Customers must track deliveries only through the secure `public_tracking_token`.
- GPS tracking must start only after the driver taps Start Delivery.
- GPS tracking must stop when the delivery becomes `delivered`, `failed`, or `cancelled`.

Redis should continue to hold only the latest live driver location. MySQL remains the permanent location history through `delivery_tracking_locations`.
