<?php

require_once __DIR__ . '/CurrentBackupStorage.php';
require_once __DIR__ . '/Db.php';

/** Durable private restore ledger. No browser connection owns a restore. */
final class CurrentRestoreJobStore
{
    const STAGES = ['Authenticating package','Checking compatibility','Preparing user data','Restoring database batches',
        'Restoring persistent files','Verifying restored data','Complete'];
    private $storage;
    public function __construct(CurrentBackupStorage $storage) { $this->storage = $storage; }

    public function create($userId, $displayName, $idempotencyKey, $sourceType, $sourceBackupId, $artifactPath, $keyFile)
    {
        if (!is_int($userId) || $userId < 1 || trim((string) $displayName) === '' || strlen((string) $idempotencyKey) < 16
            || !in_array($sourceType, ['existing','upload'], true) || !is_file($artifactPath) || !is_file($keyFile)) {
            throw new InvalidArgumentException('Restore request is invalid.');
        }
        $hash = hash('sha256', $userId . "\0" . $sourceType . "\0" . (string) $sourceBackupId . "\0" . $idempotencyKey);
        return $this->locked(function () use ($userId,$displayName,$hash,$sourceType,$sourceBackupId,$artifactPath,$keyFile) {
            foreach ($this->rawRows() as $row) if (hash_equals($row['idempotency_hash'], $hash)) { $public=self::publicRow($row);$public['_created']=false;return $public; }
            $id = self::uuid(); $now = self::now();
            $row = ['operation_id'=>$id,'idempotency_hash'=>$hash,'initiated_by_user_id'=>$userId,
                'initiated_by_name'=>trim($displayName),'source_type'=>$sourceType,'source_backup_id'=>$sourceBackupId,
                'artifact_path'=>$artifactPath,'key_file'=>$keyFile,'status'=>'Queued','stage'=>self::STAGES[0],
                'stage_progress_percent'=>0,'overall_progress_percent'=>0,'revision'=>1,'initiated_at'=>$now,
                'finished_at'=>null,'failure_summary'=>null,'restored_table_count'=>null,'restored_row_count'=>null,'updated_at'=>$now];
            $this->write($row); $public=self::publicRow($row);$public['_created']=true;return $public;
        });
    }
    public function recent($limit=30) { $rows=$this->rawRows(); usort($rows,function($a,$b){return strcmp($b['initiated_at'],$a['initiated_at']);}); return array_map([self::class,'publicRow'],array_slice($rows,0,$limit)); }
    public function get($id) { $path=$this->storage->restoreJob($id); return is_file($path) ? self::publicRow($this->read($path)) : null; }
    public function claimQueued() { return $this->locked(function(){ foreach(array_reverse($this->recent(100)) as $public){ if($public['status']!=='Queued') continue; $row=$this->required($public['operation_id']); $row['status']='Running'; $row['revision']++; $row['updated_at']=self::now(); $this->write($row); return self::publicRow($row); } return null; }); }
    public function progress($id,$stage,$percent) { $index=array_search($stage,self::STAGES,true); if($index===false||!is_int($percent)||$percent<0||$percent>100) throw new InvalidArgumentException('Restore progress is invalid.'); return $this->locked(function()use($id,$stage,$percent,$index){$row=$this->required($id); if($row['status']!=='Running') throw new RuntimeException('Restore is not running.'); $old=array_search($row['stage'],self::STAGES,true); if($index<$old||($index===$old&&$percent<$row['stage_progress_percent'])) throw new RuntimeException('Restore progress must be monotonic.'); $row['stage']=$stage;$row['stage_progress_percent']=$percent;$row['overall_progress_percent']=min(100,(int)floor((($index*100)+$percent)/count(self::STAGES)));$row['revision']++;$row['updated_at']=self::now();$this->write($row);return self::publicRow($row);}); }
    public function complete($id,$tables,$rows) { return $this->locked(function()use($id,$tables,$rows){$row=$this->required($id);$now=self::now();$row['status']='Complete';$row['stage']='Complete';$row['stage_progress_percent']=100;$row['overall_progress_percent']=100;$row['finished_at']=$now;$row['restored_table_count']=$tables;$row['restored_row_count']=$rows;$row['revision']++;$row['updated_at']=$now;$this->write($row);return self::publicRow($row);}); }
    public function fail($id,$message) { return $this->locked(function()use($id,$message){$row=$this->required($id);if(in_array($row['status'],['Complete','Failed'],true))return self::publicRow($row);$row['status']='Failed';$row['finished_at']=self::now();$row['failure_summary']=mb_substr(trim((string)$message)?:'Restore failed.',0,240);$row['revision']++;$row['updated_at']=self::now();$this->write($row);return self::publicRow($row);}); }
    private function locked($fn){$h=fopen($this->storage->restoreJobStoreLock(),'c+b');@chmod($this->storage->restoreJobStoreLock(),0600);if(!flock($h,LOCK_EX))throw new RuntimeException('Restore ledger is locked.');try{return call_user_func($fn);}finally{flock($h,LOCK_UN);fclose($h);}}
    private function rawRows(){ $rows=[];foreach(glob($this->storage->restoreJobs().DIRECTORY_SEPARATOR.'*.json')?:[] as $path)if(is_file($path)&&!is_link($path))$rows[]=$this->read($path);return $rows; }
    private function required($id){$path=$this->storage->restoreJob($id);if(!is_file($path)||is_link($path))throw new RuntimeException('Restore operation not found.');return $this->read($path);}
    private function read($path){$h=@fopen($path,'rb');if(!is_resource($h))throw new RuntimeException('Restore metadata could not be read.');if(!flock($h,LOCK_SH)){fclose($h);throw new RuntimeException('Restore metadata could not be locked.');}try{$json=stream_get_contents($h);}finally{flock($h,LOCK_UN);fclose($h);}if(!is_string($json)||$json===''||strlen($json)>65536)throw new RuntimeException('Restore metadata could not be read.');$row=json_decode($json,true);if(!is_array($row)||!in_array($row['status']??'', ['Queued','Running','Complete','Failed'],true)||!in_array($row['stage']??'',self::STAGES,true))throw new RuntimeException('Restore metadata is invalid.');return $row;}
    private function write($row){$path=$this->storage->restoreJob($row['operation_id']);$json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$h=@fopen($path,'c+b');if(!is_resource($h))throw new RuntimeException('Restore metadata could not be opened.');@chmod($path,0600);if(!flock($h,LOCK_EX)){fclose($h);throw new RuntimeException('Restore metadata could not be locked.');}try{if(!ftruncate($h,0)||fseek($h,0)!==0)throw new RuntimeException('Restore metadata could not be prepared.');for($offset=0,$length=strlen($json);$offset<$length;){$written=fwrite($h,substr($json,$offset));if(!is_int($written)||$written<1)throw new RuntimeException('Restore metadata could not be written.');$offset+=$written;}if(!fflush($h))throw new RuntimeException('Restore metadata could not be committed.');}finally{flock($h,LOCK_UN);fclose($h);}}
    private static function publicRow($row){unset($row['idempotency_hash'],$row['artifact_path'],$row['key_file']);return $row;}
    private static function now(){return gmdate('Y-m-d\TH:i:s\Z');}
    private static function uuid(){return Db::uuidV4();}
}
