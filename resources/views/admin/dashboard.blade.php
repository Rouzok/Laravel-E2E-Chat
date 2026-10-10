<x-layouts::app :title="__('Admin Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="p-6 bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 shadow-sm">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Admin Dashboard') }}
            </h1>
            <p class="mt-2 text-neutral-600 dark:text-neutral-400">
                {{ __('Welcome to the secure Admin Dashboard.') }}
            </p>
            <a href="{{ route('admin.reports') }}" class="mt-4 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                {{ __('Review user reports') }}
            </a>
        </div>
    </div>
</x-layouts::app>
