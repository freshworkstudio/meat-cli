<?php
namespace Meat\Cli\Console;

use GuzzleHttp\Exception\ClientException;
use Meat\Cli\Helpers\MeatAPI;
use Meat\Cli\Traits\canLogin;

/**
 * Class InstallCommand
 * @package Meat\Cli\Console
 */
class InstallCommand extends MeatCommand
{
    use canLogin;

    /**
     * @var bool
     */
    protected $needLogin = false;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is the installation process. Create your personal .meat file once so you can easily run your commands afterwards';

    /**
     * @var array
     */
    protected $configuration = [];

    /**
     *
     */
    public function handle() {
        $this->printBigMessage('MEAT CLI Installation ✌️');


        if ($this->configurationHandler->isInstalled()) {
            if (!$this->confirm('MEAT Cli is already installed. Do you want to update your ~/.meat file?')) {
                return;
            }
        }
        $this->askDatabaseInformation();
        $this->askMeatCredentials();
        $this->finishMessage();

    }

    /**
     *
     */
    public function askDatabaseInformation()
    {
        $this->info('');
        $this->info('Please enter your local database credentials');
        $this->info('==========================================');

        $configuration = [
            'db_user' => $this->ask('Database User', 'root'),
            'db_pass' => $this->secretAllowEmpty('Database Password'),
            'db_host' => $this->ask('Database Host', 'localhost'),
        ];

        $this->configurationHandler->save($configuration);
    }

    /**
     *
     */
    private function finishMessage()
    {
        $this->info('');
        $this->info('==========================================');
        $this->info('Installation complete!');
        $this->info('==========================================');
    }


}