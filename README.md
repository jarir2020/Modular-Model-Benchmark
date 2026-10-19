# Project Workbench

Project Workbench is a modular project and task management app built with Laravel 13, Vue 3, and Vite. Projects is the first completed module; the module roadmap and conventions live in MODULAR_APP_PLAN.md.

## Requirements

- PHP 8.3 or newer with PDO SQLite enabled
- Composer
- Node.js and npm

## First run

From the project root:

    composer install
    cp .env.example .env
    touch database/database.sqlite
    php artisan key:generate
    php artisan migrate
    npm install
    npm run build
    composer run dev

Open http://127.0.0.1:8000. The home route redirects to the Projects module.

The app uses SQLite, file sessions, file cache, and a synchronous queue by default, so no separate database or background service is required.

## Frontend development

For Vue hot reload, use two terminals:

    composer run dev

    npm run dev

Vite's Vue single-file-component plugin is configured in vite.config.js. Module components are imported and mounted from resources/js/app.js.

## Module structure

Each module owns its model, controller, view, Vue components, and route file:

    Modules/
      Projects/
        Models/
          Project.php
        Controllers/
          ProjectController.php
        Views/
          index.blade.php
        Components/
          ProjectIndex.vue
        route.php

Database migrations remain in database/migrations so Laravel's standard migration runner discovers them. Composer maps the Modules namespace to this folder. ModulesServiceProvider discovers each module's route.php and Views directory; resources/js/app.js discovers each module's *Index.vue page component. A new module needs no central route or component import.
