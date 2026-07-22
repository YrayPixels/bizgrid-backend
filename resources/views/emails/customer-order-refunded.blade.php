@extends('emails.layouts.bizgrid')

@section('title', 'Refund '.$order->order_number)
@section('preheader', 'A refund was issued for order '.$order->order_number)

@section('content')
    <p style="margin:0 0 16px 0;">Hi {{ $customerName ?? 'there' }},</p>

    <p style="margin:0 0 16px 0;">
        A refund has been issued for order <strong>{{ $order->order_number }}</strong> at
        <strong>{{ $brand['name'] }}</strong>. Funds typically return to your original payment method within a few business days.
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
