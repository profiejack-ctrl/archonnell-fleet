<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#07203F">
    <title>Sign In | Archon Nell Incorporated</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --palette-midnight: #02000D;
            --palette-navy: #07203F;
            --palette-sand: #EBDED4;
            --palette-clay: #D9AA90;
            --palette-terracotta: #A65E46;

            --bg: #07203F;
            --card-bg: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --input-border: #e2e8f0;
            --input-focus: #07203F;
            --btn-bg: #02000D;
            --btn-hover: #07203F;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: radial-gradient(circle at 50% 30%, #0e2b52 0%, #07203F 55%, #02000D 100%);
            color: var(--text-dark);
            padding: 24px 16px;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .login-card {
            width: 100%;
            background: var(--card-bg);
            border-radius: 16px;
            padding: 38px 36px 36px;
            box-shadow: 0 20px 45px -10px rgba(2, 0, 13, 0.45), 0 0 0 1px rgba(235, 222, 212, 0.25);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .brand-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 24px;
        }

        .brand-logo-img {
            width: 350px;
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
            object-fit: contain;
        }

        .subtitle {
            margin-top: 14px;
            font-size: 0.86rem;
            color: #4b5563;
            font-weight: 500;
            letter-spacing: -0.01em;
        }

        .alert-box {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: 9px;
            font-size: 0.83rem;
            margin-bottom: 18px;
            line-height: 1.4;
        }

        .alert-error {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            color: #c53030;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-group label {
            font-size: 0.84rem;
            font-weight: 600;
            color: #1e293b;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-left {
            position: absolute;
            left: 14px;
            width: 17px;
            height: 17px;
            color: #94a3b8;
            pointer-events: none;
            transition: color 0.18s ease;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 10px 14px 10px 42px;
            font-size: 0.88rem;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
            border: 1px solid var(--input-border);
            border-radius: 8px;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .form-control::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .form-control:focus {
            border-color: var(--palette-navy);
            box-shadow: 0 0 0 3px rgba(7, 32, 63, 0.12);
        }

        .input-wrapper:focus-within .input-icon-left {
            color: var(--palette-navy);
        }

        .password-toggle-btn {
            position: absolute;
            right: 8px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            color: #94a3b8;
            border-radius: 6px;
            cursor: pointer;
            transition: color 0.18s ease, background 0.18s ease;
        }

        .password-toggle-btn:hover {
            color: var(--palette-navy);
            background: rgba(7, 32, 63, 0.05);
        }

        .password-toggle-btn svg {
            width: 18px;
            height: 18px;
        }

        .btn-submit {
            margin-top: 6px;
            width: 100%;
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--palette-midnight);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 0.92rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 14px rgba(2, 0, 13, 0.25);
        }

        .btn-submit:hover {
            background: var(--palette-navy);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(7, 32, 63, 0.35);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit svg {
            width: 17px;
            height: 17px;
            transition: transform 0.18s ease;
        }

        .btn-submit:hover svg {
            transform: translateX(3px);
        }

        .footer-copyright {
            margin-top: 24px;
            font-size: 0.76rem;
            color: #64748b;
            text-align: center;
            letter-spacing: 0.01em;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 30px 24px;
            }
            .brand-logo-img {
                width: 210px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Brand Header -->
            <div class="brand-header">
                @php
                    $logoImgPath = public_path('images/logo.png');
                    if (!file_exists($logoImgPath)) {
                        $logoImgPath = public_path('logo.png');
                    }
                    $logoSrc = file_exists($logoImgPath)
                        ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoImgPath))
                        : '/images/logo.png';
                @endphp
                <img src="{{ $logoSrc }}" alt="Archon Nell Incorporated" class="brand-logo-img">
                <p class="subtitle">Performance & Development Management</p>
            </div>

            <!-- Status & Error Flash Messages -->
            @if (session('status'))
                <div class="alert-box alert-success" role="status">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            @if (isset($errors) && $errors->any())
                <div class="alert-box alert-error" role="alert">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <!-- Login Form -->
            <form method="POST" action="/login" class="login-form">
                @csrf

                <!-- Email Field -->
                <div class="form-group">
                    <label for="email">Email</label>
                    <div class="input-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                        </svg>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            placeholder="you@company.com"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <svg class="input-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            required
                        >
                        <button type="button" class="password-toggle-btn" id="passwordToggle" aria-label="Toggle password visibility">
                            <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Sign In Button -->
                <button type="submit" class="btn-submit">
                    <span>Sign In</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <!-- Footer Copyright -->
            <footer class="footer-copyright">
                &copy; 2026 Archon Nell. All rights reserved.
            </footer>
        </div>
    </div>

    <!-- Password Visibility Toggle Script -->
    <script>
        try { localStorage.removeItem('fleetops.lastActivity'); } catch (e) {}
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');
        const eyeIcon = document.getElementById('eyeIcon');

        passwordToggle.addEventListener('click', function () {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            if (isPassword) {
                passwordInput.setAttribute('type', 'text');
                eyeIcon.innerHTML = `
                    <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path>
                    <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path>
                    <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path>
                    <line x1="2" y1="2" x2="22" y2="22"></line>
                `;
            } else {
                passwordInput.setAttribute('type', 'password');
                eyeIcon.innerHTML = `
                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                `;
            }
        });
    </script>
</body>
</html>
