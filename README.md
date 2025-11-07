# WP Auto Bump

WP Auto Bump automatically updates the publish date of older posts to make them appear freshly updated. Perfect for evergreen blogs, news sites, or content marketers who want to keep their homepage dynamic.

## Features

- Select specific categories (or leave empty for all)
- Configurable bump frequency in days (1–365; default 7)
- Randomized variation (days + hours) applied ± around the schedule
- ~10% oldest-post sampling (min 1, max 100), pick one at random
- Updates post_date and post_modified (and their GMT variants)
- WP‑Cron single-event scheduling with stored next run time
- "Bump Now" button for immediate refresh
- Fully uses core WordPress APIs; translation-ready

## Requirements

- WordPress 5.5+
- PHP 7.4+

## Installation

1. Copy the `wp-auto-bump` folder to `wp-content/plugins/`.
2. Activate “WP Auto Bump” in WordPress → Plugins.
3. Go to Settings → WP Auto Bump to configure options.

## Settings

- Categories: Multi-select of all categories. Leave empty to target all posts.
- Bump Frequency: Days between bumps.
- Bump Variation: Days and Hours. Total variation (in hours) must be less than `Frequency × 24 − 1`. If exceeded, it’s automatically reduced with a notice.

## Bump Now

On the settings page, click “Bump Now” to immediately bump one random older post and recalculate the next scheduled run. A small notice confirms the result.

## How it works

- At each due time, the plugin queries the oldest eligible posts, takes ~10% (min 1, max 100), selects one randomly, and sets its timestamps to “now”.
- The next run time is computed as: `now + frequency ± variation` (randomized in both directions) and stored in the `wp_auto_bump_next_time` option.
- First run handling: if `wp_auto_bump_next_time` is missing/zero/past, a bump occurs immediately and the next run is scheduled.

## Uninstall/Deactivate

- Deactivation clears the scheduled cron event. Options remain so they can be restored on reactivation.

## License

GPLv2 or later. See `LICENSE` or https://www.gnu.org/licenses/gpl-2.0.html
