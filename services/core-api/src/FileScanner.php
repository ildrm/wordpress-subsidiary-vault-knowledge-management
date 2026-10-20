<?php
declare(strict_types=1);
namespace SubsidiaryVault;

final class FileScanner
{
    private static function address(): string
    {
        $address=getenv('SV_CLAMD_ADDRESS')?:'';
        if(!preg_match('~^(unix:///.+|tcp://[a-zA-Z0-9.\-]+:[0-9]{1,5})$~D',$address)){throw new ApiError(503,'scanner_configuration_invalid');}return $address;
    }
    public static function status(): array
    {
        $stream=@stream_socket_client(self::address(),$error,$message,3,STREAM_CLIENT_CONNECT);
        if(!$stream){throw new ApiError(503,'scanner_unavailable');}stream_set_timeout($stream,5);
        try{self::write($stream,"zVERSION\0");$response=stream_get_line($stream,1024,"\0");}finally{fclose($stream);}
        if(!is_string($response)||!preg_match('~^ClamAV ([0-9.]+)/([0-9]+)/(.+)$~D',trim($response),$m)){throw new ApiError(503,'scanner_database_unknown');}
        try{$at=(new \DateTimeImmutable($m[3],new \DateTimeZone('UTC')))->getTimestamp();}catch(\Throwable){throw new ApiError(503,'scanner_database_unknown');}
        $maxAge=(int)(getenv('SV_SCAN_MAX_DATABASE_AGE_SECONDS')?:259200);
        if($maxAge<3600||$maxAge>604800||$at>time()+3600||$at<time()-$maxAge){throw new ApiError(503,'scanner_database_stale');}
        return ['engine'=>$m[1],'definition_revision'=>$m[2],'definition_at'=>$at];
    }
    public static function validate(string $bytes): string
    {
        if((getenv('SV_UPLOAD_PROFILE')?:'text')==='text'){
            if(!mb_check_encoding($bytes,'UTF-8')||str_contains($bytes,"\0")||preg_match('/[\x01-\x08\x0b\x0c\x0e-\x1f]/',$bytes)){throw new ApiError(415,'text_only_local_profile');}
            if(str_contains($bytes,'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')){throw new ApiError(422,'test_signature_rejected');}return 'text/plain';
        }
        if(getenv('SV_UPLOAD_PROFILE')!=='scanned'){throw new ApiError(503,'upload_profile_invalid');}
        self::status();
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(!in_array($mime,['text/plain','application/pdf','image/png','image/jpeg','application/json','text/csv'],true)){throw new ApiError(415,'file_type_denied');}
        $address=self::address();
        $stream=@stream_socket_client($address,$error,$message,3,STREAM_CLIENT_CONNECT);
        if(!$stream){throw new ApiError(503,'scanner_unavailable');}stream_set_timeout($stream,15);
        try{
            self::write($stream,"zINSTREAM\0");
            for($offset=0;$offset<strlen($bytes);$offset+=65536){$chunk=substr($bytes,$offset,65536);self::write($stream,pack('N',strlen($chunk)).$chunk);}
            self::write($stream,pack('N',0));$response='';
            while(strlen($response)<2048&&!feof($stream)){
                $part=fread($stream,512);if($part===false){throw new ApiError(503,'scanner_unavailable');}$response.=$part;
                if(str_contains($response,"\0")||str_contains($response,"\n")){break;}
                if(stream_get_meta_data($stream)['timed_out']){throw new ApiError(503,'scanner_timeout');}
            }
            $response=trim($response,"\0\r\n");
            if(str_ends_with($response,' FOUND')){throw new ApiError(422,'malware_rejected');}
            if($response!=='stream: OK'){throw new ApiError(503,'scanner_inconclusive');}
        }finally{fclose($stream);}
        return $mime;
    }
    private static function write($stream,string $data): void
    {
        $offset=0;while($offset<strlen($data)){$n=fwrite($stream,substr($data,$offset));if(!$n){throw new ApiError(503,'scanner_unavailable');}$offset+=$n;}
    }
}
