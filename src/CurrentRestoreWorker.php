<?php

require_once __DIR__ . '/CurrentRestoreJobStore.php';
require_once __DIR__ . '/CurrentBackupEnvelope.php';
require_once __DIR__ . '/PortableBackupManifest.php';
require_once __DIR__ . '/PortableBackupArchive.php';
require_once __DIR__ . '/InAppRestorePolicy.php';
require_once __DIR__ . '/RealtimeIntegration.php';

final class CurrentRestoreWorker
{
    private $storage; private $jobs; private $avatarRoot; private $realtime;
    public function __construct(CurrentBackupStorage $storage, CurrentRestoreJobStore $jobs, $avatarRoot, $realtime)
    { $this->storage=$storage;$this->jobs=$jobs;$this->avatarRoot=$avatarRoot;$this->realtime=$realtime; }

    public function runPending()
    {
        $lock=fopen($this->storage->restoreWorkerLock(),'c+b');@chmod($this->storage->restoreWorkerLock(),0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return 0;}
        try{$count=0;while(($job=$this->jobs->claimQueued())!==null){$this->process($job);$count++;}return $count;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private function process(array $job)
    {
        $id=$job['operation_id']; $raw=$this->readRawJob($id); $decrypted=null;
        try {
            $this->publish($this->jobs->progress($id,'Authenticating package',5));
            $encoded=trim((string)file_get_contents($raw['key_file']));
            $key=CurrentBackupEnvelope::keyFromBase64($encoded);
            $decrypted=CurrentBackupEnvelope::decryptToPrivateStage($raw['artifact_path'],$this->storage->restoreStageRoot(),$key);
            @unlink($raw['key_file']);
            $manifest=PortableBackupManifest::parse($decrypted['manifest_json']);
            PortableBackupArchive::verify($decrypted['archive_path'],$manifest);
            $this->publish($this->jobs->progress($id,'Authenticating package',100));
            $this->publish($this->jobs->progress($id,'Checking compatibility',15));
            $pdo=Db::pdo(); InAppRestorePolicy::assertCompatible($pdo,$manifest);
            $this->publish($this->jobs->progress($id,'Checking compatibility',100));
            $restoreTables=array_values(array_intersect(InAppRestorePolicy::restoreTables(),array_keys($manifest['sql']['row_counts'])));
            $currentTables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
            $clearTables=array_values(array_intersect(InAppRestorePolicy::clearOnlyTables(),$currentTables));
            $this->publish($this->jobs->progress($id,'Preparing user data',100));
            $batches=array_values(array_filter($manifest['sql']['data'],function($entry)use($restoreTables){return in_array($entry['table'],$restoreTables,true);}));
            $total=max(1,count($batches));$done=0;$rows=0;$lastDatabasePublished=-1;
            $zip=new ZipArchive();if($zip->open($decrypted['archive_path'],ZipArchive::RDONLY)!==true)throw new RuntimeException('Verified backup archive could not be opened.');
            $pdo->beginTransaction();$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach(array_reverse(array_values(array_unique(array_merge($restoreTables,$clearTables)))) as $table){self::identifier($table);$pdo->exec('DELETE FROM `'.$table.'`');}
            foreach($batches as $entry){$sql=$zip->getFromName($entry['path']);if(!is_string($sql))throw new RuntimeException('A verified database batch is unavailable.');$this->executeBatch($pdo,$entry['table'],$sql);$done++;$rows+=(int)$entry['rows'];$percent=(int)floor($done*100/$total);$snapshot=$this->jobs->progress($id,'Restoring database batches',$percent);if($percent===100||$percent-$lastDatabasePublished>=10){$this->publish($snapshot);$lastDatabasePublished=$percent;}}
            $persistent=$manifest['persistent_files'];$pTotal=max(1,count($persistent));$pDone=0;
            if(!is_dir($this->avatarRoot)&&!@mkdir($this->avatarRoot,0700,true))throw new RuntimeException('Private avatar directory is unavailable.');
            $lastPersistentPublished=-1;foreach($persistent as $entry){$name=basename($entry['path']);$bytes=$zip->getFromName($entry['path']);if(!is_string($bytes)||strlen($bytes)!==$entry['bytes']||!hash_equals($entry['sha256'],hash('sha256',$bytes)))throw new RuntimeException('A persistent file failed verification.');$target=rtrim($this->avatarRoot,'/\\').DIRECTORY_SEPARATOR.$name;$tmp=$target.'.restore-'.bin2hex(random_bytes(5));if(file_put_contents($tmp,$bytes,LOCK_EX)===false||!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('A restored avatar could not be installed.');}@chmod($target,0600);$pDone++;$percent=(int)floor($pDone*100/$pTotal);$snapshot=$this->jobs->progress($id,'Restoring persistent files',$percent);if($percent===100||$percent-$lastPersistentPublished>=10){$this->publish($snapshot);$lastPersistentPublished=$percent;}}
            if($persistent===[])$this->publish($this->jobs->progress($id,'Restoring persistent files',100));
            $this->publish($this->jobs->progress($id,'Verifying restored data',20));
            foreach($restoreTables as $table){self::identifier($table);$actual=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();$expected=(int)$manifest['sql']['row_counts'][$table];if($actual!==$expected)throw new RuntimeException('Restored row count differs for '.$table.'.');if(!hash_equals($manifest['sql']['row_hashes'][$table],$this->tableRowHash($pdo,$table)))throw new RuntimeException('Restored row content differs for '.$table.'.');}
            $this->assertPreservedReferences($pdo,$restoreTables,$clearTables);
            $this->publish($this->jobs->progress($id,'Verifying restored data',100));
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');$pdo->commit();
            $this->publish($this->jobs->complete($id,count($restoreTables),$rows));
        } catch(Throwable $error){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(isset($pdo)&&$pdo instanceof PDO)try{$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');}catch(Throwable $ignored){}try{$this->publish($this->jobs->fail($id,'Restore failed; no automatic retry was attempted. Check restore logs: '.$error->getMessage()));}catch(Throwable $publishError){error_log($publishError->getMessage());}error_log('Syndicatum restore '.$id.' failed: '.$error->getMessage());}
        finally{if(isset($zip)&&$zip instanceof ZipArchive)$zip->close();if($decrypted&&isset($decrypted['stage_path']))$this->removeTree($decrypted['stage_path']);@unlink($raw['key_file']);@unlink($raw['artifact_path']);}
    }
    private function executeBatch(PDO $pdo,$table,$sql){self::identifier($table);$lines=preg_split('/\n/',str_replace("\r\n","\n",$sql));foreach($lines as $line){if($line==='')continue;if(!preg_match('/\AINSERT INTO `'.preg_quote($table,'/').'` \(.+\) VALUES \(.+\);\z/',$line))throw new RuntimeException('Database batch contains an unexpected statement.');$pdo->exec($line);}}
    private function assertPreservedReferences(PDO $pdo,array $restoreTables,array $clearTables){$changed=array_fill_keys(array_merge($restoreTables,$clearTables),true);$restored=array_fill_keys($restoreTables,true);$sql="SELECT table_name,column_name,referenced_table_name,referenced_column_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND referenced_table_name IS NOT NULL ORDER BY table_name,constraint_name,ordinal_position";foreach($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $key){$source=$key['table_name'];$target=$key['referenced_table_name'];if(isset($changed[$source])||!isset($restored[$target]))continue;self::identifier($source);self::identifier($target);self::identifier($key['column_name']);self::identifier($key['referenced_column_name']);$count=(int)$pdo->query('SELECT COUNT(*) FROM `'.$source.'` s LEFT JOIN `'.$target.'` t ON s.`'.$key['column_name'].'` = t.`'.$key['referenced_column_name'].'` WHERE s.`'.$key['column_name'].'` IS NOT NULL AND t.`'.$key['referenced_column_name'].'` IS NULL')->fetchColumn();if($count>0)throw new RuntimeException('The backup is incompatible with preserved target configuration references: '.$source.' -> '.$target.'.');}}
    private function tableRowHash(PDO $pdo,$table){self::identifier($table);$columnsStatement=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position');$columnsStatement->execute([$table]);$columns=$columnsStatement->fetchAll(PDO::FETCH_COLUMN);$orderStatement=$pdo->prepare("SELECT column_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND constraint_name='PRIMARY' ORDER BY ordinal_position");$orderStatement->execute([$table]);$order=$orderStatement->fetchAll(PDO::FETCH_COLUMN);if(!$columns||!$order)throw new RuntimeException('Restored table cannot be verified: '.$table.'.');$quoted=array_map(function($name){self::identifier($name);return '`'.$name.'`';},$order);$rows=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY '.implode(',',$quoted));$hash=hash_init('sha256');while($row=$rows->fetch(PDO::FETCH_ASSOC)){foreach($columns as $column){$value=$row[$column];if(is_resource($value))$value=stream_get_contents($value);if($value===null){hash_update($hash,"\x00");continue;}$bytes=(string)$value;hash_update($hash,"\x01".pack('N',strlen($bytes)).$bytes);}}return hash_final($hash);}
    private function readRawJob($id){$json=file_get_contents($this->storage->restoreJob($id));$row=json_decode($json,true);if(!is_array($row))throw new RuntimeException('Restore metadata is invalid.');return $row;}
    private function publish($job){$this->realtime->publishRestoreUpdated($job);}
    private static function identifier($name){if(!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/',$name))throw new RuntimeException('Restore table name is invalid.');}
    private function removeTree($path){if(!is_dir($path)||is_link($path))return;foreach(scandir($path) as $name){if($name==='.'||$name==='..')continue;$child=$path.DIRECTORY_SEPARATOR.$name;if(is_dir($child)&&!is_link($child))$this->removeTree($child);else @unlink($child);}@rmdir($path);}
}
