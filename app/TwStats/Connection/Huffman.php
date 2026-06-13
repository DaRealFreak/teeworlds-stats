<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Teeworlds 0.7 Huffman codec — exact port of ddnet/src/engine/shared/huffman.cpp.
 *
 * 0.7 connected-packet payloads are Huffman-compressed with this specific
 * static frequency table; 0.6 does not use Huffman.
 *
 * Bit order: LSB-first within each byte (matching Teeworlds exactly).
 * EOF symbol is index 256 (HUFFMAN_EOF_SYMBOL).
 */
final class Huffman
{
    private const HUFFMAN_EOF_SYMBOL = 256;
    private const HUFFMAN_MAX_SYMBOLS = 257; // 256 bytes + EOF
    private const HUFFMAN_MAX_NODES = 513;   // HUFFMAN_MAX_SYMBOLS * 2 - 1

    /**
     * Verbatim copy of CHuffman::ms_aFreqTable[257] from huffman.cpp.
     * Index 0..255: byte frequencies; index 256 is NOT here (EOF gets freq=1 in ConstructTree).
     *
     * @var int[]
     */
    private static array $freqTable = [
        1073741824, 4545, 2657, 431, 1950, 919, 444, 482, 2244, 617, 838, 542, 715, 1814, 304, 240, 754, 212, 647, 186,
        283, 131, 146, 166, 543, 164, 167, 136, 179, 859, 363, 113, 157, 154, 204, 108, 137, 180, 202, 176,
        872, 404, 168, 134, 151, 111, 113, 109, 120, 126, 129, 100, 41, 20, 16, 22, 18, 18, 17, 19,
        16, 37, 13, 21, 362, 166, 99, 78, 95, 88, 81, 70, 83, 284, 91, 187, 77, 68, 52, 68,
        59, 66, 61, 638, 71, 157, 50, 46, 69, 43, 11, 24, 13, 19, 10, 12, 12, 20, 14, 9,
        20, 20, 10, 10, 15, 15, 12, 12, 7, 19, 15, 14, 13, 18, 35, 19, 17, 14, 8, 5,
        15, 17, 9, 15, 14, 18, 8, 10, 2173, 134, 157, 68, 188, 60, 170, 60, 194, 62, 175, 71,
        148, 67, 167, 78, 211, 67, 156, 69, 1674, 90, 174, 53, 147, 89, 181, 51, 174, 63, 163, 80,
        167, 94, 128, 122, 223, 153, 218, 77, 200, 110, 190, 73, 174, 69, 145, 66, 277, 143, 141, 60,
        136, 53, 180, 57, 142, 57, 158, 61, 166, 112, 152, 92, 26, 22, 21, 28, 20, 26, 30, 21,
        32, 27, 20, 17, 23, 21, 30, 22, 22, 21, 27, 25, 17, 27, 23, 18, 39, 26, 15, 21,
        12, 18, 18, 27, 20, 18, 15, 19, 11, 17, 33, 12, 18, 15, 19, 18, 16, 26, 17, 18,
        9, 10, 25, 22, 22, 17, 20, 16, 6, 16, 15, 20, 14, 18, 24, 335, 1517,
    ];

    // Node fields stored in flat arrays for performance.
    /** @var int[] $nodeBits — the bit pattern for this symbol */
    private array $nodeBits;
    /** @var int[] $nodeNumBits — number of bits (0xFFFFFFFF = uninitialized leaf; set by Setbits_r) */
    private array $nodeNumBits;
    /** @var int[] $nodeLeaf0 — left child node index (0xFFFF = none) */
    private array $nodeLeaf0;
    /** @var int[] $nodeLeaf1 — right child node index (0xFFFF = none) */
    private array $nodeLeaf1;
    /** @var int[] $nodeSymbol */
    private array $nodeSymbol;

    private int $numNodes = 0;
    private int $startNode = 0;

    public function __construct()
    {
        $this->buildTree();
    }

    // -------------------------------------------------------------------------
    // Tree construction — exact port of CHuffman::ConstructTree
    // -------------------------------------------------------------------------

    private function buildTree(): void
    {
        $maxNodes = self::HUFFMAN_MAX_NODES;

        // Initialise node storage (flat arrays, pre-allocated).
        $this->nodeBits    = array_fill(0, $maxNodes, 0);
        $this->nodeNumBits = array_fill(0, $maxNodes, 0);
        $this->nodeLeaf0   = array_fill(0, $maxNodes, 0xFFFF);
        $this->nodeLeaf1   = array_fill(0, $maxNodes, 0xFFFF);
        $this->nodeSymbol  = array_fill(0, $maxNodes, 0);

        $numSymbols = self::HUFFMAN_MAX_SYMBOLS; // 257

        // CHuffmanConstructNode arrays: nodeId and frequency.
        $constructNodeId   = [];
        $constructFreq     = [];

        for ($i = 0; $i < $numSymbols; $i++) {
            // m_NumBits = 0xFFFFFFFF marks leaf (symbols).
            $this->nodeNumBits[$i] = 0xFFFFFFFF;
            $this->nodeSymbol[$i]  = $i;
            $this->nodeLeaf0[$i]   = 0xFFFF;
            $this->nodeLeaf1[$i]   = 0xFFFF;

            $constructNodeId[$i] = $i;
            // EOF symbol always gets frequency 1 (from ConstructTree).
            $constructFreq[$i]   = ($i === self::HUFFMAN_EOF_SYMBOL) ? 1 : self::$freqTable[$i];
        }

        $this->numNodes   = $numSymbols;
        $numNodesLeft     = $numSymbols;

        // Build the Huffman tree by combining the two lowest-frequency nodes.
        while ($numNodesLeft > 1) {
            // stable_sort descending by frequency — PHP's usort is NOT stable, use
            // a Schwartzian transform to preserve original order for equal frequencies.
            // We sort indices 0..(numNodesLeft-1).
            $indices = range(0, $numNodesLeft - 1);

            // Stable descending sort: primary key = frequency desc, tie = original position asc.
            // We tag each index with its original position before sorting.
            $tagged = [];
            foreach ($indices as $pos => $idx) {
                $tagged[] = [$idx, $pos];
            }

            usort($tagged, static function (array $a, array $b) use ($constructFreq): int {
                $fa = $constructFreq[$a[0]];
                $fb = $constructFreq[$b[0]];
                if ($fb !== $fa) {
                    return $fb <=> $fa; // descending
                }
                return $a[1] <=> $b[1]; // stable: preserve original order
            });

            // Rebuild construct arrays in sorted order.
            $newId   = [];
            $newFreq = [];
            foreach ($tagged as [$origIdx, $origPos]) {
                $newId[]   = $constructNodeId[$origIdx];
                $newFreq[] = $constructFreq[$origIdx];
            }
            $constructNodeId = $newId;
            $constructFreq   = $newFreq;

            // Combine the two nodes with the lowest frequency (last two entries).
            $lo = $numNodesLeft - 1; // lowest frequency
            $hi = $numNodesLeft - 2; // second lowest

            $newNodeIdx = $this->numNodes;

            // Internal nodes have m_NumBits = 0.
            $this->nodeNumBits[$newNodeIdx] = 0;
            $this->nodeLeaf0[$newNodeIdx]   = $constructNodeId[$lo];
            $this->nodeLeaf1[$newNodeIdx]   = $constructNodeId[$hi];

            // Replace slot [hi] with the new combined node; remove slot [lo].
            $constructNodeId[$hi] = $newNodeIdx;
            $constructFreq[$hi]   = $constructFreq[$lo] + $constructFreq[$hi];

            $this->numNodes++;
            $numNodesLeft--;
        }

        // The root is the last node created.
        $this->startNode = $this->numNodes - 1;

        // Assign bit codes by recursive tree walk.
        $this->setBitsRecursive($this->startNode, 0, 0);
    }

    /**
     * Port of CHuffman::Setbits_r.
     *
     * Right child (leaf[1]) appends bit 1; left child (leaf[0]) appends bit 0.
     * Internal nodes have nodeNumBits = 0; leaves have nodeNumBits = 0xFFFFFFFF initially.
     * After assignment, a leaf's nodeNumBits is set to its depth.
     */
    private function setBitsRecursive(int $nodeIdx, int $bits, int $depth): void
    {
        if ($this->nodeLeaf1[$nodeIdx] !== 0xFFFF) {
            $this->setBitsRecursive($this->nodeLeaf1[$nodeIdx], $bits | (1 << $depth), $depth + 1);
        }
        if ($this->nodeLeaf0[$nodeIdx] !== 0xFFFF) {
            $this->setBitsRecursive($this->nodeLeaf0[$nodeIdx], $bits, $depth + 1);
        }

        // Only assign to leaves (m_NumBits != 0, i.e. 0xFFFFFFFF initially).
        if ($this->nodeNumBits[$nodeIdx] !== 0) {
            $this->nodeBits[$nodeIdx]    = $bits;
            $this->nodeNumBits[$nodeIdx] = $depth;
        }
    }

    // -------------------------------------------------------------------------
    // Compress — port of CHuffman::Compress
    // -------------------------------------------------------------------------

    /**
     * Huffman-compress a byte string using the Teeworlds static frequency table.
     *
     * Bit packing: LSB-first within each byte.
     * The EOF symbol is appended after all data bytes; one trailing byte is always
     * written (even if the bitcount is already a multiple of 8 — matching the
     * "bug" documented in libtw2).
     */
    public function compress(string $input): string
    {
        $bits     = 0;
        $bitcount = 0;
        $output   = '';

        $len = strlen($input);

        // Macro equivalent: HUFFMAN_MACRO_LOADSYMBOL(sym)
        $loadSymbol = function (int $sym) use (&$bits, &$bitcount): void {
            $bits     |= $this->nodeBits[$sym] << $bitcount;
            $bitcount += $this->nodeNumBits[$sym];
        };

        // Macro equivalent: HUFFMAN_MACRO_WRITE()
        $write = function () use (&$bits, &$bitcount, &$output): void {
            while ($bitcount >= 8) {
                $output   .= chr($bits & 0xFF);
                $bits      = ($bits >> 8) & 0x00FFFFFF; // keep unsigned 32-bit behaviour
                $bitcount -= 8;
            }
        };

        if ($len > 0) {
            // {A} load the first symbol
            $symbol = ord($input[0]);
            $pos    = 1;

            while ($pos < $len) {
                // {B} load symbol loaded at {A} or previous {C}
                $loadSymbol($symbol);

                // {C} fetch next symbol
                $symbol = ord($input[$pos++]);

                // {B} write
                $write();
            }

            // write last symbol (loaded at {C} or {A} for single-byte input)
            $loadSymbol($symbol);
            $write();
        }

        // Write EOF symbol
        $loadSymbol(self::HUFFMAN_EOF_SYMBOL);
        $write();

        // Write out the last bits (always one extra byte — intentional libtw2 "bug")
        $output .= chr($bits & 0xFF);

        return $output;
    }

    // -------------------------------------------------------------------------
    // Decompress — port of CHuffman::Decompress (simple bit-by-bit tree walk)
    // -------------------------------------------------------------------------

    /**
     * Huffman-decompress a byte string.
     *
     * Reads bits LSB-first, walks the decode tree bit-by-bit, stops at EOF symbol.
     * The LUT optimisation from the C++ source is omitted — pure tree walk matches
     * the same bit order.
     */
    public function decompress(string $input): string
    {
        $bits     = 0;
        $bitcount = 0;
        $output   = '';

        $len = strlen($input);
        $pos = 0;

        while (true) {
            // Refill the bit buffer (up to 24 bits at a time, matching the C++ source).
            while ($bitcount < 24 && $pos < $len) {
                $bits     |= ord($input[$pos++]) << $bitcount;
                $bitcount += 8;
            }

            // Walk the tree bit-by-bit from the root.
            $nodeIdx = $this->startNode;

            while (true) {
                // Follow the tree one bit at a time.
                $bit      = $bits & 1;
                $bits     = ($bits >> 1) & 0x7FFFFFFF; // keep positive (unsigned shift)
                $bitcount--;

                $nodeIdx = $bit ? $this->nodeLeaf1[$nodeIdx] : $this->nodeLeaf0[$nodeIdx];

                if ($nodeIdx === 0xFFFF) {
                    // Corrupt stream — no child in this direction.
                    throw new \RuntimeException('Huffman: corrupt stream (invalid tree path)');
                }

                // Leaf node: m_NumBits != 0.
                if ($this->nodeNumBits[$nodeIdx] !== 0) {
                    break;
                }

                // Need more bits?
                if ($bitcount === 0) {
                    // Refill.
                    while ($bitcount < 24 && $pos < $len) {
                        $bits     |= ord($input[$pos++]) << $bitcount;
                        $bitcount += 8;
                    }
                    if ($bitcount === 0) {
                        throw new \RuntimeException('Huffman: corrupt stream (out of bits)');
                    }
                }
            }

            // Check for EOF.
            if ($nodeIdx === self::HUFFMAN_EOF_SYMBOL) {
                break;
            }

            $output .= chr($this->nodeSymbol[$nodeIdx]);
        }

        return $output;
    }
}
