package com.familyguard.child.data

import java.io.InputStream
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction

/** Bounded before decoding; never includes a credential-bearing response in error messages. */
internal object ApiResponseReader {
    const val MAX_BYTES = 65536

    fun readUtf8(input: InputStream): String {
        val buffer = ByteArray(MAX_BYTES + 1)
        var size = 0
        input.use {
            while (size < buffer.size) {
                val count = it.read(buffer, size, buffer.size - size)
                if (count < 0) break
                if (count == 0) {
                    val single = it.read()
                    if (single < 0) break
                    buffer[size++] = single.toByte()
                } else {
                    size += count
                }
            }
        }
        check(size <= MAX_BYTES) { "API response too large." }
        return try {
            Charsets.UTF_8.newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)
                .decode(ByteBuffer.wrap(buffer, 0, size)).toString()
        } catch (failure: java.nio.charset.CharacterCodingException) {
            throw IllegalStateException("API response is not valid UTF-8.")
        }
    }
}
