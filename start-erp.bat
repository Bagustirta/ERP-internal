@echo off
title ERP Internal Server (Laravel + Inertia + Vue 3 + PostgreSQL)
cd /d "%~dp0"
echo Menjalankan ERP Internal pada http://localhost:8000 ...
php artisan serve --host=127.0.0.1 --port=8000

