<?php // EE FTP Copy Directory - copyright Mitchell Bennis (mitch@elementengage.com) - rev 05/09/16 BETA
	
// This script should be run directly in the browser.
// It will connect to a remote FTP account, parse the remote directory and download each file to a local directory relative to this file.
// - - - Exclusions can be made for file size and/or file name.

error_reporting (E_ALL);
ini_set ('display_errors', TRUE);

// ini_set('max_execution_time', '7200');
// ini_set('memory_limit', '-1');

// Default Local Folder
$localDir = 'ee_ftp_copy'; 

// Give ourselves some time before we bail out 
// and finish the script before it dies.
$timeCushion = 15; // Seconds

// Script timing
$timeStart = microtime(true);
	
// Messaging
$messages = array();
$errors = array();
$counter = 0;
$remoteSize = 0;

$remotePathToFile = '';
$fileMaxSize = '';
$thisFolder = '';
$subFolder = '';
$localPath = '';

// FTP
$ftpAddress = '';
$ftpUsername = '';
$ftpRemote = '';
$fileList = array();

$listOnly = FALSE;

// FUNCTIONS ---------------------------------

// Check Server Time Remaining
function timeRemaining() {
	global $timeStart;
	$timeElapsed = microtime(true) - $timeStart;
	$timeRemaining = ini_get('max_execution_time') - $timeElapsed;
	return $timeRemaining;
}	
	
// List all local files in a folder
function listDirFiles($dir, $prefix = '') {
  $dir = rtrim($dir, '\\/');
  $result = array();

    foreach (scandir($dir) as $f) {
      if (strpos($f, '.') !== 0) {
        if (is_dir("$dir/$f")) {
          $result = array_merge($result, listDirFiles("$dir/$f", "$prefix$f/"));
        } else {
          $result[] = $prefix.$f;
        }
      }
    }
	return $result;
}

// Size formatting
function bytesToSize($bytes, $precision = 2) {  
    
    $kilobyte = 1024;
    $megabyte = $kilobyte * 1024;
    $gigabyte = $megabyte * 1024;
    $terabyte = $gigabyte * 1024;
   
    if (($bytes >= 0) && ($bytes < $kilobyte)) {
        return $bytes . ' B';
 
    } elseif (($bytes >= $kilobyte) && ($bytes < $megabyte)) {
        return round($bytes / $kilobyte, $precision) . ' KB';
 
    } elseif (($bytes >= $megabyte) && ($bytes < $gigabyte)) {
        return round($bytes / $megabyte, $precision) . ' MB';
 
    } elseif (($bytes >= $gigabyte) && ($bytes < $terabyte)) {
        return round($bytes / $gigabyte, $precision) . ' GB';
 
    } elseif ($bytes >= $terabyte) {
        return round($bytes / $terabyte, $precision) . ' TB';
    } else {
        return $bytes . ' B';
    }
}

// Calculate Folder Size
function dirSize($dir) {
    $size = 0;
    $io = popen ( '/usr/bin/du -sk ' . $dir, 'r' );
    $size = fgets ( $io, 4096);
    $size = substr ( $size, 0, strpos ( $size, "\t" ) );
    pclose ( $io );
    return $size;
}






// FTP FUNCTIONS --------------------------

// FTP Create Connection
function ftpConnect($server, $user, $pass) {
	
	global $messages; global $errors;
	$conn = ftp_connect($server);
	$login = ftp_login($conn, $user, $pass);
	
	if ($conn AND $login) { 
		$messages[] = "Connected to server: [$server]";
		return $conn; 
	} else {
		$errors[] = "!! Could not connect to the server [$server] !!";
	}
}

// FTP Close
function ftpClose($conn) {
	
	global $conn, $messages, $errors;
	
	if(ftp_close($conn)) { $messages[] = "FTP Connection Closed."; } else { $errors[] = '!! FTP Close Failed !!'; }
	
	if($errors) { return false; } else { return true; }
	
}

function ftp_is_dir($remotePathToFile) {
  
  	global $conn, $folderName, $messages;
	$size = ftp_size($conn, $remotePathToFile);
	// $messages[] = $remotePathToFile . ' = ' . $size;
	if ($size == '-1') {
	   $folderName = basename($remotePathToFile);
	   return TRUE; // Is directory
	} else {
		return FALSE; // Is file
	}
}


// Return the contents of a dir as an array
function list_dir_contents($thePath) {

	global $conn, $messages, $errors, $ftpRemote;
	$messages[] = 'Listing: ' . $thePath;
	$contents = ftp_nlist($conn, $thePath);
	$messages[] = $contents;
	if ($contents) {
		return $contents;
	} else {
		$errors[] = "Could not list contents of $thePath";
	}
}


// Parse contents array recusively.
function parse_dir_contents($contents) {

	global $conn, $remotePathToFile, $localPath, $ftpRemote, $errors, $messages, $localDir, $fileFilter, $fileMaxSize, $remoteSize, $counter, $listOnly, $thisFolder, $subFolder;
	
	$remotePath = ftp_pwd($conn); // Get new dir name
	
	if($remotePath == '/') {
		$remotePath = $ftpRemote;
	} else {
		$remotePath .= '/';
		$thisFolder = basename($remotePath); // Get the name if this folder
		$subFolder = $subFolder . '/' . $thisFolder . '/'; // Add it to the path
		
		
		
		$messages[] = '$subFolder = ' . $subFolder;
	}
		
	$messages[] = '$remotePath = ' . $remotePath;
	
	if(is_array($contents)) {
		
		foreach ($contents as $item) {
	
			$filtered = FALSE;
		
			if(strpos($item, '.') !== 0) { // Not folders upwards
					
				$remotePathToFile = $remotePath . '/' . $item;
				
				// $remotePathToFile = str_replace('//', '/', $remotePathToFile); // Fix double slashes
				
				$messages[] = '$remotePathToFile = ' . $remotePathToFile;
				
				if(ftp_is_dir($remotePathToFile)) { 
					
					$messages[] = 'Folder Found!';
					
					$localPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $localDir . '/' . $subFolder . basename($remotePathToFile);
					
					$messages[] = '$localPath = ' . $localPath;
					
					if(!is_dir($localPath)) {
					
						if(!mkdir($localPath)) {
							$errors[] = 'Cannot create ' . $localPath;
							return FALSE;
						} else {
							
							$messages[] = "Created Folder: " . $localPath;
						}
					
					} else {
						
						$messages[] = 'Folder Already Exists! $localPath = ' . $localPath;
					}
					
					if (ftp_chdir($conn, $remotePathToFile)) { // Change to this dir
					
						// $fullPath = ftp_pwd($conn); // Get new dir name
						
						$messages[] = 'New Path $remotePathToFile = ' . $remotePathToFile;
						
						$subdir = list_dir_contents($remotePathToFile);
						
						parse_dir_contents($subdir);
						
						ftp_cdup($conn); // Change to parent directory
						
					} else {
					
						$messages[] = 'Could not change to ' . $remotePathToFile;
						$errors[] = 'Could not change to ' . $remotePathToFile;
					}
				
				} else {  // File Operations <<<-----------------------------------------------------------
				
					$file = $item;
					
					// $remotePathToFile = str_replace('//', '/', $remotePathToFile); // Fix double slashes
					
					$remote = $remotePathToFile;
					
					$localPathToFile = $localPath . '/' . $file;
					
					$messages[] = '$localPathToFile: ' . $localPathToFile;
					
					// $local = str_replace('//', '/', $local); // Fix double slashes
					
					// Check if file already exists
					if(is_readable($localPathToFile)) {
						
						$messages[] = 'SKIPPED: ' . $remote . ' (File Already Exists)';
						
						// Compare file sizes
						if(filesize($localPathToFile) != ftp_size($conn, $remote)) {
							$errors[] = 'WARNING: File Size Mismatch for ' . $file;
							$errors[] = 'Local = ' . filesize($localPathToFile) . ' / Remote = ' . ftp_size($conn, $remote);
						}
						
					} else {
						
						// File size
						$size = ftp_size($conn, $remote)/1024/1024;
						if($fileMaxSize) {
							if($size > $fileMaxSize) {
								$filtered = 'Too Big';
							}
						}
						
						if(is_array($fileFilter)) {
							foreach($fileFilter as $value){
								$ext = trim($value);
								if(strpos($remote, $ext) OR strpos($remote, $ext) === 0) { $filtered = 'Filtered'; }
							}
						}
						
						if(!$filtered) {
							
							if(!$listOnly) {
								
								if(ftp_get($conn, $localPathToFile, $remote, FTP_BINARY)) {
										    
								    $messages[] = '$remotePathToFile = ' . $remotePathToFile;
								    
								    $remoteSize = $remoteSize + $size;
								    $counter++;
								
								} else {
								    
								    $errors[] = 'There was a problem fetching ' . $remotePathToFile;
								}
								
							} else {
								
								$messages[] = '$localPathToFile = ' . $localPathToFile;
								
							}
							
							
						} else {
							$messages[] = 'SKIPPED: ' . $remote . '(' . $filtered . ')';
						}

					}
					
				}
			
			}
			$messages[] = '';
		}
	} else {
	
		$messages[] = 'NO FILES FOUND';
	}
	
	
}


// Clear the display and setup the display page. ?>
<!DOCTYPE HTML>
<html>
<head>
<meta charset="UTF-8">
<title>FTP COPY DIRECTORY</title>
</head>
<body>
	
	
<?php // The Engine
	
if($_POST) { // AND ftpConnect($server, $user, $pass)
	
	$fileStart = microtime(true);
	
	$messages[] = "Script Started";
	$messages[] = 'Time Remaining: ' . round(timeRemaining()/60) . ' Minutes';
	
	if(@$_POST['localDir']) {
		$localDir = filter_var($_POST['localDir'], FILTER_SANITIZE_STRING);
	}
	
	// Local checks
	if(!is_dir($localDir)) {
		
		if (!mkdir($localDir, 0755)) {
			$errors[] = '!! Cannot Create Local Directory !!';
		}
	
	} else {
		
		$localFiles = listDirFiles($localDir);
		
		$num = count($localFiles);
		
		if($num) {
			$messages[] = $num . ' Files/Folders Already Exist Here.';
		}
	}
	
	if(!$errors) { // If no local issues...
		
		// Form input
		$server = filter_var($_POST['ftpAddress'], FILTER_SANITIZE_STRING);
		$user = filter_var($_POST['ftpUsername'], FILTER_SANITIZE_STRING);
		$pass = filter_var($_POST['ftpPassword'], FILTER_SANITIZE_STRING);
		$ftpRemote = filter_var($_POST['ftpRemote'], FILTER_SANITIZE_STRING);
		if(!$ftpRemote) { $ftpRemote = '/'; } // Default is root
		
		if(@$_POST['fileMaxSize']) {
			$fileMaxSize = filter_var($_POST['fileMaxSize'], FILTER_SANITIZE_NUMBER_INT);
		}
		
		if(@$_POST['fileFilter']) {
			$fileFilter = filter_var($_POST['fileFilter'], FILTER_SANITIZE_STRING);
			$fileFilter = explode(',', $fileFilter); // Make array
			$messages[] = 'File Filters...';
			$messages[] = $fileFilter;
		}
		
		if(@$_POST['listOnly']) {
			$listOnly = TRUE;
		}
		
		if($server AND $user AND $pass) { // If we have what we need.
			
			if($conn = ftpConnect($server, $user, $pass)) { // If we can connect
				
				if($ftpList = list_dir_contents($ftpRemote)) { // If there is a file list
					
					$messages[] = 'Beginning Copy...';
					
					if($listOnly) {
						$messages[] = '** LISTING FILES ONLY - NO COPY WILL OCCUR **';
					}
					
					parse_dir_contents($ftpList);
				
				} else {
					
					$messages[] = '!! No Files Found !!';
				}	
			
				ftpClose($conn);
			}
		} else {
			$errors[] = '!! Missing FTP Login Info !!';
		}
	
	}
	
	// Reporting
	
	$messages[] = '... End Copy.';
	
	$messages[] = $counter . ' Files Transfered';
	$messages[] = 'Totaling ' . round($remoteSize,1) . ' MB';
	
	$timeEnd = microtime(true);
	$time = $timeEnd - $timeStart;
	
	$messages[] = 'Script Took: ' . round($time/60,2) . ' Minutes';
	
	if($errors) {
		$messages[] = $errors;
	}
	
	echo '<h1 style="margin-left:20%;">Results</h1><pre style="margin-left:20%;font-size:1.3em;">'; print_r($messages); echo '</pre>';
	
	
} else { // End Engine, Output the form
	
?>

<style type="text/css">

* {
	margin: 0;
	padding: 0;
}

body {
	font-size: 1.1em;
	font-family: monospace;
}

form {
	margin: 2em auto;
	border: 1px solid #CCC;
	max-width: 600px;
}

h1 {
	text-align: center;
	margin-top: 2em;
}

p {
	text-align: center;
	max-width: 40%;
	margin: .5em auto;
}

label {
	display: block;
	float: left;
	clear: both;
	width: 25%;
	margin-right: 2%;
	margin-top: 1.5em;
	padding: 3px;
	background:  #666;
	color: #EEE;
	text-align: right;
}
fieldset {
	clear: both;
	margin: 1em;
	border: none;
}

input[type="text"],  
input[type="number"], 
textarea {
	float: left;
	width: 65%;
	margin-top: 2em;
	border: 1px solid #CCC;
	padding: 3px;
}
input[type=checkbox] {
	float: left;
	margin-top: 2em;
	width: 2em;
	height: 2em;
}
input[type=submit] {
	float: right;
	margin: 2em 10% 2em 0;
}
.note {
	float: left;
	clear: both;
	font-size: 80%;
	font-style: italic;
	margin-left: 27%;
	padding-top: .33em;
}

hr, input[type=submit] {
	clear: both;
}

hr {
	color: #CCC;
	border-color: #CCC;
}


</style>



</head>
<body>
	
	<h1>Copy Remote Files to This Server</h1>
	
	<p>This script will copy a remote directory via FTP recursively, file-by-file, to a local directory. Files and directories already in place will not be copied or folders recreated. Hidden files will be ignored.</p>

<form action="<?php echo basename($_SERVER['PHP_SELF']); ?>" method="post">
	
	<fieldset>
		<h2>FTP Settings</h2>
		<label for="ftpAddress">FTP Address</label><input type="text" name="ftpAddress" id="ftpAddress" size="64" />
		<span class="note">The remote FTP address</span>
		<label for="ftpUsername">FTP Username</label><input type="text" name="ftpUsername" id="ftpUsername" size="64" />
		<span class="note">The account's username</span>
		<label for="ftpPassword">FTP Password</label><input type="text" name="ftpPassword" id="ftpPassword" size="64" />
		<span class="note">The password for the account</span>
		<label for="ftpRemote">Remote Directory</label><input type="text" name="ftpRemote" id="ftpRemote" size="64" />
		<span class="note">The remote folder name. Leave blank for the root.</span>
	</fieldset>
	
	<hr />
	
	<fieldset>
		<h2>Local Directory</h2>
		<label for="localDir">Local Directory</label><input type="text" name="localDir" value="" class="" id="localDir" size="64" />
		<span class="note">If left blank, a local folder named <?php echo $localDir; ?> will be created if it does not already exist.</span>
	</fieldset>
	
	<hr />
	
	<fieldset>
		<h2>Copy Options</h2>
		<label for="fileMaxSize">File Size Limit</label><input type="number" name="fileMaxSize" id="fileMaxSize" size="" />
		<span class="note">Maximum file size to get in megabytes</span>
		<label for="fileFilter">Don't Copy</label><input type="text" name="fileFilter" value="" class="" id="fileFilter" size="64" />
		<span class="note">A comma separated list of file types or folder names, as in video, .zip, .tar.gz, etc</span>
		<label>List Files Only:</label><input type="checkbox" name="listOnly" />
		<span class="note">Nothing will be copied. This is a dry run.</span>
	</fieldset>

	<input type="submit" name="submit" id="submit" value="Let's Go" />
</form>	
	
</body>
</html>

<?php } ?>

