<?php

namespace Meat\Cli\Console;

use M1\Env\Parser;
use Meat\Cli\Traits\CanCloneRepositories;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class InstallCommand
 * @package Meat\Cli\Console
 */
class MountCommand extends MeatCommand
{
    use CanCloneRepositories;

    /**
     * @var
     */
    private $project;
    /**
     * @var
     */
    private $folder_name;
    /**
     * @var
     */
    private $working_path;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('mount')->setDescription('Clone and install a MEAT project')
            ->addArgument(
                'project-code',
                InputArgument::OPTIONAL,
                'Slug of the project. When is not provided, the name of the current folder is used')
            ->addArgument(
                'folder',
                InputArgument::OPTIONAL,
                'Folder where we will install the app. When not provided, the name of the project is used')
            ->addOption(
                'no-images',
                'k',
                InputOption::VALUE_NONE,
                'Prevent envoy from downloading all the images and user files')
            ->addOption(
                'no-browser',
                'b',
                InputOption::VALUE_NONE,
                'Prevent browser from opening app url');

    }

    /**
     * Execute the command.
     * @return void
     * @throws \Exception
     */
    protected function fire()
    {
        $info = pathinfo(getcwd());
        $this->project = $this->argument('project-code') ?? $info['basename'];
        $this->folder_name = $this->argument('folder') ?? $this->project;
        $this->working_path = !$this->argument('folder') && !$this->argument('project-code') ? '.' : $this->folder_name;
        /*var_dump($this->project);
        var_dump($this->folder_name);
        var_dump($this->working_path);*/

        $this->cloneRepositoryOrCheckDirectory()
            ->changeWorkingDirectory($this->working_path)
            ->notifyProjectInstallation()
            ->runPreInstallScripts()
            ->configureDotEnv()
            ->createDatabaseIfNeeded()
            ->composerInstall()
            ->npmInstall()
            ->compileAssets()
            ->syncDataFromServer()
            ->runMigrationsIfLaravel()
            ->runPostInstallScripts()
            ->openBrowser();

        $this->info('Process complete!');



    }

    /**
     * @return bool
     */
    public function isThemosis()
    {
        return file_exists('library/Thms/Config/Environment.php');
    }

    /**
     * @return bool
     */
    public function isLaravel()
    {
        return file_exists('artisan');
    }

    /**
     * @return $this
     */
    protected function runPreInstallScripts()
    {
        if ($cmd = project_config('scripts.pre-install')) {
            $this->runProcess($cmd);
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function configureDotEnv()
    {
        $shouldConfigureDotEnv = project_config('dotenv');
        if ($shouldConfigureDotEnv === 'auto') {
            $shouldConfigureDotEnv = !file_exists('.env') && file_exists('.env.example');
        }

        if ($shouldConfigureDotEnv === true) {
            $dotEnv = new Parser(file_get_contents(getcwd() . DIRECTORY_SEPARATOR . '.env.example'));
            $dotEnvConfiguration = $dotEnv->getContent();
            $this->line('');
            $this->line('=============================');
            $this->line('Creating .env interactively');
            $this->line('=============================');
            $this->line('');
            $newEnv = [];

            foreach ($dotEnvConfiguration as $key => $value) {
                $autocompleteOptions = $this->getAutocompletionOptions($value, $key);
                if ($this->confirm("{$key} ({$autocompleteOptions[0]}) (Y/n):")) {
                    $newEnv[$key] = $key . '=' . $autocompleteOptions[0];
                } else {
                    $response = $this->ask($key . ' (' . $autocompleteOptions[0] . '): ', $autocompleteOptions[0], $autocompleteOptions);;
                    $newEnv[$key] = $key . '=' . $response;
                }

            }

            file_put_contents(getcwd() . DIRECTORY_SEPARATOR . '.env', implode(PHP_EOL, $newEnv));
        }

        return $this;
    }



    /**
     * @return $this
     */
    protected function composerInstall()
    {
        if (project_config('composer')) {
            $this->info('Installing composer dependencies');
            $this->runProcess('composer install --prefer-dist');
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function npmInstall()
    {
        if (project_config('npm')) {
            $this->info('Installing NPM dependencies');
            $this->runProcess('yarn install || npm install');
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function compileAssets()
    {
        $driver = project_config('assets.driver');
        if ($driver) {
            $command = get_project_assets_compilation_script('dev');
            $this->info('Compiling assets: ' . $command);
            $this->runProcess($command);
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function syncDataFromServer()
    {
        $envoy = project_config('envoy');
        if ($envoy == 'auto') {
            if (file_exists('envoy.blade.php') || file_exists('Envoy.blade.php')) {
                $envoy = true;
            }
        }

        if ($envoy === true) {
            $this->info('Running envoy sync_database...');
            $this->runProcess('envoy run sync_database');
            if (!$this->option('no-images')) {
                $this->info('Running envoy pull_images...');
                $this->runProcess('envoy run pull_images');
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function runMigrationsIfLaravel()
    {
        if (project_config('migrate') || (project_config('migrate') == 'auto' && $this->isLaravel())) {
            $this->info('Running Laravel Migrations...');
            $this->runProcess('envoy run sync_database');
            $this->runProcess('envoy run pull_images');
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function runPostInstallScripts()
    {
        if ($cmd = project_config('scripts.post-install')) {
            $this->runProcess($cmd);
        }

        return $this;
    }

    /**
     * @param $value
     * @param $key
     * @return array
     */
    protected function getAutocompletionOptions($value, $key)
    {
        $autocompletes = [$value];
        if ($key == 'DB_NAME' || $key == 'DATABASE_NAME') {
            array_unshift($autocompletes, $this->project);
        }
        if ($key == 'DB_USER' || $key == 'DB_USERNAME') {
            array_unshift($autocompletes, config('db_user'));
        }
        if ($key == 'DB_PASSWORD') {
            array_unshift($autocompletes, config('db_pass'));
        }
        if ($key == 'DB_HOST') {
            array_unshift($autocompletes, config('db_host'));
        }
        if ($key == 'WP_HOME') {
            array_unshift($autocompletes, 'http://' . $this->folder_name . '.dev');
        }
        if ($key == 'WP_SITEURL') {
            array_unshift($autocompletes, '${WP_HOME}/cms');
        }

        return $autocompletes;
    }

    /**
     * @return $this
     */
    protected function createDatabaseIfNeeded()
    {
        $dbConfiguration = $this->getDatabaseConfiguration();
        switch($dbConfiguration['type']) {
            case 'mysql':
                $link = @mysqli_connect($dbConfiguration['host'], $dbConfiguration['user'], $dbConfiguration['password']);
                if ($link === false) {
                    $this->line('<error>Could not establish connection with the database</error>');
                    break;
                }
                if (!@mysqli_select_db($link, $dbConfiguration['name'])) {
                    if ($this->confirm('The database "' . $dbConfiguration['name'] . '" doesn\'t exists. Do you want to create it? (Y/n): "' )) {
                        $sql = "CREATE DATABASE `" . mysqli_escape_string($link, $dbConfiguration['name']) . '`';
                        if (!mysqli_query($link, $sql)) {
                            $this->line("<error>Error creating database: $sql" . mysqli_error($link) . "</error>");
                        }
                    }
                }

                break;
            default:
                break;
        }

        return $this;
    }

    /**
     * @return array
     */
    protected function getDotEnvConfiguration()
    {
        $dotEnv = new Parser(file_get_contents(getcwd() . DIRECTORY_SEPARATOR . '.env'));
        return $dotEnv->getContent();
    }

    /**
     * @return array
     */
    protected function getDatabaseConfiguration()
    {
        $dotEnv = $this->getDotEnvConfiguration();

        $dbConfig = [
            'type' => 'mysql',
            'name' => '',
            'host' => '',
            'user' => '',
            'pass' => ''
        ];
        foreach($dotEnv as $key => $value) {
            if(strpos($key, 'DB') === false && strpos($key, 'DATABASE') === false) {
                continue;
            }
            if(strpos($key, 'NAME') !== false) {
                $dbConfig['name'] = $value;
            }
            if(strpos($key, 'HOST') !== false) {
                $dbConfig['host'] = $value;
            }

            if(strpos($key, 'USER') !== false) {
                $dbConfig['user'] = $value;
            }

            if(strpos($key, 'PASS') !== false) {
                $dbConfig['password'] = $value;
            }

            if(strpos($key, 'TYPE') !== false) {
                $dbConfig['type'] = $value;
            }
        }

        return $dbConfig;
    }

    /**
     * @return $this
     */
    protected function notifyProjectInstallation()
    {
        $this->api->notifyProjectInstallation($this->project);
        return $this;
    }

    /**
     * @return $this
     */
    private function openBrowser($url = null)
    {
        //@TODO: Add windows implementation
        if ($this->option('no-browser')) {
            return $this;
        }

        if (is_null($url)) {
            $url = 'http://' . $this->folder_name . '.dev/';
        }

        $this->info('Opening browser: ' . $url);
        $this->runProcess('/usr/bin/open \'' . escapeshellarg($url) . '\'');

        return $this;
    }
}