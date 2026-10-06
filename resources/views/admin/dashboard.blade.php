<x-layouts::app :title="__('Admin Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="p-6 bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 shadow-sm">
            <h1 class="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Admin Dashboard') }}
            </h1>
            <p class="mt-2 text-neutral-600 dark:text-neutral-400">
                {{ __('Welcome to the secure Admin Dashboard.') }}
            </p>
        </div>
    </div>
</x-layouts::app>
