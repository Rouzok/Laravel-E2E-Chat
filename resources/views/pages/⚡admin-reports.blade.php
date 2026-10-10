<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('User Reports')] class extends Component {
    public function getReportsProperty(): \Illuminate\Support\Collection
    {
        return DB::table('user_reports')
            ->join('users as reporters', 'reporters.id', '=', 'user_reports.reporter_id')
            ->join('users as reported_users', 'reported_users.id', '=', 'user_reports.reported_user_id')
            ->select([
                'user_reports.id',
                'user_reports.reason',
                'user_reports.details',
                'user_reports.status',
                'user_reports.created_at',
                'reporters.email as reporter_email',
                'reported_users.name as reported_name',
                'reported_users.email as reported_email',
            ])
            ->orderByDesc('user_reports.created_at')
            ->get();
    }

    public function updateStatus(int $reportId, string $status): void
    {
        abort_unless(Auth::user()->isAdmin(), 403);
        $validated = validator(['status' => $status], [
            'status' => ['required', 'in:pending,reviewed,dismissed'],
        ])->validate();

        DB::table('user_reports')->where('id', $reportId)->update([
            'status' => $validated['status'],
            'updated_at' => now(),
        ]);
    }
}; ?>

<div class="mx-auto w-full max-w-6xl space-y-6 p-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('User reports') }}</h1>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ __('Private reports submitted by users for administrator review.') }}</p>
        </div>
        <a href="{{ route('admin.dashboard') }}" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700">{{ __('Admin dashboard') }}</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-zinc-900">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-neutral-600 dark:bg-zinc-800 dark:text-neutral-300">
                <tr>
                    <th class="p-3 font-medium">{{ __('Reported user') }}</th>
                    <th class="p-3 font-medium">{{ __('Reason and details') }}</th>
                    <th class="p-3 font-medium">{{ __('Reporter') }}</th>
                    <th class="p-3 font-medium">{{ __('Status') }}</th>
                    <th class="p-3 font-medium">{{ __('Review') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($this->reports as $report)
                    <tr wire:key="report-{{ $report->id }}" class="align-top text-neutral-800 dark:text-neutral-200">
                        <td class="p-3"><span class="block font-medium">{{ $report->reported_name }}</span><span class="text-neutral-500">{{ $report->reported_email }}</span></td>
                        <td class="max-w-xl whitespace-pre-wrap p-3"><span class="block font-medium">{{ $report->reason }}</span>@if ($report->details)<span class="mt-1 block text-neutral-600 dark:text-neutral-400">{{ $report->details }}</span>@endif</td>
                        <td class="p-3">{{ $report->reporter_email }}</td>
                        <td class="p-3">{{ ucfirst($report->status) }}</td>
                        <td class="p-3">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="updateStatus({{ $report->id }}, 'reviewed')" class="rounded border border-emerald-300 px-2 py-1 text-emerald-700 dark:border-emerald-900 dark:text-emerald-300">{{ __('Mark reviewed') }}</button>
                                <button type="button" wire:click="updateStatus({{ $report->id }}, 'dismissed')" class="rounded border border-neutral-300 px-2 py-1 dark:border-neutral-700">{{ __('Dismiss') }}</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-8 text-center text-neutral-500">{{ __('No reports have been submitted.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
