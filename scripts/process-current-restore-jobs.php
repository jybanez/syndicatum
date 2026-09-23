<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once dirname(__DIR__).'/src/CurrentRestoreWorker.php';
require_once dirname(__DIR__).'/src/SettingsService.php';
require_once dirname(__DIR__).'/src/PrivateStorage.php';
try{$root=dirname(__DIR__);$settings=new SettingsService(Db::pdo());$storage=new CurrentBackupStorage($root,$settings->get('recovery.backup_base_path'));$jobs=new CurrentRestoreJobStore($storage);$configured=getenv('SYNDICATUM_AVATAR_DIR');$avatars=is_string($configured)&&trim($configured)!==''?$configured:PrivateStorage::file('syndicatum-avatars');$count=(new CurrentRestoreWorker($storage,$jobs,$avatars,new RealtimeIntegration($settings)))->runPending();echo 'Processed restore jobs: '.$count.PHP_EOL;}catch(Throwable $error){fwrite(STDERR,'Restore worker failed: '.$error->getMessage().PHP_EOL);exit(1);}
