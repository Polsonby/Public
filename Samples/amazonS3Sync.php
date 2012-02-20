#! /usr/bin/php
<?php 
	//this file is to be called by CRON every 15 minutes or so
	set_time_limit(0);
	require '../application/common.php';

	$s3 = new Service_Amazon_S3(AWS_ACCESS_KEY, AWS_SECRET_KEY);
	$s3->sync();
?>