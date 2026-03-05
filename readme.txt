=== Agenda Lite ===
Contributors: hostnauta
Tags: booking, appointments, calendar, scheduling, reservations
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress booking plugin with services, staff, availability, public booking pages, and admin management.

== Description ==

Agenda Lite (Free) provides a complete booking flow for WordPress:

- Service and staff management.
- Availability rules and scheduling.
- Public booking pages (service list + single service page).
- Customer area with email-based booking history.
- CSV exports and basic analytics.
- Manual payments (bank transfer / onsite payment).
- Anti-abuse protection to reduce mass/fraudulent attempts.

Pro features may appear in the interface (clearly labeled), but the Free plugin works fully on its own.

== Features ==

- Public booking flow with customizable fields.
- Service page with search, filters, and sorting.
- Admin calendar and booking management (edit/cancel/reschedule).
- Customers section: email-grouped history + CSV export.
- Onsite payment integrated (without online payment timeout logic).
- Global anti-abuse controls: limits per period, email code verification, automatic and manual blocks.
- SEO-friendly service pages (base fields and fallback metadata).
- Builder-safe widgets/blocks (stable placeholder in editor; real render in frontend):
  - Service page
  - Single service

== External Services ==

Agenda Lite only connects to external services if the administrator enables/configures them:

- Google reCAPTCHA v3
  - Purpose: validate public booking form submissions.
  - Data sent: token, optional remote IP.
  - Endpoint: https://www.google.com/recaptcha/api/siteverify
  - Script: https://www.google.com/recaptcha/api.js?render=...

- Google Tag Manager / Google Analytics / Google Ads / Meta Pixel
  - Loaded only if enabled in Integrations.
  - Endpoints/scripts:
    - https://www.googletagmanager.com/gtm.js?id=...
    - https://www.googletagmanager.com/gtag/js?id=...
    - https://www.googletagmanager.com/ns.html?id=...
    - https://connect.facebook.net/en_US/fbevents.js
    - https://www.facebook.com/tr?id=...

== Installation ==

1. Upload the folder to `/wp-content/plugins/agenda-lite/` or install from the plugin directory.
2. Activate the plugin from `Plugins`.
3. Go to `Agenda Lite > Settings` and configure currency, timezone, and base preferences.
4. Create services, assign staff, and publish your booking flow.

== Frequently Asked Questions ==

= Does Free work without Pro? =
Yes. Free is fully usable on its own.

= Are Pro features hidden? =
No. They are shown transparently and labeled as Pro.

= Does the plugin use CDNs for bundled assets? =
No. Bundled assets are served locally from `/assets/vendor`.

== Changelog ==

= 1.0.0 =
* First public stable release.
* Builder-safe widgets/blocks (stable editor placeholder; real frontend rendering).
* Customers section with email-based history, trash, and CSV export.
* Global anti-abuse system (limits, email code verification, automatic and manual blocks).
* Onsite payment integrated without online payment timeout cancellation.
* Frontend/backend UX improvements and security fixes (Plugin Check/PHPCS).
* Packaging aligned for WordPress.org and GitHub.

== Upgrade Notice ==

= 1.0.0 =
Initial stable release of Agenda Lite Free.
