# AGENTS.md — wicket-wp-admin-org-roster

## Project Overview

`wicket-wp-admin-org-roster` is a WordPress admin plugin for managing organization rosters and person-to-organization relationships via the Wicket MDP (Member Data Platform) API. It provides bulk upload, validation, duplicate resolution, and sync workflows for administrators.

## Development

### Backend (aka PHP classes)
- Project uses PSR-4 for class autoload, use `composer dump-autoload` to generate the `/vendor/autoload.php`.

### Frontend
The project lives in `/resources/js` and uses React-islands to render specific areas.
- run `npm install` to install required packages
- run `npm run build` to create production build in `/build` folder
- run `npm run start` to watch and generate development version of the assets

## Testing

- Tests live in the `/qa` folder outside of this repo, in the Wicket Warden repo.