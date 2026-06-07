# Jumia API Module

This module provides a comprehensive API for managing Jumia orders, delivery addresses, and order tracking within the HeySolana backend system.

## Features

- **Order Management**: Create, view, and update Jumia orders
- **Delivery Address Management**: Manage multiple delivery addresses per user
- **Order Tracking**: Track order status changes and history
- **Order Items**: Manage individual products within orders
- **Statistics**: Get order statistics and analytics
- **Authentication**: Secure API endpoints with Laravel Sanctum

## Database Structure

### Tables

1. **jumia_delivery_addresses** - User delivery addresses
2. **jumia_orders** - Main orders table
3. **jumia_order_items** - Individual items within orders
4. **jumia_order_history** - Order status change history

### Relationships

- User → JumiaOrders (one-to-many)
- User → JumiaDeliveryAddresses (one-to-many)
- JumiaOrder → JumiaOrderItems (one-to-many)
- JumiaOrder → JumiaOrderHistory (one-to-many)
- JumiaOrder → JumiaDeliveryAddress (many-to-one)

## API Endpoints

### Authentication
All endpoints require authentication using Laravel Sanctum. Include the Bearer token in the Authorization header.

### Order Management

#### Get User Orders
```
GET /api-jumia/orders
```
Returns paginated list of user's orders with related data.

#### Get Specific Order
```
GET /api-jumia/orders/{orderId}
```
Returns detailed information about a specific order.

#### Create Order
```
POST /api-jumia/orders
```
Creates a new order with items and delivery address.

**Request Body:**
```json
{
  "delivery_address_id": 1,
  "items": [
    {
      "product_id": "PROD001",
      "product_name": "Samsung Galaxy A54",
      "quantity": 1,
      "unit_price": 22625.00,
      "category": "Electronics",
      "brand": "Samsung"
    }
  ],
  "payment_method": "card",
  "notes": "Please deliver in the morning",
  "is_express_delivery": false,
  "delivery_instructions": "Call before delivery"
}
```

#### Update Order Status
```
PATCH /api-jumia/orders/{orderId}/status
```
Updates order status and creates history entry.

**Request Body:**
```json
{
  "status": "shipped",
  "status_description": "Order has been shipped",
  "tracking_number": "TRK123456789",
  "notes": "Shipped via DHL Express"
}
```

### Delivery Address Management

#### Get Delivery Addresses
```
GET /api-jumia/delivery-addresses
```
Returns user's delivery addresses.

#### Create Delivery Address
```
POST /api-jumia/delivery-addresses
```
Creates a new delivery address.

**Request Body:**
```json
{
  "full_name": "John Doe",
  "phone_number": "+2348012345678",
  "email": "john@example.com",
  "address_line_1": "123 Main Street",
  "city": "Lagos",
  "state": "Lagos",
  "postal_code": "100001",
  "country": "Nigeria",
  "is_default": true,
  "landmark": "Near Central Bank",
  "additional_instructions": "Call before delivery"
}
```

#### Update Delivery Address
```
PUT /api-jumia/delivery-addresses/{addressId}
```
Updates an existing delivery address.

#### Delete Delivery Address
```
DELETE /api-jumia/delivery-addresses/{addressId}
```
Deletes a delivery address (only if not associated with orders).

### Statistics

#### Get Order Statistics
```
GET /api-jumia/orders/stats
```
Returns order statistics including total orders, pending orders, total spent, etc.

## Models

### JumiaOrder
Main order model with relationships to user, delivery address, items, and history.

**Key Fields:**
- `order_number`: Unique order identifier
- `status`: Order status (pending, confirmed, processing, shipped, etc.)
- `total_amount`: Total order amount
- `payment_status`: Payment status
- `tracking_number`: Delivery tracking number

### JumiaDeliveryAddress
User delivery address model.

**Key Fields:**
- `full_name`: Recipient's full name
- `phone_number`: Contact phone number
- `address_line_1`, `address_line_2`: Street address
- `city`, `state`, `postal_code`, `country`: Location details
- `is_default`: Whether this is the default address
- `latitude`, `longitude`: GPS coordinates

### JumiaOrderItem
Individual product items within an order.

**Key Fields:**
- `product_id`: Product identifier
- `product_name`: Product name
- `quantity`: Item quantity
- `unit_price`: Price per unit
- `total_price`: Total price for this item

### JumiaOrderHistory
Tracks order status changes over time.

**Key Fields:**
- `status`: Status at this point
- `status_description`: Human-readable description
- `timestamp`: When the status changed
- `updated_by`: Who made the change

## Resources

API responses are formatted using Laravel Resources:

- `JumiaOrderResource`: Formats order data with computed fields
- `JumiaDeliveryAddressResource`: Formats address data
- `JumiaOrderItemResource`: Formats order item data
- `JumiaOrderHistoryResource`: Formats order history data

## Validation

Form requests are used for validation:

- `CreateJumiaOrderRequest`: Validates order creation
- `CreateJumiaDeliveryAddressRequest`: Validates address creation

## Seeding

Use the `JumiaSeeder` to populate the database with sample data:

```bash
php artisan db:seed --class=JumiaSeeder
```

## Installation & Setup

1. **Run Migrations:**
```bash
php artisan migrate
```

2. **Seed Sample Data (Optional):**
```bash
php artisan db:seed --class=JumiaSeeder
```

3. **Include Routes:**
The routes are already included in `routes/api-jumia.php`

## Order Status Flow

1. **pending** → Order created, awaiting confirmation
2. **confirmed** → Order confirmed, payment verified
3. **processing** → Order being prepared for shipping
4. **shipped** → Order shipped and in transit
5. **out_for_delivery** → Order out for final delivery
6. **delivered** → Order successfully delivered
7. **cancelled** → Order cancelled
8. **returned** → Order returned by customer
9. **refunded** → Order refunded

## Payment Methods

Supported payment methods:
- `card`: Credit/Debit card
- `cash_on_delivery`: Pay on delivery
- `bank_transfer`: Bank transfer
- `wallet`: Digital wallet

## Currency

All monetary values are stored in Nigerian Naira (NGN).

## Error Handling

The API returns consistent error responses:

```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field": ["Validation error message"]
  }
}
```

## Security Features

- Authentication required for all endpoints
- User can only access their own orders and addresses
- Input validation and sanitization
- SQL injection protection through Eloquent ORM
- CSRF protection

## Rate Limiting

Consider implementing rate limiting for production use:

```php
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    // Jumia API routes
});
```

## Testing

Test the API endpoints using tools like Postman or curl:

```bash
# Get user orders (requires authentication)
curl -H "Authorization: Bearer {token}" \
     http://localhost:8000/api-jumia/orders

# Create delivery address
curl -X POST \
     -H "Authorization: Bearer {token}" \
     -H "Content-Type: application/json" \
     -d '{"full_name":"Test User","phone_number":"+2348012345678","address_line_1":"123 Test St","city":"Lagos","state":"Lagos","postal_code":"100001","country":"Nigeria"}' \
     http://localhost:8000/api-jumia/delivery-addresses
```

## Product search scraping

Endpoints (no auth):

- `POST /api/jumia/scrape/search` — body: `{ "query": "iphone", "limit": 15 }`
- `POST /api/jumia/scrape/product-details` — body: `{ "product_link": "https://..." }`

Production servers are often blocked by Jumia’s anti-bot layer. Use the same **Superproxy / Bright Data** env vars documented in [`docs/SUPERPROXY.md`](docs/SUPERPROXY.md):

```env
PROXY_URL=brd.superproxy.io:33335:your-zone-username:your-zone-password
```

Or split variables (`PROXY_HOST`, `PROXY_PORT`, `PROXY_USERNAME`, `PROXY_PASSWORD`). Optional override: `JUMIA_PROXY_URL=http://user:pass@host:port`.

If the proxy provider causes TLS errors, set `JUMIA_PROXY_VERIFY_SSL=false`.

After changing env, run `php artisan config:clear` on the server.

Debug empty results:

```bash
curl -X POST 'https://your-api/api/jumia/scrape/search?debug=1' \
  -H 'Content-Type: application/json' \
  -d '{"query":"iphone","limit":3}'
```

Check `debug.proxy_configured`, `debug.direct_status`, `debug.direct_cards_found`, and `debug.blocked`.

## Future Enhancements

- Integration with actual Jumia API
- Real-time order tracking
- Push notifications for status updates
- Order analytics and reporting
- Bulk order operations
- Order templates and favorites
- Integration with payment gateways
