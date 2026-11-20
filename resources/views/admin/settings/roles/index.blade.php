@extends('layouts.admin')

@section('content')
@php
    \App\Utilities\Breadcrumbs::reset();
    \App\Utilities\Breadcrumbs::add(__('Dashboard'), '/admin')
        ->add(__('Settings'), '/admin/settings')
        ->add(__('Roles'));
@endphp

@include('components.page-header', [
    'title' => __('Roles Management'),
    'subtitle' => __('View system roles and manage their associated permissions')
])

<div class="row">
    <div class="col-12">
        @include('components.data-table', [
            'resource' => 'roles',
            'items' => $roles,
            'pagination' => $pagination
        ])
    </div>
</div>
@endsection
