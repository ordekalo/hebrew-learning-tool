# Hebrew Learning Tool

A single-folder PHP/MySQL application for managing Hebrew vocabulary with translations, pronunciation audio, and CSV import/export helpers.

## Features

- 🎴 Flashcard-style learning interface with optional language filter.
- 📝 Quick add form with transliteration, part of speech, notes, and optional audio upload (MP3/WAV/OGG, ≤10MB).
- 🌐 Unlimited translations per word, including other scripts and usage examples.
- 📂 Admin dashboard for editing and deleting words and translations.
- 📥 CSV bulk importer with downloadable sample template.
- 🔐 CSRF protection for all forms and sanitized uploads stored in `/uploads`.

## Requirements

- PHP 8.0 or newer with PDO MySQL and Fileinfo extensions enabled.
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
hebrew,transliteration,part_of_speech,notes,lang_code,other_script,meaning,example,audio_url
```

- Only the `hebrew` column is required.
- `audio_url` accepts paths already uploaded to `/uploads`. Remote downloads are intentionally disallowed.

## Security Notes

- Authentication is not included; add HTTP basic auth, a reverse proxy, or a simple login before exposing the admin interface publicly.
- Uploaded audio files are stored with randomized names; unsupported or oversized files are rejected.

## Development Tips

- Use the admin dashboard (`words.php`) to manage translations efficiently.
- Extend the schema to track spaced repetition progress or user accounts if needed.
- Add automated backups of the `words` and `translations` tables for data safety.
- See [`docs/mobile-roadmap.md`](docs/mobile-roadmap.md) for a milestone-based plan that translates the new mobile-first feature
  brief into implementation tasks (database migrations, APIs, UI work, QA).

## License

This project is released under the MIT License. See [LICENSE](LICENSE) for details.
