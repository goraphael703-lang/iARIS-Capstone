@extends('layouts.app')

@section('title', 'iARIS — Dashboard')
@section('page-title', 'Admissions Overview')
@section('page-subtitle', "Academic Year {$academicYear} · {$periodLabel}")

@push('styles')
<style>
    .stat-label {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: var(--iaris-text-soft);
    }

    .stat-value {
        font-size: 30px;
        font-weight: 700;
    }

    .stat-change {
        font-size: 12.5px;
        font-weight: 600;
        color: var(--iaris-green);
    }

    .stat-change.neutral {
        color: var(--iaris-text-soft);
    }

    .iaris-tag {
        display: inline-block;
        font-size: 11.5px;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 8px;
    }

    .chart-placeholder {
        height: 230px;
        border: 2px dashed var(--iaris-border);
        border-radius: 10px;
        background: var(--iaris-green-tint);
        color: var(--iaris-text-soft);
    }

    .iaris-table th {
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        color: var(--iaris-text-soft);
        border-bottom: 1.5px solid var(--iaris-border);
        padding: 10px 12px;
        white-space: nowrap;
    }

    .iaris-table td {
        font-size: 13.5px;
        padding: 14px 12px;
        border-bottom: 1px solid #f3f5f3;
    }

    .notif-date {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: var(--iaris-green);
        margin: 18px 0 6px;
    }

    .notif-date:first-child {
        margin-top: 0;
    }

    .notif-item + .notif-item {
        border-top: 1px solid #f3f5f3;
    }

    .notif-icon {
        width: 36px;
        height: 36px;
        font-size: 16px;
    }
</style>
@endpush

@section('content')
    @include('home.partials.stats')
    @include('home.partials.charts')

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            @include('home.partials.recent-applicants')
        </div>
        <div class="col-12 col-xl-5">
            @include('home.partials.notifications')
        </div>
    </div>
@endsection
