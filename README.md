# Hebrew Learning Tool

A single-folder PHP/MySQL application for managing Hebrew vocabulary with translations, media, spaced repetition, and a mobile-first study experience.

## Features

- 🔐 Email/password login with an SM-2–inspired spaced repetition engine, daily streak tracking, and retry queues for mistakes inside the same session.
- 📱 Mobile-first daily session view with huge touch targets (≥44px), swipe gestures (Again/Easy), vibration feedback, inline audio playback, and Web Speech TTS fallback when no recording exists.
- 🏠 Home dashboard summarising cards due today, streak stats, deck tiles with per-deck due counts, and the six most recent additions.
- 📝 Quick add form with deck assignment, tags, transliteration, grammar notes, inline audio recording/upload (≤10MB), and mobile camera-ready image upload with automatic thumbnails.
- 🔍 Advanced search supporting query tokens (`q:"exact"`, `tag:תחביר`, `lang:ru`, `pos:noun`) alongside audio/image filters, backed by the `/api/search` endpoint for instant results.
- 📚 Deck administration page (cover image + description) and tag-aware word editor, plus CSV importer support for `deck`, `tags`, `audio_url`, and `image_url` with row-by-row error reporting and 500-row batching.
- 🌐 Progressive Web App manifest + service worker so the latest CSS/JS/cards remain available offline and the app can be installed on Android/Chrome.
- 🛡️ CSRF tokens, per-endpoint rate limiting, MIME/size upload validation, and JSON APIs designed for native/PWA clients.

## Requirements

- PHP 8.0 or newer with PDO MySQL, Fileinfo, and GD extensions enabled.
- MySQL 5.7+/MariaDB 10+ database.
- Web server capable of serving PHP files (Apache, Nginx + PHP-FPM, built-in server, etc.).

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/your-user/hebrew-learning-tool.git
   cd hebrew-learning-tool
   ```
2. **Create the database schema**
   ```sql
   SOURCE db.sql;
   ```
3. **Configure database credentials** by editing `config.php` or setting environment variables.
   - The default configuration matches the provided hosting details:
     - Host: `sql303.ezyro.com`
     - Database: `ezyro_40031468_hebrew_vocab`
     - User: `ezyro_40031468`
     - Password: `450bd088fa3`
   - Override any value via the environment variables:
     - `HEBREW_APP_DB_HOST`
     - `HEBREW_APP_DB_NAME`
     - `HEBREW_APP_DB_USER`
     - `HEBREW_APP_DB_PASS`
4. **Ensure the `uploads/` directory is writable** by the web server user.
5. **Serve the application**
   ```bash
   php -S localhost:8000
   ```
   Then browse to [http://localhost:8000/index.php](http://localhost:8000/index.php).

## Hosting Control Panel Reference

If you are deploying to the InfinityFree/iFastNet account shown in the provided VistaPanel screenshots, the following values are already configured in `config.php` and may be useful when setting up deployments or FTP access:

| Item | Value |
| --- | --- |
| Plan | Free Hosting |
| Main domain | `b3r79a.ezyro.com` |
| FTP hostname | `ftpupload.net` |
| FTP username | `ezyro_40031468` |
| MySQL hostname | `sql303.ezyro.com` |
| MySQL username | `ezyro_40031468` |
| MySQL database | `ezyro_40031468_hebrew_vocab` |
| Disk quota | 5 GB (0 MB used) |
| Bandwidth | Unlimited |
| Daily hits quota | 50,000 |
| Hosting volume | `vol1000_8` |

Use these details together with the database password above when configuring clients such as phpMyAdmin, MySQL Workbench, or FTP software. Update the credentials if they change in the control panel.

## CSV Import Format

The importer expects UTF-8 CSV files with the header:

```
hebrew,transliteration,part_of_speech,notes,lang_code,other_script,meaning,example,audio_url,image_url,deck,tags
```

- Only the `hebrew` column is required.
- `audio_url`/`image_url` may reference existing paths in `/uploads` **or** HTTPS resources (audio ≤10MB, image ≤5MB) that will be downloaded, validated, and thumbnailed server-side.
- `deck` and `tags` accept multiple values separated by `,`, `;`, or `|` and are created automatically if they do not exist.
- Imports stream in batches of 500 rows; a detailed per-line error report (line + message) is displayed after each run.

## Security Notes

- Email/password login with session cookies ships out of the box—add HTTPS (reverse proxy or host configuration) before exposing publicly.
- Login and learning APIs are rate limited; every POST request validates CSRF tokens.
- Audio/image uploads are renamed, MIME checked, size capped, and thumbnailed before being referenced in cards.

## Development Tips

- Use the admin dashboards (`words.php`, `decks.php`, `import_csv.php`) for maintenance workflows.
- API endpoints ready for integrations:
  - `GET /api/learn/next?deck=…`
  - `POST /api/learn/answer`
  - `GET /api/search`
  - `POST /api/progress/sync`
- Add automated database backups via cron or hosting tools for resilience.
- See [`docs/mobile-roadmap.md`](docs/mobile-roadmap.md) for long-range milestones that build on the implemented mobile-first foundation.

## License

This project is released under the MIT License. See [LICENSE](LICENSE) for details.
