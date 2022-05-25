<?php
/** @noinspection PhpMultipleClassDeclarationsInspection */

declare(strict_types=1);

namespace Elite;

use Exception;
use Generator;
use Symfony\Component\HttpClient\CurlHttpClient;
use ZMQ;
use ZMQContext;
use ZMQSocketException;

class EDDN
{
	public const ALLEGIANCE_EMPIRE='Empire';
	public const ALLEGIANCE_INDEPENDANT='Independant';
	public const ALLEGIANCE_FEDERATION='Federation';
	public const ALLEGIANCE_ALLIANCE='Alliance';

	public const LOG_TYPE_FSD_JUMP='Journal.FSDJump';
	public const LOG_TYPE_DOCKED='Journal.Docked';
	public const LOG_TYPE_COMODITY='Commodity';

	public const ENVIRONEMENT_PRODUCTION='production';
	public const ENVIRONEMENT_TEST='test';

	private const SCHEMAS=[
		'commodity'=>[
			'production'=>'https://eddn.edcd.io/schemas/commodity/3',
			'test'=>'https://eddn.edcd.io/schemas/commodity/3/test',
		],
		'fssbodysignals'=>[
			'production'=>'https://eddn.edcd.io/schemas/fssbodysignals/1',
			'test'=>'https://eddn.edcd.io/schemas/fssbodysignals/1/test',
		],
	];

	public CurlHttpClient $client;

	public ?array $star_pos=null;
	public ?bool $horizons=null;
	public ?bool $odyssey=null;

	public function __construct(
		public $environement=self::ENVIRONEMENT_PRODUCTION,
		public $gateway='https://eddn.edcd.io:4430/upload/',
	)
	{
		$this->client=new CurlHttpClient();
	}

	/** @noinspection PhpInconsistentReturnPointsInspection,PhpMultipleClassDeclarationsInspection */
	public function listen_messages($filter_schema=null): Generator
	{
		$relayEDDN='tcp://eddn.edcd.io:9500';
		$timeoutEDDN=600000;

		$context=new ZMQContext();
		$subscriber=$context->getSocket(ZMQ::SOCKET_SUB);
		$subscriber->setSockOpt(ZMQ::SOCKOPT_SUBSCRIBE,'');
		$subscriber->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO,$timeoutEDDN);

		//$schemas=[];
		while(true)
		{
			try
			{
				$subscriber->connect($relayEDDN);

				while(true)
				{
					$message=$subscriber->recv();

					if($message===false)
					{
						$subscriber->disconnect($relayEDDN);
						break;
					}

					$message=json_decode(zlib_decode($message),true);
					$schema=$message['$schemaRef']??null;

					if(!$filter_schema || strpos($schema,$filter_schema))
						yield $message;
				}
			}
			catch(ZMQSocketException $e)
			{
				echo 'ZMQSocketException: '.$e->getMessage()."\n";
			}
		}
	}

	public function read_personnal_log(): Generator
	{
		$files=glob(__DIR__.'/../../data/log/*.log');
		rsort($files);

		foreach($files as $file)
		{
			foreach(file($file,FILE_IGNORE_NEW_LINES) as $message)
			{
				yield json_decode($message,true);
			}
		}
	}

	public function iterate_log($type=self::LOG_TYPE_FSD_JUMP,bool $extract_message=false,?int $date=null): Generator
	{
		//clean
		$types=[self::LOG_TYPE_DOCKED,self::LOG_TYPE_FSD_JUMP,self::LOG_TYPE_COMODITY];
		if(!in_array($type,$types)) throw new Exception('Unknown type');

		$min_date=time()-86400;
		foreach(glob(__DIR__.'/../../data/{'.implode(',',$types).'}*.jsonl',GLOB_BRACE) as $filename)
		{
			if(filemtime($filename)<$min_date)
				unlink($filename);
		}

		$filename=$type.'-'.date('Y-m-d',$date??time()-10800).'.jsonl';
		$path=__DIR__.'/../../data/'.$filename;
		/** @noinspection HttpUrlsUsage */
		$url='http://edgalaxydata.space/EDDN/'.$filename;
		$size=is_file($path)?filesize($path):0;
		$response=$this->client->request('GET',$url,['headers'=>['Range'=>'bytes='.$size.'-']]);
		$fp=fopen($path,'a');

		if($response->getStatusCode()<400)
		{
			foreach($this->client->stream($response) as $chunk)
			{
				fwrite($fp,$chunk->getContent());
			}
		}

		fclose($fp);
		unset($response,$data);
		//read the file
		$fp=fopen($path,'r');
		while(($entry=fgets($fp))!==false)
		{
			$entry=json_decode($entry,true);
			if($extract_message) $entry=$entry['message'];
			yield $entry;
		}

		fclose($fp);
		// http://edgalaxydata.space/EDDN/Journal.FSDJump-2020-08-07.jsonl
	}

	private function update_position(array &$entry): void
	{
		if(in_array($entry['event'],['FSDJump','CarrierJump','Location']) && isset($entry['StarPos']))
		{
			$this->star_pos=$entry['StarPos'];
		}

		if($this->star_pos && !isset($entry['StarPos']))
			$entry['StarPos']=$this->star_pos;
	}

	private function remove_localized(array &$entry): void
	{
		foreach($entry as $k=>&$v)
		{
			if(is_array($v))
				$this->remove_localized($v);
			elseif(is_string($k) && str_contains($k,'_Localised'))
				unset($entry[$k]);
		}
	}

	private function add_flags(array &$entry): void
	{
		if($entry['event']==='LoadGame')
		{
			$this->horizons=$entry['Horizons'];
			$this->odyssey=$entry['Odyssey'];
		}
		elseif($this->horizons!==null && $this->odyssey!==null)
		{
			$entry['horizons']=$this->horizons;
			$entry['odyssey']=$this->odyssey;
		}
	}

	public function post_message(string $schema='commodity',array $message=[]): void
	{
		if(!str_contains($schema,'//'))
			$schema=self::SCHEMAS[$schema][$this->environement]??null;

		if(!$schema)
			throw new Exception('No schema');

		$response=$this->client->request('POST',$this->gateway,['json'=>[
			'$schemaRef'=>$schema,
			'header'=>[
				'uploaderID'=>'Aymeric45',
				'softwareName'=>'Cmdr-Aymeric45',
				'softwareVersion'=>'0.1',
			],
			'message'=>$message,
		]]);

		var_dump($response->getContent(false));
	}

	public function handle_entry(array $entry): array
	{
		$this->update_position($entry);
		$this->remove_localized($entry);
		$this->add_flags($entry);

		if($entry['event']==='FSSBodySignals')
		{
			$this->post_message(
				schema:'fssbodysignals',
				message:$entry,
			);

			var_dump($entry);
		}

		return $entry;
	}
}
