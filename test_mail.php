<?php
/**
 * JD Tech Solutions - Isolated Mailer Test Tool
 * Target: Test Microsoft Graph API integration via mailer_config.php
 * Note: Zero database interaction. Safe to run for testing purposes.
 */

// I-include ang iyong mga updated configurations at bagong mailer function
include_once 'config.php';
include_once 'mailer_config.php';

// Paunang mensahe sa screen para alam mong tumatakbo ang script
echo "<h3>JDTech Notification System - Microsoft Graph API Test</h3>";
echo "Inihahanda ang secure OAuth2 token handshake sa Microsoft Servers...<br><br>";

// DITO MO SUBUKAN: Palitan mo ito ng iyong personal o aktwal na email para makita mo kung papasok sa Inbox
$test_recipient = "jmbartolome@bof.com.ph"; 
$test_subject   = "JDTech Core Mailer - Microsoft Graph API Test Connection";

// Ang mensaheng ipapadala gamit ang orihinal mong HTML template framework sa mailer_config
$test_message   = "<strong>GUMAGANA NA!</strong><br><br>
                   Matagumpay na nakakonekta ang Timekeeping System sa 
                   <span style='color: #28a745; font-weight: bold;'>Microsoft Graph API (OAuth2)</span>.<br><br>
                   Hindi na ito maaapektuhan ng Microsoft Security Defaults kahit naka-ON pa ito.";

// Patakbuhin ang function na inayos natin sa mailer_config.php
if (sendBOFEmail($test_recipient, $test_subject, $test_message)) {
    echo "<b style='color: green; font-size: 1.2em;'>SUCCESS: Nakapag-send na gamit ang Microsoft Graph API!</b><br>";
    echo "Paki-check ang inbox ng: <u>" . htmlspecialchars($test_recipient) . "</u> o ang 'Sent Items' folder ng <u>" . MS_SENDER_EMAIL . "</u>.";
} else {
    echo "<b style='color: red; font-size: 1.2em;'>FAILED PA RIN:</b><br>";
    echo "Hindi makakuha ng token o tinanggihan ng Microsoft ang request.<br><br>";
    echo "<b>Mga Dapat Suriin sa config.php:</b><br>";
    echo "1. Tama ba ang Client Secret <b>Value</b> (hindi yung Secret ID)?<br>";
    echo "2. May 'Mail.Send' (Application Permission) na ba sa Azure ID at Naka-Green Check (Granted Admin Consent)?<br>";
    echo "3. Tama ba ang Tenant ID at Client ID?<br><br>";
    echo "<i>Tingnan din ang error logs sa: xampp/php/logs/php_error_log para sa detalyadong error mula sa Microsoft server.</i>";
}
?>