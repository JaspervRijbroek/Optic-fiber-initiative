<?php

/**
 * Deployer configuration for Optic Fiber Initiative (Symfony 7).
 *
 * Required GitHub Actions secrets:
 *   DEPLOY_HOST         – SSH hostname or IP of the production server
 *   DEPLOY_USER         – SSH user on the production server
 *   DEPLOY_SSH_KEY      – Private SSH key (corresponding public key must be in ~/.ssh/authorized_keys)
 *   DEPLOY_KNOWN_HOSTS  – Contents of ~/.ssh/known_hosts for the production server
 *   DEPLOY_PATH         – Absolute path to the deployment directory on the server
 *                         e.g. /var/www/optic-fiber-initiative
 *
 * Shared files/dirs that must exist on the server before the first deploy:
 *   {{deploy_path}}/shared/.env.local   – production environment variables
 *   {{deploy_path}}/shared/var/         – Symfony var directory (cache, logs, SQLite DB)
 */

namespace Deployer;

require 'recipe/composer.php';

// ── Project ────────────────────────────────────────────────────────────────────
set('application', 'optic-fiber-initiative');
set('bin/console', '{{release_path}}/bin/console');
set('repository', 'git@github.com:JaspervRijbroek/Optic-fiber-initiative.git');
set('git_tty', false);

// ── Deployer options ──────────────────────────────────────────────────────────
set('keep_releases', 5);
set('ssh_multiplexing', true);

// ── Shared files & directories ─────────────────────────────────────────────────
// These live in {{deploy_path}}/shared and are symlinked into every release.
add('shared_files', ['.env.local', 'var/data.db']);

// ── Writable directories ───────────────────────────────────────────────────────
add('writable_dirs', ['var']);

// ── Hosts ──────────────────────────────────────────────────────────────────────
host('production')
    ->setHostname(getenv('DEPLOY_HOST') ?: 'example.com')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setDeployPath(getenv('DEPLOY_PATH') ?: '/var/www/optic-fiber-initiative')
    ->set('branch', 'main');

// ── Custom tasks ───────────────────────────────────────────────────────────────

// Reload supervisor so the messenger worker picks up the new release.
desc('Reload supervisor and restart messenger consumers');
task('deploy:supervisor:reload', function () {
    run('sudo supervisorctl reread');
    run('sudo supervisorctl update');
    run('sudo supervisorctl restart messenger-consume');
});

// Warm up the Symfony cache.
desc('Warm up Symfony cache');
task('deploy:cache:warmup', function () {
    run('{{bin/php}} {{bin/console}} cache:warmup');
});

// Run Doctrine migrations after each deploy.
desc('Run Doctrine migrations');
task('deploy:migrations', function () {
    run('cd {{release_path}} && {{bin/php}} {{bin/console}} doctrine:migrations:migrate --no-interaction');
});

// Set up Messenger transports (idempotent).
desc('Set up Symfony Messenger transports');
task('deploy:messenger:setup', function () {
    run('cd {{release_path}} && {{bin/php}} {{bin/console}} messenger:setup-transports');
});

// ── Deploy flow ───────────────────────────────────────────────────────────────
desc('Deploy the application');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:cache:warmup',
    'deploy:migrations',
    'deploy:messenger:setup',
    'deploy:publish',
    'deploy:supervisor:reload',
]);

// Roll back on failure.
after('deploy:failed', 'deploy:unlock');
