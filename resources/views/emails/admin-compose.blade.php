@extends('emails.layouts.bizgrid')

@section('title', $subject ?? ($brandName ?? 'Message'))

@section('content')
    {!! $html !!}
@endsection
