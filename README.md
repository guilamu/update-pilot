# Update Pilot

[![Latest Release](https://img.shields.io/github/v/release/guilamu/update-pilot?color=blue)](https://github.com/guilamu/update-pilot/releases) [![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-green.svg)](LICENSE) [![WordPress: 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-blue.svg)](https://wordpress.org) [![PHP: 8.0+](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

Take command of WordPress auto-updates: choose what updates, schedule when, delay risky releases, and get a truthful log of everything that happened.

## Update Control

- Choose what updates on its own: WordPress core (minor, major and development branches separately), plugins, themes and translation files
- Exclude individual plugins and themes, with the exclusion list and the native **Auto-updates** column kept in agreement in both directions
- Leave anything unmanaged and Update Pilot steps aside completely, rather than forcing updates off behind WordPress's back
- Import an existing Companion Auto Update configuration without touching a single row of its data

## Scheduling & Safety

- Set a maintenance window — anything Update Pilot manages updates only inside it, including during the update passes WordPress starts on its own; windows may cross midnight. Types you leave unmanaged keep WordPress's own behaviour, window included
- Exclude days of the week, so "never on a Friday" is one checkbox
- Ask for an update pass at a chosen hour and recurrence, from hourly to monthly, without taking over any WordPress cron event
- Hold a new release for 1 to 90 days after it first appears, for plugins, themes and — if you insist — core
- See how late WP-Cron actually is, and the exact system cron line to fix it

## Visibility & Alerts

- A log of real update events: version before, version after, what triggered it, and whether it worked — read from WordPress itself, never inferred from file dates
- Failed updates are recorded and can be e-mailed to you, which is the one thing core does not really tell you
- A dashboard widget showing the last seven updates, and a Status screen that names whatever is blocking updates
- Environment checks for `DISALLOW_FILE_MODS`, `AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE`, `DISABLE_WP_CRON`, third-party filters, file ownership and version control checkouts
- A warning when the core update-check events have been left on a slower recurrence by a plugin that has since been removed — a new version can otherwise go unseen for a week
- A compatibility report showing how many WordPress releases each plugin's author is behind on their "tested up to" declaration, counted against the real release history rather than by subtracting version numbers; plugins that declare nothing, and plugins that are not on wordpress.org, are reported as such rather than called outdated
- A **Send a test e-mail** button, so notification delivery is proven before a failure depends on it
- Site Health integration, so a blocked site says so where administrators already look

## Key Features

- **Never updates anything itself:** WordPress performs every update; Update Pilot decides eligibility, timing and reporting around it
- **Honest about WP-Cron:** a chosen hour is an intention unless a real cron calls `wp-cron.php`, and the Status screen measures the difference
- **Multilingual:** works with content in any language
- **Translation-Ready:** all strings are internationalized, French included
- **Secure:** every write is POST with a nonce and a dedicated `manage_update_pilot` capability, every query is prepared, every output escaped, and `wp-config.php` is never modified
- **GitHub Updates:** automatic updates from GitHub releases

## Requirements

- WordPress 6.5 or higher
- PHP 8.0 or higher
- Updates require `DISALLOW_FILE_MODS` and `AUTOMATIC_UPDATER_DISABLED` to be unset or false in `wp-config.php`
- A chosen hour requires a system cron calling `wp-cron.php`; with `DISABLE_WP_CRON` set and no system cron, nothing ever runs

## Installation

1. Upload the `update-pilot` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Update Pilot** in the admin menu and review the Settings screen — on activation the plugin adopts whatever the site was already doing, so nothing changes until you change it
4. Open **Update Pilot → Status** to confirm nothing in the environment is blocking updates
5. Migrating from Companion Auto Update? Install Update Pilot **while it is still active**, accept the import notice, check the result, and only then deactivate it — deactivating that plugin drops its tables and takes its settings with them. Afterwards, the Status screen will tell you if it left the core update checks on a slower recurrence

## FAQ

### Nothing updates. Why?

Open **Update Pilot → Status**. The usual causes are `DISALLOW_FILE_MODS` or `AUTOMATIC_UPDATER_DISABLED` in `wp-config.php`, a maintenance window that excludes the current time, or WP-Cron never firing on a low-traffic site.

### I get no e-mails.

Failure alerts are on by default, success mails are off, so a run with nothing to report sends nothing. Check the recipients field and your site's mail delivery.

### The chosen hour is not respected.

WP-Cron only runs when someone visits the site. Add a system cron; the Status screen shows the command.

### Can I override a decision in code?

Yes, with the `update_pilot_decision` filter:

```php
add_filter( 'update_pilot_decision', function ( $verdict, $item, $settings ) {
    if ( 'plugin' === $item['type'] && 'akismet/akismet.php' === $item['id'] ) {
        $verdict['decision'] = 'deny';
        $verdict['reason']   = 'house rule';
    }

    return $verdict;
}, 10, 3 );
```

### Can I change who gets notified?

Yes, with the `update_pilot_recipients` filter:

```php
add_filter( 'update_pilot_recipients', function ( $recipients ) {
    $recipients[] = 'ops@example.com';

    return $recipients;
} );
```

### Does it work on multisite?

Version 1.0 manages the current site only and says so on screen. Network-wide settings are planned for 2.0.

## Project Structure

```
.
├── update-pilot.php               # Main plugin file: header, constants, loading, activation
├── uninstall.php                  # Opt-in data removal
├── README.md
├── admin
│   ├── css
│   │   └── admin.css              # Styles for the four screens
│   └── js
│       └── admin.js               # Dims fields whose parent option is off
├── includes
│   ├── class-settings.php         # Settings and state, validation, native option sync
│   ├── class-policy.php           # Eligibility engine (pure) and the six core filters
│   ├── class-scheduler.php        # Own cron event, window, recurrences, cron lateness
│   ├── class-logger.php           # Log table, writes and retention
│   ├── class-log-repository.php   # Log queries, filters and pagination
│   ├── class-listeners.php        # Records real update results from core hooks
│   ├── class-notifier.php         # Composes and sends the e-mails
│   ├── class-compatibility.php    # "Tested up to" reporting for installed plugins
│   ├── class-diagnostics.php      # Environment checks and Site Health
│   ├── class-migrator.php         # Import from Companion Auto Update
│   ├── class-admin.php            # Menu, screens, forms, dashboard widget
│   ├── class-github-updater.php   # GitHub auto-updates
│   └── Parsedown.php              # Markdown parser used by the updater
└── languages
    ├── update-pilot-fr_FR.mo      # French translation (binary)
    ├── update-pilot-fr_FR.po      # French translation (source)
    └── update-pilot.pot           # Translation template
```

## Changelog

### 1.0.0 - 2026-08-17

Initial release.

- Eligibility engine for plugins, themes, core branches and translations, with per-item exclusions kept in sync with the native auto-update options
- Releases that wordpress.org has flagged with `disable_autoupdate` — pulled, or found harmful — are never installed, whatever the rest of the policy allows
- Maintenance window with weekday selection, correct across midnight
- Scheduled update pass on the plugin's own cron event, leaving every WordPress core event untouched
- Safety delay of 1 to 90 days from a version's first sighting, covering plugins, themes and core
- Update log built from `automatic_updates_complete` and `upgrader_process_complete`, with versions before and after, trigger source and outcome
- E-mail alerts for failed updates, installed updates and available updates, sent one message per recipient so addresses are never disclosed to each other
- Status screen and Site Health test covering `DISALLOW_FILE_MODS`, `AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE`, `DISABLE_WP_CRON`, third-party filters, filesystem access, version control, WP-Cron lateness and the recurrence of the core update-check events
- Compatibility report and optional alert for plugins whose author has stopped declaring support for recent WordPress releases, counted against the real release history; plugins with no declaration, and plugins outside wordpress.org, are reported as such
- Test e-mail button on the Status screen
- Non-destructive import of Companion Auto Update settings
- `manage_update_pilot` capability, grantable to editors and authors; running an update pass additionally requires permission to install updates

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/update-pilot/security/advisories/new). Do not open a public issue for security reports.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/update-pilot).

For translations, the plugin uses WordPress i18n. You can contribute translations by editing the `.po` files in the `languages/` directory and generating the corresponding `.mo` files with the `wp i18n` CLI commands.

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) — see the [LICENSE](LICENSE) file for details.

### Third-party components

`includes/Parsedown.php` is [Parsedown](https://github.com/erusev/parsedown) by Emanuil Rusev, used to render this README inside the WordPress "View details" popup. It is loaded only when that popup is opened, and it only ever parses this plugin's own README. Parsedown is distributed under the MIT licence, which is compatible with the AGPL and requires the notice below to travel with the code:

```
The MIT License (MIT)

Copyright (c) 2013-2018 Emanuil Rusev, erusev.com

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```

---

Made with love for the WordPress community
