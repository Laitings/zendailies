<?php
// app/Views/layout/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$title = $title ?? 'Sign in • Zentropa Dailies';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/assets/img/zentropa-favicon.png">
    <link rel="stylesheet" href="/assets/css/main.css">
    <style>
        :root {
            --bg: #0b0c10;
            --panel: #111318;
            --accent: #3aa0ff;
            --text: #e9eef3;
            --muted: #9aa7b2;
            --border: #1f2430;
            --danger: #d62828;
        }

        html,
        body {
            height: 100%
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.45 system-ui, -apple-system, Segoe UI, Roboto, Inter, Arial sans-serif;
        }

        .auth-wrap {
            min-height: 100%;
            display: grid;
            place-items: center;
            padding: 28px
        }

        .auth-card {
            width: 100%;
            max-width: 460px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px 28px 26px;
            box-shadow: 0 14px 50px rgba(0, 0, 0, .35)
        }

        .zd-login-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px
        }

        .zd-login-brand img {
            height: 75px;
            width: auto;
            display: block
        }

        .zd-login-brand .brand-name {
            font-weight: 600;
            letter-spacing: .2px
        }

        h1 {
            margin: 6px 0 18px 0;
            font-size: 26px;
            font-weight: 650
        }

        form {
            display: grid;
            gap: 12px
        }

        label {
            display: grid;
            gap: 6px;
            margin: 0
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            background: #0f1218;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 13px;
            color: var(--text);
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="text"]:focus {
            border-color: #2b6fb0;
            box-shadow: 0 0 0 3px rgba(58, 160, 255, .15);
        }

        .btn {
            display: inline-block;
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #244a70;
            background: #18324a;
            color: var(--text);
            cursor: pointer;
            transition: transform .04s ease, background .15s ease, border-color .15s ease
        }

        .btn:hover {
            background: #1b3a57;
            border-color: #2a5e95
        }

        .btn:active {
            transform: translateY(1px)
        }

        .error {
            background: #2a1518;
            border: 1px solid #612b2f;
            color: #f2c2c6;
            padding: 10px 12px;
            border-radius: 10px;
            margin: 0 0 10px 0
        }

        .muted {
            color: var(--muted);
            font-size: 12px;
            margin-top: 6px
        }

        /* small screens tighten padding a bit */
        @media (max-width:480px) {
            .auth-card {
                padding: 22px 20px 18px;
                border-radius: 16px
            }

            h1 {
                font-size: 22px
            }
        }

        .pwd-wrap {
            position: relative
        }

        .pwd-wrap input {
            width: 100%;
            box-sizing: border-box;
            padding-right: 44px
        }

        /* keeps full width; room for eye */
        .pwd-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: 0;
            padding: 6px;
            cursor: pointer;
            color: var(--muted);
        }

        .pwd-toggle:hover {
            color: var(--text)
        }

        .pwd-toggle:focus {
            outline: 2px solid rgba(58, 160, 255, .35);
            outline-offset: 2px;
            border-radius: 8px
        }

        .pwd-toggle svg {
            width: 20px;
            height: 20px;
            display: block
        }

        /* --- Hard-lock UI font so Chrome/Edge don't swap it post-interaction --- */
        :root {
            /* Pick one concrete Windows font to avoid Blink switching between system fallbacks */
            --ui-font: "Segoe UI", Segoe UI, Arial, sans-serif;
            --ui-size: 14px;
            --ui-lh: 1.45;
        }

        /* Make the whole page + controls use the SAME font, size, weight */
        html,
        body {
            font-family: var(--ui-font);
            font-size: var(--ui-size);
            line-height: var(--ui-lh);
        }

        input,
        select,
        textarea,
        button {
            font-family: var(--ui-font) !important;
            font-size: var(--ui-size) !important;
            line-height: var(--ui-lh) !important;
            font-weight: 400 !important;
            letter-spacing: .2px !important;
            -webkit-appearance: none;
            appearance: none;
        }

        /* Keep dark theme + lock typography when Blink applies autofill/focus */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text);
            caret-color: var(--text);
            -webkit-box-shadow: 0 0 0 1000px #0f1218 inset;
            box-shadow: 0 0 0 1000px #0f1218 inset;
            border: 1px solid var(--border);
            border-radius: 12px;

            /* Force same font metrics during autofill */
            font-family: var(--ui-font) !important;
            font-size: var(--ui-size) !important;
            line-height: var(--ui-lh) !important;
            font-weight: 400 !important;
            letter-spacing: .2px !important;

            transition: background-color 9999s ease-out 0s;
        }

        /* Crucial: Blink renders autofill text via ::first-line—force the same font there */
        input:-webkit-autofill::first-line {
            font-family: var(--ui-font) !important;
            font-size: var(--ui-size) !important;
            line-height: var(--ui-lh) !important;
            font-weight: 400 !important;
            letter-spacing: .2px !important;
            -webkit-text-fill-color: var(--text) !important;
        }

        /* Hide Chrome’s credentials chip that can overlap the eye icon */
        input::-webkit-credentials-auto-fill-button {
            visibility: hidden;
            display: none;
        }

        /* Nudge text rendering to stay stable on Blink */
        html {
            -webkit-text-size-adjust: 100%;
            text-size-adjust: 100%;
        }

        body,
        input,
        button,
        select,
        textarea {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
    </style>

</head>

<body>
    <div class="auth-wrap">
        <div class="auth-card">
            <?= $sections['content'] ?? '' ?>
        </div>
    </div>
</body>

</html>