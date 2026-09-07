# Lightweight Cookieless Analytics

A minimal, privacy-friendly analytics plugin for WordPress. Tracks page views in real time without using cookies or storing personal data.

## Features

- **No cookies**: Completely cookieless tracking.
- **Lightweight**: Inline script using `navigator.sendBeacon()` (no blocking).
- **Real-time dashboard**: View live stats from the last 5 minutes.
- **Privacy-first**: IP addresses are hashed with a secret salt; no personal data is stored.
- **Clean uninstall**: Removes its database table and options when deleted.
- **No external dependencies**: Works out of the box.

## Installation

1. Download or clone this repository.
2. Upload the plugin folder to `/wp-content/plugins/`.
3. Activate the plugin through the WordPress admin panel.
4. Go to **Analytics** in the admin menu to see the real-time dashboard.

## Usage

- The plugin automatically starts tracking page views after activation.
- To exclude logged-in administrators from tracking, go to **Settings > General** and enable the option "Exclude admins from tracking".
- The dashboard updates automatically every 10 seconds and shows:
  - Total views (last 5 minutes)
  - Unique visitors (hashed IPs)
  - Top pages

## Privacy

- No cookies are set.
- No personal data (like IP address, user agent) is stored in plain text.
- IP addresses are anonymized using a one-way hash with a site-specific salt.
- The plugin complies with GDPR and similar privacy regulations.

## Uninstallation

Deleting the plugin from the WordPress admin will:
- Drop the custom database table.
- Remove all plugin options.
- Leave no leftover data.

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

## License

[GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
