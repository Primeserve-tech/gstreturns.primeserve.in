<?php
namespace App\Libraries;
use Throwable;

final class ReturnWorker
{
    public function __construct(private ReturnStore $store, private ReturnGateway $gateway) {}

    public function tick(): bool
    {
        // One process owns this lock across initiation/status requests. Never send two initiations concurrently.
        $lock=fopen(WRITEPATH.'data/worker-v3.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return false; }
        try {
            file_put_contents(WRITEPATH.'data/worker-v3-heartbeat',(string)time());
            $this->store->cleanup();
            $this->store->query("UPDATE jobs SET status='attention',message='Worker restarted during initiation. Check the provider outcome before any new request.',updated=? WHERE status='initiating'",[time()]);
            $job=$this->store->query("SELECT * FROM jobs WHERE status='queued' ORDER BY rowid LIMIT 1")->fetch();
            if ($job) {
                $this->store->update($job['id'],['status'=>'initiating','message'=>'Initiating return request…']);
                try {
                    $connection=$this->store->connection($job['owner']);
                    if ($connection['gstin']!==$job['gstin']) throw new \RuntimeException('Reconnect the GSTIN associated with this job.');
                    $id=$this->gateway->audited($this->store,$job['owner'],$job['client_ip'])->initiate($this->store->open($connection['secret']),$job);
                    $this->store->update($job['id'],['status'=>'processing','job_id'=>$id,'message'=>'Request accepted. Waiting for job completion.','next_poll'=>time()+$this->interval(),'deadline'=>min($this->store->assertWindow($job['owner']),time()+max(60,min(600,(int)env('GST_JOB_TIMEOUT_SECONDS',540))))]);
                } catch (Throwable $e) {
                    $rejected=$e instanceof ProviderException && $e->definiteRejection;
                    $this->store->update($job['id'],['status'=>$this->gateway->fixture||$rejected?'failed':'attention','message'=>$e->getMessage(),'retry_allowed'=>$this->gateway->fixture?1:0]);
                }
            }
            // Poll an independently recorded job. Status checks do not reinitiate returns.
            $poll=$this->store->query("SELECT * FROM jobs WHERE status='processing' AND next_poll<=? ORDER BY next_poll,rowid LIMIT 1",[time()])->fetch();
            if ($poll) $this->poll($poll);
            if ((int)date('s')%30===0) $this->store->cleanup();
            return (bool)($job || $poll);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function interval(): int { return max(1,min(3600,(int)env('GST_POLL_SECONDS',10))); }

    private function poll(array $job): void
    {
        if ((int)$job['deadline']<=time()) {
            $this->store->update($job['id'],['status'=>'timeout','message'=>'Monitoring deadline reached. You may resume status checks without initiating a new return.','retry_allowed'=>1]); return;
        }
        try {
            $connection=$this->store->connection($job['owner']);
            if ($connection['gstin']!==$job['gstin']) throw new \RuntimeException('Reconnect the GSTIN associated with this job.');
            $result=$this->gateway->audited($this->store,$job['owner'],$job['client_ip'])->status($this->store->open($connection['secret']),$job);
            $this->store->update($job['id'],['status'=>$result['status'],'upstream_status'=>$result['raw'],'poll_errors'=>0,'next_poll'=>time()+$this->interval(),'retry_allowed'=>$result['status']==='failed' && ($this->gateway->fixture || ($this->gateway->contract['allowInitiationRetry']??false))?1:0,'message'=>match($result['status']) {'completed'=>'Your ZIP file is ready to download.','failed'=>'The provider reported that this job failed.','empty'=>'No return data is available for this period.',default=>'The return is being processed.'}]);
        } catch (Throwable $e) {
            if ($e instanceof ProviderException && $e->providerStatus!==null) $this->store->update($job['id'],['upstream_status'=>$e->providerStatus]);
            $errors=(int)$job['poll_errors']+1;
            $limit=max(1,min(8,(int)env('GST_POLL_MAX_ERRORS',4)));
            $this->store->update($job['id'],['status'=>$errors>=$limit?'monitoring_error':'processing','poll_errors'=>$errors,'next_poll'=>time()+min(300,$this->interval()*(2**$errors)),'retry_allowed'=>$errors>=$limit?1:0,'message'=>$errors>=$limit?'Status checks paused after repeated errors. Reconnect if needed, then resume checks.':$e->getMessage().' Retrying status check with backoff.']);
        }
    }
}
