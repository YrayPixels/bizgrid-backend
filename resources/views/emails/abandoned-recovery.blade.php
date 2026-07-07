@php
    $brand = $brand ?? \App\Support\MailBranding::platform();
@endphp
@extends('emails.layouts.bizgrid')

@section('title', $subjectLine ?? 'Message from '.$brand['name'])
@section('preheader', $subjectLine ?? 'A message from '.$brand['name'])

@section('content')
    <div style="margin:0 0 20px 0;white-space:pre-wrap;">{!! nl2br(e($body)) !!}</div>

    @include('emails.partials.recovery-items', [
        'items' => $items ?? [],
        'currency' => $currency ?? 'NGN',
        'totalAmount' => $totalAmount ?? 0,
    ])

    @if (filled($recoveryUrl))
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 8px 0;">
            <tr>
                <td style="border-radius:8px;background:{{ $brand['primary_color'] ?? '#0d9488' }};">
                    <a href="{{ $recoveryUrl }}" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Complete your order</a>
                </td>
            </tr>
        </table>
        <p style="margin:0;font-size:13px;color:#64748b;word-break:break-all;">{{ $recoveryUrl }}</p>
    @endif
@endsection

@section('footer')
    You are receiving this email from {{ $brand['name'] }}@if($brand['support_email']) ({{ $brand['support_email'] }})@endif.
@endsection
