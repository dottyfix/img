<?php
namespace Dottyfix\Tools\Img;

class Img {
	
	static $chmodFile = false;
	static $chmodDir = 755;
	
	static protected $expectedPrefs = ['avif', 'webp', 'jpeg'];
	
	static function acceptedServerFormats($out = false) {
		$accepted = [];
		foreach(['png', 'jpeg', 'gif', 'avif', 'webp'] as $ext)
			if(function_exists("image$ext"))
				$accepted[] = $ext;
		return $accepted;
	}
	
	public function expected() {
		if($this->extension)
			return $this->extension;
		$errors = [];
		$prefs = self::$expectedPrefs;
		$accepted = self::acceptedServerFormats();
		foreach($prefs as $ext)
			if(in_array($ext, $accepted)) {
				return $ext;
			}
			else
				$errors[] = 'Extension '.$ext.' non prise en charge en sortie (pas de function imagecreatefrom'.$ext.').';
		return $this->fail('Fichier '.$this->src.' non trouvé.');(implode("\n<br>", $errors));
	}
	
	public static function infos($src) {
		$gis = getimagesize($src);
		return [
			'width' => $gis[0],
			'height' => $gis[1],
			'ext' => image_type_to_extension($gis[2], false),
			'attr' => $gis[3],
			'bits' => $gis['bits'],
			'mime' => $gis['mime']
		];
	}
	
	public $quality = null;
	public $max_height = null;
	public $max_width = null;
	public $height = null;
	public $width = null;
	public $crop = false;
	public $text = null;
	
	public $extension = null;
	public $forceConvert = false;
	public $cachePath = __DIR__.'/../cache/img/';
	public $invalid = false;
	
	protected $src = '';
	protected $discrete = false;
	protected $originalInfos = [];
	protected $infos = [];
	
	function __construct($src, $discrete = false) {
		if(!file_exists($src)) {
			$this->invalid = true;
			return $discrete ? false : $this->fail('Fichier '.$this->src.' non trouvé.');
		}
		$this->originalInfos = self::infos($src);
		$this->src = $src;
		$this->discrete = $discrete;
	}
	
	function quality($expected) {
		if($this->quality === null and in_array($expected, ['jpeg', 'png', 'avif', 'webp']))
			return -1;	// PHP default quality. (jpeg: 75%, png: zlib default compression level, avif: 52, webp: 80)
		$q = max(0, min(100, $this->quality));
		if(in_array($expected, ['avif', 'jpeg', 'webp']))
			return (int) $q;
		if($expected == 'png')
			return (int) floor(9 * (1 - $q / 100));	// taux de compression au lieu de % de qualité.
		return false;
	}
	
	function paramKey() {
		$newSizes = $this->getNewSizes();
		$w = $newSizes ? $newSizes['nw'] : $this->originalInfos['width'];
		$h = $newSizes ? $newSizes['nh'] : $this->originalInfos['height'];
		$params = ['q' => $this->quality, 'w' => $w, 'h' => $h];
		foreach($params as $k => $p)
			if(!$p)
				unset($params[$k]);
			else
				$params[$k] = $k.$params[$k];
		if($this->crop)
			$params['c'] = 'cropped';
		if(count($params))
			return '.'.implode('-', $params);
		return '';
	}
	
	function cacheFile($expected) {
		$f = substr($this->src, strlen($this->cachePath) + 1);
		$f = $f.$this->paramKey().'.'.$expected;
		return $this->cachePath.$f;
	}
	
	function fail($msg) {
		if($this->discrete)
			return $this->src;
		throw new Exception($msg);
	}
	
	static function resizeCrop($img, $w, $h, $ow, $oh){

		$nImg = imagecreatetruecolor($w, $h);
		 
		$nw = (int) ($ow * $w / $h);
		$nh = (int) ($ow * $h / $w);
		//if the new width is greater than the actual width of the image, then the height is too large and the rest cut off, or vice versa
		if($nw > $ow){
			//cut point by height
			$h_point = (int) (($ow - $nh) / 2);
			//copy image
			imagecopyresampled($nImg, $img, 0, 0, 0, $h_point, $w, $h, $ow, $nh);
		}else{
			//cut point by width
			$w_point = (int) (($ow - $nw) / 2);
			imagecopyresampled($nImg, $img, 0, 0, $w_point, 0, $w, $h, $nw, $ow);
		}
		return $nImg;
	}
	
	static function resize($img, $nw, $nh, $ow, $oh) {
		$nImg = imagecreatetruecolor($nw, $nh);
		imagecopyresampled($nImg, $img, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
		return $nImg;
	}
	
	function prepareSize($k) {
		$p = 'max_'.$k;
		if(in_array(strval($this->$p), [null, '100%', $this->originalInfos[$k]]))
			$this->$p = $this->originalInfos[$k];
		else {
			// percent conversion here
			return $this->$p;
		}
		return false;
	}
	
	function getNewSizes() {
		$w = $this->prepareSize('width');
		$h = $this->prepareSize('height');
		$toResize = !!($w or $h);

		if(!$toResize)
			false;
		$h = (int) ($h ? $h : $this->originalInfos['height']);
		$w = (int) ($w ? $w : $this->originalInfos['width']);
		
		if($this->crop)
			return ['nw' => $w, 'nh' => $h];
		
		$r = $this->originalInfos['width'] / $this->originalInfos['height'];

		if ($w / $h > $r)
			return ['nw' => (int) ($h * $r), 'nh' => (int) $h];
		else
			return ['nw' => (int) $w, 'nh' => (int) ($w / $r)];
	}
	
	function convert($expected = null) {
		$newSizes = $this->getNewSizes();
		if(!$expected)
			$expected = self::expected();
		elseif(!in_array($expected, self::acceptedServerFormats()))
			return $this->discrete ? false : $this->fail('Extension '.$expected.' non prise en charge en sortie (pas de function imagecreatefrom'.$expected.').');
		$dest = $this->cacheFile($expected);
		if(!$this->forceConvert and file_exists($dest) and filemtime($this->src) < filemtime($dest)) {
			$this->infos = self::infos($dest);
			return $dest;
		}
		
		$ext = $this->originalInfos['ext'];
		
		if(!in_array($ext, self::acceptedServerFormats()))
			return $this->fail('Extension '.$ext.' non prise en charge en entrée (pas de function image'.$ext.').');
		$img = 'imagecreatefrom'.$ext;
		$img = $img($this->src);
		
		$white = imagecolorallocate($img, 255, 255, 255);
		imagefill($img, 0, 0, $white);
		
		if(in_array($ext, ['jpeg', 'tiff', 'png', 'webp']) and $exif = exif_read_data($this->src)) {
			$rotations = [3 => 180, 6 => 270, 8 => 90];
			if(in_array($exif['Orientation'], $rotations))
				$img = imagerotate($img, $rotations[$exif['Orientation']], 0);
		}
		
		imagepalettetotruecolor($img);
		imagealphablending($img, true);
		imagesavealpha($img, true);

		
		if($newSizes) {
			$method = $this->crop ? 'resizeCrop' : 'resize';
			$img = self::$method($img, $newSizes['nw'], $newSizes['nh'], $this->originalInfos['width'], $this->originalInfos['height']);
		}
		
		$out = 'image'.$expected;
		
		$destDir = dirname($dest);
		if(!file_exists($destDir))
			mkdir($destDir, self::$chmodDir ? self::$chmodDir : 0777, true);
		
		if(is_string($this->text)) {
			$textcolor = imagecolorallocate($img, 0, 0, 0);	//black color
			imagestring($img, 4, 0, 0, $this->text, $textcolor);
		}
		
//var_dump($q = $this->quality($expected)); die("$out($img, $dest, $q)");
		if($q = $this->quality($expected))
			if($expected == 'avif')
				$out = $out($img, $dest, $q, 0);	// speed 0 => best quality.
			else
				$out = $out($img, $dest, $q);
		else
			$out = $out($img, $dest);
		if(self::$chmodFile)
			chmod($dest, self::$chmodFile);
		$this->infos = self::infos($dest);
		return $dest;
	}
	
	function getOutputFileInfos($expected = null) {
		if(!$expected)
			$expected = self::expected();
		if($expected == 'svg')
			$infos['target'] = $this->src;
		$target = $this->convert($expected);
		$infos = $this->infos;
		$infos['target'] = $target;
		return $infos;
	}
	
	static protected $queryParams = ['t' => 'text', 'q' => 'quality', 'w' => 'max_width', 'h' => 'max_height', 'ext' => 'extension'];
	
	static function fromString($str, $query) {

		$img = new static($src);
		foreach(self::$queryParams as $kget => $k)
			if(isset($query[$kget]))
				$img->$k = (string) $query[$kget];
		return [$img];
	}
	
	static function router($basePath, $baseUrl, $cachePath, $request) {
		
		$basePath = realpath($basePath).'/';
		$cachePath = realpath($cachePath).'/';
//var_dump([$basePath,$src]);

		$pos = strpos($request, '?');
		if($pos === false) {
			$img = new static($basePath.$request);
		} else {
			$src = substr($request, 0, $pos);
			$query = substr($request, $pos + 1);
			parse_str($query, $query);
			$img = new static($basePath.$src);
		}
		
		$img->cachePath = $cachePath;
		
		if(isset($query))
			foreach(self::$queryParams as $kget => $k)
				if(isset($query[$kget]))
					$img->$k = (string) $query[$kget];
			if(isset($query['c']))
				$img->crop = true;
		
		$infos = $img->getOutputFileInfos();
		$infos['src'] = $baseUrl.substr($infos['target'], strlen($cachePath));
		return $infos;
	}
	
	static function __init() {
		define('IMG_ACCEPTED_SERVER_FORMATS', self::acceptedServerFormats());
	}
	
}

if( $_SERVER['SCRIPT_FILENAME'] != __FILE__ ) return;


$debug = true;

ob_start();

$cachePath = realpath(__DIR__.'/../cache/img/').'/';

if($debug and isset($_GET['from'])) {
	$srcDirectory = __DIR__.'/img';
	$query = $from = (string) $_GET['from'];
	unset($_GET['from']);
	$prefix = '?';
	if(count($_GET)) {
		foreach($_GET as $k => $v) {
			$query .= $prefix.($v ? "$k=$v" : $k);
			$prefix = '&';
		}
	}
//die($query);
	$infos = Img::router($srcDirectory, '/img.php?', $cachePath, $query);	// 'subfolder/big.png?q=90&w=400&c'   {{url 'image' 'subfolder/big.png?q=90&h=200&c'}}
	$infos['from'] = $from;
	$infos['query'] = $query;
	$infos['file_size'] = filesize($infos['target']).' bytes';
	$_infos = var_export($infos, true);

	die( '<figure>
			<img
			src="'.$infos['src'].'"
			alt="Elephant at sunset" />
			<figcaption><pre>'.$_infos.'</pre></figcaption>
		</figure>' );
}

$f = str_replace('/..', '', $cachePath.$_SERVER['QUERY_STRING']);
if(strpos($f, $cachePath) !== 0)
	die('Image Path Error');

if($debug) {
	header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
	header("Cache-Control: post-check=0, pre-check=0", false);
	header("Pragma: no-cache");
}
if($out = ob_get_clean())
	die($out);
header('Content-Type: '.mime_content_type($f));
readfile($f);
