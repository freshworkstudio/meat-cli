<?php

namespace Meat\Cli\Console;

use GuzzleHttp\Exception\ClientException;
use Meat\Cli\Traits\CanCloneRepositories;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class CreateCommand
 * @package Meat\Cli\Console
 */
class CreateCommand extends MeatCommand
{
    /**
     * @var
     */
    protected $folder;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {

        $info = pathinfo(getcwd());
        $this->setName('create')
            ->setDescription('Create a new project based on a predefined template')
            ->addOption(
                'no-commit',
                'c',
                InputOption::VALUE_NONE,
                'Do not run the first commit. Just add the remote origin')
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                'Automatically reply yes to every answer');

    }

    /**
     *
     */
    public function fire()
    {

        list($type, $name) = $this->askTypeAndName();
        $project = $this->askCodeAndCreateProject($name, $type);
        $folder = $this->askForInstallationDirectory($project);

        $this->installBaseScaffolding($type)
            ->changeWorkingDirectory($folder);

        $bitbucket = $this->confirm('Create Bitbucket repository? (Y/n): ');
        if ($bitbucket) {
            $this->info('Setting up bitbucket repository...');
            $project = $this->api->setupProjectBitbucket($project['id']);
        }

        $staging = $bitbucket && $this->confirm('Create staging on Laravel Forge? (Y/n): ');
        if ($staging) {
            $this->info('Setting up staging...');
            $project = $this->api->setupProjectForge($project['id'], get_project_assets_compilation_script('production'));
        }

        $trello = $this->confirm('Create Trello Board? (Y/n): ');
        if ($trello) {
            $this->info('Setting up Trello...');
            $project = $this->api->setupProjectTrello($project['id']);
        }

        $slack = $this->confirm('Create Slack Channel? (Y/n): ');
        if ($slack) {
            $this->info('Setting up Slack...');
            $project = $this->api->setupProjectSlack($project['id']);
        }




        if ($bitbucket) {
            $this->info('Setting newly created repository as a git remote');
            $this->setAsNewGitRepository($this->getRemoteUrlByProject($project));
            $this->info('Repository added to remote origin successfully');
        }
        
        $this->line('');
        $this->line('=============================');
        $this->line('Process complete!');
        $this->line('=============================');
        $this->line('');

    }

    /**
     * @param $type
     * @param $folder
     * @return mixed
     */
    public function getCreateCommandByProjectType($type, $folder)
    {
        $escapedFolder = escapeshellarg($folder);
        $repos = [
            'themosis'  => 'composer create-project digitalmeat/themosis:dev-master ' . $escapedFolder,
            'laravel'  => 'laravel new ' . $escapedFolder . ' > /dev/null 2>&1 || composer create-project --prefer-dist laravel/laravel ' . $escapedFolder,
            'blank'  => 'mkdir ' . $escapedFolder,
        ];

        return $repos[$type];
    }

    /**
     * @param $base
     * @return $this
     */
    private function installBaseScaffolding($base)
    {
        $folder = $this->folder;
        $command = $this->getCreateCommandByProjectType($base, $folder);
        $this->info("Installing base on $folder");

        $this->runProcess($command);

        return $this;
    }

    /**
     * @param $project_code
     * @param $project_name
     * @param $project_type
     * @return mixed
     */
    public function createProjectOnMeatApi($project_name, $project_code, $project_type) {
        $this->line('Creating project on MEAT Cloud...');
        $project = $this->api->createProject($project_code, $project_name, $project_type);
        $this->info('Project created successfully!');
        return $project;
    }

    /**
     * @param $remote
     */
    protected function setAsNewGitRepository($remote)
    {
        if (file_exists('.git')) {
            rmdir('.git');
        }

        $this->execPrint('git init');
        $this->execPrint('git remote add origin ' . escapeshellarg($remote));

        if (!$this->option('no-commit')) {
            $this->runProcess('git add .');
            $this->runProcess('git commit -m"[GIT] Initial commit"');
        }

    }

    public function confirm($question, $default = true) {
        if ($this->option('yes')) {
            return true;
        }

        return parent::confirm($question, $default = true);
    }
    /**
     * @param $project
     * @return string
     */
    protected function getRemoteUrlByProject($project)
    {
        return 'git@bitbucket:' . $project['repo_full_name'] . '.git';
    }
    /**
     * @param $name
     * @param $type
     * @return mixed
     */
    protected function askCodeAndCreateProject($name, $type)
    {
        $code = str_slug($name);
        $project = false;
        while (true) {
            $code = $this->ask("Project code ($code): ", $code, null, true);

            try {
                $project = $this->createProjectOnMeatApi($name, $code, $type);
                break;
            } catch (ClientException $e) {
                $this->error(json_decode($e->getResponse()->getBody()->getContents(), true)['msg']);
            }
        }

        return $project;
    }
    /**
     * @return array
     */
    protected function askTypeAndName()
    {
        $type = $this->choice('Select a base scaffolding', [
            'themosis' => 'Themosis',
            'laravel' => 'Laravel',
            'blank' => 'Blank'
        ], 'blank');

        $this->info($type . ' selected');
        $name = $this->ask('Project name: ', null, null, true);

        return array($type, $name);
    }
    /**
     * @param $project
     * @return string
     */
    protected function askForInstallationDirectory($project)
    {
        $folder = getcwd() . DIRECTORY_SEPARATOR . $project->code;
        while (true) {
            $folder = $this->ask("Installation folder ($folder): ", $folder);
            if (!file_exists($folder)) {
                break;
            }
            $this->error('This directory already exists... ');
        }

        $this->folder = $folder;

        return $folder;
    }

}