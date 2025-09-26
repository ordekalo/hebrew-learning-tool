# DB Migrations – מה לוודא

## migrate.php
- הקובץ נמצא בתיקייה `/htdocs/`.
- טוען את `config.php` ואת `.deploy_secret.php` (אם קיים) או משתמש במשתנה סביבה כדי לקבל את `DEPLOY_TOKEN`.
- מאובטח: מקבל בקשות `POST` בלבד ומוודא שהכותרת `X-Deploy-Token` תואמת לטוקן.

## .deploy_secret.php
- קיים רק בשרת, לא ב-GitHub (נמצא ב-`.gitignore`).
- מכיל:
  ```php
  <?php
  $DEPLOY_TOKEN = "10b8c53a2b5d4c7d8a77ea1c23868699";
  ```
- ערך הטוקן חייב להיות זהה לערך שמוגדר ב-GitHub Secrets.

## תיקיית migrations/
- קיימת בריפו.
- כל קובץ SQL ממוספר לפי סדר הריצה: `001_xxx.sql`, `002_xxx.sql` וכו'.
- כל קובץ idempotent — ניתן להרצה שוב בלי לגרום לשגיאה (למשל `CREATE TABLE IF NOT EXISTS`).

## טבלת migrations
- נוצרת בבסיס הנתונים עם:
  ```sql
  CREATE TABLE IF NOT EXISTS migrations (...);
  ```
- מתעדת אילו קבצי SQL כבר הוחלו כדי למנוע ריצה כפולה.

## GitHub Secrets
- חייבים להיות מוגדרים:
  - `FTP_SERVER`
  - `FTP_PORT`
  - `FTP_USERNAME`
  - `FTP_PASSWORD`
  - `FTP_SERVER_DIR`
  - `DEPLOY_TOKEN`

## Workflow
- הקובץ `.github/workflows/deploy.yml` מריץ אחרי שלב ה-FTP:
  ```yaml
  - name: Run DB migrations via HTTP
    run: |
      curl -sS --fail -X POST \
        -H "X-Deploy-Token: ${{ secrets.DEPLOY_TOKEN }}" \
        https://hebrew-learning-tool.liveblog365.com/migrate.php
  ```
- ה-Action נכשל אם הטוקן שגוי או אם אחת המיגרציות נכשלת.

## בדיקות מומלצות
- לבדוק ב-phpMyAdmin שהשינויים מה-migrations מופיעים.
- לוודא שטבלת `migrations` מתעדכנת עם שם הקובץ שהורץ.
- להריץ קריאה ידנית עם `curl` ולוודא שמתקבל `status=ok`.
