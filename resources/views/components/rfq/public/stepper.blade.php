@props(['steps', 'token', 'locked' => false])
<nav class="ft-rfq-portal-stepper" aria-label="Quotation progress">
    @foreach($steps as $stepItem)
        @php
            $isComplete = (bool) ($stepItem['complete'] ?? false);
            $isActive = (bool) ($stepItem['active'] ?? false);
        @endphp
        <div class="ft-rfq-portal-step {{ $isComplete && ! $isActive ? 'is-complete' : '' }} {{ $isActive ? 'is-active' : '' }}">
            <a href="{{ route('rfq.public.show', ['token' => $token, 'step' => $stepItem['key']]) }}" aria-current="{{ $isActive ? 'step' : 'false' }}">
                <span class="ft-rfq-portal-step__circle">{{ $isComplete && !$isActive ? '✓' : $stepItem['number'] }}</span>
                @if($isComplete && ! $isActive)<span class="ft-rfq-portal-step__number">{{ $stepItem['number'] }}</span>@endif
                <span class="ft-rfq-portal-step__label">{{ $stepItem['label'] }}</span>
            </a>
            @unless($loop->last)<span class="ft-rfq-portal-step__line"></span>@endunless
        </div>
    @endforeach
</nav>
