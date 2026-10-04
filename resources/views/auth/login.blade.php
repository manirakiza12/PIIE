@extends('layouts.signin_page')

@section('content')
@php $demo = config('demo'); @endphp

<style>
  body { margin: 0; background: #0f2642; min-height: 100vh; }

  .login-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: linear-gradient(135deg, #0f2642 0%, #1a3c5e 50%, #0d1f35 100%);
  }

  .login-card {
    background: #fff;
    border-radius: 20px;
    padding: 48px 44px 40px;
    width: 100%;
    max-width: 440px;
    box-shadow: 0 30px 80px rgba(0,0,0,0.4);
  }

  .login-logo {
    text-align: center;
    margin-bottom: 6px;
  }

  .login-logo .logo-circle {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: #1a3c5e;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    overflow: hidden;
  }

  .login-logo .logo-circle img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  .login-logo h4 {
    color: #1a3c5e;
    font-weight: 700;
    font-size: 1.25rem;
    margin: 0 0 4px;
    text-transform: uppercase;
    letter-spacing: 1px;
  }

  .login-logo p {
    color: #6c8caa;
    font-size: 0.8rem;
    margin: 0 0 28px;
  }

  .login-label {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 1.2px;
    color: #1a3c5e;
    text-transform: uppercase;
    margin-bottom: 6px;
    display: block;
  }

  /* ===== PASSWORD INPUT WITH TOGGLE ===== */
  .password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
  }

  .password-wrapper .login-input {
    padding-right: 48px;
  }

  .password-toggle {
    position: absolute;
    right: 14px;
    background: none;
    border: none;
    color: #6c8caa;
    cursor: pointer;
    font-size: 1rem;
    padding: 6px 4px;
    transition: color 0.2s;
    z-index: 2;
  }

  .password-toggle:hover {
    color: #1a3c5e;
  }

  .login-input {
    border: 1.5px solid #dce4ef;
    border-radius: 8px;
    padding: 11px 14px;
    width: 100%;
    font-size: 0.92rem;
    color: #1a3c5e;
    background: #f5f8fc;
    box-sizing: border-box;
    transition: border-color .2s, background .2s;
    outline: none;
  }

  .login-input:focus {
    border-color: #1a3c5e;
    background: #fff;
  }

  /* ===== REMEMBER ME CHECKBOX ===== */
  .form-options-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 12px;
    flex-wrap: wrap;
    gap: 8px;
  }

  .remember-me {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    color: #4a5a72;
    cursor: pointer;
  }

  .remember-me input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #1a3c5e;
    cursor: pointer;
    margin: 0;
    flex-shrink: 0;
  }

  .forgot-link {
    font-size: 0.78rem;
    color: #6c8caa;
    text-decoration: none;
    transition: color 0.2s;
  }

  .forgot-link:hover {
    color: #1a3c5e;
    text-decoration: underline;
  }

  .quick-login-label {
    text-align: center;
    font-size: 0.75rem;
    color: #6c8caa;
    margin: 18px 0 10px;
  }

  .quick-login-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
    margin-bottom: 4px;
  }

  .ql-btn {
    border: 1.5px solid #1a3c5e;
    border-radius: 20px;
    padding: 5px 16px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .18s;
    background: #fff;
    color: #1a3c5e;
    letter-spacing: 0.3px;
  }

  .ql-btn:hover,
  .ql-btn.active {
    background: #1a3c5e;
    color: #fff;
  }

  .btn-signin {
    background: #1a3c5e;
    color: #fff;
    border: none;
    border-radius: 10px;
    width: 100%;
    padding: 13px;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    cursor: pointer;
    margin-top: 22px;
    transition: background .2s;
  }

  .btn-signin:hover { background: #0f2642; }

  .login-footer-links {
    text-align: center;
    margin-top: 16px;
    font-size: 0.78rem;
    color: #8a9ab0;
  }

  .login-footer-links a { color: #1a3c5e; text-decoration: none; }
  .login-footer-links a:hover { text-decoration: underline; }

  .error-msg {
    background: #fff0f0;
    border: 1px solid #f5c6cb;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.82rem;
    color: #c0392b;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* ===== SUCCESS MESSAGE ===== */
  .success-msg {
    background: #f0fff4;
    border: 1px solid #b8e6c8;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.82rem;
    color: #1a7a3a;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* ===== RESPONSIVE ===== */
  @media (max-width: 480px) {
    .login-card {
      padding: 32px 20px 28px;
    }
    .form-options-row {
      flex-direction: column;
      align-items: flex-start;
    }
    .quick-login-grid {
      gap: 6px;
    }
    .ql-btn {
      font-size: 0.7rem;
      padding: 4px 12px;
    }
  }
</style>

<div class="login-wrapper">
  <div class="login-card">

    {{-- Logo.
         `dark_logo` is UNSET on this installation, so the original expression
         `asset('assets/uploads/logo/'.get_settings('dark_logo'))` concatenated an
         empty string and produced `.../assets/uploads/logo` - the bare directory,
         which 404s and renders a broken-image icon on the sign-in page.

         Now: use the configured file only when it is set AND actually exists on
         disk, otherwise fall back to `dark-logo.png`, which ships in the repository.
         An empty or dangling setting can therefore never produce a broken logo
         again, on this install or any other.

         Presentation only. Nothing about authentication is touched. --}}
    @php
        $piieLoginLogoSetting = trim((string) get_settings('dark_logo'));
        $piieLoginLogoFile = ($piieLoginLogoSetting !== '' && is_file(public_path('assets/uploads/logo/'.$piieLoginLogoSetting)))
            ? $piieLoginLogoSetting
            : 'dark-logo.png';
    @endphp
    <div class="login-logo">
      <div class="logo-circle">
        <img src="{{ asset('assets/uploads/logo/'.$piieLoginLogoFile) }}" alt="PIIE logo"
             onerror="this.src='{{ asset('assets/uploads/logo/dark-logo.png') }}';">
      </div>
      <h4>{{ get_settings('system_title') ?: 'PIIE' }}</h4>
      <p>{{ get_settings('school_name') ?: 'Management System' }}</p>
    </div>

    {{-- Validation errors --}}
    @if ($errors->any())
      <div class="error-msg">
        <i class="bi bi-exclamation-circle"></i>
        {{ $errors->first() }}
      </div>
    @endif

    {{-- Success message (e.g., password reset) --}}
    @if(session('success'))
      <div class="success-msg">
        <i class="bi bi-check-circle"></i>
        {{ session('success') }}
      </div>
    @endif

    {{-- Status message (e.g., account disabled) --}}
    @if(session('status'))
      <div class="error-msg">
        <i class="bi bi-info-circle"></i>
        {{ session('status') }}
      </div>
    @endif

    <form method="POST" action="{{ route('login') }}" id="loginForm">
      @csrf

      {{-- Email --}}
      <div class="mb-3">
        <label class="login-label">Email</label>
        <input type="email" name="email" id="emailInput" class="login-input"
               value="{{ old('email') }}" placeholder="Enter your email" required autofocus>
      </div>

      {{-- Password with Show/Hide Toggle --}}
      <div class="mb-1">
        <label class="login-label">Password</label>
        <div class="password-wrapper">
          <input type="password" name="password" id="passwordInput" class="login-input"
                 placeholder="Enter your password" required>
          <button type="button" class="password-toggle" id="togglePasswordBtn" 
                  onclick="togglePasswordVisibility()" aria-label="Toggle password visibility">
            <i class="bi bi-eye" id="passwordIcon"></i>
          </button>
        </div>
      </div>

      {{-- Remember Me & Forgot Password --}}
      <div class="form-options-row">
        <label class="remember-me">
          <input type="checkbox" name="remember" id="rememberCheckbox" 
                 {{ old('remember') ? 'checked' : '' }}>
          {{ get_phrase('Remember Me') }}
        </label>
        <a href="{{ route('password.request') }}" class="forgot-link">
          {{ get_phrase('Forgot password?') }}
        </a>
      </div>

      {{-- Quick Login Buttons --}}
      @if(!empty($demo['enabled']) && !empty($demo['accounts']))
      <div class="quick-login-label">Quick login as:</div>
      <div class="quick-login-grid">
        @foreach($demo['accounts'] as $i => $acc)
          <button type="button" class="ql-btn"
                  data-email="{{ $acc['email'] }}"
                  data-password="{{ $acc['password'] }}"
                  onclick="quickLogin(this)">
            {{ $acc['label'] }}
          </button>
        @endforeach
      </div>
      @endif

      <button type="submit" class="btn-signin">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
      </button>
    </form>

    <div class="login-footer-links">
      {{-- Help link.
         `help_link` is stored as `avanteontech.net` with NO SCHEME, so
         `href="avanteontech.net"` is a RELATIVE URL. The browser resolves it
         against the current origin and requests
         `http://127.0.0.1:8000/avanteontech.net`, which 404s.

         The value is left exactly as the institution configured it - silently
         rewriting it to `https://avanteontech.net` would be inventing an
         intention nobody stated. Instead the link is rendered only when the
         configured value is an ABSOLUTE url. A dead "Help" link on a sign-in page
         is worse than no Help link, and a schemeless value silently becoming a
         same-origin path is a mis-click hazard.

         Presentation only. Nothing about authentication is touched. --}}
    @php
        $piieHelpLink = trim((string) get_settings('help_link'));
        $piieHelpIsAbsolute = (bool) preg_match('#^(https?://|mailto:)#i', $piieHelpLink);
    @endphp
    @if($piieHelpIsAbsolute)
      <a href="{{ $piieHelpLink }}" target="_blank" rel="noopener noreferrer">Help</a>
    @endif
    </div>
  </div>
</div>

<script>
// ============================================================
// 1. TOGGLE PASSWORD VISIBILITY
// ============================================================
function togglePasswordVisibility() {
  const passwordInput = document.getElementById('passwordInput');
  const icon = document.getElementById('passwordIcon');
  
  if (passwordInput.type === 'password') {
    passwordInput.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    passwordInput.type = 'password';
    icon.className = 'bi bi-eye';
  }
}

// Allow toggling with keyboard (Enter/Space on the button)
document.addEventListener('DOMContentLoaded', function() {
  const toggleBtn = document.getElementById('togglePasswordBtn');
  if (toggleBtn) {
    toggleBtn.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        togglePasswordVisibility();
      }
    });
  }
});

// ============================================================
// 2. QUICK LOGIN
// ============================================================
function quickLogin(btn) {
  // Deactivate all buttons
  document.querySelectorAll('.ql-btn').forEach(function(b) {
    b.classList.remove('active');
  });
  // Activate clicked
  btn.classList.add('active');
  
  // Fill form fields
  document.getElementById('emailInput').value = btn.dataset.email;
  document.getElementById('passwordInput').value = btn.dataset.password;
  
  // Optionally auto-submit (uncomment if you want instant login)
  // document.getElementById('loginForm').submit();
}

// ============================================================
// 3. REMEMBER ME - Auto-check if email exists in localStorage
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
  const rememberCheckbox = document.getElementById('rememberCheckbox');
  const emailInput = document.getElementById('emailInput');
  
  // If there's a saved email, populate it and check the box
  const savedEmail = localStorage.getItem('savedLoginEmail');
  if (savedEmail) {
    emailInput.value = savedEmail;
    rememberCheckbox.checked = true;
  }
  
  // When form is submitted, save or clear the email
  document.getElementById('loginForm').addEventListener('submit', function() {
    if (rememberCheckbox.checked) {
      localStorage.setItem('savedLoginEmail', emailInput.value);
    } else {
      localStorage.removeItem('savedLoginEmail');
    }
  });
});

// ============================================================
// 4. ENTER KEY SUPPORT - Works natively with form
// ============================================================

// ============================================================
// 5. AUTO-FILL FOR DEVELOPMENT (Remove in production)
// ============================================================
// Uncomment below lines for quick testing
// document.addEventListener('DOMContentLoaded', function() {
//   document.getElementById('emailInput').value = 'superadmin@piie.test';
//   document.getElementById('passwordInput').value = 'password123';
// });
</script>
@endsection