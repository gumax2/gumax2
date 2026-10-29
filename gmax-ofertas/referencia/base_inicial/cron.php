<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Apenas CLI');}
require __DIR__.'/app.php';
try{echo slot(db(),false).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
