@extends('errors.layout')
@section('code', '403')
@section('title', 'Not allowed')
{{-- The reason given to abort() is the app's own and meant to be read ("Your account has no active company."). --}}
@section('message', $exception->getMessage() ?: 'Your account does not have access to this page.')
