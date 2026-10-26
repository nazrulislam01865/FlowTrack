<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>STEP PROMO</title>
    <link rel="icon" href="{{ $branding['favicon_url'] ?? asset('images/step-promo/step-promo-icon.webp') }}">
    <script
        src="{{ asset('js/flowtrack-image-fallback.js') }}?v={{ \App\Support\FrontendBuildVersion::current() }}"
        data-fallback-src="{{ asset('images/flowtrack-image-fallback.svg') }}"
    ></script>
    @vite(['resources/css/login.css', 'resources/theme/flowtrack/core.css'])
</head>
<body>
<div class="login screen">
    <section class="login-visual" aria-label="Step Promo">
        <div class="login-visual-content">
            <img
                class="step-promo-logo"
                src="{{ asset('images/step-promo/step-promo-logo.webp') }}"
                alt="STEP PROMO — Your Promo Partner"
            >

            <div class="login-message">
                <h1>Your promo journey,<br>perfectly in step.</h1>
                <p>Manage every quote, proof, sample, production<br class="desktop-break"> milestone and delivery from one clear workspace.</p>

                <div class="flow-preview" aria-label="Promotional workflow stages">
                    <div>01 · Quote</div>
                    <div>02 · Artwork</div>
                    <div>03 · Production</div>
                    <div>04 · Delivery</div>
                </div>
            </div>
        </div>

        <div class="login-visual-footer">Built for smoother promotional workflows</div>
    </section>

    <section class="login-form-wrap">
        <form class="login-form" method="POST" action="{{ route('login.store') }}">
            @csrf

            @if (request()->query('reason') === 'other-device')
                <div class="validation-error ft-login-session-message" role="alert">
                    Another device logged in with the same user ID and password. Your previous session was logged out.
                </div>
            @elseif (request()->query('reason') === 'timeout')
                <div class="validation-error ft-login-session-message" role="alert">
                    Your {{ config('session.lifetime', 30) }}-minute session has expired. Please sign in again.
                </div>
            @elseif (request()->query('reason') === 'session-refresh')
                <div class="validation-error ft-login-session-message" role="alert">
                    Your browser session was refreshed securely. Please sign in again.
                </div>
            @endif

            <div class="login-form-brand">
                <img src="{{ asset('images/step-promo/step-promo-icon.webp') }}" alt="" aria-hidden="true">
                <span>STEP PROMO</span>
            </div>

            <h2>Welcome back</h2>
            <p class="login-form-intro">Sign in to manage Orders, assignments and client delivery.</p>

            <div class="field">
                <label for="email">Email</label>
                <input
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus
                >
                @error('email')
                    <div class="validation-error" role="alert">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-field">
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                    <button
                        type="button"
                        id="togglePassword"
                        class="password-toggle"
                        tabindex="-1"
                        title="Toggle password visibility"
                        aria-label="Show password"
                    >
                        <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
                @error('password')
                    <div class="validation-error" role="alert">{{ $message }}</div>
                @enderror
            </div>

            <label class="check-row">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>Remember me</span>
            </label>

            <button class="primary" type="submit">Sign in</button>

            @if((bool) config('flowtrack.order_tracking_enabled', false))
                <hr class="login-tracking-divider">

                <div class="login-tracking-prompt">
                    <span class="login-tracking-prompt-label">Just checking an order?</span>
                    <a href="{{ route('order.track') }}" class="login-tracking-link">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                            <line x1="12" y1="22.08" x2="12" y2="12"></line>
                        </svg>
                        <span>Track your order &rarr;</span>
                    </a>
                    <p class="login-tracking-subtext">Use your order or reference number and email. No sign-in needed.</p>
                </div>
            @endif
        </form>
    </section>
</div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword?.addEventListener('click', function () {
        const showPassword = passwordInput.type === 'password';
        passwordInput.type = showPassword ? 'text' : 'password';
        this.classList.toggle('is-visible', showPassword);
        this.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
    });
</script>
</body>
</html>
