<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

use App\TwStats\Discovery\FlavorClassifier;
use App\TwStats\Net\UdpTransport;

/**
 * Client-side Teeworlds game connection — handshake to ONLINE plus the
 * reliable (vital/ack/resend) message layer, for both 0.6 and 0.7.
 *
 * Ported from ddnet/src/engine/shared/network_conn.cpp (CNetConnection).
 *
 * States (mirroring CNetConnection::EState):
 *   OFFLINE → (0.7: WANT_TOKEN →) CONNECT → ONLINE
 *   ONLINE  → OFFLINE  (via disconnect())
 *   Any     → OFFLINE  (via CLOSE from peer)
 *
 * Reliable layer (m_Sequence / m_Ack / m_PeerAck / resendBuffer):
 *   - m_Sequence: our outgoing vital counter; incremented BEFORE assigning.
 *   - m_Ack:      highest in-order incoming vital seq we have accepted; put in
 *                 every outgoing packet header.
 *   - m_PeerAck:  peer's ack from received packet headers; drives AckChunks().
 *   - Resend:     unacked vital chunks are re-sent after 1 second (configurable
 *                 via the injected clock so tests can drive it deterministically).
 *
 * Wire-format notes (ambiguities flagged for live smoke — Task 8):
 *
 *   0.7 token exchange
 *   ------------------
 *   The client sends a TOKEN-request as a CONTROL packet (sixup=true) with the
 *   server-token header field = 0xFFFFFFFF (TOKEN_NONE). The payload is
 *   [P7_TOKEN=5][myToken 4 bytes BE][508 zero bytes] (Extended=true, 513 bytes
 *   total payload). This exactly matches SevenConnless::tokenRequest() and
 *   ControlMessages::tokenRequest07().
 *
 *   The server's token response is also a 0.7 CONTROL packet whose header
 *   token field is TOKEN_NONE (0xFFFFFFFF) and whose payload is
 *   [P7_TOKEN=5][serverToken 4 bytes BE]. The ResponseToken in the C++ feed path
 *   is extracted from aChunkData[1..4] (bytes 1–4 of the control payload).
 *   ** FLAGGED FOR LIVE SMOKE: ** confirm this byte layout with a real 0.7 server.
 *
 *   0.6 security token
 *   ------------------
 *   The server's CONNECTACCEPT extra bytes are TKEN magic (4 bytes) followed by
 *   the security token (4 bytes BE). The token is appended to EVERY subsequent
 *   connected packet (control and data alike) in the chunk payload before Huffman
 *   compression — mirrors SendPacket's WriteSecurityToken call (network.cpp).
 *   The receiver strips and validates it before parsing chunks (Feed() in
 *   network_conn.cpp). Implemented via the $securityToken06 parameter on
 *   PacketCodec::pack() and ::unpack(). The initial CONNECT passes null
 *   (token not yet known; C++ uses NET_SECURITY_TOKEN_UNKNOWN = -1).
 *   ** FLAGGED FOR LIVE SMOKE (Task 8): ** confirm with a real 0.6 DDNet server.
 */
final class GameConnection implements ConnectionInterface
{
    // -----------------------------------------------------------------------
    // Connection states
    // -----------------------------------------------------------------------

    private const STATE_OFFLINE    = 'offline';
    private const STATE_WANT_TOKEN = 'want_token'; // 0.7 only
    private const STATE_CONNECT    = 'connect';
    private const STATE_ONLINE     = 'online';

    // -----------------------------------------------------------------------
    // Protocol constants
    // -----------------------------------------------------------------------

    /**
     * Resend threshold in milliseconds (1 000 ms = time_freq() in C++ at 1 Hz).
     * network_conn.cpp Update(): "resend packet if we haven't got it acked in 1 second".
     */
    private const RESEND_TIMEOUT_MS = 1000;

    /**
     * Sequence number modulus (NET_MAX_SEQUENCE = 1 << 10 = 1024).
     */
    private const MAX_SEQUENCE = 1024;

    /**
     * Half the sequence space, used by IsSeqInBackroom.
     */
    private const HALF_SEQUENCE = 512;

    // -----------------------------------------------------------------------
    // Fields mirroring CNetConnection
    // -----------------------------------------------------------------------

    private string $state = self::STATE_OFFLINE;

    /** m_Sequence: our outgoing vital counter (0..1023). */
    private int $sequence = 0;

    /** m_Ack: highest in-order vital seq we accepted from the peer. */
    private int $ack = 0;

    /** m_PeerAck: peer's ack field from received packets. */
    private int $peerAck = 0;

    /**
     * Resend buffer: array of pending unacked vital chunks.
     * Each entry: ['seq'=>int, 'data'=>string, 'firstSentMs'=>int, 'lastSentMs'=>int].
     *
     * @var array<int, array{seq: int, data: string, firstSentMs: int, lastSentMs: int}>
     */
    private array $resendBuffer = [];

    /**
     * Construct buffer: chunks queued for the next flush.
     * Each entry: ['vital'=>bool, 'seq'=>int, 'data'=>string, 'resend'=>bool].
     *
     * @var array<int, array{vital: bool, seq: int, data: string, resend: bool}>
     */
    private array $constructChunks = [];

    /** Whether the next flush packet should carry the RESEND flag. */
    private bool $constructResend = false;

    /** 0.6 security token received from the server (NET_SECURITY_TOKEN). */
    private int $securityToken = -1; // NET_SECURITY_TOKEN_UNKNOWN = -1

    /** 0.7: our own token (random, set on Connect7). */
    private int $myToken = 0;

    /** 0.7: the server token extracted from the token response (goes in packet header). */
    private int $serverToken = 0xFFFFFFFF; // TOKEN_NONE

    /** Whether this is a 0.7 (sixup) connection. */
    private bool $sixup = false;

    // -----------------------------------------------------------------------
    // Dependencies
    // -----------------------------------------------------------------------

    private readonly UdpTransport $transport;
    private readonly string $ip;
    private readonly int $port;
    private readonly string $flavor;

    /** callable(): int — returns current time in milliseconds. Injectable for tests. */
    private readonly mixed $clock;

    private readonly PacketCodec $codec;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    public function __construct(
        UdpTransport $transport,
        string $ip,
        int $port,
        string $flavor,
        ?callable $now = null,
    ) {
        $this->transport = $transport;
        $this->ip        = $ip;
        $this->port      = $port;
        $this->flavor    = $flavor;
        $this->clock     = $now ?? static fn (): int => (int) (hrtime(true) / 1_000_000);
        $this->codec     = new PacketCodec();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Run the client-side handshake to reach ONLINE.
     *
     * @throws ConnectionFailed on timeout, peer CLOSE, or protocol error.
     */
    public function connect(int $overallTimeoutMs = 5000): void
    {
        $deadline = ($this->clock)() + $overallTimeoutMs;

        $this->state      = self::STATE_OFFLINE;
        $this->sequence   = 0;
        $this->ack        = 0;
        $this->peerAck    = 0;
        $this->resendBuffer   = [];
        $this->constructChunks = [];
        $this->constructResend = false;
        $this->securityToken  = -1; // NET_SECURITY_TOKEN_UNKNOWN

        $this->sixup = $this->isSixup();

        if ($this->sixup) {
            // 0.7: WANT_TOKEN → send token request → receive token → CONNECT
            // → receive ACCEPT → ONLINE
            $this->myToken    = $this->generateToken();
            $this->serverToken = 0xFFFFFFFF; // TOKEN_NONE
            $this->state       = self::STATE_WANT_TOKEN;
            $this->sendTokenRequest07();
        } else {
            // 0.6: CONNECT → send CONNECT → receive CONNECTACCEPT → send ACCEPT → ONLINE
            $this->state = self::STATE_CONNECT;
            $this->sendControl06(ControlMessages::connect06());
        }

        // Poll until ONLINE or timeout.
        while ($this->state !== self::STATE_ONLINE) {
            $remaining = $deadline - ($this->clock)();
            if ($remaining <= 0) {
                $this->state = self::STATE_OFFLINE;
                throw new ConnectionFailed('Handshake timed out');
            }

            $dgram = $this->transport->receive((int) min($remaining, 200));
            if ($dgram === null) {
                continue;
            }

            // Only process datagrams from our target server.
            if ($dgram['ip'] !== $this->ip || $dgram['port'] !== $this->port) {
                continue;
            }

            try {
                $this->feedHandshake($dgram['data']);
            } catch (ConnectionFailed $e) {
                throw $e;
            } catch (\Throwable) {
                // Malformed datagram — ignore and keep waiting.
            }
        }
    }

    /**
     * Queue a vital (reliable) chunk for transmission.
     * The sequence number is assigned here (incremented before assigning,
     * mirroring QueueChunk which increments m_Sequence then calls QueueChunkEx).
     */
    public function sendVital(string $message): void
    {
        $this->sequence = ($this->sequence + 1) % self::MAX_SEQUENCE;
        $seq = $this->sequence;

        $this->constructChunks[] = [
            'vital'  => true,
            'seq'    => $seq,
            'data'   => $message,
            'resend' => false,
        ];

        // Save for potential resend.
        $now = ($this->clock)();
        $this->resendBuffer[] = [
            'seq'         => $seq,
            'data'        => $message,
            'firstSentMs' => $now,
            'lastSentMs'  => $now,
        ];
    }

    /**
     * Send all queued chunks (and any due resends) in one packet.
     * Mirrors CNetConnection::Flush() + the resend-due check from Update().
     */
    public function flush(): void
    {
        // Before flushing, check for overdue resends and queue them.
        $this->queueDueResends();

        // Nothing to send unless there are chunks or a resend signal.
        if ($this->constructChunks === [] && !$this->constructResend) {
            return;
        }

        $flags = 0;
        if ($this->constructResend) {
            $flags |= PacketCodec::FLAG_RESEND;
        }

        $chunks = array_map(
            fn (array $c) => ['vital' => $c['vital'], 'seq' => $c['seq'], 'data' => $c['data']],
            $this->constructChunks,
        );

        $packet = [
            'flags'  => $flags,
            'ack'    => $this->ack,
            'chunks' => $chunks,
        ];

        // For 0.6, thread the negotiated security token so it is appended to the
        // chunk payload before compression (mirrors SendPacket in network.cpp).
        // Pass null when securityToken is UNKNOWN (-1) or UNSUPPORTED (0);
        // only a positive value means a real token has been negotiated.
        $token06 = (!$this->sixup && $this->securityToken > 0) ? $this->securityToken : null;

        $wireBytes = $this->sixup
            ? $this->codec->pack($packet, true, $this->serverToken)
            : $this->codec->pack($packet, false, null, $token06);

        $this->transport->send($this->ip, $this->port, $wireBytes);

        // Reset construct buffer.
        $this->constructChunks = [];
        $this->constructResend = false;
    }

    /**
     * Receive one datagram, process control messages, update acks, and return
     * the list of non-control data payloads (chunk data strings).
     *
     * @return string[]  Data payloads from non-control, accepted chunks.
     * @throws ConnectionFailed if the peer sent CLOSE.
     */
    public function receive(int $timeoutMs): array
    {
        $dgram = $this->transport->receive($timeoutMs);
        if ($dgram === null) {
            return [];
        }

        // Ignore datagrams not from our peer.
        if ($dgram['ip'] !== $this->ip || $dgram['port'] !== $this->port) {
            return [];
        }

        try {
            return $this->feedOnline($dgram['data']);
        } catch (ConnectionFailed $e) {
            throw $e;
        } catch (\Throwable) {
            // Malformed datagram — silently ignore.
            return [];
        }
    }

    /**
     * Send a CLOSE control packet and transition to OFFLINE.
     */
    public function disconnect(): void
    {
        if ($this->state === self::STATE_OFFLINE) {
            return;
        }

        $this->sendControlPacket(ControlMessages::close());
        $this->state = self::STATE_OFFLINE;
    }

    public function isOnline(): bool
    {
        return $this->state === self::STATE_ONLINE;
    }

    public function getState(): string
    {
        return $this->state;
    }

    // -----------------------------------------------------------------------
    // Handshake helpers
    // -----------------------------------------------------------------------

    /**
     * Process an incoming datagram during the handshake phase.
     *
     * @throws ConnectionFailed if the peer sends CLOSE.
     * @throws \InvalidArgumentException if the datagram is malformed.
     */
    private function feedHandshake(string $data): void
    {
        $packet = $this->codec->unpack($data, $this->sixup);

        // A CLOSE during handshake is a hard failure.
        if ($packet['flags'] & PacketCodec::FLAG_CONTROL) {
            $ctrl = ControlMessages::parse($packet['control']);

            if ($ctrl['ctrlMsg'] === ControlMessages::CLOSE) {
                $this->state = self::STATE_OFFLINE;
                $reason = rtrim($ctrl['extra'], "\x00");
                throw new ConnectionFailed("Server closed connection: {$reason}");
            }

            if ($this->sixup) {
                $this->feedHandshake07($packet, $ctrl);
            } else {
                $this->feedHandshake06($packet, $ctrl);
            }
        }
        // Non-control packets during handshake: ignore (should not arrive yet).
    }

    /**
     * 0.6 handshake feed: waiting for CONNECTACCEPT.
     *
     * @throws ConnectionFailed
     */
    private function feedHandshake06(array $packet, array $ctrl): void
    {
        if ($this->state === self::STATE_CONNECT && $ctrl['ctrlMsg'] === ControlMessages::CONNECTACCEPT) {
            // Extract security token if present (TKEN magic + 4-byte token).
            if (strlen($ctrl['extra']) >= 8 && str_starts_with($ctrl['extra'], ControlMessages::SECURITY_TOKEN_MAGIC)) {
                $this->securityToken = ControlMessages::securityTokenFromConnectAccept($ctrl['extra']);
            } else {
                $this->securityToken = 0; // NET_SECURITY_TOKEN_UNSUPPORTED
            }

            // Send ACCEPT and transition to ONLINE.
            $this->sendControl06(ControlMessages::accept());
            $this->state = self::STATE_ONLINE;
        }
    }

    /**
     * 0.7 handshake feed.
     *
     * WANT_TOKEN: waiting for P7_TOKEN response → extract server token → send CONNECT.
     * CONNECT:    waiting for P7_ACCEPT → transition to ONLINE.
     *
     * @throws ConnectionFailed
     */
    private function feedHandshake07(array $packet, array $ctrl): void
    {
        if ($this->state === self::STATE_WANT_TOKEN && $ctrl['ctrlMsg'] === ControlMessages::P7_TOKEN) {
            // The server token is in bytes 1–4 of the control payload (ResponseToken
            // extraction in C++: ToSecurityToken(&pPacket->m_aChunkData[1])).
            if (strlen($ctrl['extra']) >= 4) {
                $this->serverToken = unpack('N', substr($ctrl['extra'], 0, 4))[1] & 0xFFFFFFFF;
            }

            $this->state = self::STATE_CONNECT;
            // Send CONNECT with the server token in the packet header.
            $this->sendControl07(ControlMessages::connect07($this->myToken));
        } elseif ($this->state === self::STATE_CONNECT && $ctrl['ctrlMsg'] === ControlMessages::P7_ACCEPT) {
            $this->state = self::STATE_ONLINE;
        }
    }

    // -----------------------------------------------------------------------
    // Online receive helper
    // -----------------------------------------------------------------------

    /**
     * Feed a datagram while ONLINE.
     *
     * @return string[]
     * @throws ConnectionFailed if CLOSE is received.
     * @throws \InvalidArgumentException on malformed data.
     */
    private function feedOnline(string $data): array
    {
        // For 0.6, pass the negotiated security token so the suffix is stripped
        // before chunk parsing (mirrors Feed() in network_conn.cpp).
        // Only strip when a real positive token has been negotiated (> 0).
        $token06 = (!$this->sixup && $this->securityToken > 0) ? $this->securityToken : null;
        $packet = $this->codec->unpack($data, $this->sixup, $token06);

        // Validate the peer ack is within the window [m_PeerAck, m_Sequence] before
        // advancing.  Mirrors network_conn.cpp Feed() lines 350-361.
        $incomingAck = $packet['ack'];
        if ($this->sequence >= $this->peerAck) {
            if ($incomingAck < $this->peerAck || $incomingAck > $this->sequence) {
                // Out-of-range ack — drop the packet silently.
                return [];
            }
        } else {
            // Sequence has wrapped; reject only if ack is between peerAck and sequence
            // in the "gap" direction.
            if ($incomingAck < $this->peerAck && $incomingAck > $this->sequence) {
                return [];
            }
        }

        $this->peerAck = $incomingAck;
        $this->ackChunks($this->peerAck);

        // When the peer sets the RESEND flag, re-queue all unacked buffered chunks
        // (mirrors CNetConnection::Resend() called from Feed() in network_conn.cpp:366-367).
        if ($packet['flags'] & PacketCodec::FLAG_RESEND) {
            $this->resend();
        }

        if ($packet['flags'] & PacketCodec::FLAG_CONTROL) {
            $ctrl = ControlMessages::parse($packet['control']);

            if ($ctrl['ctrlMsg'] === ControlMessages::CLOSE) {
                $this->state = self::STATE_OFFLINE;
                $reason = rtrim($ctrl['extra'], "\x00");
                throw new ConnectionFailed("Server closed connection: {$reason}");
            }

            if ($ctrl['ctrlMsg'] === ControlMessages::KEEPALIVE
                || $ctrl['ctrlMsg'] === ControlMessages::P7_KEEPALIVE) {
                // Reply with a keepalive.
                $this->sendControlPacket(ControlMessages::keepAlive());
            }

            return [];
        }

        // Non-control packet: process chunks.
        $messages = [];
        foreach ($packet['chunks'] as $chunk) {
            if ($chunk['vital']) {
                $expectedSeq = ($this->ack + 1) % self::MAX_SEQUENCE;
                if ($chunk['seq'] === $expectedSeq) {
                    // In-order vital chunk: accept and advance ack.
                    $this->ack = $chunk['seq'];
                    $messages[] = $chunk['data'];
                } elseif ($this->isSeqInBackroom($chunk['seq'], $this->ack)) {
                    // Already acknowledged — ignore duplicate.
                } else {
                    // Out-of-order: signal resend request (ignore the data).
                    $this->signalResend();
                }
            } else {
                // Non-vital chunk: always deliver.
                $messages[] = $chunk['data'];
            }
        }

        return $messages;
    }

    // -----------------------------------------------------------------------
    // Resend logic
    // -----------------------------------------------------------------------

    /**
     * Mark that the next flush packet should carry the RESEND flag.
     * Mirrors CNetConnection::SignalResend().
     */
    private function signalResend(): void
    {
        $this->constructResend = true;
    }

    /**
     * Re-queue ALL buffered unacked vital chunks for sending and update their
     * lastSentMs timestamps.  Mirrors CNetConnection::Resend() (network_conn.cpp
     * lines 210-213) which calls ResendChunk() on every entry in the resend ring
     * buffer.  Called when the peer sets the RESEND flag in a received packet.
     */
    private function resend(): void
    {
        $now = ($this->clock)();

        foreach ($this->resendBuffer as &$entry) {
            $this->constructChunks[] = [
                'vital'  => true,
                'seq'    => $entry['seq'],
                'data'   => $entry['data'],
                'resend' => true,
            ];
            $this->constructResend    = true;
            $entry['lastSentMs']      = $now;
        }
        unset($entry);
    }

    /**
     * Drop all resend-buffer entries whose seq is ≤ the peer's ack.
     * Mirrors CNetConnection::AckChunks().
     */
    private function ackChunks(int $ack): void
    {
        $this->resendBuffer = array_values(array_filter(
            $this->resendBuffer,
            fn (array $r) => !$this->isSeqInBackroom($r['seq'], $ack),
        ));
    }

    /**
     * Check all resend-buffer entries for overdue status and queue them into
     * the construct buffer with the RESEND flag.
     * Mirrors the resend-fix block in CNetConnection::Update().
     */
    private function queueDueResends(): void
    {
        $now = ($this->clock)();

        foreach ($this->resendBuffer as &$entry) {
            if ($now - $entry['lastSentMs'] >= self::RESEND_TIMEOUT_MS) {
                $this->constructChunks[] = [
                    'vital'  => true,
                    'seq'    => $entry['seq'],
                    'data'   => $entry['data'],
                    'resend' => true,
                ];
                $this->constructResend = true;
                $entry['lastSentMs']   = $now;
            }
        }
        unset($entry);
    }

    /**
     * IsSeqInBackroom: returns true if $seq is in the acknowledged window
     * (i.e. ≤ $ack within the circular sequence space).
     *
     * Mirrors CNetBase::IsSeqInBackroom (network.cpp lines 435-451):
     *   Bottom = Ack - MAX_SEQUENCE/2
     *   If Bottom < 0: Seq <= Ack || Seq >= Bottom+MAX_SEQUENCE
     *   Else:          Seq <= Ack && Seq >= Bottom
     */
    private function isSeqInBackroom(int $seq, int $ack): bool
    {
        $bottom = $ack - self::HALF_SEQUENCE;

        if ($bottom < 0) {
            if ($seq <= $ack) {
                return true;
            }
            if ($seq >= ($bottom + self::MAX_SEQUENCE)) {
                return true;
            }
        } else {
            if ($seq <= $ack && $seq >= $bottom) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Low-level send helpers
    // -----------------------------------------------------------------------

    /**
     * Send a 0.6 connected CONTROL packet.
     *
     * Threads the negotiated security token (when known) so it is appended to
     * the control payload — mirrors SendPacket / SendControlMsg in the C++ engine
     * which calls WriteSecurityToken regardless of CONTROL flag.
     */
    private function sendControl06(string $controlPayload): void
    {
        // Pass the token only when it has a real positive negotiated value.
        // securityToken = -1 (UNKNOWN) or 0 (UNSUPPORTED) means no suffix.
        $token06 = ($this->securityToken > 0) ? $this->securityToken : null;

        $wireBytes = $this->codec->pack([
            'flags'   => PacketCodec::FLAG_CONTROL,
            'ack'     => $this->ack,
            'chunks'  => [],
            'control' => $controlPayload,
        ], false, null, $token06);

        $this->transport->send($this->ip, $this->port, $wireBytes);
    }

    /**
     * Send a 0.7 connected CONTROL packet (uses the server token in the header).
     */
    private function sendControl07(string $controlPayload): void
    {
        $wireBytes = $this->codec->pack([
            'flags'   => PacketCodec::FLAG_CONTROL,
            'ack'     => $this->ack,
            'chunks'  => [],
            'control' => $controlPayload,
        ], true, $this->serverToken);

        $this->transport->send($this->ip, $this->port, $wireBytes);
    }

    /**
     * Send a control packet on the correct protocol.
     */
    private function sendControlPacket(string $controlPayload): void
    {
        if ($this->sixup) {
            $this->sendControl07($controlPayload);
        } else {
            $this->sendControl06($controlPayload);
        }
    }

    /**
     * Send a 0.7 TOKEN-request.
     *
     * The token-request is a CONTROL packet with TOKEN_NONE (0xFFFFFFFF) in
     * the header (we don't know the server token yet). The payload is the
     * standard ControlMessages::tokenRequest07() (513 bytes: P7_TOKEN byte +
     * myToken 4 bytes + 508 zero bytes).
     *
     * Mirrors CNetConnection::Connect7(): SendControlWithToken7(P7_TOKEN, TOKEN_NONE)
     * → SendControlMsgWithToken7(..., Token=TOKEN_NONE, ..., Extended=true).
     */
    private function sendTokenRequest07(): void
    {
        $wireBytes = $this->codec->pack([
            'flags'   => PacketCodec::FLAG_CONTROL,
            'ack'     => 0,
            'chunks'  => [],
            'control' => ControlMessages::tokenRequest07($this->myToken),
        ], true, 0xFFFFFFFF); // TOKEN_NONE as the header token

        $this->transport->send($this->ip, $this->port, $wireBytes);
    }

    /**
     * Derive whether to use the 0.7 (sixup) protocol from the flavor string.
     *
     * Only vanilla 0.7 servers speak sixup.  DDNet is a 0.6-based game: DDNet
     * (and DDNet-derived fng) servers serve maps over the classic 0.6 wire and
     * actively reject 0.7 connections ("0.7 connections are not accepted at this
     * time").  Routing FLAVOR_DDNET through sixup made every DDNet/fng map
     * download fail at the handshake, so DDNet uses the 0.6 classic protocol here
     * (the security-token suffix is negotiated from CONNECTACCEPT either way).
     */
    private function isSixup(): bool
    {
        return $this->flavor === FlavorClassifier::FLAVOR_VANILLA_07;
    }

    /**
     * Generate a random 32-bit client token (mirrors GenerateToken7).
     */
    private function generateToken(): int
    {
        return random_int(0, 0xFFFFFFFE); // stay below TOKEN_NONE (0xFFFFFFFF)
    }
}
