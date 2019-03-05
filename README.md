# vesta-cp-backup-to-google-drive
This repository for vesta-cp php script to backup user to google drive: **free 15GB storage**

**How to use:**

1. You need to enable API, create OAUTH2 credentials, save to credentials.json
2. Run first time from CLI to authorize this application to google api.
```php
php gdrivebackup
```
You will be asked to follow link, grant permissions, GDRIVE and GMAIL (for sending notifications). Paste key from google api to console. One time operation.

I tried to use service account auth, yet unable to overcome permission problem. :(

3. Get packages  "google/apiclient": "^2.0", "monolog/monolog": "^1.24"
```bash
composer install
```
4. Typical usage:
```bash
php gdrivebackup.php --email=email_notifications --user=vest_username
````
Email tag --email is optional.

You can use this script to upload specific file:

```bash
php gdrivebackup.php --file=file_name
````

App will log itself to gdrivebackup.log file.
