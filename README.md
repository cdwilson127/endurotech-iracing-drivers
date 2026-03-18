# Endurotech iRacing Drivers

WordPress plugin that displays iRacing driver statistics for the Endurotech Racing team.

**Version:** 1.9.0  
**Requires:** PHP 7.4+, WordPress 5.8+

---

## Installation

1. Download the ZIP from [GitHub Releases](https://github.com/cdwilson127/endurotech-iracing-drivers/releases).
2. In WordPress Admin → Plugins → Add New → Upload Plugin, select the ZIP.
3. Activate the plugin.
4. Go to **iRacing Drivers → Manage Drivers** to add your team.
5. Place `[iracing_drivers]` on any page.

### Updating (no reinstall needed)

When a new release is published on GitHub, WordPress will show an **Update Available** notice in your Plugins screen — just like any other plugin. Click **Update Now**. All settings and driver data are stored in the database and are never touched by an update.

---

## Configuration

### iRacing Drivers → Settings

**API Credentials** — optional. Without them you manage drivers manually.

| Field | Description |
|---|---|
| Client ID | OAuth2 client ID from iRacing |
| Client Secret | OAuth2 client secret |
| iRacing Username | Your iRacing login email |
| iRacing Password | Your iRacing password |
| Team ID | Found in the iRacing member site URL for your team page |
| Cache Duration | Hours between live data refreshes (default 1) |

**Feature Toggles** — enable/disable every feature site-wide. These are the defaults; any can be overridden per-shortcode.

| Feature | Default |
|---|---|
| Card flip on hover | On |
| Animated stat counters | On |
| iRating trend badge (▲/▼) | On |
| Recently active indicator | On |
| Driver spotlight card | On |
| Race ticker | Off |
| Role filter bar | Off |
| Team summary bar | On |
| Last race result | On |
| Driver photos | On |
| Sim gear / setup | On |

**Display Style** — accent colour (default EDR yellow `#f1c40f`), card background, border radius, and subtitle text. Leave the subtitle blank for the default count, or type `none` to hide it entirely.

---

## Manage Drivers

Add drivers manually. Every field is optional — add what you have.

- **iRacing Customer ID** — links the driver to live API data (stats override manual values automatically)
- **Country / Flag** — dropdown generates the emoji flag automatically
- **Featured** — marks the driver as a spotlight/hero driver (shown above the main grid)
- **Display Order** — used when `sort_by="custom"` in the shortcode
- **Drag handle (≡)** — drag to reorder cards; Display Order updates automatically

### Sync from API

If API credentials are configured, **Sync Team Roster from iRacing API** imports all current team members as new driver profiles (existing profiles are unchanged).

---

## Shortcode

```
[iracing_drivers]
```

### Attributes

| Attribute | Default | Options | Description |
|---|---|---|---|
| `title` | Our Drivers | Any text | Section heading |
| `layout` | cards | cards, table | Card grid or compact table |
| `columns` | auto | auto, 1, 2, 3, 4 | Cards per row |
| `sort_by` | irating | irating, wins, starts, name, custom | Sort field |
| `sort_order` | desc | asc, desc | Sort direction |
| `accent` | auto | auto, red, blue, green, gold | Accent colour (`auto` = admin setting) |
| `card_style` | default | default, minimal | Minimal strips to essentials |
| `demo` | no | yes, no | Sample data — no API needed |

**Feature toggle attributes** — `yes`, `no`, or `inherit` (uses admin default):

`show_summary` · `show_last_race` · `show_photo` · `show_gear` · `card_flip` · `counters` · `show_trend` · `show_active` · `show_spotlight` · `show_ticker` · `show_filter`

`ticker_speed` — seconds per full scroll cycle (5–300, higher = slower, default 60). Overrides the admin setting.

**Stat column attributes** (always explicit):

`show_role` · `show_number` · `show_wins` · `show_starts` · `show_top5` · `show_laps`

### Examples

```
[iracing_drivers]
[iracing_drivers demo="yes"]
[iracing_drivers layout="table" show_gear="no"]
[iracing_drivers columns="3" sort_by="custom" card_flip="yes" show_ticker="yes"]
[iracing_drivers show_filter="yes" show_spotlight="yes" accent="red"]
```

---

## Features (v1.6)

- **3D flip on hover** — front shows stats; back reveals gear, bio, and last race
- **Driver spotlight** — featured drivers get a hero card above the main grid
- **iRating trend badge** — ▲/▼ badge showing change since last race
- **Recently active dot** — green indicator for drivers who raced in the last 30 days
- **Animated stat counters** — numbers count up when scrolled into view
- **Role filter bar** — filter cards by team role with one click
- **Race ticker** — scrolling latest results strip
- **Emoji flags** — auto-generated from ISO country code
- **Style editor** — accent colour, card background, border radius, subtitle text, all in admin
- **Auto image resize** — uploaded photos auto-cropped to 400×500 portrait
- **One-click updates** — GitHub release checker; update without reinstalling

---

## Security

- All API credentials are stored in `wp_options` using WordPress's own encryption layer.
- Passwords are hashed (SHA-256 + Base64) before being sent to iRacing's OAuth endpoint.
- All admin forms use WordPress nonces.
- All output is escaped with `esc_html`, `esc_attr`, `esc_url`.
- Capability checks (`manage_options`) on all admin actions.
