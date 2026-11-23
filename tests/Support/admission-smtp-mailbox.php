<?php

declare(strict_types=1);

// Loopback-only test mailbox. The retry scenario can request an initial rejection.
$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}
echo 'ready '.stream_socket_get_name($server, false)."\n";
fflush(STDOUT);
$dropAck = in_array('--drop-ack-once', $argv, true);
$first = in_array('--reject-first', $argv, true);
while ($client = stream_socket_accept($server, 60)) {
    if ($first) {
        $first = false;
        echo "deferred\n";
        fflush(STDOUT);
        fwrite($client, "421 Temporary outage\r\n");
        fclose($client);
        continue;
    }
    fwrite($client, "220 localhost ESMTP\r\n");
    while (($line = fgets($client)) !== false) {
        $verb = strtoupper(strtok(trim($line), ' '));
        if ($verb === 'QUIT') {
            fwrite($client, "221 Goodbye\r\n");
            break;
        }
        if ($verb === 'DATA') {
            fwrite($client, "354 End with a dot\r\n");
            $message = '';
            while (($line = fgets($client)) !== false && $line !== ".\r\n") {
                $message .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            if ($line === false) {
                break;
            }
            echo 'delivered '.base64_encode($message)."\n";
            fflush(STDOUT);
            if ($dropAck) {
                $dropAck = false;
                break;
            }
            fwrite($client, "250 Accepted\r\n");
        } elseif (in_array($verb, ['EHLO', 'HELO', 'MAIL', 'RCPT', 'RSET', 'NOOP'], true)) {
            fwrite($client, "250 OK\r\n");
        } else {
            fwrite($client, "502 Unsupported\r\n");
        }
    }
    fclose($client);
}
