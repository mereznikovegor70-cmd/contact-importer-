<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Contacts\Normalizer as N;
use Contacts\TableReader;
use Contacts\GoogleSource;
use Contacts\Exporter;
$checks=0;
function check(bool $ok,string $label): void { global $checks; if(!$ok){throw new RuntimeException('FAIL: '.$label);} $checks++; echo 'OK '.$label.PHP_EOL; }
function rejects(callable $fn,string $label):void {try{$fn();}catch(Throwable){check(true,$label);return;}check(false,$label);}
check(N::phones('+7 (3842) 75‒67‒97')==='+73842756797','Unicode phone separators');
check(N::phones('8 (950) 594-77-71; +79505947771; 9505947771')==='+79505947771','Russian prefixes and deduplication');
check(N::phones('+7 (495) 123-45-67 доб. 123')==='+74951234567 доб. 123','Phone extension');
check(N::tel('+74951234567 доб. 123')==='tel:+74951234567;ext=123','Dial link with extension');
check(N::tel('javascript:alert(1)')===null,'Unsafe dial value');
check(N::websiteLink('javascript:alert(1)')===null,'Unsafe website scheme');
check(N::emails('TEST@EXAMPLE.COM; test@example.com')==='test@example.com','Email deduplication');
$headers=['Название','Город','Адрес','Телефон','Мобильный телефон','whatsapp','viber','Email','Сайт'];
$mapping=N::guess($headers);
check($mapping['phones']===[3,4,5,6] && $mapping['address']===[1,2],'2GIS auto mapping');
rejects(fn()=>N::validateMapping(['company'=>[99]],9),'Reject unknown column');
rejects(fn()=>N::validateMapping([],9),'Reject empty mapping');
check(GoogleSource::exportUrl('https://docs.google.com/spreadsheets/d/abc-123/edit#gid=42')==='https://docs.google.com/spreadsheets/d/abc-123/export?format=csv&gid=42','Google gid');
rejects(fn()=>GoogleSource::exportUrl('http://127.0.0.1/private'),'Reject local source URL');
rejects(fn()=>GoogleSource::exportUrl('https://docs.google.com.attacker.test/spreadsheets/d/x'),'Reject lookalike Google domain');
rejects(fn()=>GoogleSource::exportUrl('https://docs.google.com:443/spreadsheets/d/x'),'Reject URL port');
check(Exporter::safeCsv('=1+1')==="'=1+1" && Exporter::safeCsv('+79505947771')==="'+79505947771",'Formula-safe CSV');
$config=require dirname(__DIR__).'/config.example.php';
$reader=new TableReader($config);
$path=tempnam(sys_get_temp_dir(),'contacts-test-');
try{
 file_put_contents($path,mb_convert_encoding("Компания;Адрес;Телефон\r\nООО Тест;\"Москва; улица\";+79505947771\r\n",'Windows-1251','UTF-8'));
 $csv=$reader->read($path,'csv');
 check($csv['rows'][1][1]==='Москва; улица' && $csv['encoding']==='Windows-1251','CP1251 CSV and quoted separator');
 $table=TableReader::table($csv['rows'],1,true); check(count($table['data'])===1 && $table['data'][0]['number']===2,'CSV source row preservation');
 $noheader=TableReader::table([['One','123'],['Two','456']],1,false); check(count($noheader['data'])===2,'Headerless table retains first row');
 $blank=TableReader::table([['Name'],[''],['0']],1,true); check(count($blank['data'])===1 && $blank['data'][0]['values'][0]==='0','Zero is not an empty row');
 $rows=[['company'=>'=HYPERLINK("https://example.test")','address'=>'A','phones'=>'+79505947771','email'=>'a@example.test','website'=>'https://example.test']];
 Exporter::xls($rows,$path); $xls=$reader->read($path,'xls');
 check($xls['rows'][1][0]===$rows[0]['company'] && $xls['rows'][1][2]==='+79505947771','Real XLS export roundtrip and literal formula');
 if(isset($argv[1])){
  $sample=$reader->read($argv[1],'xlsx');$table=TableReader::table($sample['rows'],1,true);
  check(count($table['data'])===21 && count($table['headers'])===22,'User workbook: 21 contacts, 22 columns');
  $map=N::guess($table['headers']);$row=N::row($table['data'][0]['values'],$map);
  check($row['company']==='Создан в саду, салон цветов','Sample company mapping');
  check($row['phones']==='+73842756797; +79235306494','Sample landline and WhatsApp');
  check(str_contains($row['address'],'Кемерово') && str_contains($row['address'],'50 лет Октября'),'Sample combined address');
 }
}finally{unlink($path);}
echo 'PASS '.$checks.' checks'.PHP_EOL;
