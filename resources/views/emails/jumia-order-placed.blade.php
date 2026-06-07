<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Jumia Order</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); padding: 20px; }
        h1 { color: #333; }
        .order-number { font-size: 18px; font-weight: bold; color: #971BB2; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #eee; }
        .total { font-weight: bold; font-size: 16px; }
        .footer { margin-top: 20px; font-size: 12px; color: #777; }
    </style>
</head>
<body>
    <div class="container">
        <h1>New Jumia order placed</h1>
        <p>A new order has been placed and is pending processing.</p>
        <p class="order-number">Order number: {{ $order->order_number }}</p>
        <p>Total: NGN {{ number_format($order->total_amount, 2) }}</p>
        <p>Status: {{ $order->status }}</p>
        @if($order->relationLoaded('deliveryAddress') && $order->deliveryAddress)
            <h3>Delivery address</h3>
            <p>
                {{ $order->deliveryAddress->full_name }}<br>
                {{ $order->deliveryAddress->address_line_1 }}<br>
                {{ $order->deliveryAddress->city }}, {{ $order->deliveryAddress->state }} {{ $order->deliveryAddress->postal_code }}<br>
                {{ $order->deliveryAddress->country }}<br>
                Phone: {{ $order->deliveryAddress->phone_number }}
            </p>
        @endif
        @if($order->relationLoaded('orderItems') && $order->orderItems->count() > 0)
            <h3>Items</h3>
            <table>
                <thead>
                    <tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr>
                </thead>
                <tbody>
                    @foreach($order->orderItems as $item)
                        <tr>
                            <td>{{ $item->product_name }}</td>
                            <td>{{ $item->quantity }}</td>
                            <td>NGN {{ number_format($item->unit_price, 2) }}</td>
                            <td>NGN {{ number_format($item->total_price, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p class="total">Total: NGN {{ number_format($order->total_amount, 2) }}</p>
        <div class="footer">Process this order in the admin dashboard.</div>
    </div>
</body>
</html>
