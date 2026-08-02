<div id="loginScreen" class="login screen {{ $flowtrackAuthenticated ? 'hidden' : '' }}">
  <section class="login-visual" aria-label="FlowTrack overview">
    <div class="login-visual-inner">
      <div class="login-brand"><div class="login-logo {{ !empty($flowtrackBranding['logoUrl']) ? 'has-brand-image' : '' }}" data-flowtrack-brand-mark>@if(!empty($flowtrackBranding['logoUrl']))<img class="flowtrack-brand-image" src="{{ $flowtrackBranding['logoUrl'] }}" alt="FlowTrack logo">@else FT @endif</div><span>FlowTrack</span></div>
      <h1>Plan the work. Track every job.<br>Deliver with confidence.</h1>
      <p class="login-hero-copy">FlowTrack helps teams set up jobs quickly, standardize recurring tasks, monitor progress and keep every update visible.</p>
      <div class="login-feature-grid">
        <div class="login-feature">
          <div class="login-feature-icon" aria-hidden="true"><svg viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="2"><rect x="10" y="9" width="36" height="42" rx="5"/><path d="M21 9V6h14v3M18 21l3 3 5-6M18 31l3 3 5-6M18 41l3 3 5-6M31 21h8M31 31h8M31 41h8"/></svg></div>
          <div><h3>Easy job setup</h3><p>Launch repeatable workflows<br>without complexity.</p></div>
        </div>
        <div class="login-feature">
          <div class="login-feature-icon" aria-hidden="true"><svg viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8h25l9 9v31H12z"/><path d="M37 8v10h9M19 24h12M19 30h18M19 36h13"/><rect x="18" y="20" width="5" height="5" rx="1"/></svg></div>
          <div><h3>Reusable task templates</h3><p>Standardize routine work and<br>reduce missed steps.</p></div>
        </div>
        <div class="login-feature">
          <div class="login-feature-icon" aria-hidden="true"><svg viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="38" height="42" rx="4"/><path d="M16 18h7v7h-7zM29 18h11M16 31h7v7h-7zM29 31h11M16 44h7M29 44h11"/></svg></div>
          <div><h3>Board &amp; list views</h3><p>See workflow progress or<br>scan detailed records.</p></div>
        </div>
        <div class="login-feature">
          <div class="login-feature-icon" aria-hidden="true"><svg viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 11h36v28H28L18 48v-9h-8z"/><path d="M18 21h20M18 29h14"/></svg></div>
          <div><h3>Fast, clear updates</h3><p>Change owners, status and<br>dates without losing context.</p></div>
        </div>
      </div>
      <div class="login-ownership"><span>Clear ownership · Fewer missed handoffs · One source of truth</span></div>
    </div>
    <div class="login-visual-footer"><div class="login-copyright">© {{ now()->year }} FlowTrack</div></div>
  </section>
  <section class="login-form-wrap">
    <form id="loginForm"
      class="login-form"
      method="POST"
      action="{{ route('flowtrack.login.store') }}"
      data-native-secure-login
      autocomplete="off"
      novalidate>
      @csrf
      @if($flowtrackReplaceSession)<input type="hidden" name="replace_session" value="1">@endif
      <div class="login-form-brand"><div class="login-logo {{ !empty($flowtrackBranding['logoUrl']) ? 'has-brand-image' : '' }}" data-flowtrack-brand-mark>@if(!empty($flowtrackBranding['logoUrl']))<img class="flowtrack-brand-image" src="{{ $flowtrackBranding['logoUrl'] }}" alt="FlowTrack logo">@else FT @endif</div><span>FlowTrack</span></div>
      <h2>Welcome back</h2>
      <p class="login-subtitle">Sign in to continue to your workspace.</p>
      @if($flowtrackLoginError)<div id="loginError" class="login-error" role="alert">{{ $flowtrackLoginError }}</div>@endif
      <div class="login-field"><label for="loginEmail">Work email</label><input id="loginEmail" name="email" placeholder="name@company.com" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="email" type="email" required></div>
      <div class="login-field"><label for="loginPassword">Password</label><div class="login-password-wrap"><input id="loginPassword" name="password" placeholder="Enter your password" autocomplete="off" type="password" required></div></div>
      <div class="login-options"><label class="login-remember"><input id="loginRemember" name="remember" value="1" type="checkbox"><span>Remember me</span></label><button id="forgotPasswordLink" class="login-link" type="button">Forgot password?</button></div>
      <div id="loginSessionAlert" class="login-session-alert {{ $flowtrackReplaceSession ? '' : 'hidden' }}" role="status" aria-live="polite">
        <div class="login-session-icon" aria-hidden="true"><svg viewBox="0 0 36 42" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 2 31 8v11c0 9-5.4 16-13 20C10.4 35 5 28 5 19V8z"/><rect x="13" y="17" width="10" height="9" rx="2"/><path d="M15 17v-3a3 3 0 0 1 6 0v3M18 20v3"/></svg></div>
        <div><h3>Another active session was found</h3><p>To continue on this device, sign out your other active sessions.</p><label class="login-session-choice"><input type="radio" checked aria-label="Sign out other devices and continue here"><span>Sign out other devices and continue here</span></label><div class="login-session-help">Enter your password again. It is submitted directly to Laravel over HTTPS and is never handled by FlowTrack JavaScript.</div><a class="login-link login-session-cancel" href="{{ route('login') }}">Cancel and keep existing session</a></div>
      </div>
      <div id="loginInlineStatus" class="login-inline-status" role="status" aria-live="polite"></div>
      <button id="loginBtn" class="login-submit" type="submit">{{ $flowtrackReplaceSession ? 'Sign out other devices & sign in' : 'Sign in' }}</button>
      <div class="login-access-help">Need access? <button id="contactAdminLink" class="login-link" type="button">Contact your workspace administrator.</button></div>
      <div class="login-footer" aria-label="Login information">
        <button class="login-link" type="button" data-login-info="Help">Help</button>
        <button class="login-link" type="button" data-login-info="Privacy">Privacy</button>
        <button class="login-link" type="button" data-login-info="Terms">Terms</button>
        <span class="login-footer-divider" aria-hidden="true"></span>
        <span class="login-secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="6" y="10" width="12" height="10" rx="2"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/></svg>Secure, encrypted access</span>
      </div>
    </form>
  </section>
</div>
