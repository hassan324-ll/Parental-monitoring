package com.familyguard.child.data

import java.io.ByteArrayInputStream
import java.io.IOException
import java.io.InputStream
import org.junit.Assert.*
import org.junit.Test

class ApiResponseReaderTest {
    private class TrackingStream(private val bytes: ByteArray, private val chunkSize: Int = Int.MAX_VALUE) : InputStream() {
        var consumed = 0
        var closed = false
        override fun read(): Int = if (consumed == bytes.size) -1 else bytes[consumed++].toInt() and 255
        override fun read(buffer: ByteArray, offset: Int, length: Int): Int {
            if (consumed == bytes.size) return -1
            val size = minOf(length, chunkSize, bytes.size - consumed)
            bytes.copyInto(buffer, offset, consumed, consumed + size)
            consumed += size
            return size
        }
        override fun close() { closed = true }
    }

    @Test fun acceptsExactByteLimitAndClosesStream() {
        val stream = TrackingStream(ByteArray(ApiResponseReader.MAX_BYTES) { 32 })
        assertEquals(ApiResponseReader.MAX_BYTES, ApiResponseReader.readUtf8(stream).length)
        assertTrue(stream.closed)
    }

    @Test fun rejectsOversizedResponseWithoutDrainingItOrEchoingCredentialContent() {
        val credential = "a".repeat(64)
        val stream = TrackingStream(credential.repeat(2048).toByteArray())
        try {
            ApiResponseReader.readUtf8(stream)
            fail("Oversized response must fail")
        } catch (failure: IllegalStateException) {
            assertFalse(failure.toString().contains(credential))
            assertEquals("API response too large.", failure.message)
        }
        assertEquals(ApiResponseReader.MAX_BYTES + 1, stream.consumed)
        assertTrue(stream.closed)
    }

    @Test fun handlesChunkedMultibyteNamesWithoutSplittingOrReplacingCharacters() {
        val response = "{\"family_name\":\"خاندان 🚗\"}"
        val stream = TrackingStream(response.toByteArray(Charsets.UTF_8), chunkSize = 1)
        assertEquals(response, ApiResponseReader.readUtf8(stream))
        assertTrue(stream.closed)
    }

    @Test fun byteLimitAppliesToUtf8BytesRatherThanCharacterCount() {
        val response = "é".repeat(ApiResponseReader.MAX_BYTES / 2 + 1)
        val stream = TrackingStream(response.toByteArray(Charsets.UTF_8), chunkSize = 7)
        try {
            ApiResponseReader.readUtf8(stream)
            fail("UTF-8 byte limit must apply")
        } catch (failure: IllegalStateException) {
            assertEquals("API response too large.", failure.message)
        }
        assertTrue(stream.closed)
    }

    @Test fun rejectsMalformedAndTruncatedUtf8InsteadOfReplacingIt() {
        for (bytes in listOf(byteArrayOf(0xc3.toByte(), 0x28), byteArrayOf(0xf0.toByte(), 0x9f.toByte()))) {
            val stream = TrackingStream(bytes)
            try {
                ApiResponseReader.readUtf8(stream)
                fail("Invalid UTF-8 must fail")
            } catch (failure: IllegalStateException) {
                assertEquals("API response is not valid UTF-8.", failure.message)
                assertNull(failure.cause)
            }
            assertTrue(stream.closed)
        }
    }

    @Test fun streamReadFailurePropagatesAndStillClosesTheResponse() {
        var closed = false
        val failure = IOException("synthetic transport failure")
        val stream = object : InputStream() {
            override fun read(): Int = throw failure
            override fun close() { closed = true }
        }
        try {
            ApiResponseReader.readUtf8(stream)
            fail("Read failure must propagate")
        } catch (actual: IOException) {
            assertSame(failure, actual)
        }
        assertTrue(closed)
    }

    @Test fun zeroLengthBulkReadMakesProgressAndEmptyResponseRemainsEmpty() {
        val bytes = ByteArrayInputStream("{}".toByteArray())
        val stream = object : InputStream() {
            override fun read(buffer: ByteArray, offset: Int, length: Int): Int = 0
            override fun read(): Int = bytes.read()
        }
        assertEquals("{}", ApiResponseReader.readUtf8(stream))
        assertEquals("", ApiResponseReader.readUtf8(ByteArrayInputStream(ByteArray(0))))
    }
}
