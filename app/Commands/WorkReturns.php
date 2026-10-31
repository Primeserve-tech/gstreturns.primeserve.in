<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\ReturnStore;
use App\Libraries\ReturnGateway;
use App\Libraries\ReturnWorker;

class WorkReturns extends BaseCommand
{
    protected $group='GST';
    protected $name='returns:process-v3';
    protected $description='Run sequential return initiation and bounded status monitoring.';
    public function run(array $params)
    {
        if (in_array('--fixture',$params,true)||CLI::getOption('fixture')!==null) { $_ENV['GST_MODE']='fixture';$_SERVER['GST_MODE']='fixture';putenv('GST_MODE=fixture'); }
        $worker=new ReturnWorker(new ReturnStore(),new ReturnGateway());
        CLI::write('Return worker started. Press Ctrl+C to stop.');
        do { $worker->tick(); if (in_array('--once',$params,true) || CLI::getOption('once')!==null) break; sleep(1); } while (true);
    }
}
