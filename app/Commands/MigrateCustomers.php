<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\CustomerAccounts;
use App\Libraries\ReturnStore;
class MigrateCustomers extends BaseCommand
{
    protected $group='GST';protected $name='customers:migrate';protected $description='Apply additive customer and billing schema migrations without altering GST return records.';
    public function run(array $params){new CustomerAccounts(new ReturnStore());CLI::write('Customer schema migrations applied. No accounts or wallet balances were imported or replaced.');}
}
