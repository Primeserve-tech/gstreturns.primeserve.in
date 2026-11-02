<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\CustomerAccounts;
use App\Libraries\ReturnStore;

class SetPrepaidKey extends BaseCommand
{
    protected $group='GST';
    protected $name='customers:set-prepaid-key';
    protected $description='Install a prepaid API key from standard input into encrypted backend settings. Never pass the key as a command argument.';
    public function run(array $params)
    {
        $key=trim((string)fgets(STDIN,1024));CustomerAccounts::validateKey($key);
        $store=new ReturnStore();$accounts=new CustomerAccounts($store);
        $accounts->atomic(function()use($store,$accounts,$key){
            $store->query("INSERT INTO customer_settings(name,value) VALUES('prepaid_key',?) ON CONFLICT(name) DO UPDATE SET value=excluded.value",[$store->seal(['key'=>$key])]);
            $accounts->audit('local-configuration','prepaid-key-rotated','prepaid');
        });
        CLI::write('Prepaid API key installed in encrypted backend settings. Its value is not displayed.');
    }
}
