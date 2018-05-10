<?php

namespace App\Console\Commands;

use App\TwRequest\TwRequest;
use Illuminate\Console\Command;

class UpdateData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * @var TwRequest
     */
    protected $twRequest;

    /**
     * Create a new command instance.
     *
     * @param TwRequest $twRequest
     */
    public function __construct(TwRequest $twRequest)
    {
        $this->twRequest = $twRequest;
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->twRequest->loadServersFromMasterservers();
        $this->twRequest->loadServerInfo();
        ob_start();
        $this->twRequest->reloadFailedServerInfo();
        var_export($this->twRequest->getServers());
        file_put_contents("/app/dump_" . time() . ".txt", ob_get_flush());

        $this->info('update server data');
        return True;
    }
}
