<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupJobStore.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupEnvelope.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentRestoreJobStore.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentRestoreWorkerLauncher.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/SettingsService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/RealtimeIntegration.php';

function restorePrivateWrite($path, $bytes) {
    $h=@fopen($path,'xb');if(!is_resource($h))throw new RuntimeException('Private restore staging could not be created.');
    @chmod($path,0600);try{for($offset=0,$length=strlen($bytes);$offset<$length;){$written=fwrite($h,substr($bytes,$offset));if(!is_int($written)||$written<1)throw new RuntimeException('Private restore staging could not be written.');$offset+=$written;}}finally{fclose($h);}
}
function restorePrivateCopy($source,$destination) {
    $in=@fopen($source,'rb');$out=@fopen($destination,'xb');if(!is_resource($in)||!is_resource($out)){if(is_resource($in))fclose($in);if(is_resource($out))fclose($out);throw new RuntimeException('Restore package could not be staged.');}
    @chmod($destination,0600);try{while(!feof($in)){$chunk=fread($in,1048576);if($chunk===false)throw new RuntimeException('Restore package could not be read.');if($chunk==='')break;for($offset=0;$offset<strlen($chunk);){$written=fwrite($out,substr($chunk,$offset));if(!is_int($written)||$written<1)throw new RuntimeException('Restore package could not be staged.');$offset+=$written;}}}finally{fclose($in);fclose($out);}
}
function restoreReceiveChunk(CurrentBackupStorage $storage, array $user, $encodedKey) {
    $uploadId=strtolower(trim((string)($_POST['upload_id']??'')));$index=filter_var($_POST['chunk_index']??null,FILTER_VALIDATE_INT);$count=filter_var($_POST['chunk_count']??null,FILTER_VALIDATE_INT);
    if(!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',$uploadId)||$index===false||$count===false||$index<0||$count<1||$count>8192||$index>=$count)throw new InvalidArgumentException('Backup upload chunk metadata is invalid.');
    $upload=$_FILES['backup_chunk']??null;if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']??''))throw new InvalidArgumentException('A backup upload chunk is missing.');
    $bytes=filesize($upload['tmp_name']);if(!is_int($bytes)||$bytes<1||$bytes>1048576)throw new InvalidArgumentException('Backup upload chunks must be between 1 byte and 1 MiB.');
    $base=$storage->restoreStageRoot().DIRECTORY_SEPARATOR.'upload-'.$uploadId;$artifact=$base.'.syndicatum-backup';$keyFile=$base.'.key';$metadata=$base.'.json';$lockPath=$base.'.lock';
    $lock=@fopen($lockPath,'c+b');if(!is_resource($lock)||!flock($lock,LOCK_EX))throw new RuntimeException('Backup upload could not be locked.');@chmod($lockPath,0600);
    try{
        if($index===0){if(file_exists($artifact)||file_exists($metadata)||file_exists($keyFile))throw new InvalidArgumentException('This upload identifier has already been used.');CurrentBackupEnvelope::keyFromBase64($encodedKey);restorePrivateWrite($artifact,'');restorePrivateWrite($keyFile,$encodedKey);$state=['user_id'=>(int)$user['id'],'chunk_count'=>$count,'next_index'=>0,'bytes'=>0,'key_id'=>CurrentBackupEnvelope::keyId(CurrentBackupEnvelope::keyFromBase64($encodedKey))];}
        else{$json=is_file($metadata)?file_get_contents($metadata):false;$state=is_string($json)?json_decode($json,true):null;if(!is_array($state))throw new InvalidArgumentException('The backup upload session is unavailable.');CurrentBackupEnvelope::keyFromBase64($encodedKey);if((int)$state['user_id']!==(int)$user['id']||(int)$state['chunk_count']!==$count||!hash_equals((string)$state['key_id'],CurrentBackupEnvelope::keyId(CurrentBackupEnvelope::keyFromBase64($encodedKey))))throw new InvalidArgumentException('Backup upload session metadata does not match.');}
        if((int)$state['next_index']!==$index||!is_file($artifact)||filesize($artifact)!==(int)$state['bytes'])throw new InvalidArgumentException('Backup upload chunks must be sent once and in order.');
        $in=fopen($upload['tmp_name'],'rb');$out=fopen($artifact,'ab');try{while(!feof($in)){$chunk=fread($in,65536);if($chunk===false)throw new RuntimeException('Backup upload chunk could not be read.');if($chunk==='')break;for($offset=0;$offset<strlen($chunk);){$written=fwrite($out,substr($chunk,$offset));if(!is_int($written)||$written<1)throw new RuntimeException('Backup upload chunk could not be stored.');$offset+=$written;}}if(!fflush($out))throw new RuntimeException('Backup upload chunk could not be committed.');}finally{fclose($in);fclose($out);}
        $state['next_index']=$index+1;$state['bytes']=(int)$state['bytes']+$bytes;$json=json_encode($state,JSON_UNESCAPED_SLASHES);if(file_put_contents($metadata,$json,LOCK_EX)===false)throw new RuntimeException('Backup upload state could not be committed.');@chmod($metadata,0600);
        return ['complete'=>$state['next_index']===$count,'artifact'=>$artifact,'key_file'=>$keyFile,'metadata'=>$metadata,'lock'=>$lockPath,'received_chunks'=>$state['next_index'],'chunk_count'=>$count,'bytes'=>$state['bytes']];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

try {
    $method=Api::method();if(!in_array($method,['GET','POST'],true))Api::json(['error'=>true,'code'=>'method_not_allowed','message'=>'Method not allowed.'],405,['Allow'=>'GET, POST']);
    $auth=new AuthService(Db::pdo());$user=$auth->requireAdministrator();if($method!=='GET')$auth->validateCsrf($user,Api::csrfToken());$root=dirname(dirname(dirname(__DIR__)));
    $settings=new SettingsService(Db::pdo());$storage=new CurrentBackupStorage($root,$settings->get('recovery.backup_base_path'));
    $backups=new CurrentBackupJobStore($storage);$jobs=new CurrentRestoreJobStore($storage);$realtime=new RealtimeIntegration($settings);
    if($method==='GET'){
        $candidates=array_values(array_filter($backups->recent(),function($row)use($storage){return $row['status']==='Ready'&&$row['include_data']===true&&is_file($storage->artifact($row['operation_id']));}));
        Api::json(['data'=>['backups'=>$candidates,'restores'=>$jobs->recent()]]);
    }
    // This mutation does not accept client paths, database credentials, or cutover options.
    $idempotency=Api::idempotencyKey();
    $contentType=strtolower((string)Api::header('Content-Type'));$sourceType='';$sourceBackupId=null;$sourcePath='';$encodedKey='';$preStaged=false;$uploadState=null;
    if(strpos($contentType,'multipart/form-data')===0){
        $sourceType='upload';$encodedKey=trim((string)($_POST['recovery_key']??''));
        if((string)($_POST['confirmation']??'')!=='RESTORE DATA')throw new InvalidArgumentException('Confirm the destructive data restore explicitly.');
        $uploadState=restoreReceiveChunk($storage,$user,$encodedKey);
        if(!$uploadState['complete'])Api::json(['data'=>['upload'=>['received_chunks'=>$uploadState['received_chunks'],'chunk_count'=>$uploadState['chunk_count'],'bytes'=>$uploadState['bytes']]]],202);
        $sourcePath=$uploadState['artifact'];$preStaged=true;
    } else {
        $body=Api::body();if(array_keys($body)!==['backup_operation_id','confirmation']||!is_string($body['backup_operation_id'])||($body['confirmation']??'')!=='RESTORE DATA')throw new InvalidArgumentException('Choose an existing backup and confirm the destructive data restore explicitly.');
        $sourceType='existing';$sourceBackupId=trim($body['backup_operation_id']);$backup=$backups->get($sourceBackupId);
        if($backup===null||$backup['status']!=='Ready'||$backup['include_data']!==true)throw new InvalidArgumentException('Choose a Ready full-clone backup.');
        $sourcePath=$storage->artifact($sourceBackupId);$encodedKey=(string)CurrentBackupStorage::configuration('SYNDICATUM_BACKUP_KEY');
        CurrentBackupEnvelope::keyFromBase64($encodedKey);
    }
    $token=bin2hex(random_bytes(16));$artifact=$preStaged?$sourcePath:$storage->restoreStageRoot().DIRECTORY_SEPARATOR.'source-'.$token.'.syndicatum-backup';$keyFile=$preStaged?$uploadState['key_file']:$storage->restoreStageRoot().DIRECTORY_SEPARATOR.'key-'.$token.'.txt';
    try{if(!$preStaged){restorePrivateCopy($sourcePath,$artifact);restorePrivateWrite($keyFile,$encodedKey);}$job=$jobs->create((int)$user['id'],(string)$user['display_name'],$idempotency,$sourceType,$sourceBackupId,$artifact,$keyFile);$created=$job['_created']===true;unset($job['_created']);if(!$created){@unlink($artifact);@unlink($keyFile);}if($uploadState){@unlink($uploadState['metadata']);@unlink($uploadState['lock']);}if($job['status']==='Queued'){$realtime->publishRestoreUpdated($job);CurrentRestoreWorkerLauncher::launch($storage,$root);}}
    catch(Throwable $error){@unlink($artifact);@unlink($keyFile);if($uploadState){@unlink($uploadState['metadata']);@unlink($uploadState['lock']);}if(isset($job['operation_id']))try{$jobs->fail($job['operation_id'],$error->getMessage());}catch(Throwable $ignored){}if(stripos($error->getMessage(),'Realtime')!==false)throw new RuntimeException('RESTORE_REALTIME_UNAVAILABLE');throw $error;}
    $auth->audit((int)$user['id'],'restore.started','restore',$job['operation_id'],['source_type'=>$sourceType,'source_backup_id'=>$sourceBackupId,'scope'=>'user_generated_data']);
    Api::json(['data'=>['restore'=>$jobs->get($job['operation_id'])]],202);
} catch(InvalidArgumentException $error){Api::json(['error'=>true,'code'=>'validation_failed','message'=>$error->getMessage()],422);}
catch(RuntimeException $error){$code=$error->getMessage();$status=$code==='AUTHENTICATION_REQUIRED'?401:($code==='ADMINISTRATOR_REQUIRED'||$code==='CSRF_VALIDATION_FAILED'?403:503);$message=$code==='RESTORE_REALTIME_UNAVAILABLE'?'Restore Realtime is unavailable; no restore was started.':($status===503?'Restore service is unavailable.':$error->getMessage());Api::json(['error'=>true,'code'=>strtolower($code),'message'=>$message],$status);}
catch(Throwable $error){error_log('Syndicatum restore API failed: '.$error->getMessage());Api::json(['error'=>true,'code'=>'server_error','message'=>'Restore service is unavailable.'],500);}
