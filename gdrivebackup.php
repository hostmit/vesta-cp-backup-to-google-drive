<?php

require __DIR__ . '/vendor/autoload.php';

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
define("LOGFILE", "gdrivebackup.log");
define("DELETESLEEPDELAY", 30); //we need a delay after deleting a file to let google drive to update free space

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('Google Drive API PHP Quickstart');
    $client->setScopes([
        Google_Service_Drive::DRIVE,
        Google_Service_Gmail::GMAIL_SEND,
    ]);
    $client->setAuthConfig(__DIR__ . '/credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    // The file token.json stores the user's access and refresh tokens, and is
    // created automatically when the authorization flow completes for the first
    // time.
    $tokenPath = __DIR__ . '/token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}

function getFreeSpace($service)
{
    global $log;
    try {
        $optParams = array('fields' => '*');
        $about = $service->about->get($optParams);
        return (intval($about->getStorageQuota()["limit"]) - intval($about->getStorageQuota()["usage"]));
    } catch (Exception $e) {
        $log->error("An error occurred while getting free space: " . $e->getMessage());
        return -1;
    }
}

function getTotalSpace($service)
{
    global $log;
    try {
        $optParams = array('fields' => '*');
        $about = $service->about->get($optParams);
        return (intval($about->getStorageQuota()["limit"]));
    } catch (Exception $e) {
        $log->error("An error occurred while getting total space: " . $e->getMessage());
        return -1;
    }
}

function humanRedeableBytes($bytes, $decimals = 2)
{
    $size = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
}

function readChunk($handle, $chunkSize)
{
    $byteCount = 0;
    $giantChunk = "";
    while (!feof($handle)) {
        // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
        $chunk = fread($handle, 8192);
        $byteCount += strlen($chunk);
        $giantChunk .= $chunk;
        if ($byteCount >= $chunkSize) {
            return $giantChunk;
        }
    }
    return $giantChunk;
}

function sendEmail($address, $msg, $subject = "Error occured while making backup")
{
    global $client;
    $mailService = new Google_Service_Gmail($client);
    $mailer = $mailService->users_messages;

    $user = 'me';
    $strRawMessage = "";
    //$strRawMessage = "From: myAddress<thebodywear.backup@gmail.com>\r\n";
    $strRawMessage .= "To: <$address>\r\n";
    $strRawMessage .= 'Subject: =?utf-8?B?' . base64_encode($subject) . "?=\r\n";
    $strRawMessage .= "MIME-Version: 1.0\r\n";
    $strRawMessage .= "Content-Type: text/plain; charset=utf-8\r\n";
    $strRawMessage .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
    $strRawMessage .= "$msg\r\n";
    // The message needs to be encoded in Base64URL
    $mime = rtrim(strtr(base64_encode($strRawMessage), '+/', '-_'), '=');

    $message = new Google_Service_Gmail_Message($client);
    $message->setRaw($mime);

    $mailer = $mailService->users_messages;

    $mailService->users_messages->send("me", $message);
}

function exitFatal($msg, $code = 1)
{
    global $log;
    global $address;
    $log->critical($msg);
    if ($address) sendEmail($address, $msg);
    exit($code);
}

$log = new Logger("log");
$stream = new RotatingFileHandler(__DIR__ . '/' . LOGFILE, 5, Logger::DEBUG, true, 0664);
$formatter = new LineFormatter("[%datetime%] %level_name%: %message% \n", "Y.m.d H:i:s");
$formatterNoNewLine = new LineFormatter("%message%",null,true);
$stream->setFormatter($formatter);
$log->pushHandler($stream);

// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_Drive($client);

$fileName = false;
$userName = false;
$saveLocalCopy = false;
$address = false;

foreach ($argv as $value) {
    if ($value == "--help") {
        echo "--file=fileName REQUIRED" . PHP_EOL;
        echo "--user=userName REQUIRED" . PHP_EOL;
        echo "--getfreespace" . PHP_EOL;
        exit(0);
    } elseif (strpos($value, "--file=") === 0) {
        $fileName = substr($value, strlen("--file="));
    } elseif (strpos($value, "--user=") === 0) {
        $userName = substr($value, strlen("--user="));
    } elseif ($value == "--savelocalcopy") {
        $saveLocalCopy = true;
    } elseif ($value == "--getfreespace") {
        echo getFreeSpace($service) . PHP_EOL;
        exit(0);
    } elseif (strpos($value, "--email=") === 0) {
        $address = substr($value, strlen("--email="));
    }
}

if (php_sapi_name() != 'cli') {
    exitFatal('This application must be run on the command line.');
}

$totalSpace = getTotalSpace($service);
$freeSpace = getFreeSpace($service);



if ($fileName && $userName) {
    exitFatal("Use one of options, either --file or --user");
}

if (!$fileName && !$userName) {
    exitFatal("I need  a file to upload --file=fileName or --user=userName tag");
}

//Let's figure out which file to use

$output = null;
$outputArr = null;
$returnValue = null;

if ($userName) {
    $log->info("userName: " . $userName . ", will check if backup already exists");
    $output = exec("ls -rt /backup/|grep " . $userName . ".$(date +%Y)-$(date +%m)-$(date +%d)", $outputArr, $returnValue);
    if ($output == "") {
        $log->info("Looks like we dont have a backup file, will create new one");
        $output = exec("/usr/local/vesta/bin/v-backup-user $userName", $outputArr, $returnValue);
        if ($returnValue != 0) {
            exitFatal("Something went wrong making backup for user: $userName...");
        } else {
            $fileName = "/backup/" . exec("ls -t /backup/|grep " . $userName . ".$(date +%Y)-$(date +%m)-$(date +%d)", $outputArr, $returnValue);
            $log->info("Backup created, filenName: $fileName");
        }
    } else {
        $fileName = "/backup/" . $output;
        $log->info("We have today's backup, fileName: $fileName");
    }

}

if (!is_writable($fileName)) {
    exitFatal("File " . $fileName . " does not exist or is not writable...");
}

if (filesize($fileName) == 0) {
    exitFatal("File " . $fileName . " is empty...");
}

$fileSize = filesize($fileName);

if ($fileSize > $totalSpace) {
    exitFatal("File " . $fileName . ", size: " . humanRedeableBytes($fileSize) . ", which is over Google Drive Total Space: " . humanRedeableBytes($totalSpace));
}

//Print the names and IDs for up to 10 files.
$optParams = array(
    'pageSize' => 10,
    'fields' => 'nextPageToken, files(id, name, size, createdTime)',
    //'q' => "'" . DIRNAME . "' in parents",
    "orderBy" => "createdTime asc",
);

$filesArr = array();
$results = $service->files->listFiles($optParams);
foreach ($results->getFiles() as $file) {
    $filesArr[] = [
        "id" => $file->getId(),
        "name" => $file->getName(),
        "size" => $file->getSize(),
        "createdTime" => $file->getCreatedTime(),
    ];
}

foreach ($filesArr as $value) {
    if ($value["name"] == $fileName && $value["size"] == $fileSize) {
        $log->info("File $fileName with exact size is already in google drive....");
        exit(0);
    }
}

$log->info("Google Drive total space: " . humanRedeableBytes($totalSpace) . ", free space: " . humanRedeableBytes($freeSpace) . ", filename: " . $fileName . ", filesize: " . humanRedeableBytes($fileSize));
$i = 0; //prevent endless loop

$needDeleting = false;
while ($freeSpace < $fileSize) {
    $needDeleting = true;
    if ($i > 100) {
        exitFatal("Something went wrong while clearing space, did over $i loops...");
    }
    $log->info("Google Drive has only " . humanRedeableBytes($freeSpace) . ", while filesize is " . humanRedeableBytes($fileSize) . " will clear some space...");
    $i++;
    try {
        $service->files->delete($filesArr[0]["id"]);
        $log->info("File: " . $filesArr[0]["name"] . " deleted...");
        array_shift($filesArr);
    } catch (Exception $e) {
        exitFatal("An error occurred while deleting file: " . $e->getMessage());
    }

    $freeSpace = getFreeSpace($service);
    $log->info("Now Google Drive has: " . humanRedeableBytes($freeSpace));
}
if ($needDeleting) {
    $log->info("Sleeping for " . DELETESLEEPDELAY . " for google to update free space ....");
    $k = 0;

    $stream->setFormatter($formatterNoNewLine);
    while ($k != DELETESLEEPDELAY) {
        $k++;
        sleep(1);
        $log->info(".");
    }
    $log->info(PHP_EOL);
    $stream->setFormatter($formatter);
}

$log->info("Moving on to upload procedure...");

try {

    $file = new Google_Service_Drive_DriveFile();
    $file->name = $fileName;
    $chunkSizeBytes = 10 * 1024 * 1024;
// Call the API with the media upload, defer so it doesn't immediately return.
    $client->setDefer(true);
    $request = $service->files->create($file);
// Create a media file upload to represent our upload process.
    $media = new Google_Http_MediaFileUpload(
        $client,
        $request,
        'text/plain',
        null,
        true,
        $chunkSizeBytes
    );
    $media->setFileSize(filesize($fileName));
// Upload the various chunks. $status will be false until the process is
    // complete.
    $status = false;
    $handle = fopen($fileName, "rb");
    $i = 0;
    $stream->setFormatter($formatterNoNewLine);
    while (!$status && !feof($handle)) {
        $log->info(".");
        // read until you get $chunkSizeBytes from $fileName
        // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
        // An example of a read buffered file is when reading from a URL
        $chunk = readChunk($handle, $chunkSizeBytes);
        $status = $media->nextChunk($chunk);
    }
    $log->info(PHP_EOL);
    $stream->setFormatter($formatter);
// The final value of $status will be the data from the API for the object
    // that has been uploaded.
    fclose($handle);

} catch (Exception $e) {
    exitFatal("Upload procedure failed with exception: " . $e->getMessage());
}

if ($status != false) {
    $log->info("File: " . $fileName . " successfuly uploaded!");
    if (!$saveLocalCopy) {
        $log->info("Removing $fileName...");
        unlink($fileName);
    }
    exit(0);
} else {
    exitFatal("File: " . $fileName . " upload failed!");
}
