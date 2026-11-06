<?php

require_once __DIR__ . '/vendor/autoload.php'; 
include_once __DIR__ . '/config.php';

use League\OAuth2\Client\Provider\GenericProvider;
use Microsoft\Graph\Core\GraphServiceClient;
use GuzzleHttp\Client;

function sendBOFEmail($to, $subject, $message) {
    try {

        $provider = new GenericProvider([
            'clientId'                => MS_CLIENT_ID,
            'clientSecret'            => MS_CLIENT_SECRET,
            'urlAuthorize'            => '', 
            'urlAccessToken'          => 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => ''
        ]);

        $accessToken = $provider->getAccessToken('client_credentials', [
            'scope' => 'https://graph.microsoft.com/.default'
        ]);

        $token = $accessToken->getToken();

        $full_html_body = "
        <div style='font-family: Arial, sans-serif; border: 1px solid #f1f1f1; padding: 20px; border-radius: 10px;'>
            <h2 style='color: #4318ff;'>JDTech Portal Notification</h2>
            <hr style='border: 0.5px solid #eee;'>
            <div style='padding: 10px 0; color: #1b254b;'>
                $message
            </div>
            <hr style='border: 0.5px solid #eee;'>
            <p style='font-size: 11px; color: #a3aed0;'>This is an automated message. Please do not reply.</p>
        </div>";

        $mailBody = [
            "message" => [
                "subject" => $subject,
                "body" => [
                    "contentType" => "HTML",
                    "content" => $full_html_body
                ],
                "toRecipients" => [
                    [
                        "emailAddress" => [
                            "address" => $to
                        ]
                    ]
                ]
            ],
            "saveToSentItems" => "true"
        ];

        $guzzleClient = new Client();
        $url = "https://graph.microsoft.com/v1.0/users/" . urlencode(MS_SENDER_EMAIL) . "/sendMail";

        $response = $guzzleClient->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'json' => $mailBody
        ]);

        if ($response->getStatusCode() === 202) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Microsoft Graph API Mailer Error: " . $e->getMessage());
        return false;
    }
}