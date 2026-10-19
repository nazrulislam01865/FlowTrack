<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

trait UsesPagePlaceholder
{
    public function placeholder(): View
    {
        $segments = explode('\\', static::class);
        $section = $segments[count($segments) - 2] ?? 'FlowTrack';

        return view('livewire.shared.page-placeholder', [
            'title' => Str::headline($section),
        ]);
    }
}
