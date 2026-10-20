# WP Subscription Gate — Embedded Login Shortcode Design

Status: **Approved for implementation** (new feature branch, after `feature/portable-settings` merges)
Target version: **1.3.0** (one bump for the new branch, per project rule)

---

## 1. Goal

Replace the current button-only `[wpsg_login]` shortcode with a real, embeddable
WordPress login form. Visitors authenticate on a normal site page without ever
seeing `wp-login.php`, matching the old Pie Register login-page experience.

---

## 2. Settled decisions

| Decision | Choice | Rationale |
|---|---|---|
| Submit mechanism | Same-page POST (PRG pattern) | Matches Pie Register; no `/wp-admin/` URL leaks in the form action |
| Forgot password | Deferred (no link in v1) | Avoids revealing `wp-login.php` and avoids a large sub-feature |
| Shortcode | Replace `[wpsg_login]` | One shortcode is enough; logged-in state stays the same |
| Post-login redirect | Honor global "After login, send users to" setting | Simple, predictable, already built |
| Brute-force protection | Built-in throttle with **generous** defaults | Last-resort safety; sites can use Wordfence etc. for stricter control |
| Remember me default | Unchecked | Privacy/security-friendly |
| Login field label | "Username or Email Address" | Mirrors WordPress default; both are supported |
| Registration link | Omit in v1 | Users find Create Account via the site menu |
| Per-shortcode redirect override | Defer | Global setting is enough for v1 |

---

## 3. Requirements

### Functional

- `[wpsg_login]` renders a login form for logged-out visitors:
  - **Username or Email Address** (single field; accepts username, full email,
    or bare email prefix via the existing `authenticate` filter)
  - **Password**
  - **Remember me** checkbox (unchecked by default)
  - **Log In** submit button
- Logged-in visitors see the existing view:
  - "You are already logged in."
  - **Manage Account** → Account page
  - **Go to Webmail** → webmail URL
- On submit:
  - Verify nonce
  - Apply generous throttle check
  - Call `wp_signon()` with `remember` flag
  - On success: redirect to `wpsg_after_login_url()` (fallback `home_url('/')`)
  - On failure: redirect back to the login page with a generic error flag
    (PRG — no re-submission on refresh)
- Generic error message; never reveal whether the username or password was wrong
- No forgot-password link in v1
- No registration link in v1

### Security

- CSRF: `wp_nonce_field()` / `wp_verify_nonce()`
- Open redirect: use `wpsg_after_login_url()`, already `wp_validate_redirect()`-safe
- Brute force: transient-based throttle
  - Generous defaults: **10 failed attempts per IP per 15 minutes**, then a
    **15-minute lockout**
  - Filterable: `wpsg_login_throttle_limit`, `wpsg_login_throttle_window`
- Input handling: `sanitize_text_field(wp_unslash())` for username; password
  passed raw to `wp_signon()`

### UX / Design

- Reuse existing `.wpsg-*` styles; add `.wpsg-login-form` styles to `register.css`
- Form looks consistent with `[wpsg_register]`
- All strings translation-ready (`WPSG_TEXT_DOMAIN`)
- Accessible labels and error output

### Caching requirement (documentation + site config)

The login page contains a nonce, so it **must be excluded from page caching**
(e.g., W3 Total Cache). Document this in README and recommend admins exclude
their Login page from cache.

---

## 4. Technical design

### 4.1 Shortcode rendering

`wpsg_login_shortcode()` (replaces the current button-only implementation):

```php
if (is_user_logged_in()) {
    // existing logged-in view unchanged
}

// logged-out form:
<form class="wpsg-login-form" method="post" action="">
    <?php wp_nonce_field('wpsg_login', 'wpsg_login_nonce'); ?>
    <input type="hidden" name="wpsg_login_submit" value="1" />
    <input type="hidden" name="wpsg_login_redirect" value="<?php echo esc_url(get_permalink()); ?>" />

    <label>Username or Email Address</label>
    <input type="text" name="wpsg_login_username" required autocomplete="username" />

    <label>Password</label>
    <input type="password" name="wpsg_login_password" required autocomplete="current-password" />

    <label><input type="checkbox" name="wpsg_login_remember" value="1" /> Remember me</label>

    <button type="submit" class="wpsg-btn wpsg-btn-primary">Log In</button>

    <?php /* render error when ?wpsg_login=failed or ?wpsg_login=locked */ ?>
</form>
```

Notes:

- `action=""` posts back to the current page URL (same-page POST).
- Error state is read from the query string after the PRG redirect and rendered
  as a styled message.

### 4.2 Form handler

Hook on `init` (runs before any output):

```php
add_action('init', 'wpsg_maybe_handle_login_post');

function wpsg_maybe_handle_login_post() {
    if (!isset($_POST['wpsg_login_submit'])) return;
    if (is_user_logged_in()) return;

    // 1. Nonce
    if (!wp_verify_nonce($_POST['wpsg_login_nonce'] ?? '', 'wpsg_login')) {
        wpsg_login_redirect_failure('failed');
    }

    // 2. Throttle
    if (wpsg_login_throttle_is_locked()) {
        wpsg_login_redirect_failure('locked');
    }

    // 3. Credentials
    $username = sanitize_text_field(wp_unslash($_POST['wpsg_login_username'] ?? ''));
    $password = wp_unslash($_POST['wpsg_login_password'] ?? '');
    $remember = !empty($_POST['wpsg_login_remember']);

    if ($username === '' || $password === '') {
        wpsg_login_redirect_failure('failed');
    }

    // 4. Authenticate
    $user = wp_signon([
        'user_login'    => $username,
        'user_password' => $password,
        'remember'      => $remember,
    ], is_ssl());

    if (is_wp_error($user)) {
        wpsg_login_throttle_record_failure();
        wpsg_login_redirect_failure('failed');
    }

    // 5. Success
    wpsg_login_throttle_reset();
    $redirect = wpsg_after_login_url();
    if (!$redirect) {
        $redirect = home_url('/');
    }
    wp_safe_redirect($redirect);
    exit;
}
```

`wpsg_login_redirect_failure()`:

- Redirects back to `$_POST['wpsg_login_redirect']` (validated same-site) with
  `?wpsg_login=failed` or `?wpsg_login=locked`
- Uses `wp_safe_redirect()` + `exit`

### 4.3 Throttle helpers

Transient-based, keyed by a hash of the visitor IP:

- `wpsg_login_throttle_key()` — `wpsg_login_fail_<ip_hash>`
- `wpsg_login_throttle_record_failure()` — increments a counter transient
- `wpsg_login_throttle_is_locked()` — true when the counter exceeds the limit
- `wpsg_login_throttle_reset()` — clears failures on success

Defaults (filterable):

```php
apply_filters('wpsg_login_throttle_limit', 10);   // failures before lockout
apply_filters('wpsg_login_throttle_window', 900); // 15 minutes, in seconds
```

### 4.4 Interaction with existing features

- **Email-prefix login:** no new logic needed. The existing `authenticate` filter
  already converts `alice` → `alice@<site-domain>` and `wp_signon()` invokes it.
- **Post-login redirect:** uses the existing `wpsg_after_login_url()` setting.
- **Menu visibility:** unchanged. Sites can hide their Login page for logged-in
  users via the existing toggle.
- **Assets:** `register.css` is already enqueued for `wpsg_login` pages; add the
  form styles to it.

### 4.5 Files to change

| File | Change |
|---|---|
| `wp-subscription-gate.php` | Replace `wpsg_login_shortcode()`; add `init` handler and throttle helpers |
| `assets/register.css` | Add `.wpsg-login-form` styles |
| `README.md` | Document `[wpsg_login]` as a form; add page-cache exclusion note |

No new settings fields are required.

---

## 5. Deferred / future

- Forgot-password shortcode or link
- "Need an account? Create one" link (needs a registration-page URL setting)
- Per-shortcode redirect override (`[wpsg_login redirect="..."]`)
- AJAX submission
- Localized inline JS strings and a `languages/` `.pot` template

---

## 6. Acceptance criteria

1. `[wpsg_login]` renders a working login form for logged-out visitors.
2. Form submits to the same page; no `wp-admin` URL appears in the page source.
3. Successful login redirects to the configured post-login destination.
4. Failed login redirects back with a generic error; refresh does not re-submit.
5. Nonce, throttle, and redirect validation are in place.
6. Logged-in visitors see Manage Account / Go to Webmail.
7. `php -l` clean; shortcode renders correctly via `wp-load.php` CLI smoke test.
8. README documents the caching exclusion requirement.
