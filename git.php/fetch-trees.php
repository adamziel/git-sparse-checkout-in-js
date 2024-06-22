<?php

require_once 'pack-decoder.php';

// const body = `0033git-upload-pack ${repoPath}\000filter blob:none\n0000`;
// $data = encodeGitRequest(array(
//     "want 35db76a868703b6f5de43e2f0f8f469d2ca06a9f multi_ack_detailed no-done side-band-64k ofs-delta agent=git/isomorphic-git@0.0.0-development",
//     "shallow 35db76a868703b6f5de43e2f0f8f469d2ca06a9f",
//     "deepen 1",
//     "filter blob:none",
//     "done",
//     "done"
// ));
/*
Reference Discovery
When the client initially connects the server will immediately respond with a version number 
(if "version=1" is sent as an Extra Parameter), and a listing of each reference it has (all 
branches and tags) along with the object name that each reference currently points to.
*/
// 35db76a868703b6f5de43e2f0f8f469d2ca06a9f – some commit
// ec86a82ca6aa66887c2509e88ce0a584f759c398 – another commit
    // b413f4ca8cb437ecf09ea9fddc1a1b14a3e3e79a – docs
    // 126102d32c6f93b9a4f562dc6848ec69df8c9897 – tool
    // 100644 blob 7f28c110d41416e186be7f6adcce73944c9f5916	index.js
    // 100644 blob ced8866edd5a4eb85d0394ff773138ff8332f4dc	manifest.js
/*
Otherwise, it enters the negotiation phase, where the client and server determine what the 
minimal packfile necessary for transport is, by telling the server what objects it wants, its
shallow objects (if any), and the maximum commit depth it wants (if any). The client will also 
send a list of the capabilities it wants to be in effect, out of what the server said it could 
do with the first want line.
*/

/*
 * Parsing inspired by isomorphic-git
 * 
 * * Enumerate PACK objects: https://github.com/isomorphic-git/isomorphic-git/blob/bbcdda7d0cd401c5a79d1f372ea31f968e44e93f/src/utils/git-list-pack.js#L9
 * * Parse PACK objects: https://github.com/isomorphic-git/isomorphic-git/blob/bbcdda7d0cd401c5a79d1f372ea31f968e44e93f/src/models/GitPackIndex.js#L7
 */

function encodeGitRequest($commands) {
    $out = '';
    $level = 0;
    foreach ($commands as $command) {
        if ($level > 0) {
            $out .= str_repeat('0000', $level);
        }
        $out .= sprintf("%04x%s\n", strlen($command) + 5, $command);
        if(str_starts_with($command, 'deepen')) {
            $level++;
        } else if(str_starts_with($command, 'done')) {
            $level--;
        }
    }
    return $out;
}

function decodeGitResponse($response) {
    $at = 0;
    while (true) {
        if($at >= strlen($response)) {
            break;
        }
        $lengthStrlen = 4; //strcspn($response, "\0", $at); // Read the current packet's length
        if($lengthStrlen === 0) {
            return;
        }

        $length = hexdec(substr($response, $at, $lengthStrlen));
        if($length === 0) {
            ++$at;
            continue;
        }
        yield substr(
            $response, 
            $at 
                + $lengthStrlen // skip the bytes for encoding encode the packet length
                + 1,            // skip the null byte delimiting the length
            $length 
                - $lengthStrlen // skip the bytes used for encoding the packet length
                - 1             // skip the newline
        );

        $at += $length;
    }
}

function gitUploadPack($url, $body)
{
    // Initialize a cURL session
    $ch = curl_init();

    // Set the URL
    curl_setopt($ch, CURLOPT_URL, $url);

    // Set the headers
    $origin = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
    $headers = [
        "accept: application/x-git-upload-pack-result",
        "content-type: application/x-git-upload-pack-request",
        "origin: $origin",
        // "Git-Protocol: version=2",
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Set the raw data to send in the request body
    // $data = "0090want 35db76a868703b6f5de43e2f0f8f469d2ca06a9f multi_ack_detailed no-done side-band-64k ofs-delta agent=git/isomorphic-git@0.0.0-development\n0035shallow 35db76a868703b6f5de43e2f0f8f469d2ca06a9f\n000ddeepen 1\n00000032have 35db76a868703b6f5de43e2f0f8f469d2ca06a9f\n0009done\n";
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    // Ensure the output is returned as a string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execute the cURL request
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch);
        die();
    }

    // Close the cURL session
    curl_close($ch);

    // Output the response
    return $response;
}

function getBranchRef($infoRefsUrl, $branchName) {
    // Initialize cURL for fetching refs
    $ch = curl_init($infoRefsUrl);

    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_VERBOSE, true); // Enable verbose output for debugging

    // Execute the request
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
        return null;
    } else {
        if (preg_match('/([0-9a-f]{40})\s+refs\/heads\/'.$branchName.'/', $response, $matches)) {
            $branch = $matches[1];
        } else {
            echo "Default branch commit hash not found.\n";
            return null;
        }
    }

    // Close the cURL session
    curl_close($ch);
    return $branch;
}

$commit_hash = getBranchRef(
    'https://github.com/adamziel/wxr-normalize.git/info/refs?service=git-upload-pack',
    'trunk'
);
$trees_raw = gitUploadPack(
    "https://github.com/adamziel/wxr-normalize.git/git-upload-pack",
    encodeGitRequest(array(
        "want $commit_hash multi_ack_detailed no-done filter ",
        "filter blob:none",
        "shallow $commit_hash",
            "deepen 1",
            // "want $commit_hash",
            "done",
        "done",
    ))
);

var_dump($trees_raw);
die();

$trees_raw = substr($trees_raw, strpos($trees_raw, 'PACK'));
$fp = fopen('php://memory', 'r+');
$trees_stream = new StreamWithTextBuffer($fp, $trees_raw);
foreach (listpack($trees_stream) as $object) {
    print_r($object);
}
fclose($fp);

die();
$packidx = strpos($response, 'PACK');
$unpack = decodeGitPack(substr($response, $packidx));
print_r($unpack);
// Parse the raw response
// $parsedResponse = parseGitUploadPackResponse($response);
// $packfile = substr($parsedResponse[2], 4, strlen($parsedResponse[2]) - 4);
// var_dump($packfile);

// var_dump($response);
// var_dump($parsedResponse);
