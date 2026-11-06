<?php
include 'config.php';
if(!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'HR') { http_response_code(403); exit('Access Denied.'); }
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version      : " . PHP_VERSION . "\n";
echo "php.ini loaded   : " . (php_ini_loaded_file() ?: '(none)') . "\n";
echo "cURL extension   : " . (extension_loaded('curl') ? 'YES' : 'NO') . "\n";
echo "openssl extension: " . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n";
echo "curl.cainfo      : " . (ini_get('curl.cainfo') ?: '(blank)') . "\n";
echo "openssl.cafile   : " . (ini_get('openssl.cafile') ?: '(blank)') . "\n";
echo "Server time      : " . date('Y-m-d H:i:s T') . "\n";
echo "DNS (login.microsoftonline.com): " . gethostbyname('login.microsoftonline.com') . "\n";
echo "Secret length    : " . strlen(MS_CLIENT_SECRET) . " chars | looks like a GUID (Secret ID)? "
     . (preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', MS_CLIENT_SECRET) ? 'YES - mali ito, Value ang kailangan' : 'no') . "\n\n";

if(!function_exists('curl_init')) { exit("cURL is missing. Enable extension=curl in php.ini then restart Apache.\n"); }

$ch = curl_init("https://login.microsoftonline.com/" . MS_TENANT_ID . "/oauth2/v2.0/token");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => MS_CLIENT_ID,
        'client_secret' => MS_CLIENT_SECRET,
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials'
    ])
]);
$resp = curl_exec($ch);
echo "cURL errno : " . curl_errno($ch) . "\n";
echo "cURL error : " . (curl_error($ch) ?: '(none)') . "\n";
echo "HTTP code  : " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n\n";
curl_close($ch);

$j = json_decode((string)$resp, true);
if(isset($j['access_token'])) {
    echo "RESULT: TOKEN OK. Gumagana ang koneksyon at credentials. Ang problema ay nasa pagpapadala (Mail.Send / mailer_config.php).\n";
} else {
    echo "RESULT: TOKEN FAILED\n";
    echo "error      : " . ($j['error'] ?? '(walang sagot galing Microsoft)') . "\n";
    echo "description: " . strtok($j['error_description'] ?? '', "\r\n") . "\n";
}