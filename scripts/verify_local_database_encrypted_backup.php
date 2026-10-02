<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$path=getenv('SUXIOS_LOCAL_BACKUP_TARGET') ?: ''; $expected=getenv('SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256') ?: ''; $key=hex2bin(getenv('SUXIOS_LOCAL_BACKUP_KEY') ?: '') ?: '';
if (strlen($key)!==32 || !preg_match('/^[a-f0-9]{64}$/D',$expected) || !is_file($path) || !hash_equals($expected,hash_file('sha256',$path))) throw new RuntimeException('Encrypted backup identity invalid');
$file=fopen($path,'rb'); $header="SUXIOS-AES256GCM-CHUNKS-v1\n";
if (fread($file,strlen($header))!==$header) throw new RuntimeException('Encrypted backup header invalid');
$read=static function(int $size)use($file):string { $data='';while(strlen($data)<$size){$part=fread($file,$size-strlen($data));if($part===false || $part==='')throw new RuntimeException('Encrypted backup truncated');$data.=$part;}return $data; };
$chunks=0;$bytes=0;$hash=hash_init('sha256');
while (($length=fread($file,4))!=='') {
    if (strlen($length)!==4) throw new RuntimeException('Encrypted backup framing invalid');
    $size=unpack('N',$length)[1]; if ($size<1 || $size>65536) throw new RuntimeException('Encrypted backup chunk size invalid');
    $nonce=$read(12);$tag=$read(16);$cipher=$read($size);$plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag);
    if ($plain===false) throw new RuntimeException('Encrypted backup authentication failed');
    $bytes+=strlen($plain);$chunks++;hash_update($hash,$plain);unset($plain,$cipher);
}
fclose($file);unset($key);if ($chunks===0)throw new RuntimeException('Empty backup');
echo json_encode(['status'=>'all_encrypted_chunks_authenticated','plaintext_bytes'=>$bytes,'encrypted_chunks'=>$chunks,'plaintext_sha256'=>hash_final($hash),'plaintext_written_to_disk'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
