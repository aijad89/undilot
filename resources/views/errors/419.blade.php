@extends('errors.layout')
@section('code', '419')
@section('title', 'Your session expired')
{{-- Common on a phone left open: the form was sent after the session ended. --}}
@section('message', 'The page was open too long, so the form could not be sent. Go back, reload the page, and send it again.')
