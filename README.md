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
- Hold a new release for 1 to 90 days after publication, for plugins, themes and — if you insist — core
- See how late WP-Cron actually is, and the exact system cron line to fix it

## Visibility & Alerts

- A log of real update events: version before, version after, what triggered it, and whether it worked — read from WordPress itself, never inferred from file dates
- Failed updates are recorded and can be e-mailed to you, which is the one thing core does not really tell you
- A dashboard widget showing the last seven updates, and a Status screen that names whatever is blocking updates
- A **Pending updates** list on the Status screen, and a countdown on the Exclusions screen, so a release being held by the safety delay says how many days are left; the daily e-mail carries the same reasons, split into what is waiting and what is ready
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
│       ├── admin.js               # Dims fields whose parent option is off
│       └── update-core.js         # Corrects core's auto-update timing on WordPress Updates
├── includes
│   ├── class-settings.php         # Settings and state, validation, native option sync
│   ├── class-policy.php           # Eligibility engine (pure) and the six core filters
│   ├── class-scheduler.php        # Own cron event, window, recurrences, cron lateness
│   ├── class-logger.php           # Log table, writes and retention
│   ├── class-log-repository.php   # Log queries, filters and pagination
│   ├── class-listeners.php        # Records real update results from core hooks
│   ├── class-pending.php          # What is on offer and why it is waiting
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

### 1.2.4 - 2026-09-23

- The safety delay counts from the wordpress.org publication date when known, not from when this site first saw the version
- The last day of a delay is shown in hours ("6 hours left", not "1 day left")
- For a held update, core's "Automatic update scheduled in…" is replaced by Update Pilot's reason on the Plugins and WordPress Updates screens

### 1.2.3 - 2026-09-21

- Fixed the Details toggle showing `B8` and `BE` instead of its triangle: the character escape in the stylesheet was written wrong

### 1.2.2 - 2026-09-21

- A Log transcript now opens in a row of its own, across the whole table, instead of inside the narrow outcome column

### 1.2.1 - 2026-09-21

- A Log entry fits on one line: numeric timestamp (`21/09/26 04:53`), type beside the name, **Details** beside the outcome. Only an unusually long name still wraps
- The Log gets a 960px column rather than the 800px the other screens use. That width is for prose; this screen is a five-column table

### 1.2.0 - 2026-09-21

- Fixed the Log breaking the page: a download URL in an entry's outcome has nowhere to wrap, so the table grew to twice the width of the screen and scrolled the whole admin sideways
- A long transcript folds away behind **Details**. A failure's reason is short and stays in the open
- The **What** column no longer repeats the item's file path under its name. Search still matches it, and Exclusions still shows it
- Every screen gained the side margin it was missing below 840px

### 1.1.9 - 2026-08-24

- A release wordpress.org flags `disable_autoupdate` was reported as **withdrawn by wordpress.org**, which it is not. The flag means one thing only — do not install this one unattended. The release stays published and the Extensions screen offers it normally; a plugin genuinely pulled from the directory draws no update offer at all. The row now says wordpress.org has blocked unattended installation of this release
- **Update now** works on those rows again. It no longer goes through `WP_Automatic_Updater`, which core stops on the flag before any filter of ours is consulted, but through `Plugin_Upgrader` — the supervised install the flag is asking for, and the one the Extensions screen performs. Automatic passes still refuse the release, exactly as before
- Rebuilt the French translation binary, which had fallen eight strings behind its source

### 1.1.8 - 2026-08-23

- An update forced by hand from the Status screen was logged as **Automatic**. The marker saying a person asked for it was never read on the path that writes the entry, so **Forced by hand** — a label offered since 1.1.0 — could not appear. The marker is now read where the entry is actually written
- Entries already in the log keep the source they were written with: the column is stored at the time of the update, not worked out again when the log is read

### 1.1.7 - 2026-08-21

- **Status** is the screen the menu opens on, and **Pending updates** moved into it rather than sitting behind a tab. Settings keeps its `page=update-pilot` address
- The compatibility report no longer calls a plugin's absence from wordpress.org a failure to check it: `plugins_api()` returns the same error for both, so the 404 is now read off the response. The slug comes from core's update check where it exists, not from the folder name
- A plugin outside the directory is asked what it declares, and the figure is shown as self-declared rather than merged with wordpress.org's. The bundled GitHub updater stopped answering `tested` with whatever WordPress the site happens to run

### 1.1.6 - 2026-08-18

- A bare admin notice carries core's `margin: 5px 15px 2px`, and core takes the side margins back only inside `.wrap` — which these screens do not use. That rule is now applied where the notices actually land

### 1.1.5 - 2026-08-18

- The `.wp-header-end` marker moved inside the 800px column. WordPress moves every notice to just after it, so notices were being lifted out of the column and stretched across the page

### 1.1.4 - 2026-08-18

- Exclusions and Status gained sections of their own: **Plugins** and **Themes**; **Schedule**, **Pending updates**, **Environment** and **Plugin compatibility**
- What acts on the whole screen stays outside the sections and reachable from any of them. Without JavaScript every panel shows at once, as before

### 1.1.3 - 2026-08-18

- The settings title now sits the same distance above its tabs as on the other three screens; the second row of tabs makes the header taller instead

### 1.1.2 - 2026-08-18

- `Plugin_Upgrader::upgrade()` deactivates the plugin it is replacing unless the request is a cron one, so **Update now** and **Run an update pass now** installed the new version and left the plugin off — Update Pilot updating itself took its own menu with it. Both buttons now switch back on whatever the upgrader switched off
- Scheduled runs were never affected. A plugin that cannot be reactivated, which normally means a broken release, is reported on screen instead of disappearing quietly

### 1.1.1 - 2026-08-18

- Exclusions, Log and Status wear the same chrome as the settings screen: the plugin's name over a row of tabs, the current one underlined, and the same 800px column
- One list of screens behind both the menu and the tabs, so a screen cannot exist in one and be missing from the other

### 1.1.0 - 2026-08-18

- **Update now** on each held row of **Pending updates** installs that item on that occasion, without changing the rule holding it: nothing is written to the settings
- WordPress still performs the installation, with every other item refused for the run's duration. The log records these as **Forced by hand**
- No button is offered where pressing it would do nothing: withdrawn releases are refused before the policy is consulted, and `WP_AUTO_UPDATE_CORE` still outranks the button

### 1.0.3 - 2026-08-18

- The **Plugin compatibility** table reads each plugin's name and version from the site on every load, so a plugin updated since the last check no longer shows yesterday's version. Only the wordpress.org verdict stays cached, and deleted plugins are left out

### 1.0.2 - 2026-08-18

- The imaginary plugin and theme Site Health passes through the eligibility filters are left to core's own answer, instead of being held back and reported as broken auto-updates on any site doing its job
- **Check for updates now** clears the plugin's own twelve-hour cache of the GitHub release first, so a new version of Update Pilot is no longer invisible for up to twelve hours

### 1.0.1 - 2026-08-17

- A held update now says why it is waiting and how many days are left: on Exclusions, in a **Pending updates** section on Status, and in the daily e-mail, split into *Waiting* and *Ready to install*
- Releases wordpress.org has withdrawn are reported as such instead of appearing eligible
- One shared collector behind all three, so the screens and the e-mail cannot drift apart — and none of them starts a delay's countdown by being looked at

### 1.0.0 - 2026-08-17

Initial release.

- Eligibility engine for plugins, themes, core branches and translations, with per-item exclusions kept in sync with the native auto-update options; releases wordpress.org has flagged `disable_autoupdate` are never installed unattended, and are offered as a supervised install from the Status screen instead
- Maintenance window correct across midnight, weekday exclusions, a scheduled pass on the plugin's own cron event leaving core's events untouched, and a safety delay of 1 to 90 days from a version's first sighting
- Update log built from core's own hooks with versions before and after, trigger source and outcome; e-mail alerts for failed, installed and available updates, one message per recipient
- Status screen and Site Health test covering the constants, cron lateness and third-party filters; compatibility report for plugins whose author has stopped declaring support; Companion Auto Update import; `manage_update_pilot` capability

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
