<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionFailed;
use App\TwStats\Connection\ControlMessages;
use App\TwStats\Connection\GameConnection;
use App\TwStats\Connection\PacketCodec;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeUdpTransport;

/**
 * Tests for the GameConnection client handshake and reliable message layer.
 *
 * All transport I/O is scripted via FakeUdpTransport so no real UDP socket is
 * used. The clock is injected so resend timeouts are deterministic.
 *
 * Wire-format notes for the live smoke (Task 8):
 *   - 0.7 token-response framing: a 7-byte connected control header with token
 *     field = 0xFFFFFFFF (TOKEN_NONE), control payload = [P7_TOKEN=5][serverToken 4 bytes BE].
 *   - 0.6 CONNECTACCEPT extra: [TKEN magic 4 bytes][security token 4 bytes BE].
 *   - 0.7 CONNECT: CONTROL packet with server token in the header, payload =
 *     [NET_CTRLMSG_CONNECT=1][myToken 4 bytes BE][508 zero bytes] (Extended=true, 513 bytes).
 */
class GameConnectionTest extends TestCase
{
    private PacketCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new PacketCodec();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a connected control packet (0.6) with the given control payload.
     * This mirrors what a 0.6 server sends back.
     */
    private function make06ControlPacket(string $controlPayload, int $ack = 0): string
    {
        return $this->codec->pack([
            'flags'   => PacketCodec::FLAG_CONTROL,
            'ack'     => $ack,
            'chunks'  => [],
            'control' => $controlPayload,
        ], false);
    }

    /**
     * Build a connected control packet (0.7 / sixup) with the given control payload.
     * The token in the header is the server's security token passed in the header.
     */
    private function make07ControlPacket(string $controlPayload, int $headerToken, int $ack = 0): string
    {
        return $this->codec->pack([
            'flags'   => PacketCodec::FLAG_CONTROL,
            'ack'     => $ack,
            'chunks'  => [],
            'control' => $controlPayload,
        ], true, $headerToken);
    }

    /**
     * Build a 0.7 token-response packet (what a 0.7 server sends to the client's
     * NET_CTRLMSG_TOKEN request). Mirrors UpdateDataTest's tokenResponse lambda.
     *
     * Format: 7-byte connected control header with TOKEN_NONE (0xFFFFFFFF) in the
     * token slot, control payload = [P7_TOKEN=5][serverToken 4 bytes BE].
     */
    private function make07TokenResponse(int $serverToken): string
    {
        // Mirrors UpdateDataTest: "\x04\x00\x00\xff\xff\xff\xff" . "\x05" . pack('N', $t)
        // byte0: (P7_CONTROL=1 << 2) | ack-high = 0x04
        // byte1: ack-low = 0x00
        // byte2: numChunks = 0x00
        // bytes 3-6: TOKEN_NONE = 0xFFFFFFFF
        // control payload: [P7_TOKEN=5][serverToken 4 bytes BE]
        return "\x04\x00\x00\xff\xff\xff\xff"
            . chr(ControlMessages::P7_TOKEN)
            . pack('N', $serverToken & 0xFFFFFFFF);
    }

    /**
     * Build a 0.7 ACCEPT control packet (sent by the server after it receives CONNECT).
     * The header carries the server's security token.
     */
    private function make07AcceptPacket(int $serverToken): string
    {
        return $this->make07ControlPacket(
            chr(ControlMessages::P7_ACCEPT),
            $serverToken
        );
    }

    /**
     * Build a 0.6 CONNECTACCEPT control packet with the security token embedded.
     * Extra bytes = TKEN magic (4 bytes) + token (4 bytes BE).
     */
    private function make06ConnectAcceptPacket(int $securityToken): string
    {
        $extra = ControlMessages::SECURITY_TOKEN_MAGIC . pack('N', $securityToken & 0xFFFFFFFF);
        return $this->make06ControlPacket(
            chr(ControlMessages::CONNECTACCEPT) . $extra
        );
    }

    /**
     * Build a 0.6 or 0.7 CLOSE control packet.
     */
    private function make06ClosePacket(string $reason = ''): string
    {
        return $this->make06ControlPacket(ControlMessages::close($reason));
    }

    /**
     * Build a packet with one non-vital data chunk (simulates a server data message).
     */
    private function make06DataPacket(string $data, int $ack = 0): string
    {
        return $this->codec->pack([
            'flags'  => 0,
            'ack'    => $ack,
            'chunks' => [
                ['vital' => false, 'seq' => -1, 'data' => $data],
            ],
        ], false);
    }

    /**
     * Build a 0.6 packet that acks up to the given sequence (empty chunks, just the ack field).
     */
    private function make06AckPacket(int $ack): string
    {
        return $this->codec->pack([
            'flags'  => 0,
            'ack'    => $ack,
            'chunks' => [
                ['vital' => false, 'seq' => -1, 'data' => 'x'],
            ],
        ], false);
    }

    // -----------------------------------------------------------------------
    // Test 1 — 0.6 handshake
    // -----------------------------------------------------------------------

    /**
     * The 0.6 handshake must:
     *   1. Send CONNECT (CONTROL packet with TKEN magic payload).
     *   2. Receive CONNECTACCEPT (extracts security token).
     *   3. Send ACCEPT.
     *   4. Transition to ONLINE.
     */
    public function test_06_handshake_reaches_online(): void
    {
        $transport = new FakeUdpTransport();
        $securityToken = 0xDEADBEEF;
        $serverIp = '127.0.0.1';
        $serverPort = 8303;

        // Queue: server replies with CONNECTACCEPT carrying the security token.
        $transport->queue($serverIp, $serverPort, $this->make06ConnectAcceptPacket($securityToken));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_06');
        $conn->connect(2000);

        $this->assertTrue($conn->isOnline(), 'Connection must be ONLINE after 0.6 handshake');

        // Verify sent datagrams: [0] = CONNECT, [1] = ACCEPT.
        $sent = $transport->sent;
        $this->assertGreaterThanOrEqual(2, count($sent), 'Expected at least CONNECT + ACCEPT sent');

        // First sent: CONNECT control packet with TKEN magic.
        $connectPkt = $this->codec->unpack($sent[0]['data'], false);
        $this->assertSame(PacketCodec::FLAG_CONTROL, $connectPkt['flags'] & PacketCodec::FLAG_CONTROL);
        $ctrl = ControlMessages::parse($connectPkt['control']);
        $this->assertSame(ControlMessages::CONNECT, $ctrl['ctrlMsg']);
        $this->assertStringStartsWith(ControlMessages::SECURITY_TOKEN_MAGIC, $ctrl['extra'],
            'CONNECT must carry TKEN magic');

        // Last sent: ACCEPT control packet.
        $acceptPkt = $this->codec->unpack($sent[count($sent) - 1]['data'], false);
        $acceptCtrl = ControlMessages::parse($acceptPkt['control']);
        $this->assertSame(ControlMessages::ACCEPT, $acceptCtrl['ctrlMsg']);
    }

    // -----------------------------------------------------------------------
    // Test 1b — DDNet flavor uses the 0.6 classic handshake (regression)
    //
    // DDNet (and DDNet-derived fng) servers are 0.6-based and reject 0.7
    // connections.  A DDNet-flavored connection must therefore drive the classic
    // 0.6 CONNECT/CONNECTACCEPT/ACCEPT handshake — not the 0.7 token request.
    // The old isSixup() treated FLAVOR_DDNET as sixup, which made every DDNet/fng
    // map download fail with "0.7 connections are not accepted at this time".
    // -----------------------------------------------------------------------

    public function test_ddnet_flavor_uses_06_handshake(): void
    {
        $transport = new FakeUdpTransport();
        $securityToken = 0x0A0B0C0D;
        $serverIp = '127.0.0.1';
        $serverPort = 8303;

        // A 0.6 CONNECTACCEPT must be enough to reach ONLINE — proving the
        // connection spoke 0.6, not 0.7 (a sixup connection would have sent a
        // token request and ignored this 0.6 packet).
        $transport->queue($serverIp, $serverPort, $this->make06ConnectAcceptPacket($securityToken));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'ddnet');
        $conn->connect(2000);

        $this->assertTrue($conn->isOnline(), 'DDNet connection must reach ONLINE via the 0.6 handshake');

        // First sent datagram must be a 0.6 CONNECT (CONTROL + TKEN magic),
        // NOT a 0.7 token request.
        $connectPkt = $this->codec->unpack($transport->sent[0]['data'], false);
        $this->assertSame(PacketCodec::FLAG_CONTROL, $connectPkt['flags'] & PacketCodec::FLAG_CONTROL);
        $ctrl = ControlMessages::parse($connectPkt['control']);
        $this->assertSame(ControlMessages::CONNECT, $ctrl['ctrlMsg'], 'DDNet must send a 0.6 CONNECT');
        $this->assertStringStartsWith(ControlMessages::SECURITY_TOKEN_MAGIC, $ctrl['extra'],
            'DDNet 0.6 CONNECT must carry TKEN magic');
    }

    // -----------------------------------------------------------------------
    // Test 2 — 0.7 handshake
    // -----------------------------------------------------------------------

    /**
     * The 0.7 handshake must:
     *   1. Send a TOKEN-request (CONTROL+token packet to get the server token).
     *   2. Receive a P7_TOKEN response (extract server token).
     *   3. Send CONNECT (CONTROL packet with server token in header).
     *   4. Receive P7_ACCEPT.
     *   5. Transition to ONLINE.
     */
    public function test_07_handshake_reaches_online(): void
    {
        $transport = new FakeUdpTransport();
        $serverToken = 0xCAFEBABE;
        $serverIp = '127.0.0.1';
        $serverPort = 8303;

        // Queue: token response then ACCEPT.
        $transport->queue($serverIp, $serverPort, $this->make07TokenResponse($serverToken));
        $transport->queue($serverIp, $serverPort, $this->make07AcceptPacket($serverToken));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_07');
        $conn->connect(2000);

        $this->assertTrue($conn->isOnline(), 'Connection must be ONLINE after 0.7 handshake');

        // Verify sent datagrams.
        $sent = $transport->sent;
        $this->assertGreaterThanOrEqual(2, count($sent),
            'Expected at least TOKEN-request + CONNECT sent');

        // First sent: TOKEN-request (0.7 control with TOKEN_NONE in header).
        // This is the SevenConnless or PacketCodec-framed token request.
        $tokenReqBytes = $sent[0]['data'];
        // Must be a CONTROL packet (0.7 wire: P7_FLAG_CONTROL=1 in bits 2-7 of byte0)
        $byte0 = ord($tokenReqBytes[0]);
        $wireFlags = ($byte0 & 0xFC) >> 2; // raw flags from wire
        $this->assertSame(1, $wireFlags & 1, 'TOKEN request must have P7_CONTROL flag set (=1)');

        // Second sent (index 1): CONNECT with server token in the header.
        $connectBytes = $sent[1]['data'];
        $connectPkt = $this->codec->unpack($connectBytes, true);
        $this->assertSame(PacketCodec::FLAG_CONTROL, $connectPkt['flags'] & PacketCodec::FLAG_CONTROL,
            'CONNECT must be a CONTROL packet');
        $this->assertSame($serverToken, $connectPkt['token'],
            'CONNECT header must carry the server token');
        $connectCtrl = ControlMessages::parse($connectPkt['control']);
        $this->assertSame(ControlMessages::P7_CONNECT, $connectCtrl['ctrlMsg'],
            'CONNECT payload must start with NET_CTRLMSG_CONNECT=1');
    }

    // -----------------------------------------------------------------------
    // Test 3 — Vital sequencing + ack clears resend buffer
    // -----------------------------------------------------------------------

    /**
     * After ONLINE: sendVital('a') seq=1, sendVital('b') seq=2, flush() sends them.
     * When a server packet acks seq=2, the resend buffer must be empty (no re-flush
     * re-sends those chunks).
     */
    public function test_vital_sequencing_and_ack_clears_resend_buffer(): void
    {
        $transport = new FakeUdpTransport();
        $serverIp = '127.0.0.1';
        $serverPort = 8303;

        // Script the 0.6 handshake.
        $transport->queue($serverIp, $serverPort, $this->make06ConnectAcceptPacket(0x12345678));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_06');
        $conn->connect(2000);
        $this->assertTrue($conn->isOnline());

        // Clear sent tracking from the handshake.
        $transport->sent = [];

        // Send two vital chunks.
        $conn->sendVital('chunk_a');
        $conn->sendVital('chunk_b');
        $conn->flush();

        // One flush packet with both chunks should have been sent.
        $this->assertCount(1, $transport->sent, 'flush() must send exactly one packet');
        $flushPkt = $this->codec->unpack($transport->sent[0]['data'], false);
        $this->assertCount(2, $flushPkt['chunks']);
        $this->assertSame(1, $flushPkt['chunks'][0]['seq'], 'First vital chunk must have seq=1');
        $this->assertSame(2, $flushPkt['chunks'][1]['seq'], 'Second vital chunk must have seq=2');

        // Now the server acks seq=2.
        $transport->queue($serverIp, $serverPort, $this->make06AckPacket(2));
        $transport->sent = [];

        $conn->receive(100);

        // After acking seq=2, a subsequent flush must send nothing (empty resend buffer).
        $conn->flush();
        $this->assertCount(0, $transport->sent,
            'After full ack, flush must not re-send any chunks');
    }

    // -----------------------------------------------------------------------
    // Test 4 — Resend on timeout
    // -----------------------------------------------------------------------

    /**
     * After sending a vital chunk, advancing the injected clock past the
     * 1-second resend timeout without an ack must cause the next flush() to
     * re-send with the RESEND flag set.
     */
    public function test_resend_fires_after_injected_timeout(): void
    {
        $nowMs = 0;
        $clock = function () use (&$nowMs): int { return $nowMs; };

        $transport = new FakeUdpTransport();
        $serverIp = '127.0.0.1';
        $serverPort = 8303;

        // Script 0.6 handshake.
        $transport->queue($serverIp, $serverPort, $this->make06ConnectAcceptPacket(0xABCDEF01));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_06', $clock);
        $conn->connect(2000);
        $this->assertTrue($conn->isOnline());

        // Send one vital chunk at t=0.
        $conn->sendVital('important_data');
        $conn->flush();

        // Advance clock past the 1000 ms resend threshold.
        $nowMs = 1100;

        // Clear sent after initial flush.
        $transport->sent = [];

        // flush() again — with no ack received, the overdue resend must fire.
        $conn->flush();

        // At least one packet must have been re-sent.
        $this->assertGreaterThanOrEqual(1, count($transport->sent),
            'flush() must re-send overdue vital chunks');

        // The re-sent packet must have the RESEND flag.
        $reSentPkt = $this->codec->unpack($transport->sent[0]['data'], false);
        $this->assertSame(PacketCodec::FLAG_RESEND, $reSentPkt['flags'] & PacketCodec::FLAG_RESEND,
            'Re-sent packet must have FLAG_RESEND set');
    }

    // -----------------------------------------------------------------------
    // Test 5 — CLOSE packet transitions to disconnected
    // -----------------------------------------------------------------------

    /**
     * Receiving a CLOSE control packet must cause receive() to throw
     * ConnectionFailed (or mark offline) so the caller can detect the
     * disconnection.
     */
    public function test_close_packet_disconnects(): void
    {
        $transport = new FakeUdpTransport();
        $serverIp = '127.0.0.1';
        $serverPort = 8303;

        // Script 0.6 handshake then a CLOSE.
        $transport->queue($serverIp, $serverPort, $this->make06ConnectAcceptPacket(0x11223344));
        $transport->queue($serverIp, $serverPort, $this->make06ClosePacket('Server shutting down'));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_06');
        $conn->connect(2000);
        $this->assertTrue($conn->isOnline());

        // receive() must throw or mark offline when it processes the CLOSE.
        $thrown = false;
        try {
            $conn->receive(100);
        } catch (ConnectionFailed $e) {
            $thrown = true;
        }

        if (!$thrown) {
            // Acceptable alternative: sets offline without throwing.
            $this->assertFalse($conn->isOnline(),
                'Connection must be offline after receiving CLOSE');
        }
    }

    // -----------------------------------------------------------------------
    // Test 7 — Post-ONLINE 0.6 flush carries the negotiated security-token suffix
    // -----------------------------------------------------------------------

    /**
     * After a 0.6 handshake that negotiates a security token, flush() must produce
     * a packet whose raw wire bytes are 4 bytes longer than a flush() without a
     * token would be — confirming the token suffix is appended before compression.
     *
     * Mirrors SendPacket (network.cpp): WriteSecurityToken appends 4 bytes to
     * m_aChunkData before the Huffman-compress step.
     */
    public function test_06_post_online_flush_appends_token_suffix(): void
    {
        $transport   = new FakeUdpTransport();
        $serverIp    = '127.0.0.1';
        $serverPort  = 8303;
        $securityToken = 0xBEEFCAFE;

        // Complete the handshake — this sets the negotiated security token.
        $transport->queue($serverIp, $serverPort, $this->make06ConnectAcceptPacket($securityToken));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_06');
        $conn->connect(2000);
        $this->assertTrue($conn->isOnline());

        // Build a reference packet WITHOUT a token (using the codec directly).
        $referencePacket = $this->codec->pack([
            'flags'  => 0,
            'ack'    => 0,
            'chunks' => [
                ['vital' => true, 'seq' => 1, 'data' => 'payload'],
            ],
        ], false);

        // Flush one vital chunk via the connection.
        $transport->sent = [];
        $conn->sendVital('payload');
        $conn->flush();

        $this->assertCount(1, $transport->sent, 'flush() must send one packet');
        $flushedWire = $transport->sent[0]['data'];

        // The flushed packet must be 4 bytes longer than the same packet without
        // a token suffix (token is appended before compression; both are small
        // enough that neither will compress).
        $this->assertSame(
            strlen($referencePacket) + 4,
            strlen($flushedWire),
            'Post-ONLINE 0.6 flush must append the 4-byte security token suffix',
        );
    }

    // -----------------------------------------------------------------------
    // Test 8 — Peer-RESEND flag re-queues buffered vital chunks
    // -----------------------------------------------------------------------

    /**
     * When a received packet has the RESEND flag set, GameConnection must
     * re-queue ALL currently-buffered unacked vital chunks for the next flush(),
     * mirroring CNetConnection::Resend() (network_conn.cpp lines 210-213).
     *
     * The re-sent packet must carry the FLAG_RESEND flag (because constructResend
     * is set by resend()), and the re-sent chunks must contain the original data.
     */
    public function test_peer_resend_flag_requeues_buffered_vitals(): void
    {
        $transport  = new FakeUdpTransport();
        $serverIp   = '127.0.0.1';
        $serverPort = 8303;

        // Script the 0.6 handshake (securityToken=0 = UNSUPPORTED, no token suffix).
        // Build a CONNECTACCEPT packet without the TKEN magic so securityToken = 0.
        $noTokenAccept = $this->codec->pack([
            'flags'   => PacketCodec::FLAG_CONTROL,
            'ack'     => 0,
            'chunks'  => [],
            'control' => chr(ControlMessages::CONNECTACCEPT), // no TKEN extra
        ], false);
        $transport->queue($serverIp, $serverPort, $noTokenAccept);

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_06');
        $conn->connect(2000);
        $this->assertTrue($conn->isOnline());

        // Send one vital chunk and flush — it stays in the resend buffer (no ack yet).
        $transport->sent = [];
        $conn->sendVital('vital_data');
        $conn->flush();
        $this->assertCount(1, $transport->sent, 'Initial flush must send one packet');

        // Build a data packet from the server that has FLAG_RESEND set.
        $resendFlagPacket = $this->codec->pack([
            'flags'  => PacketCodec::FLAG_RESEND,
            'ack'    => 0, // does NOT ack our vital
            'chunks' => [
                ['vital' => false, 'seq' => 0, 'data' => 'ping'],
            ],
        ], false);
        $transport->queue($serverIp, $serverPort, $resendFlagPacket);

        // Process the resend-flag packet.
        $transport->sent = [];
        $conn->receive(100);

        // After receiving the RESEND flag, flush() must immediately re-send the
        // buffered vital (constructChunks is already populated by resend()).
        $conn->flush();
        $this->assertGreaterThanOrEqual(1, count($transport->sent),
            'flush() must re-send buffered vitals after peer RESEND flag');

        // The re-sent packet must carry FLAG_RESEND.
        $reSentPkt = $this->codec->unpack($transport->sent[0]['data'], false);
        $this->assertSame(PacketCodec::FLAG_RESEND, $reSentPkt['flags'] & PacketCodec::FLAG_RESEND,
            'Re-sent packet must have FLAG_RESEND set');

        // The re-sent chunk must contain the original data.
        $this->assertCount(1, $reSentPkt['chunks'],
            'Re-sent packet must contain the re-queued vital chunk');
        $this->assertSame('vital_data', $reSentPkt['chunks'][0]['data'],
            'Re-sent chunk must contain the original vital data');
    }

    // -----------------------------------------------------------------------
    // Test 6 — Malformed datagram is ignored, not fatal
    // -----------------------------------------------------------------------

    /**
     * A garbage datagram that cannot be unpacked must be silently ignored;
     * the connection must remain ONLINE and receive() must return an empty list
     * (or the next valid message) rather than throwing.
     */
    public function test_malformed_datagram_is_ignored(): void
    {
        $transport = new FakeUdpTransport();
        $serverIp = '127.0.0.1';
        $serverPort = 8303;

        // Script 0.6 handshake.
        $transport->queue($serverIp, $serverPort, $this->make06ConnectAcceptPacket(0xDEADC0DE));

        $conn = new GameConnection($transport, $serverIp, $serverPort, 'vanilla_06');
        $conn->connect(2000);
        $this->assertTrue($conn->isOnline());

        // Queue a garbage datagram (2 bytes — too short for any packet header).
        $transport->queue($serverIp, $serverPort, "\xFF\xFE");

        // receive() must not throw; connection must remain ONLINE.
        $messages = $conn->receive(100);

        $this->assertTrue($conn->isOnline(),
            'Connection must remain ONLINE after a malformed datagram');
        $this->assertIsArray($messages,
            'receive() must return an array even when ignoring garbage');
    }
}
