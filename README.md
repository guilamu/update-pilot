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
│       └── admin.js               # Dims fields whose parent option is off
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

### 1.1.5 - 2026-08-18

Admin notices were drawn edge to edge, across a page whose content is centred.

- The `.wp-header-end` marker now sits inside the 800px column. WordPress moves every notice on a screen to just after that marker — ours and any other plugin's — so with it above the column, notices were being lifted out of it and stretched the full width of the page

### 1.1.4 - 2026-08-18

Exclusions and Status now have sections of their own, as the settings screen does.

- Exclusions is split into **Plugins** and **Themes**; Status into **Schedule**, **Pending updates**, **Environment** and **Plugin compatibility**
- A panel no longer repeats its own tab as a heading. **Last 30 days** keeps its heading and sits under Schedule, where it belongs
- What acts on the whole screen stays outside the sections and reachable from any of them: the save button on Exclusions, and the three buttons under **Actions** on Status
- As on the settings screen, the sections are a convenience of the browser's: without JavaScript every panel shows at once and the screen is the one long page it was before

### 1.1.3 - 2026-08-18

The settings screen stood a little tighter than the other three.

- Its title now sits the same distance above its tabs as everywhere else. The second row of tabs makes that header taller, rather than the title moving up to hold the header at a fixed height

### 1.1.2 - 2026-08-18

An update run from the Status screen left the plugin it had just updated switched off.

- `Plugin_Upgrader::upgrade()` deactivates the plugin it is about to replace, silently, unless the request is a cron one — core's own comment says a browser is then required to switch it back on, and core does that from a path a pass of ours does not take. So **Update now** and **Run an update pass now** installed the new version and left the plugin deactivated. Update Pilot updating itself switched itself off, taking its own menu with it. Both buttons now switch back on whatever the upgrader switched off
- Scheduled runs were never affected: cron is precisely the case core exempts
- A plugin that cannot be switched back on again — which normally means the new version is broken — is now reported on screen instead of disappearing quietly. Reactivation runs core's fatal-error check, so a broken release stays off rather than taking the site with it

### 1.1.1 - 2026-08-18

The four screens now look like one plugin.

- Exclusions, Log and Status wear the same chrome as the settings screen: the plugin's name over a row of tabs moving between the four screens, the current one underlined, and the same 800px column beneath
- The settings screen keeps its five sections, now on a second row under the first. That row is still a tablist switching panels in place; the row above it is made of links, and loads a screen
- One list of screens behind both the menu and the tabs, so a screen cannot exist in one and be missing from the other

### 1.1.0 - 2026-08-18

An update held back can now be installed on the spot, without changing the rule that was holding it.

- **Update now** on each held row of the **Pending updates** table on the Status screen. It applies to that item, on that occasion: nothing is written to the settings, and the next pass judges everything by the rules again
- WordPress still performs the installation. The pass runs exactly as the scheduled one does, with every other item refused for its duration, so asking for one plugin cannot quietly install three
- The log records these as **Forced by hand** rather than as automatic, because the schedule did not choose them
- No button is offered where pressing it would do nothing: a release wordpress.org has withdrawn is refused before the policy is consulted, and the column is absent entirely for a user who may read this screen but not install updates. `WP_AUTO_UPDATE_CORE` still outranks the button

### 1.0.3 - 2026-08-18

The Status screen reported the version a plugin had yesterday.

- The **Plugin compatibility** table reads each plugin's name and version from the site itself on every load, instead of from the day-long cache. Only the compatibility wordpress.org declares is cached, because only that costs a network request — so a plugin updated since the last check no longer shows its previous version, sometimes for a full day
- Plugins deleted since the last check are left out of the table rather than listed with a verdict about software that is no longer installed

### 1.0.2 - 2026-08-18

Site Health reported a critical security issue on any site where Update Pilot was doing its job, and the plugin could not see its own new releases.

- The imaginary plugin and theme Site Health passes through the eligibility filters are recognised and left to core's own answer, instead of being held back by the maintenance window or the safety delay and reported as broken auto-updates
- Those invented items no longer have a first sighting written to the state option every time the Site Health screen is opened
- **Check for updates now** clears the plugin's own twelve-hour cache of the GitHub release before asking WordPress to look again. It forced the core, plugin and theme checks out to the network, then answered them from a cached release — so a new version of Update Pilot itself stayed invisible for up to twelve hours, however often the button was pressed

### 1.0.1 - 2026-08-17

An update held back by the safety delay was only admitted to on the Exclusions screen, and only as a date. It is now said everywhere, with a countdown.

- The Exclusions screen counts the days left as well as naming the date a held release comes due
- A **Pending updates** section on the Status screen lists everything on offer and why each item has or has not been installed
- The daily available-updates e-mail is split into *Waiting* and *Ready to install*, and gives the reason next to each held update
- Releases wordpress.org has withdrawn are reported as such instead of appearing eligible, matching what the eligibility filter has always done with them
- One shared collector behind all three, so the screens and the e-mail cannot drift apart — and none of them starts a delay's countdown by being looked at

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
