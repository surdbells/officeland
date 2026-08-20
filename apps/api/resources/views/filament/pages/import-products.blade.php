<x-filament-panels::page>
    {{ $this->form }}

    @if ($report)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="p-6 space-y-4">
                <h3 class="text-base font-semibold">{{ $report->summary() }}</h3>

                {{-- Errors first: these are the rows that will not be applied. --}}
                @if ($report->errors())
                    <div>
                        <h4 class="font-medium text-danger-600">Rejected rows</h4>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($report->errors() as $error)
                                <li><span class="font-mono">Row {{ $error['line'] }}</span> — {{ $error['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($report->warnings())
                    <div>
                        <h4 class="font-medium text-warning-600">Warnings</h4>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($report->warnings() as $warning)
                                <li><span class="font-mono">Row {{ $warning['line'] }}</span> — {{ $warning['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($report->creates())
                    <div>
                        <h4 class="font-medium text-success-600">New products ({{ $report->createCount() }})</h4>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach (array_slice($report->creates(), 0, 50) as $create)
                                <li><span class="font-mono">Row {{ $create['line'] }}</span> — {{ $create['name'] }}</li>
                            @endforeach
                        </ul>
                        @if ($report->createCount() > 50)
                            <p class="mt-2 text-sm text-gray-500">…and {{ $report->createCount() - 50 }} more.</p>
                        @endif
                    </div>
                @endif

                {{-- Field-level diff: what actually changes, per row. --}}
                @if ($report->updates())
                    <div>
                        <h4 class="font-medium">Updated products ({{ $report->updateCount() }})</h4>
                        <ul class="mt-2 space-y-2 text-sm">
                            @foreach (array_slice($report->updates(), 0, 50) as $update)
                                <li>
                                    <span class="font-mono">Row {{ $update['line'] }}</span> — {{ $update['name'] }}
                                    <ul class="ml-6 mt-1 space-y-0.5 text-gray-600 dark:text-gray-400">
                                        @foreach ($update['changes'] as $field => $change)
                                            <li>
                                                <span class="font-medium">{{ $field }}</span>:
                                                <span class="line-through">{{ \Illuminate\Support\Str::limit((string) $change['from'], 60) ?: '—' }}</span>
                                                →
                                                <span>{{ \Illuminate\Support\Str::limit((string) $change['to'], 60) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                        @if ($report->updateCount() > 50)
                            <p class="mt-2 text-sm text-gray-500">…and {{ $report->updateCount() - 50 }} more.</p>
                        @endif
                    </div>
                @endif

                @if ($report->unchangedCount() > 0)
                    <p class="text-sm text-gray-500">
                        {{ $report->unchangedCount() }} row(s) already match and will not be touched.
                    </p>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
