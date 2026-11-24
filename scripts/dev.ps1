# Fast local run on Windows: PHP runs natively, only Postgres runs in Docker.
# (The full Docker stack reads the code through a slow Windows/OneDrive mount.)
#
#   powershell -ExecutionPolicy Bypass -File scripts\dev.ps1
#
# Needs PHP 8.4 with pdo_pgsql enabled in php.ini. Sessions, cache and the queue use
# files and run in-process here, so Redis and Horizon are not needed. Mail is written
# to storage/logs/laravel.log. Stop with Ctrl+C.

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

docker compose up -d postgres | Out-Null

$env:APP_URL = 'http://localhost:8088'
$env:DB_HOST = '127.0.0.1'
$env:DB_READ_HOSTS = '127.0.0.1'
$env:DB_PORT = '15432'
$env:SESSION_DRIVER = 'file'
$env:CACHE_STORE = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'log'
# Decision D-14: any email code, the stand-in KYC capture page, fanfolio:dev-staff.
$env:FANFOLIO_DEV_MODE = 'true'

php artisan migrate --force
php artisan serve --host=127.0.0.1 --port=8088
