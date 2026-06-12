/** Decode a CString (NUL-terminated UTF-8) from a byte view, stopping at the first NUL. */
export function decodeCString(bytes: Uint8Array): string {
    let end = bytes.indexOf(0);
    if (end < 0) end = bytes.length;
    return new TextDecoder().decode(bytes.subarray(0, end));
}

/**
 * Decode an I32String: the i32s are big-endian byte groups; drop the trailing NUL byte, then
 * wrapping-subtract 128 from each remaining byte to recover a NUL-padded CString.
 * (See the libtw2 map format doc.)
 */
export function decodeI32String(ints: Int32Array): string {
    const bytes = new Uint8Array(ints.length * 4);
    const dv = new DataView(bytes.buffer);
    for (let i = 0; i < ints.length; i++) dv.setInt32(i * 4, ints[i] ?? 0, false); // big-endian
    // last byte is the terminator; subtract 128 (wrapping) from the rest
    const out = new Uint8Array(bytes.length - 1);
    for (let i = 0; i < out.length; i++) out[i] = ((bytes[i] ?? 0) - 128) & 0xff;
    return decodeCString(out);
}
