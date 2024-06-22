<?php

class StreamWithTextBuffer {
    private $stream;
    private $buffer = '';

    public function __construct($stream, $buffer = '') {
        $this->stream = $stream;
        $this->buffer = $buffer;
    }

    public function prepend($string)
    {
        $this->buffer = $string . $this->buffer;        
    }

    public function read($length) {
        if (strlen($this->buffer) < $length && !feof($this->stream)) {
            $this->buffer .= fread($this->stream, 8192);
        }
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $data;
    }

    public function readLine() {
        $line = '';
        while (($pos = strpos($this->buffer, "\n")) === false) {
            $line .= $this->buffer;
            $this->buffer = fread($this->stream, 8192);
        }
        $line .= substr($this->buffer, 0, $pos + 1);
        $this->buffer = substr($this->buffer, $pos + 1);
        return $line;
    }

    public function readUntil($delimiter) {
        $line = '';
        while (($pos = strpos($this->buffer, $delimiter)) === false) {
            $line .= $this->buffer;
            $this->buffer = fread($this->stream, 8192);
        }
        $line .= substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + strlen($delimiter));
        return $line;
    }

    public function feof() {
        return feof($this->stream) && strlen($this->buffer) === 0;
    }
}

function listpack(StreamWithTextBuffer $stream) {
    $header = $stream->read(4);
    if ($header !== 'PACK') {
        throw new Exception("Invalid PACK header '$header'");
    }

    $version = unpack('N', $stream->read(4))[1];
    if ($version !== 2) {
        throw new Exception("Invalid packfile version: $version");
    }

    $numObjects = unpack('N', $stream->read(4))[1];
    if ($numObjects < 1) return;


    while (!$stream->feof() && $numObjects--) {
        $headerData = parseHeader($stream);
        $type = $headerData['type'];
        $expected_uncompressed_length = $headerData['length'];
        $ofs = $headerData['ofs'];
        $reference = $headerData['reference'];

        $compressed_data = '';
        $uncompressed_data = '';

        $ctx = inflate_init(ZLIB_ENCODING_DEFLATE);
        do {
            $chunk = $stream->read(8192);
            if ($chunk === false) {
                throw new Exception("Failed to read 8192 bytes from packfile.");
            }
            $compressed_data .= $chunk;
            $uncompressed_chunk = @inflate_add($ctx, $chunk);
            if($uncompressed_chunk === false) {
                die('Failed to inflate data.');
                // throw new Exception("Failed to inflate data.");
            }
            $uncompressed_data .= $uncompressed_chunk;
        } while (!$stream->feof() && strlen($uncompressed_data) < $expected_uncompressed_length);

        if (strlen($uncompressed_data) !== $expected_uncompressed_length) {
            throw new Exception("Failed to read $expected_uncompressed_length bytes from packfile.");
        }
        $data = substr($uncompressed_data, 0, $expected_uncompressed_length);
        $uncompressed_data = substr($uncompressed_data, $expected_uncompressed_length);

        $compressed_bytes_read = inflate_get_read_len($ctx);
        $remaining_compressed_data = substr($compressed_data, $compressed_bytes_read);
        
        if (strlen($data) !== $expected_uncompressed_length) {
            throw new Exception("Inflated object size is different from that stated in packfile.");
        }
        $stream->prepend($remaining_compressed_data);

        yield [
            'data' => $data,
            'type' => $type,
            'typestr' => $headerData['typestr'],
            'num' => $numObjects,
            // 'offset' => $offset,
            // 'end' => $end,
            'reference' => $reference,
            'ofs' => $ofs,
        ];
    }
}

function parseHeader(StreamWithTextBuffer $stream) {
    $byte = ord($stream->read(1));
    $type = ($byte >> 4) & 0b111;
    $length = $byte & 0b1111;

    if ($byte & 0b10000000) {
        $shift = 4;
        do {
            $byte = ord($stream->read(1));
            $length |= ($byte & 0b01111111) << $shift;
            $shift += 7;
        } while ($byte & 0b10000000);
    }

    $ofs = null;
    $reference = null;
    if ($type === 6) {
        $shift = 0;
        $ofs = 0;
        $bytes = [];
        do {
            $byte = ord($stream->read(1));
            $ofs |= ($byte & 0b01111111) << $shift;
            $shift += 7;
            $bytes[] = $byte;
        } while ($byte & 0b10000000);
        $reference = pack('C*', ...$bytes);
    }
    if ($type === 7) {
        $reference = $stream->read(20);
    }

    switch($type) {
        case 1: $typeStr = "Commit"; break;
        case 2: $typeStr = "Tree"; break;
        case 3: $typeStr = "Blob"; break;
        case 4: $typeStr = "Tag"; break;
        case 6: $typeStr = "OFS_DELTA"; break;
        case 7: $typeStr = "REF_DELTA"; break;
        default: $typeStr = "Unknown"; break;
    }

    return ['type' => $type, 'typestr' => $typeStr, 'length' => $length, 'ofs' => $ofs ? bin2hex($ofs) : null, 'reference' => $reference ? bin2hex($reference) : null];
}

// $fp = fopen('test.pack', 'r');
// $i = 0;
// foreach(listpack(new StreamWithTextBuffer($fp)) as $object) {
//     print_r($object);
//     if (++$i > 100) {
//         break;
//     }
//     // echo "Type: {$object['type']}\n";
//     // echo "Length: {$object['length']}\n";
//     // echo "Offset: {$object['offset']}\n";
//     // echo "End: {$object['end']}\n";
//     // echo "Reference: " . bin2hex($object['reference']) . "\n";
//     // echo "Offset: {$object['ofs']}\n";
//     // echo "Data: " . bin2hex($object['data']) . "\n";
//     echo "\n";
// }
// fclose($fp);

// die("Nothing was decoded");