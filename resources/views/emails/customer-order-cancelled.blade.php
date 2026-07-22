@extends('emails.layouts.bizgrid')

@section('title', 'Order cancelled '.$order->order_number)
@section('preheader', 'Order '.$order->order_number.' was cancelled')

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $customerName ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        Your order <strong>{{ $order->order_number }}</strong> at
        <strong>{{ $brand['name'] }}</strong> has been cancelled.
        @if (($order->payment_status ?? '') === 'refunded')
            A refund has been processed for this order.
        @endif
    </p>

    @include('emails.partials.order-summary', [
        'items' => $order->items,
        'currency' => $order->currency,
        'totalAmount' => $order->total_amount,
    ])
@endsection

@section('footer')
    You are receiving this email because you placed an order at {{ $brand['name'] }}.
@endsection
