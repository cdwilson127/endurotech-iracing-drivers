# Endurotech iRacing Drivers — WordPress Plugin

A lightweight WordPress plugin that pulls live driver statistics from the **iRacing Data API** and displays them on your site using a simple shortcode. Built for [Endurotech Racing](https://www.endurotechracing.com).

## What It Displays

Each driver card can show:

| Data | Source | Required? |
|---|---|---|
| Driver Name | Team roster via `/data/team/get` | Auto (from API) |
| iRating | Most recent race (`newi_rating`) | Auto (from API) |
| Safety Rating | Most recent race (`new_sub_level`) | Auto (from API) |
| Wins / Starts / Top 5s / Laps | Career stats via `/data/stats/member_career` | Auto (from API) |
| Last Race Result | Position, track, series from `/data/stats/member_recent_races` | Auto (from API) |
| **Driver Photo** | Uploaded via WordPress media library | **Optional** |
| **Sim Gear / Setup** | Entered in admin (wheel, pedals, rig, display, PC) | **Optional** |

The layout **adapts automatically** — if a driver has no photo or gear info, the card collapses cleanly to show just their stats. No empty boxes or broken layouts.

Team summary cards show total drivers, average iRating, total wins, total starts, and total laps.

---

## Requirements

- WordPress 5.8+
- PHP 8.0+
- An **iRacing account** with [OAuth2 client credentials](https://oauth.iracing.com/oauth2/book/client_registration.html)
- Your **iRacing Team ID**

## Installation

1. **Copy** the `endurotech-iracing-drivers` folder into `wp-content/plugins/`.

2. **Activate** in **WordPress Admin → Plugins**.

3. **Configure API credentials** in **iRacing Drivers → API Settings**:
   - Client ID / Client Secret (from iRacing OAuth registration)
   - Username (iRacing email) / Password
   - Team ID (numeric, from the iRacing member site URL)
   - Cache Duration (default: 1 hour)

4. **Add driver photos and gear** (optional) in **iRacing Drivers → Driver Profiles**:
   - Click "Upload Photo" to use the WordPress media library
   - Fill in any gear fields you want — leave blank to skip
   - Click "Save All Profiles"

5. **Create a page** and add the shortcode:

   ```
   [iracing_drivers]
   ```

6. Publish. Done.

## Shortcode Options

```
[iracing_drivers title="Our Drivers" show_last_race="yes" layout="cards"]
```

| Attribute | Default | Description |
|---|---|---|
| `title` | `Our Drivers` | Heading text above the display |
| `show_last_race` | `yes` | Show/hide last race info (`yes` or `no`) |
| `layout` | `cards` | `cards` (grid with photos/gear) or `table` (compact rows) |

## Layout Behaviour

The card layout adapts based on what data is available for each driver:

| Photo | Gear | Result |
|---|---|---|
| Yes | Yes | Full card — photo, stats, last race, gear section |
| Yes | No | Card with photo + stats + last race (no gear section) |
| No | Yes | Card with stats + last race + gear (no photo) |
| No | No | Compact card with stats + last race only |

Every card always shows: rank, name, iRating badge, Safety Rating badge, wins, starts, top 5s, and laps.

## How Authentication Works

Uses iRacing's **OAuth2 password_limited flow** (same as [iracing-bot](https://github.com/dbousamra/iracing-bot)):

1. Password → SHA-256 hashed with username as salt → base64
2. Client secret → SHA-256 hashed with client ID as salt → base64
3. POST to `https://oauth.iracing.com/oauth2/token`
4. Bearer token cached as WordPress transient until near-expiry
5. Data API requests → iRacing returns S3 pre-signed link → plugin fetches actual data

**All API calls are server-side.** No credentials exposed to the browser.

## Caching

- **Access token**: transient (auto-expires ~2 min before token does)
- **Driver data**: transient (configurable hours, default 1)
- **Clear cache**: button on the API Settings page

## Security

- Credentials in `wp_options` (standard WordPress storage)
- All output escaped with `esc_html()` / `esc_attr()` / `esc_url()`
- Admin pages require `manage_options` capability
- Nonce-protected form actions
- No external JS dependencies

## File Structure

```
endurotech-iracing-drivers/
├── endurotech-iracing-drivers.php       # Main plugin file
├── includes/
│   ├── class-iracing-api.php            # iRacing OAuth2 client & data fetching
│   ├── class-admin-settings.php         # API settings + Driver Profiles admin pages
│   └── class-driver-display.php         # Shortcode rendering (cards + table)
├── assets/
│   ├── css/
│   │   ├── drivers.css                  # Frontend dark theme styles
│   │   └── admin-profiles.css           # Admin profiles page styles
│   └── js/
│       └── admin-profiles.js            # WordPress media uploader integration
└── README.md
```

## Finding Your Team ID

1. Log in to [members.iracing.com](https://members.iracing.com)
2. Navigate to your team page
3. The Team ID is the numeric value in the URL (e.g., `teamId=12345`)

## Troubleshooting

| Issue | Solution |
|---|---|
| No data showing | Check iRacing Drivers → API Settings — all fields must be filled |
| No drivers on Profiles page | Visit your frontend drivers page once first to populate the cache |
| Photos not saving | Ensure your WordPress media library is working and you click "Save All Profiles" |
| Stale data | Click "Clear Driver Cache" on API Settings |
| Rate limited | Increase cache duration |
| PHP errors | Check `wp-content/debug.log` — errors prefixed with `EDR iRacing:` |
