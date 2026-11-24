@extends('errors.layout')
@section('code', '503')
@section('title', 'Down for maintenance')
@section('message', 'undilot is being updated. Please try again in a few minutes.')
@section('action')
    <button type="button" class="err-btn err-btn-primary" onclick="location.reload()">Try again</button>
@endsection
