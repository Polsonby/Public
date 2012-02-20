<?php 

class providerFeed
{

	private $publicationCode;
	private $providerFeedURL;
	private $feed;
	private $pageNumber;
	private $totalNumberOfPages;
	private $totalNumberOfIssues;
	private $helper;

	public function __construct($publicationCode, $p = 1)
	{
		$this->publicationCode = $publicationCode;
		$this->providerFeedURL = "http://www.providermedia.com/archiveFeed.php";
		if ($p == 1) {
			$url = dirname(__FILE__) . "/feed$publicationCode.xml";
			$xml = simplexml_load_file($url);
			$success = $this->parseFeed($xml);
		} else {
			$xml = $this->getProviderXML($publicationCode, $p);
			$success = $this->parseFeed($xml);
		}
		$this->pageNumber = (int) $this->feed['pageNumber'];
		$this->totalNumberOfPages = (int) $this->feed['totalNumberOfPages'];
		$this->totalNumberOfIssues = (int) $this->feed['totalNumberOfIssues'];
		$this->helper = new ImageHelper();

	}

	private function getProviderXML($publicationCode, $p = 1) {
		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $this->providerFeedURL . "?publications=$publicationCode&pagenumber=$p&rowsperpage=10");
		curl_setopt($handle, CURLOPT_HEADER, false);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		$code = curl_exec($handle);
		curl_close($handle);
		try {
			if (!$xml = new SimpleXMLElement($code)) {
				throw new Exception('Provider feed: Parsing XML failed');
			} else {
				return $xml;
			}
		} catch (Exception $e)  {
			echo 'Caught exception: ' . $e->getMessage();
			return 0;
		}
		
	}
	
	
	private function parseFeed($xml) // converts XML document into a structured array $feed for later use
	{
		$this->feed = array(
			'pageNumber' => $xml['pageNumber'],
			'totalNumberOfPages' => $xml['totalNumberOfPages'],
			'totalNumberOfIssues' => $xml['totalNumberOfIssues'],
			'items' => array()
		);
		foreach ($xml->issue as $issue) {
			$this->feed['issues'][] = array(
				'title' => $issue->title,
				'url' => $issue->url,
				'description' => $issue->description,
				'coverImage' => $issue->coverImage,
				'publishedDate' => $issue->publishedDate
			);
		}
		return 1;
	}
	
	public function getIssueHeadline($issue) 
	{
		return $this->feed['issues'][$issue]['title'];
	}
	
	public function getIssueLink($issue, $str) 
	{
		return '<a href="' . $this->getIssueURL($issue) . '" target="_blank">' . $str . '</a>';
	}
	
	public function getIssueURL($issue) 
	{
		return $this->feed['issues'][$issue]['url'];
	}
	
	public function getIssueDescription($issue) 
	{
		return $this->feed['issues'][$issue]['description'];
	}
	
	public function getIssueDate($issue)  
	{
		$d = strtotime($this->feed['issues'][$issue]['publishedDate']);
		return date('j F Y', $d);
	}
	
	public function getIssueCoverURL($issue, $thumb = false) 
	{
		if ($thumb) {
			return $this->feed['issues'][$issue]['coverImage'];
			$coverURL = $this->feed['issues'][$issue]['coverImage'];
			$coverURL = str_replace('/med/', '/thumbs/', $coverURL);
			$coverURL = str_replace('jpeg', 'jpg', $coverURL);
			return $coverURL;
		} else {
			return $this->feed['issues'][$issue]['coverImage'];
		}
	}
	public function getIssueCover($issue, $width = 250, $alt = '', $thumb = false) 
	{
		$coverURL = $this->getIssueCoverURL($issue, $thumb); 
		$imageURL = $this->helper->retrieveImage($coverURL, $width);
		return '<img src="' . $imageURL . '" alt="' . $alt . '" width="' . $width . '" border="0">';
	}
	
	public function getIssueCount() 
	{
		return count($this->feed['issues']);
	}
	
	public function getStatusIndicator() 
	{
		return "<span>Page $this->pageNumber of $this->totalNumberOfPages</span>";
	}
	
	public function getPreviousPageLink($str)
	{
		$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); 
		if ($this->pageNumber < $this->totalNumberOfPages) {
			if (isset($_GET['search'])) {
				$querystring = '?search=' . $_GET['search'] . '&'; 
			} else {
				$querystring = '?';
			}
			$querystring .= 'p=' . ($this->pageNumber + 1);
		} else {
			return $str;
		}
		return '<a href="' . $url . $querystring . '">' . $str . '</a>';
	}
	
	public function getNextPageLink($str)
	{
		$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); 
		if ($this->pageNumber > 1) {
			if (isset($_GET['search'])) {
				$querystring = '?search=' . $_GET['search'] . '&'; 
			} else {
				$querystring = '?';
			}
			$querystring .= 'p=' . ($this->pageNumber - 1);
		} else {
			return $str;
		}
		return '<a href="' . $url . $querystring . '">' . $str . '</a>';
	}
	
	
	
	public function updateProviderFeed() {
		$pubCode = $this->publicationCode;
		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $this->providerFeedURL . "?publications=$pubCode&pagenumber=1&rowsperpage=10");
		curl_setopt($handle, CURLOPT_HEADER, false);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		$code = curl_exec($handle);
		curl_close($handle);
		try {
			if (!$xml = new SimpleXMLElement($code)) {
				throw new Exception('Provider feed: Parsing XML failed');
			} else {
				if ($this->writeXML($code, $pubCode)) {
					return 1;
				} else {
					throw new Exception('Provider feed: Writing file failed');
				}
			}
		} catch (Exception $e)  {
			echo 'Caught exception: ' . $e->getMessage();
			return 0;
		}
		
	}
	
	public function writeXML($txt, $pubCode) {
		$f = fopen("feed$pubCode.xml.new", 'w');
		if (fwrite($f, $txt)) {
			fclose($f);
			rename("feed$pubCode.xml", "feed$pubCode.xml.bak");
			rename("feed$pubCode.xml.new", "feed$pubCode.xml");
			return 1;
		} else {
			fclose($f);
			return 0;
		}
	}
	
}

/**
 * Contains functionality for working with Provider covers
 *
 * retrieves large covers from Provider, saves them locally as source files, offers methods to generate
 * copies at any size given the remote URL for the cover and a desired width, and derive local paths 
 * to the generated images
 * 
 * @author     Ian Anderson <ipanderson@gmail.com>
 *
 */
class ImageHelper
{
	
	public function __construct()
	{
		define('BASE_PATH', dirname(__FILE__));
	}

	/**
	 * Return a local file path given a remote image URL and dimension
	 *
	 * If a local generated version of the remote image exists at the right size
	 * we return its local path. Otherwise we generate it from the local stored master copy
	 * retrieving the master copy from the remote URL if necessary
	 *
	 * @author Ian Anderson
	 * @param string $url Remote location of the image to be displayed
	 * @param int $size The desired width of the generated image in pixels
	 * @return string The local root-relative web path to the generated image
	 */
	public function retrieveImage($url, $size) {
		$outputFileName = $this->buildOutputFilename($url, $size); // /Users/ian/Desktop/dev/img/images/generated/XXX_281109_0001-230.jpg
		if (file_exists($outputFileName)) {
			//echo 'final image displayed from cache' . '<br>';
			return $this->buildBaseFilename($outputFileName);
		} else {
			$sourceFileName = $this->buildSourceFileName($url); // /Users/ian/Desktop/dev/img/images/source/XXX_281109_0001.jpeg
			if (file_exists($sourceFileName)) {
				//echo 'generated final image using cached master' . '<br>';
				$this->saveImage($sourceFileName, $outputFileName, $size);
				return $this->buildBaseFilename($outputFileName);
			} else {
				//echo 'retrieved master, generated final image' . '<br>';
				$this->getMasterImage($url);
				$this->saveImage($sourceFileName, $outputFileName, $size);
				return $this->buildBaseFilename($outputFileName);
			}
		}
	}
	
	public function getMasterImage($inPath) {
		$outPath = $this->buildSourceFilename($inPath);
		$ch = curl_init($inPath);
		$fp = fopen($outPath, 'wb');
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_exec($ch);
		curl_close($ch);
		fclose($fp);
	}
	
	public function buildBaseFilename($inputFile) {
		return '/provider/images/generated' . substr($inputFile, strrpos($inputFile, '/'));
	}
	
	public function buildSourceFilename($inputFile) {
		return BASE_PATH . '/images/source' . substr($inputFile, strrpos($inputFile, '/'));
	}
	
	public function buildOutputFilename($inputFile, $size) {
		$baseName = substr($inputFile, strrpos($inputFile, '/'));
		$baseName = BASE_PATH . '/images/generated' . substr($baseName, 0, strrpos($baseName, '.'));
		$outputFile = $baseName . '-' . $size . '.jpg';
		return $outputFile;
	}
	
	public function retrieveRemoteImage($url) {
		$img = imagecreatefromjpeg($url);
		var_dump($img);
		$success = (int) imagejpeg($image_p, 'targ.jpg', 80);
	}
	
	public function checkAndCreateLocalFile($file, $size) {
		if (file_exists($this->buildOutputFilename($file, $size))) {
			return 1; 
		} else {
			return $this->saveImage($file, $size);
		}
	}
	
	public function saveImage($sourceFileName, $outputFileName, $size) {
		
		// set image properties from original
		$imageProperties = getimagesize($sourceFileName);
		$width_orig = $imageProperties[0];
		$height_orig = $imageProperties[1];
		$mimeType = $imageProperties['mime'];
		
		// Set a maximum height and width
		$width = $size;
		$height = $size;
	
		// Get new dimensions
		$ratio_orig = $width_orig/$height_orig;
		
		if ($width/$height < $ratio_orig) {
		   $width = $height*$ratio_orig;
		} else {
		   $height = $width/$ratio_orig;
		}
		
		// Resample - NOTE THIS DOES NOT WORK ON GIFS!
		$image_p = imagecreatetruecolor($width, $height);
		$image = imagecreatefromjpeg($sourceFileName); 
		imagecopyresampled($image_p, $image, 0, 0, 0, 0, $width, $height, $width_orig, $height_orig);
		
		// Save file to disk with JPEG quality of 80
		$success = (int) imagejpeg($image_p, $outputFileName, 80);
		return $success;
		
	}

}
?>