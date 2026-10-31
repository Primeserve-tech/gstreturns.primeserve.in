<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\ReturnStore;

class PurgeReturns extends BaseCommand
{
    protected $group='GST';
    protected $name='returns:purge-v3';
    protected $description='Independently purge expired portal records and temporary files every second.';
    public function run(array $params)
    {
        if (in_array('--fixture',$params,true)||CLI::getOption('fixture')!==null) { $_ENV['GST_MODE']='fixture';$_SERVER['GST_MODE']='fixture';putenv('GST_MODE=fixture'); }
        $store=new ReturnStore();
        CLI::write('Retention janitor started. Fixed retention: 600 seconds.');
        do {
            $store->cleanup();file_put_contents(WRITEPATH.'data/janitor-v3-heartbeat',(string)time());
            if (in_array('--once',$params,true)||CLI::getOption('once')!==null) break;
            sleep(1);
        } while (true);
    }
}
