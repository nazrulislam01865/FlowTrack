@extends('layouts.app')
@section('content')
{{-- User/Profile editor needs original route context (including ?from=administration). --}}
<livewire:user-editor.index :user-id="$user->id" :profile-mode="$profileMode ?? false" />
@endsection
