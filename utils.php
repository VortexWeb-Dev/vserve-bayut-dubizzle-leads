<?php
require_once __DIR__ . '/crest/crest.php';

define('LISTINGS_ENTITY_TYPE_ID', 1048);
// define('SECONDARY_ENTITY_TYPE_ID', 1110);

function makeApiRequest(string $url, array $headers)
{
    // Validate the URL before making the request
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        logData('error.log', "Invalid URL: $url");
        throw new Exception("Invalid URL: $url");
    }

    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the response
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Execute cURL request
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        $error_message = "cURL error: " . curl_error($ch);
        logData('error.log', $error_message);  // Log error message to a file
        throw new Exception($error_message);
    }

    // Check the HTTP status code of the response
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        $error_message = "HTTP error: $httpCode - Response: $response";
        logData('error.log', $error_message);  // Log HTTP error
        throw new Exception($error_message);
    }

    // Separate headers and body
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $header_size);

    curl_close($ch);

    // Decode the JSON response
    $data = json_decode($body, true);

    // Check if JSON decoding was successful
    if ($data === null) {
        $json_error_message = "JSON Decoding Error: " . json_last_error_msg();
        logData('error.log', $json_error_message);  // Log JSON decoding error
        throw new Exception($json_error_message);
    }

    // Return the decoded data
    return $data;
}

function logData(string $filename, string $message): void
{
    date_default_timezone_set('Asia/Kolkata');

    $baseDir = __DIR__ . '/logs';
    $year = date('Y');
    $month = date('m');
    $day = date('d');

    $logDir = "$baseDir/$year/$month/$day";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $logFile = "$logDir/$filename";
    $logMessage = date('Y-m-d H:i:s') . " - $message\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

function fetchLeads(string $type, string $timestamp, string $authToken, string $platform)
{
    $url = $platform === 'bayut' ? "https://www.bayut.com/api-v7/stats/website-client-leads?type=$type&timestamp=$timestamp" : "https://dubizzle.com/profolio/api-v7/stats/website-client-leads?type=$type&timestamp=$timestamp";

    try {
        $data = makeApiRequest($url, [
            'Content-Type: application/json',
            "Authorization: Bearer $authToken"
        ]);

        if (empty($data)) {
            return null;
        }


        return $data ?? [];
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

function createBitrixDeal($fields)
{
    $response = CRest::call('crm.lead.add', [
        'fields' => $fields
    ]);

    return $response['result'];
}

function generatePropertyLink($propertyId)
{
    return "https://www.bayut.com/property/details-$propertyId.html";
}

function getProcessedLeads($file)
{
    if (file_exists($file)) {
        return file($file, FILE_IGNORE_NEW_LINES);
    }

    return [];
}

function saveProcessedLead($file, $lead_id)
{
    file_put_contents($file, $lead_id . PHP_EOL, FILE_APPEND);
}

function registerCall($fields)
{
    $res = CRest::call('telephony.externalcall.register', $fields);
    return $res['result'];
}

function finishCall($fields)
{
    $res = CRest::call('telephony.externalcall.finish', $fields);
    return $res['result'];
}

function attachRecord($fields)
{
    $res = CRest::call('telephony.externalcall.attachRecord', $fields);
    return $res['result'];
}

function createContact($fields)
{
    $res = CRest::call('crm.contact.add', ['fields' => $fields]);
    return $res['result'];
}

function timeToSec($time)
{
    $time = explode(':', $time);
    return $time[0] * 3600 + $time[1] * 60 + $time[2];
}

function parseMessageAndLink($input)
{
    preg_match('/Link:\s(https?:\/\/\S+)/', $input, $linkMatch);
    $link = $linkMatch[1] ?? null;

    $parts = explode('Link:', $input, 2);
    $message = trim($parts[0]);

    return [
        'message' => $message,
        'link' => $link
    ];
}

function getUserId(array $filter): ?int
{
    $response = CRest::call('user.get', [
        'filter' => array_merge($filter, ['ACTIVE' => 'Y']),
    ]);

    if (!empty($response['error'])) {
        error_log('Error getting user: ' . $response['error_description']);
        return null;
    }

    if (empty($response['result'])) {
        return null;
    }

    if (empty($response['result'][0]['ID'])) {
        return null;
    }

    return (int)$response['result'][0]['ID'];
}

function getResponsiblePerson(string $searchValue, string $searchType): ?int
{
    if ($searchType === 'reference') {
        $response = CRest::call('crm.item.list', [
            'entityTypeId' => LISTINGS_ENTITY_TYPE_ID,
            'filter' => ['ufCrm12ReferenceNumber' => $searchValue],
            'select' => ['ufCrm12ReferenceNumber', 'ufCrm12AgentEmail', 'ufCrm12ListingOwner', 'ufCrm12OwnerId'],
        ]);

        if (!empty($response['error'])) {
            error_log(
                'Error getting CRM item: ' . $response['error_description']
            );
            return DEFAULT_ASSIGNED_USER_ID;
        }

        if (
            empty($response['result']['items']) ||
            !is_array($response['result']['items'])
        ) {
            error_log(
                'No listing found with reference number: ' . $searchValue
            );
            return DEFAULT_ASSIGNED_USER_ID;
        }

        $listing = $response['result']['items'][0];

        $ownerId = $listing['ufCrm12OwnerId'] ?? null;
        if ($ownerId && is_numeric($ownerId)) {
            return (int)$ownerId;
        }

        $ownerName = $listing['ufCrm12ListingOwner'] ?? null;

        if ($ownerName) {
            $nameParts = explode(' ', trim($ownerName), 2);

            $firstName = $nameParts[0] ?? null;
            $lastName = $nameParts[1] ?? null;

            return getUserId([
                '%NAME' => $firstName,
                '%LAST_NAME' => $lastName,
                '!ID' => [3, 268]
            ]);
        }


        $agentEmail = $listing['ufCrm12AgentEmail'] ?? null;
        if ($agentEmail) {
            return getUserId([
                'EMAIL' => $agentEmail,
                '!ID' => 3,
                '!ID' => 268
            ]);
        } else {
            error_log(
                'No agent email found for reference number: ' . $searchValue
            );
            return DEFAULT_ASSIGNED_USER_ID;
        }
    } else if ($searchType === 'phone') {
        return getUserId([
            '%PERSONAL_MOBILE' => $searchValue,
            '!ID' => 3,
            '!ID' => 268
        ]);
    }

    return DEFAULT_ASSIGNED_USER_ID;
}

function getPropertyPrice($propertyReference)
{
    $response = CRest::call('crm.item.list', [
        'entityTypeId' => LISTINGS_ENTITY_TYPE_ID,
        'filter' => ['ufCrm12ReferenceNumber' => $propertyReference],
        'select' => ['ufCrm12Price'],
    ]);

    return $response['result']['items'][0]['ufCrm12Price'] ?? null;
}
