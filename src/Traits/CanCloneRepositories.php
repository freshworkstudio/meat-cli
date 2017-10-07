<?php

namespace Meat\Cli\Traits;

trait CanCloneRepositories
{
    /**
     * @return $this
     * @throws \Exception
     */

    protected function cloneRepositoryOrCheckDirectory()
    {
        if (file_exists($this->working_path)) {
            $this->line("The folder $this->working_path already exists...");
            if (!file_exists($this->working_path . DIRECTORY_SEPARATOR . '.git')) {
                throw new \Exception('Folder already exists and could not find a project');
            }
            $this->setProjectNameBasedOnGitRepository();
        } else {
            $this->cloneRepository($this->project, $this->folder_name);
        }

        return $this;
    }

    protected function setProjectNameBasedOnGitRepository()
    {
        $olddir = getcwd();
        chdir($this->working_path);
        $process = $this->runProcess('git remote get-url origin', false);
        chdir($olddir);
        $this->project = substr(explode($this->bitbucketUsername(), trim($process->getOutput()))[1], 1, -4);
        $this->line('Project name defined as '. $this->project);
    }

    /**
     * @param $project
     * @param $folder
     * @throws \Exception
     */
    protected function cloneRepository($project, $folder)
    {
        $repo_clone = 'git@bitbucket.org:' . $this->bitbucketUsername() . '/' . $project . '.git';
        $this->info("Cloning $repo_clone repository on $folder");
        $command = "git clone $repo_clone $folder";
        $this->execPrint($command);
    }

    /**
     * @return string
     */
    protected function bitbucketUsername()
    {
        return 'digitalmeatdev';
    }
}