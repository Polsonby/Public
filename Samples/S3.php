<?php 

require APP_PATH . '/S3/S3.php';

/**
 * Synchronise local file store with file store at Amazon S3
 *
 * 
 * @author     Ian Anderson <ipanderson@gmail.com>
 *
 */
class Service_Amazon_S3 extends S3
{
	protected $db;
	protected $hashes;
	
	public function __construct($awsUserKey, $awsPrivateKey, $useSSL = false)
	{
		parent::__construct($awsUserKey, $awsPrivateKey, $useSSL);
		$this->db = Zend_Registry::get('dbh');
	}	
	
	/**
	 * Main synchronisation function
	 * 
	 * Gets the list of buckets from Amazon - for every bucket
	 * it gets the list of local copies and the list of objects 
	 * in the bucket and compares their hashes
	 *
	 * @param string $bucket   Optional parameter containing the name of a single bucket to synchronise
	 * @param string $filename For future use - if we want to check the status of a single file
	 * @return void
	 */
	public function sync($bucket = null, $fileName = null)
	{
		$buckets = ($bucket) ? array($bucket) : $this->listBuckets();
		if ($buckets) {
			foreach ($buckets as $bucket) {
				$objects = ($bucket && $fileName) ?	$this->getObjectData($filename) : $this->getBucket($bucket) ;
				if ($objects) {
					foreach ($objects as $object) {
						$object['bucket'] = $bucket;
						$result = $this->checkLocal($object);
						switch ($result['filetype']) {
							case 'new':
								//echo '<li>new';
								$this->addObject($object);
								$this->queueForDownload($object);
								break;
							case 'unchanged':
								//echo '<li>unchanged';
								break;
							case 'modified':
								//echo '<li>modified';
								$objectID = $result['objectID'];
								$this->updateObject($object, $objectID);
								$this->queueForDownload($object);
								break;
						}
					}
				}
			}
		}

	}
	
	/**
	 * For any object returned by Amazon, see if it exists locally and is unchanged
	 * 
	 * Gets the list of buckets from Amazon - for every bucket
	 * it gets the list of local copies and the list of objects 
	 * in the bucket and compares their hashes
	 *
	 * @param object $object   An object of stdclass returned by Amazon describing an S3 object
	 * @return array Containing: filetype - either modified, new or unchanged; objectID = the database objectID of the object in question
	 */
	public function checkLocal($object)
	{
		$hash = $object['hash'] . $object['name'];
		if (empty($this->hashes)) {
			$hashes = $this->db->fetchCol("SELECT CONCAT(hash, name) FROM S3_Objects");
			$this->hashes = (implode($hashes, ', ')); 
		}
		if (strpos($this->hashes, $hash) === false) {
			$name = $object['name'];
			$name = $this->db->quote($name); 
			$row = $this->db->fetchRow("SELECT * FROM S3_Objects WHERE name = $name");
			if ($row) {
				return array('filetype' => 'modified', 'objectID' => $row['objectID']);
			} else {
				return array('filetype' => 'new');
			}
		} else {
			return array('filetype' => 'unchanged');
		}
	}

	/**
	 * Save the details of a given S3 object into the database
	 * 
	 * @param object $object  An object of stdclass describing an S3 object
	 * @return void
	 */
	public function addObject($object)
	{
		if ($this->isValidFile($object['name'])) { 
			$this->db->insert('S3_Objects', $object);
		}
	}

	/**
	 * Update the details of a given S3 object in the database
	 * 
	 * @param object $object  An object of stdclass describing an S3 object
	 * @param int $objectID  The database id of the row to update
	 * @return void
	 */
	public function updateObject($object, $objectID)
	{
		if ($this->isValidFile($object['name'])) { 
			$where = 'objectID = ' . $objectID;
			$this->db->update('S3_Objects', $object, $where);
		}
	}

	/**
	 * Adds objects to be added or updated to a database table representing the download queue
	 * 
	 * @param object $object  An object of stdclass describing an S3 object
	 * @return void
	 */
	public function queueForDownload($object)
	{
		$name = $object['name'];
		if (substr($name, 0, 1) == '/') {
			$name = substr($name, 1);
		}
		$path = FILE_STORE_LOCATION . '/' . $object['bucket'];
		if (!file_exists($path)) {
			mkdir($path, 0755);
		}
		$saveLocation =  $path . '/' . $name;
		if ($this->isValidFile($name)) { 
			$this->createFoldersFromFilename($saveLocation);
			$data = array(
				'name' => $object['name'],
				'bucket' => $object['bucket'],
				'saveLocation' => $saveLocation,
				'size' => $object['size']
			);
			$this->db->insert('File_Downloads', $data);
			$downloadID = $this->db->lastInsertId();
			$count = $this->db->fetchOne("SELECT COUNT(*) FROM File_Downloads WHERE started");
			$throttle = FILE_DOWNLOAD_THROTTLE; 
			if ($count < $throttle) {
				exec(APPLICATION_ROOT . "/cli/download.php $downloadID > /dev/null  &#038;");
			}
		}
	}

	/**
	 * Downloads a designated object from S3
	 * 
	 * Downloads a designated object from S3 and saves it to the specified location (location stored with the download request)
	 * 
	 * @param object $downloadID  The database ID for the rows in the download queue database table
	 * @return void
	 */
	public function download($downloadID)
	{
		$downloadID = (int) $downloadID;
		$row = $this->db->fetchRow("SELECT * FROM File_Downloads WHERE downloadID = $downloadID");
		if ($row) {
			$where = 'downloadID = ' . $downloadID;
			$data = array(
				'startTime' => date('Y-m-d H:i:s'),
				'started' => 1
			);
			$this->db->update('File_Downloads', $data, $where);
			$result = $this->getObject($row['bucket'], $row['name'], $row['saveLocation']);
			if ($result) {
				if ($result->code == 200) {
					echo 'download successful';
					$data = array(
						'endTime' => date('Y-m-d H:i:s'),
						'completed' => 1
					);
					$this->db->delete('File_Downloads', $where);
				} else {
					echo $result->error;
				}
			} else {
				echo 'download failed';
			}
		} else {
			echo 'no row found';
		}
	}
	
	/**
	 * Checks the S3 object name to see that it's not a fake folder item created by some FTP package
	 * 
	 * @param object $name  The S3 object name to check
	 * @return boolean Is this name OK to download?
	 */
	public function isValidFile($name)
	{
		if ((substr($name, -1) == '/') || (strpos($name, '_$folder$') !== false)) {
			return false;
		} else {
			return true;
		}
	}
	
	/**
	 * Parses an object name and creates any folders necessary to store it in
	 * 
	 * @param object $saveLocation  The object name to check
	 * @return void
	 */
	public function createFoldersFromFilename($saveLocation)
	{
		$bits = split('/', $saveLocation);
		$path = '';
		for ($i = 0; $i < count($bits) - 1; $i++) {
			if ($i > 0) {
				$path .= '/';
			}
			$path .= $bits[$i];
			if (!file_exists($path)) {
				mkdir($path, 0755);
			}
		}
	}
	
	/**
	 * Check to see if any new download sessions should be kicked off
	 * 
	 * @return void
	 */
	public function checkForDownloads()
	{
		$throttle = FILE_DOWNLOAD_THROTTLE; 
		if (!file_exists(FILE_DOWNLOAD_TMP_FILE)) {
			exec("touch " . FILE_DOWNLOAD_TMP_FILE);
			$pendingDownloads = $this->db->fetchCol("SELECT downloadID FROM File_Downloads WHERE NOT started");
			foreach ($pendingDownloads as $downloadID) {
				$count = $this->db->fetchOne("SELECT COUNT(*) FROM File_Downloads WHERE started");
				if ($count < $throttle) {
					exec(APPLICATION_ROOT . "/cli/download.php $downloadID > /dev/null  &#038;");
				}
				sleep(1);
			}
			exec("rm " . FILE_DOWNLOAD_TMP_FILE);
		}
	}

}