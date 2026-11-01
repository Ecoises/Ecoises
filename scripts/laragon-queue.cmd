@echo off
:: Worker de la cola para el Procfile de Laragon.
:: Laragon lo arranca antes que MySQL y queue:work se cierra si no hay base de
:: datos, así que se vuelve a lanzar cada 5 segundos hasta que conecte.
:: Procfile: EcoIses cola: autorun C:/laragon/www/biodiversidad-api/scripts/laragon-queue.cmd

cd /d "%~dp0.."
set PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe

:loop
"%PHP%" artisan queue:work --queue=interactive,default,species-sync,ecology-batch --tries=3 --timeout=120
echo El worker se detuvo. Reintentando en 5 segundos...
ping -n 6 127.0.0.1 >nul
goto loop
