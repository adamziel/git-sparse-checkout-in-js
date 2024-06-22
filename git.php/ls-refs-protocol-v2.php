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
        if($command === '0001') {
            $out .= $command;
            continue;
        }
        if($command === '0000') {
            $out .= $command."\n";
            continue;
        }
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
        // "accept: ",
        'Accept: application/x-git-upload-pack-advertisement',
        'Accept: application/x-git-upload-pack-result',
        "content-type: application/x-git-upload-pack-request",
        "origin: $origin",
        "Git-Protocol: version=2",
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_VERBOSE, true);

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

    // // Prepare the v2 command call.
    // $command = "command=ls-refs\n";
    // if (!empty($refs)) {
    //     $command .= "peel\n";
    //     foreach ($refs as $ref) {
    //         $command .= "ref $ref\n";
    //     }
    // }

    // $full_command = "0032git-upload-pack" . pack("H*", "00");
    // $len = sprintf("%04x", strlen($command) + 4);
    // $full_command .= $len . $command . "0000";

    // // Prepare a new cURL session for the git protocol v2 query.
    // $ch = curl_init($repoUrl . '/git-upload-pack');

    // // Set the necessary cURL options.
    // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // curl_setopt($ch, CURLOPT_POST, true);
    // curl_setopt($ch, CURLOPT_VERBOSE, true);
    // curl_setopt($ch, CURLOPT_POSTFIELDS, $full_command);
    // curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //     'Content-Type: application/x-git-upload-pack-request',
    //     'Accept: application/x-git-upload-pack-advertisement',
    //     'Content-Length: ' . strlen($full_command),
    //     'Git-Protocol-Version: 2'
    // ]);
$request = encodeGitRequest(
    array(
        'command=ls-refs',
        // 'obj-info=0051d9cd270d5abf0741f8773bc01010cff1d558d79e',
        // 'agent=git/2.37.3',
        // 'object-format=sha1',
        // '0001',
        // 'oid 0051d9cd270d5abf0741f8773bc01010cff1d558d79e',
        // 'symrefs',
        // 'peel',
        // 'ref-prefix HEAD',
        '0000'
    )
);
var_dump($request);
// $request = '006fcommand=ls-refs
// 0042ref-prefix HEAD
// 0000
// ';
$trees_raw = gitUploadPack(
    "https://github.com/adamziel/wxr-normalize.git/git-upload-pack",
    $request
    // "git-upload-pack\0".
    // $request
    // '0032git-upload-pack'.pack('H*', '00').
    // "0014command=ls-refs\n0009peel\n".pack('H*', '00')//."0000"//pack('H*', '00').
    // encodeGitRequest(["command=ls-refs", '0000'])//pack('H*', '00').
    
    // '0019agent=git/github-74f58a100f14'.pack('H*', '00').
    // '0010object-format=sha1'.pack('H*', '00').
    // '0001peel'.pack('H*', '00').
    // '0000'
    // '# service=git-upload-pack\n'.'version 2\n'.


    // encodeGitRequest(array(
    //     // "",
    //     "command=ls-refs", // ref-prefix HEAD",
    //     "agent=git/2.37.3",
    //     "object-format=sha1"
    // ))


    // ."0001\n"
    // .encodeGitRequest(array(
    //     // "0001",
    //     "peel",
    //     "ref-prefix trunk",
    //     // "0000",
    //     // "peel",
    //     // "ref HEAD",
    //     // "ref refs/heads/trunk",
    //     // "ref refs/heads/trunk",
    //     // "agent=git/2.30.0",
    //     // "want-ref refs/heads/trunk",
    //     // "want-ref trunk",
    // ))
    // ."0000\n"
);
var_dump($trees_raw);